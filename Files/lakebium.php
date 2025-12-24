<?php
// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Session settings
ini_set('session.gc_maxlifetime', 2592000); // 30 days
session_set_cookie_params(2592000);
session_start();

// Database connection
define('DB_HOST', 'localhost');
define('DB_USER', 'lakebanc_Offihito');
define('DB_PASS', 'P4QG(m2jkWXN');
define('DB_NAME', 'lakebanc_Database');

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// Stripe configuration
require_once __DIR__ . '/stripe-php/init.php';
\Stripe\Stripe::setApiKey('');
define('STRIPE_PUBLISHABLE_KEY', '');

// Prices in TRY (in cents)
$prices = [
    'monthly' => 5999, // 59.99 TRY
    'yearly' => 59999, // 599.99 TRY
    'lifetime' => 90000 // 900.00 TRY
];

// Default language
$default_lang = 'tr';

// Get browser language
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fi', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Language selection
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

// Load language file
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        $content = file_get_contents($langFile);
        if ($content === false) {
            error_log("Language file could not be read: $langFile");
            return [];
        }
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Language file JSON error: " . json_last_error_msg());
            return [];
        }
        return $decoded ?: [];
    }
    error_log("Language file not found: $langFile");
    return [];
}

$translations = loadLanguage($lang);

// Check user login status
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$username = $isLoggedIn ? ($_SESSION['username'] ?? null) : null;
$profilePicture = null;
$isPremium = false;
$hasLakebiumBadge = false;

if ($isLoggedIn) {
    try {
        // Fetch user information
        $stmt = $db->prepare("SELECT u.username, p.avatar_url FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $_SESSION['username'] = $result['username'];
            $username = $result['username'];
            $profilePicture = $result['avatar_url'] ?? null;
        } else {
            error_log("User not found: user_id = " . $_SESSION['user_id']);
            session_unset();
            session_destroy();
            $isLoggedIn = false;
            $username = null;
        }

        // Check premium status
        $stmt = $db->prepare("SELECT plan_type, end_date, status FROM lakebium WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$_SESSION['user_id']]);
        $lakebium = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lakebium && ($lakebium['end_date'] === null || $lakebium['end_date'] > date('Y-m-d H:i:s'))) {
            $isPremium = true;
        }

        // Check for Lakebium badge
        $stmt = $db->prepare("SELECT user_id, badge_id FROM user_badges WHERE user_id = ? AND badge_id = 5");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetch()) {
            $hasLakebiumBadge = true;
        }
    } catch (PDOException $e) {
        error_log("User profile or lakebium query error: " . $e->getMessage());
    }
}

