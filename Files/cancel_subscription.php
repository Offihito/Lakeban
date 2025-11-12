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
\Stripe\Stripe::setApiKey('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');

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
$subscription = null;

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

        // Check premium status and get subscription details
        $stmt = $db->prepare("SELECT plan_type, end_date, status, stripe_subscription_id, cancel_at_period_end FROM lakebium WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$_SESSION['user_id']]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($subscription && ($subscription['end_date'] === null || $subscription['end_date'] > date('Y-m-d H:i:s'))) {
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

// Handle cancellation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn && $isPremium && isset($subscription['stripe_subscription_id'])) {
    try {
        // Retrieve subscription from Stripe
        $stripe_subscription = \Stripe\Subscription::retrieve($subscription['stripe_subscription_id']);
        
        // Check if subscription is already canceled
        if ($stripe_subscription->status === 'canceled') {
            // Update database to reflect canceled status
            $db->beginTransaction();
            $stmt = $db->prepare("
                UPDATE lakebium 
                SET status = 'cancelled',
                    cancel_at_period_end = 0,
                    updated_at = NOW()
                WHERE stripe_subscription_id = ? AND user_id = ?
            ");
            $stmt->execute([$subscription['stripe_subscription_id'], $_SESSION['user_id']]);
            $db->commit();
            
            error_log("Subscription already canceled in Stripe, updated database: user_id={$_SESSION['user_id']}, subscription_id={$subscription['stripe_subscription_id']}");
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $translations['cancel']['already_canceled'] ?? 'Bu abonelik zaten iptal edilmiş.']);
            exit;
        }

        // Check if subscription is already set to cancel at period end
        if ($stripe_subscription->cancel_at_period_end) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $translations['cancel']['already_cancelled'] ?? 'Bu abonelik zaten fatura dönemi sonunda iptal edilecek.']);
            exit;
        }

        // Set subscription to cancel at period end
        $stripe_subscription->cancel_at_period_end = true;
        $stripe_subscription->save();
        
        // Update database
        $db->beginTransaction();
        $stmt = $db->prepare("
            UPDATE lakebium 
            SET cancel_at_period_end = 1,
                updated_at = NOW()
            WHERE stripe_subscription_id = ? AND user_id = ?
        ");
        $stmt->execute([$subscription['stripe_subscription_id'], $_SESSION['user_id']]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            error_log("Database update failed: No rows affected for stripe_subscription_id={$subscription['stripe_subscription_id']}, user_id={$_SESSION['user_id']}");
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $translations['cancel']['error'] ?? 'Abonelik iptal edilirken bir hata oluştu: Veritabanı güncellenemedi.']);
            exit;
        }
        $db->commit();
        
        error_log("Subscription set to cancel at period end for user_id={$_SESSION['user_id']}, subscription_id={$subscription['stripe_subscription_id']}");
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $translations['cancel']['success_period_end'] ?? 'Aboneliğiniz mevcut fatura dönemi sonunda iptal edilecek. O zamana kadar premium özellikler kullanılabilir.']);
        exit;
    } catch (\Stripe\Exception\InvalidRequestError $e) {
        error_log("Stripe invalid request error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $translations['cancel']['error_invalid_request'] ?? 'Geçersiz abonelik ID\'si veya Stripe isteği hatası: ' . $e->getMessage()]);
        exit;
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log("Stripe API error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $translations['cancel']['error'] ?? 'Abonelik iptal edilirken bir hata oluştu: ' . $e->getMessage()]);
        exit;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error during cancellation: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $translations['cancel']['error'] ?? 'Abonelik iptal edilirken bir hata oluştu: Veritabanı hatası.']);
        exit;
    } catch (Exception $e) {
        error_log("Unexpected error during cancellation: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $translations['cancel']['error'] ?? 'Bilinmeyen bir hata oluştu: ' . $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LakeBan - <?php echo $translations['cancel']['title'] ?? 'Aboneliği İptal Et'; ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Gochi+Hand&display=swap">
    <style>
        :root {
            --dark-bg: #0f1114;
            --card-bg: #1e2025;
            --card-border: #2c2e33;
            --text-light: #e0e0e0;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --accent: #00c6ff;
            --transition: all 0.3s ease;
            --shadow-sm: 0 4px 6px rgba(0,0,0,0.1);
            --radius-md: 12px;
            --gradient-start: #4CAF50;
            --gradient-end: #2E7D32;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--dark-bg); color: var(--text-light); }

        /* Header Styles */
        .header { 
            display: flex; 
            justify-content: space-between; 
            padding: 1rem 5%; 
            background: rgba(10, 12, 15, 0.95); 
            position: fixed; 
            top: 0; 
            width: 100%; 
            z-index: 1000; 
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(76, 175, 80, 0.1);
        }

        .logo-container { display: flex; align-items: center; gap: 0.8rem; }
        .logo-container img { height: 45px; }
        .logo-container .brand { 
            font-weight: 700; 
            font-size: 1.4rem; 
            background: linear-gradient(to right, var(--gradient-start), var(--gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .header nav { display: flex; gap: 1.5rem; align-items: center; }
        .header nav a { color: var(--text-secondary); text-decoration: none; font-weight: 500; transition: var(--transition); }
        .header nav a:hover { color: white; }
        .utils { display: flex; align-items: center; gap: 1.5rem; }
        .language-selector select { 
            background: var(--card-bg); 
            color: white; 
            border: 1px solid var(--card-border); 
            padding: 0.5rem; 
            border-radius: 8px; 
        }
        .auth-buttons { display: flex; gap: 1rem; }
        .login-btn, .register-btn { 
            padding: 0.6rem 1.5rem; 
            border-radius: 8px; 
            text-decoration: none; 
            transition: var(--transition); 
        }
        .login-btn { color: white; border: 1px solid rgba(255,255,255,0.2); }
        .login-btn:hover { background: rgba(255,255,255,0.1); }
        .register-btn { background: var(--primary); color: white; border: none; }
        .register-btn:hover { background: var(--primary-dark); }
        .user-profile { display: flex; align-items: center; gap: 0.75rem; }
        .avatar { 
            width: 32px; 
            height: 32px; 
            border-radius: 50%; 
            background: var(--primary); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden; 
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .badge { width: 24px; height: 24px; object-fit: contain; }

        /* Main Content */
        .cancel-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 6rem 5% 4rem;
            background: linear-gradient(to bottom, var(--dark-bg) 0%, #0a0c0f 100%);
            position: relative;
            overflow: hidden;
        }

        .cancel-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 100%, rgba(76, 175,Hooke 80, 0.15) 0%, transparent 60%);
        }

        .cancel-container {
            max-width: 600px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 2;
            background: var(--card-bg);
            border-radius: 20px;
            padding: 3rem;
            border: 1px solid var(--card-border);
            box-shadow: var(--shadow-sm);
        }

        .cancel-container h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(to right, var(--gradient-start), var(--gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1.5rem;
        }

        .cancel-container p {
            font-size: 1.2rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .cancel-button {
            display: inline-block;
            padding: 1.1rem 2rem;
            border-radius: 12px;
            background: linear-gradient(to right, #ff4444, #cc0000);
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            margin: 1rem 0;
        }

        .cancel-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 68, 68, 0.4);
        }

        .cancel-button.disabled {
            background: var(--card-border);
            cursor: not-allowed;
        }

        .cancel-button.disabled:hover {
            transform: none;
            box-shadow: none;
        }

        .back-button {
            display: inline-block;
            padding: 1rem 2rem;
            border-radius: 12px;
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--card-border);
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .back-button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Footer */
        .footer {
            padding: 5rem 5% 2rem;
            background: #0a0c0f;
            position: relative;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(76, 175, 80, 0.3), transparent);
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
        }

        .footer-logo-section {
            flex: 1;
            min-width: 250px;
        }

        .footer-logo-section img {
            height: 60px;
            margin-bottom: 1rem;
        }

        .footer-logo-section .brand {
            font-weight: 700;
            font-size: 1.8rem;
            color: var(--primary);
            display: block;
            margin-bottom: 1rem;
        }

        .footer-logo-section p {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .footer-links {
            flex: 2;
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
        }

        .footer-column {
            min-width: 150px;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 12px;
        }

        .footer-column ul li a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-column ul li a:hover {
            color: var(--primary);
        }

        .footer-bottom {
            text-align: center;
            margin-top: 60px;
            color: var(--text-secondary);
            padding-top: 20px;
            border-top: 1px solid var(--card-border);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .header nav {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .cancel-container {
                padding: 2rem;
            }

            .cancel-container h1 {
                font-size: 2rem;
            }

            .cancel-container p {
                font-size: 1rem;
            }

            .footer-content {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-container">
            <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
            <span class="brand">LakeBan</span>
        </div>
        <nav>
            <a href="/"><?php echo $translations['header']['nav']['home'] ?? 'Anasayfa'; ?></a>
            <a href="/topluluklar"><?php echo $translations['header']['nav']['communities'] ?? 'Topluluklar'; ?></a>
            <a href="/support"><?php echo $translations['header']['nav']['support'] ?? 'Destek'; ?></a>
        </nav>
        <div class="utils">
            <div class="language-selector">
                <select onchange="changeLanguage(this.value)">
                    <option value="tr" <?php if ($lang == 'tr') echo 'selected'; ?>>Türkçe</option>
                    <option value="en" <?php if ($lang == 'en') echo 'selected'; ?>>English</option>
                </select>
            </div>
            <?php if ($isLoggedIn): ?>
                <div class="user-profile">
                    <a href="/profile-page?username=<?php echo htmlspecialchars($username); ?>">
                        <div class="avatar">
                            <?php if ($profilePicture): ?>
                                <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Avatar">
                            <?php else: ?>
                                <?php echo strtoupper(substr($username, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                        <?php if ($hasLakebiumBadge): ?>
                            <img src="/badges/lakebium.png" alt="Lakebium Badge" class="badge" title="Lakebium">
                        <?php endif; ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="auth-buttons">
                    <a href="/login" class="login-btn"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></a>
                    <a href="/register" class="register-btn"><?php echo $translations['header']['register'] ?? 'Kayıt Ol'; ?></a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <section class="cancel-section">
            <div class="cancel-container">
                <h1><?php echo $translations['cancel']['title'] ?? 'Aboneliği İptal Et'; ?></h1>
                <?php if (!$isLoggedIn): ?>
                    <p><?php echo $translations['cancel']['not_logged_in'] ?? 'Aboneliği iptal etmek için lütfen giriş yapın.'; ?></p>
                    <a href="/login" class="cancel-button"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></a>
                <?php elseif (!$isPremium || !$subscription): ?>
                    <p><?php echo $translations['cancel']['no_subscription'] ?? 'Aktif bir aboneliğiniz bulunmamaktadır.'; ?></p>
                    <a href="/lakebium.php" class="cancel-button"><?php echo $translations['cancel']['view_plans'] ?? 'Planları Görüntüle'; ?></a>
                <?php else: ?>
                    <p><?php echo sprintf($translations['cancel']['confirm_message'] ?? 'Mevcut %s aboneliğinizi iptal etmek istediğinizden emin misiniz? İptal işlemi sonrası premium özelliklere erişiminiz, mevcut fatura döneminizin sonuna kadar devam edecektir.', $subscription['plan_type']); ?></p>
                    <button onclick="cancelSubscription()" class="cancel-button"><?php echo $translations['cancel']['confirm_button'] ?? 'Aboneliği İptal Et'; ?></button>
                    <a href="/lakebium.php" class="back-button"><?php echo $translations['cancel']['back'] ?? 'Geri Dön'; ?></a>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="footer-content">
            <div class="footer-logo-section">
                <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
                <span class="brand">LakeBan</span>
                <p><?php echo $translations['footer']['slogan'] ?? 'Yeni nesil sosyal platformunuz.'; ?></p>
            </div>
            <div class="footer-links">
                <div class="footer-column">
                    <h3><?php echo $translations['footer']['lakeban'] ?? 'LakeBan'; ?></h3>
                    <ul>
                        <li><a href="/hakkimizda"><?php echo $translations['footer']['about'] ?? 'Hakkımızda'; ?></a></li>
                        <li><a href="/destek"><?php echo $translations['footer']['support'] ?? 'Destek'; ?></a></li>
                        <li><a href="/kariyer"><?php echo $translations['footer']['careers'] ?? 'Kariyer'; ?></a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Kaynaklar</h3>
                    <ul>
                        <li><a href="/blog">Blog</a></li>
                        <li><a href="/rehber">Kılavuz</a></li>
                        <li><a href="/sss">SSS</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Gizlilik</h3>
                    <ul>
                        <li><a href="/gizlilik">Gizlilik Politikası</a></li>
                        <li><a href="/kullanim">Kullanım Şartları</a></li>
                        <li><a href="/cerez">Çerez Politikası</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <?php echo sprintf($translations['footer']['copyright'] ?? '&copy; %d LakeBan. Tüm hakları saklıdır.', date('Y')); ?>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        function changeLanguage(lang) {
            window.location.href = window.location.pathname + '?lang=' + lang;
        }

        function cancelSubscription() {
            if (confirm('<?php echo $translations['cancel']['confirm_prompt'] ?? 'Bu işlemi onaylıyor musunuz?'; ?>')) {
                fetch('/cancel_subscription.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: ''
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.href = '/lakebium.php';
                    } else {
                        alert(data.error);
                    }
                })
                .catch(error => {
                    alert('<?php echo $translations['cancel']['error'] ?? 'Bir hata oluştu. Lütfen tekrar deneyin.'; ?>');
                });
            }
        }
    </script>
</body>
</html>