// Create Stripe Checkout Session
function createCheckoutSession($plan, $user_id, $db) {
    try {
        global $prices;
        
        if (!in_array($plan, ['monthly', 'yearly', 'lifetime'])) {
            throw new Exception("Invalid plan selected");
        }

        // Ensure user_id is valid
        if (!$user_id) {
            throw new Exception("Invalid user_id");
        }

        // Check for existing active subscription (not pending)
        $stmt = $db->prepare("SELECT id FROM lakebium WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$user_id]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Zaten aktif bir abonelik var'];
        }

        $db->beginTransaction();
        
        // If there's a pending subscription, update it instead of creating a new one
        $stmt = $db->prepare("SELECT id, plan_type FROM lakebium WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$user_id]);
        $existingPending = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingPending) {
            // Update existing pending subscription
            $end_date = $plan === 'monthly' ? date('Y-m-d H:i:s', strtotime('+1 month')) :
                       ($plan === 'yearly' ? date('Y-m-d H:i:s', strtotime('+1 year')) : null);
            
            $stmt = $db->prepare("
                UPDATE lakebium 
                SET plan_type = ?, start_date = NOW(), end_date = ?, status = 'pending'
                WHERE id = ?
            ");
            $stmt->execute([$plan, $end_date, $existingPending['id']]);
            $subscription_id = $existingPending['id'];
        } else {
            // Create new subscription record
            $end_date = $plan === 'monthly' ? date('Y-m-d H:i:s', strtotime('+1 month')) :
                       ($plan === 'yearly' ? date('Y-m-d H:i:s', strtotime('+1 year')) : null);
            
            $stmt = $db->prepare("
                INSERT INTO lakebium (user_id, plan_type, start_date, end_date, status)
                VALUES (?, ?, NOW(), ?, 'pending')
            ");
            $stmt->execute([$user_id, $plan, $end_date]);
            $subscription_id = $db->lastInsertId();
        }

        // Create Stripe Checkout Session
        $mode = $plan === 'lifetime' ? 'payment' : 'subscription';
        $price_id = [
            'monthly' => 'price_1S2HaUAMGaQlZwTtwjR8IOM4',
            'yearly' => 'price_1S2Hb5AMGaQlZwTtDt9Tn5e0',
            'lifetime' => 'price_lifetime_id'
        ][$plan];

        $session_params = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $price_id,
                'quantity' => 1,
            ]],
            'mode' => $mode,
            'success_url' => 'https://lakeban.com/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://lakeban.com/payment-cancel.php',
            'client_reference_id' => "SUB_{$user_id}_{$subscription_id}",
            'metadata' => [
                'user_id' => (string)$user_id,
                'subscription_id' => (string)$subscription_id
            ]
        ];

        if ($mode === 'subscription') {
            $session_params['subscription_data'] = [
                'metadata' => [
                    'user_id' => (string)$user_id,
                    'subscription_id' => (string)$subscription_id
                ]
            ];
        }

        $session = \Stripe\Checkout\Session::create($session_params);
        
        $db->commit();
        error_log("Stripe session created: session_id={$session->id}, plan=$plan, user_id=$user_id, subscription_id=$subscription_id");
        return ['success' => true, 'session_id' => $session->id];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Checkout session creation error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Handle checkout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan']) && isset($_SESSION['user_id'])) {
    $plan = $_POST['plan'];
    $result = createCheckoutSession($plan, $_SESSION['user_id'], $db);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LakeBan - Lakebium</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <script src="https://js.stripe.com/v3/"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'dark-bg': '#0f1114',
                        'card-bg': '#1e2025',
                        'card-border': '#2c2e33',
                        'text-light': '#e0e0e0',
                        'text-secondary': 'rgba(255, 255, 255, 0.7)',
                        'primary': '#4CAF50',
                        'primary-dark': '#388E3C',
                        'accent': '#00c6ff',
                    },
                    fontFamily: {
                        'sans': ['Inter', 'ui-sans-serif', 'system-ui'],
                    },
                }
            }
        }
    </script>
</head>
<body class="bg-dark-bg text-text-light">
    <header class="fixed top-0 w-full bg-dark-bg/95 backdrop-blur-md z-50 border-b border-primary/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo" class="h-10">
                <span class="text-xl font-bold bg-gradient-to-r from-primary to-primary-dark bg-clip-text text-transparent">LakeBan</span>
            </div>
            <nav class="hidden md:flex items-center gap-6">
                <button onclick="window.location.href='/'" class="text-text-secondary hover:text-white transition-colors"><?php echo $translations['header']['nav']['home'] ?? 'Anasayfa'; ?></button>
                <button onclick="window.location.href='/topluluklar'" class="text-text-secondary hover:text-white transition-colors"><?php echo $translations['header']['nav']['communities'] ?? 'Topluluklar'; ?></button>
            </nav>
            <div class="flex items-center gap-4">
                <select onchange="changeLanguage(this.value)" class="bg-card-bg text-white border border-card-border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="tr" <?php if ($lang == 'tr') echo 'selected'; ?>>Türkçe</option>
                    <option value="en" <?php if ($lang == 'en') echo 'selected'; ?>>English</option>
                </select>
                <?php if ($isLoggedIn): ?>
                    <div class="flex items-center gap-2">
                        <button onclick="window.location.href='/profile-page?username=<?php echo htmlspecialchars($username); ?>'" class="flex items-center gap-2 hover:bg-card-bg rounded-lg p-2 transition-colors">
                            <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center overflow-hidden">
                                <?php if ($profilePicture): ?>
                                    <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Avatar" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span class="text-white font-medium"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="text-text-light"><?php echo htmlspecialchars($username); ?></span>
                            <?php if ($hasLakebiumBadge): ?>
                                <img src="/badges/lakebium.png" alt="Lakebium Badge" class="w-6 h-6" title="Lakebium">
                            <?php endif; ?>
                        </button>
                    </div>
                <?php else: ?>
                    <div class="flex gap-2">
                        <button onclick="window.location.href='/login'" class="px-4 py-2 border border-text-secondary/20 text-white rounded-lg hover:bg-card-bg transition-colors"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></button>
                        <button onclick="window.location.href='/register'" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors"><?php echo $translations['header']['register'] ?? 'Kayıt Ol'; ?></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="pt-20">
        <section class="min-h-screen flex items-center py-16 px-4 sm:px-6 lg:px-8 bg-gradient-to-b from-dark-bg to-[#0a0c0f] relative overflow-hidden">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_100%,rgba(76,175,80,0.15)_0%,transparent_60%)]"></div>
            <div class="max-w-7xl mx-auto text-center relative z-10">
                <button onclick="window.location.href='/cancel_subscription'" class="text-primary hover:underline mb-6"><?php echo $translations['premium']['cancel_subscription'] ?? 'Şuanki aboneliğinizi iptal mi etmeye çalışıyorsunuz?'; ?></button>
                <h1 class="text-4xl sm:text-5xl font-extrabold bg-gradient-to-r from-primary to-primary-dark bg-clip-text text-transparent mb-6"><?php echo $translations['premium']['title'] ?? 'Lakebium ile Deneyimini Yükselt'; ?></h1>
                <p class="text-lg sm:text-xl text-text-secondary max-w-3xl mx-auto mb-10"><?php echo $translations['premium']['description'] ?? 'Premium avantajlarla LakeBan deneyimini bir üst seviyeye taşı!'; ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 max-w-5xl mx-auto">
                    <?php
                    $badges = [
                        ['icon' => 'fa-gem', 'title' => 'Profil Çerçevesi', 'desc' => 'Sizi öne çıkaran özel tasarım profil çerçeveleri'],
                        ['icon' => 'fa-paint-brush', 'title' => 'Özel Tema', 'desc' => 'Tamamen kişiselleştirilebilir arayüz'],
                        ['icon' => 'fa-certificate', 'title' => 'Lakebium Rozeti', 'desc' => 'Prestijli üyelik rozeti'],
                        ['icon' => 'fa-cloud-upload-alt', 'title' => '500MB Upload', 'desc' => 'Daha büyük dosya yükleme kapasitesi'],
                        ['icon' => 'fa-video', 'title' => 'HD Yayın', 'desc' => 'Full HD canlı yayın deneyimi'],
                        ['icon' => 'fa-volume-up', 'title' => 'Kaliteli Ses', 'desc' => 'Kristal netliğinde ses kalitesi'],
                    ];
                    foreach ($badges as $badge): ?>
                        <div class="bg-card-bg border border-card-border rounded-2xl p-6 hover:border-primary hover:-translate-y-1 transition-all duration-300">
                            <i class="fas <?php echo $badge['icon']; ?> text-4xl text-primary mb-4"></i>
                            <h3 class="text-lg font-semibold mb-2"><?php echo $translations['premium']['badges'][$badge['title']] ?? $badge['title']; ?></h3>
                            <p class="text-text-secondary"><?php echo $translations['premium']['badges'][$badge['desc']] ?? $badge['desc']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="py-16 px-4 sm:px-6 lg:px-8 bg-dark-bg">
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl sm:text-4xl font-bold text-primary"><?php echo $translations['premium']['pricing_title'] ?? 'Sana En Uygun Planı Seç'; ?></h2>
                    <p class="text-lg text-text-secondary max-w-xl mx-auto mt-4"><?php echo $translations['premium']['pricing_subtitle'] ?? 'Bütçenize uygun planlarla Premium ayrıcalıkların tadını çıkarın.'; ?></p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-5xl mx-auto">
                    <div class="bg-card-bg border border-card-border rounded-2xl p-8 flex flex-col hover:border-primary hover:-translate-y-2 transition-all duration-300">
                        <h3 class="text-2xl font-semibold mb-4"><?php echo $translations['premium']['monthly_plan'] ?? 'Aylık'; ?></h3>
                        <div class="text-4xl font-extrabold text-primary mb-2">₺59.99<span class="text-base font-normal text-text-secondary">/ay</span></div>
                        <ul class="flex-grow mb-6 space-y-3">
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Özel Profil Çerçevesi</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Özel Tema Seçenekleri</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Lakebium Rozeti</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> 500MB Dosya Yükleme</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> HD Yayın Kalitesi</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Gelişmiş Ses Kalitesi</li>
                        </ul>
                        <?php if ($isLoggedIn && !$isPremium): ?>
                            <button onclick="initiateCheckout('monthly')" class="bg-gradient-to-r from-primary to-primary-dark text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all duration-300">Planı Seç</button>
                        <?php elseif ($isPremium): ?>
                            <span class="bg-card-border text-text-secondary py-3 rounded-lg font-semibold text-center cursor-not-allowed">Zaten Premium</span>
                        <?php else: ?>
                            <button onclick="window.location.href='/login'" class="bg-gradient-to-r from-primary to-primary-dark text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all duration-300">Giriş Yap</button>
                        <?php endif; ?>
                    </div>
                    <div class="bg-card-bg border-2 border-primary rounded-2xl p-8 flex flex-col hover:-translate-y-2 transition-all duration-300 relative">
                        <span class="absolute top-4 right-4 bg-primary text-white text-xs font-bold uppercase px-3 py-1 rounded-full">Popüler</span>
                        <h3 class="text-2xl font-semibold mb-4"><?php echo $translations['premium']['yearly_plan'] ?? 'Yıllık'; ?></h3>
                        <div class="text-4xl font-extrabold text-primary mb-2">₺599.99<span class="text-base font-normal text-text-secondary">/yıl</span></div>
                        <div class="text-primary font-semibold mb-4">%16,6 tasarruf fırsatı!</div>
                        <ul class="flex-grow mb-6 space-y-3">
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Tüm Aylık Plan Avantajları</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Özel Yıllık Rozeti</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Öncelikli Destek</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Özel Etkinliklere Erişim</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Özel İndirimler</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check text-primary"></i> Yeni özelliklere erken erişim imkânı</li>
                        </ul>
                        <?php if ($isLoggedIn && !$isPremium): ?>
                            <button onclick="initiateCheckout('yearly')" class="bg-gradient-to-r from-primary to-primary-dark text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all duration-300">Planı Seç</button>
                        <?php elseif ($isPremium): ?>
                            <span class="bg-card-border text-text-secondary py-3 rounded-lg font-semibold text-center cursor-not-allowed">Zaten Premium</span>
                        <?php else: ?>
                            <button onclick="window.location.href='/login'" class="bg-gradient-to-r from-primary to-primary-dark text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all duration-300">Giriş Yap</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="py-16 px-4 sm:px-6 lg:px-8 bg-[#0a0c0f]">
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl sm:text-4xl font-bold text-primary">Neler Sunuyoruz?</h2>
                    <p class="text-lg text-text-secondary max-w-xl mx-auto mt-4">Lakebium ile elde edeceğiniz harika özellikleri keşfedin</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 max-w-5xl mx-auto">
                    <?php foreach ($badges as $badge): ?>
                        <div class="bg-card-bg border border-card-border rounded-2xl p-6 text-center hover:border-primary hover:-translate-y-1 transition-all duration-300">
                            <i class="fas <?php echo $badge['icon']; ?> text-4xl text-primary mb-4"></i>
                            <h3 class="text-lg font-semibold mb-2"><?php echo $translations['premium']['badges'][$badge['title']] ?? $badge['title']; ?></h3>
                            <p class="text-text-secondary"><?php echo $translations['premium']['badges'][$badge['desc']] ?? $badge['desc']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="py-16 px-4 sm:px-6 lg:px-8 bg-dark-bg">
            <div class="max-w-3xl mx-auto">
                <div class="text-center mb-12">
                    <h2 class="text-3xl sm:text-4xl font-bold text-primary">Sıkça Sorulan Sorular</h2>
                    <p class="text-lg text-text-secondary max-w-xl mx-auto mt-4">Lakebium hakkında merak ettikleriniz</p>
                </div>
                <div class="space-y-4">
                    <?php
                    $faqs = [
                        ['question' => 'Lakebium nedir?', 'answer' => 'Lakebium, LakeBan platformunun premium üyelik sistemidir. Özel özellikler, gelişmiş yetenekler ve kişiselleştirme seçenekleri sunar.'],
                        ['question' => 'Ödeme nasıl yapılır?', 'answer' => 'Ödemeler Stripe üzerinden güvenli bir şekilde kredi kartı veya banka kartı ile yapılabilir.'],
                        ['question' => 'Aboneliği iptal edebilir miyim?', 'answer' => 'Evet, aboneliğinizi istediğiniz zaman iptal edebilirsiniz. İptal ettiğinizde, aboneliğinizin bitim tarihine kadar premium özelliklere erişmeye devam edersiniz.'],
                    ];
                    foreach ($faqs as $index => $faq): ?>
                        <div class="faq-item bg-card-bg border border-card-border rounded-lg overflow-hidden hover:border-primary transition-all duration-300">
                            <button class="faq-question w-full text-left p-4 flex justify-between items-center font-semibold" onclick="toggleFaq(<?php echo $index; ?>)">
                                <?php echo $translations['faq'][$faq['question']] ?? $faq['question']; ?>
                                <i class="fas fa-chevron-down faq-icon"></i>
                            </button>
                            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300">
                                <p class="p-4 text-text-secondary"><?php echo $translations['faq'][$faq['answer']] ?? $faq['answer']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <footer class="py-16 px-4 sm:px-6 lg:px-8 bg-[#0a0c0f] border-t border-primary/10">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row gap-10">
            <div class="flex-1 min-w-[250px]">
                <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo" class="h-12 mb-4">
                <span class="text-xl font-bold text-primary">LakeBan</span>
                <p class="text-text-secondary mt-2"><?php echo $translations['footer']['slogan'] ?? 'Yeni nesil sosyal platformunuz.'; ?></p>
            </div>
            <div class="flex-2 grid grid-cols-1 sm:grid-cols-3 gap-10">
                <div>
                    <h3 class="text-lg font-semibold text-primary mb-4"><?php echo $translations['footer']['lakeban'] ?? 'LakeBan'; ?></h3>
                    <ul class="space-y-2">
                        <li><button onclick="window.location.href='/hakkimizda'" class="text-text-secondary hover:text-primary transition-colors"><?php echo $translations['footer']['about'] ?? 'Hakkımızda'; ?></button></li>
                        <li><button onclick="window.location.href='/destek'" class="text-text-secondary hover:text-primary transition-colors"><?php echo $translations['footer']['support'] ?? 'Destek'; ?></button></li>
                        <li><button onclick="window.location.href='/kariyer'" class="text-text-secondary hover:text-primary transition-colors"><?php echo $translations['footer']['careers'] ?? 'Kariyer'; ?></button></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-primary mb-4">Kaynaklar</h3>
                    <ul class="space-y-2">
                        <li><button onclick="window.location.href='/blog'" class="text-text-secondary hover:text-primary transition-colors">Blog</button></li>
                        <li><button onclick="window.location.href='/rehber'" class="text-text-secondary hover:text-primary transition-colors">Kılavuz</button></li>
                        <li><button onclick="window.location.href='/sss'" class="text-text-secondary hover:text-primary transition-colors">SSS</button></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-primary mb-4">Gizlilik</h3>
                    <ul class="space-y-2">
                        <li><button onclick="window.location.href='/gizlilik'" class="text-text-secondary hover:text-primary transition-colors">Gizlilik Politikası</button></li>
                        <li><button onclick="window.location.href='/kullanim'" class="text-text-secondary hover:text-primary transition-colors">Kullanım Şartları</button></li>
                        <li><button onclick="window.location.href='/cerez'" class="text-text-secondary hover:text-primary transition-colors">Çerez Politikası</button></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="mt-10 pt-6 border-t border-card-border text-center text-text-secondary">
            <?php echo sprintf($translations['footer']['copyright'] ?? '&copy; %d LakeBan. Tüm hakları saklıdır.', date('Y')); ?>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        function changeLanguage(lang) {
            window.location.href = window.location.pathname + '?lang=' + lang;
        }

        function toggleFaq(index) {
            const items = document.querySelectorAll('.faq-item');
            items.forEach((item, i) => {
                const answer = item.querySelector('.faq-answer');
                const icon = item.querySelector('.faq-icon');
                if (i === index && !answer.classList.contains('max-h-96')) {
                    answer.classList.add('max-h-96');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                } else {
                    answer.classList.remove('max-h-96');
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                }
            });
        }

        function initiateCheckout(plan) {
            fetch('/lakebium.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'plan=' + encodeURIComponent(plan)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const stripe = Stripe('<?php echo htmlspecialchars(STRIPE_PUBLISHABLE_KEY); ?>');
                    stripe.redirectToCheckout({ sessionId: data.session_id })
                        .then(result => {
                            if (result.error) {
                                alert('Ödeme yönlendirme hatası: ' + result.error.message);
                                window.location.href = '/lakebium.php';
                            }
                        });
                } else {
                    alert('Ödeme başlatma başarısız: ' + data.error);
                    window.location.href = '/lakebium.php';
                }
            })
            .catch(error => {
                alert('Hata: ' + error.message);
                window.location.href = '/lakebium.php';
            });
        }
    </script>
</body>

</html>
