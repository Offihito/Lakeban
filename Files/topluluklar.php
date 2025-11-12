<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Varsayılan dil
$default_lang = 'tr';

// Tarayıcı dilini al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fi', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Dil seçeneğini kontrol et
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

// Dil dosyalarını yükle
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        $translations = json_decode(file_get_contents($langFile), true);
        $defaultTranslations = [
            'communities' => [
                'title' => 'Topluluklar',
                'subtitle' => 'Keşfetmek için harika topluluklar',
                'search_placeholder' => 'Topluluk veya etiket ara...',
                'sort' => [
                    'popular' => 'Popüler',
                    'date' => 'Tarih',
                    'name' => 'İsim',
                    'bump' => 'Bump',
                    'random' => 'Rastgele'
                ],
                'no_results' => 'Sonuç bulunamadı',
                'no_results_with_query' => "'{query}' ile eşleşen topluluk bulunamadı",
                'clear_search' => 'Aramayı temizle',
                'bump_button' => 'Bump',
                'bump_cooldown' => 'Bu topluluğu tekrar bump etmek için {hours} saat beklemelisiniz.',
                'bump_success' => 'Topluluk başarıyla bump edildi!',
                'bump_not_member' => 'Bu topluluğu bump etmek için üye olmalısınız.',
                'last_bump' => 'Son Bump',
                'verified' => 'Doğrulanmış',
                'back_button' => 'Geri Dön',
                'join_button' => 'Katıl',
                'empty_state_title' => 'Henüz bir topluluk yok',
                'empty_state_text' => 'İlk topluluğu sen oluşturmak ister misin?',
                'create_community' => 'Topluluk Oluştur'
            ],
            'header' => [
                'nav' => [
                    'posts' => 'Gönderiler',
                    'communities' => 'Topluluklar',
                    'messages' => 'Mesajlar',
                    'explore' => 'Lakealt\'lar'
                ],
                'search_placeholder' => 'Ara...',
                'profile_menu' => [
                    'my_profile' => 'Profilim',
                    'account_details' => 'Hesap Detayları',
                    'logout' => 'Çıkış Yap'
                ],
                'title' => 'Lakeban'
            ]
        ];
        return array_replace_recursive($defaultTranslations, $translations);
    }
    return [
        'communities' => [
            'title' => 'Topluluklar',
            'subtitle' => 'Keşfetmek için harika topluluklar',
            'search_placeholder' => 'Topluluk veya etiket ara...',
            'sort' => [
                'popular' => 'Popüler',
                'date' => 'Tarih',
                'name' => 'İsim',
                'bump' => 'Bump',
                'random' => 'Rastgele'
            ],
            'no_results' => 'Sonuç bulunamadı',
            'no_results_with_query' => "'{query}' ile eşleşen topluluk bulunamadı",
            'clear_search' => 'Aramayı temizle',
            'bump_button' => 'Bump',
            'bump_cooldown' => 'Bu topluluğu tekrar bump etmek için {hours} saat beklemelisiniz.',
            'bump_success' => 'Topluluk başarıyla bump edildi!',
            'bump_not_member' => 'Bu topluluğu bump etmek için üye olmalısınız.',
            'last_bump' => 'Son Bump',
            'verified' => 'Doğrulanmış',
            'back_button' => 'Geri Dön',
            'join_button' => 'Katıl',
            'empty_state_title' => 'Henüz bir topluluk yok',
            'empty_state_text' => 'İlk topluluğu sen oluşturmak ister misin?',
            'create_community' => 'Topluluk Oluştur'
        ],
        'header' => [
            'nav' => [
                'posts' => 'Gönderiler',
                'communities' => 'Topluluklar',
                'messages' => 'Mesajlar',
                'explore' => 'Lakealt\'lar'
            ],
            'search_placeholder' => 'Ara...',
            'profile_menu' => [
                'my_profile' => 'Profilim',
                'account_details' => 'Hesap Detayları',
                'logout' => 'Çıkış Yap'
            ],
            'title' => 'Lakeban'
        ]
    ];
}

$translations = loadLanguage($lang);

// Veritabanı bağlantısı
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : null;

// Tema ayarları
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

if ($isLoggedIn) {
    try {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $userStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($userData) {
            $currentTheme = $userData['theme'] ?? $defaultTheme;
            $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
            $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
        }
    } catch (PDOException $e) {
        error_log("Tema ayarları alınırken hata: " . $e->getMessage());
    }
}

// Veritabanı değişkeni
$db = null;

// Sıralama parametreleri
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['popular', 'date', 'name', 'bump', 'random']) ? $_GET['sort'] : 'random';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? strtoupper($_GET['order']) : 'DESC';

// Sıralama SQL ifadesi
$orderBy = '';
switch ($sort) {
    case 'date':
        $orderBy = "s.created_at $order";
        break;
    case 'name':
        $orderBy = "s.name $order";
        break;
    case 'bump':
        $orderBy = "s.last_bump $order, s.bump_count $order";
        break;
    case 'random':
        $orderBy = "RAND()";
        break;
    default: // popular
        $orderBy = "member_count $order, s.created_at DESC";
        break;
}

// Zaman farkı fonksiyonu
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) {
        return $diff->y . " yıl önce";
    } elseif ($diff->m > 0) {
        return $diff->m . " ay önce";
    } elseif ($diff->d > 0) {
        return $diff->d . " gün önce";
    } elseif ($diff->h > 0) {
        return $diff->h . " saat önce";
    } elseif ($diff->i > 0) {
        return $diff->i . " dakika önce";
    } else {
        return "az önce";
    }
}

// Sunucuları getir
try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
    $tagQuery = isset($_GET['tag']) ? $_GET['tag'] : '';

    $sql = "
        SELECT s.*, COUNT(sm.user_id) AS member_count
        FROM servers s
        LEFT JOIN server_members sm ON s.id = sm.server_id
        WHERE s.show_in_community = 1";
    
    if (!empty($searchQuery)) {
        $sql .= " AND (s.name LIKE :searchQuery OR s.tags LIKE :searchQuery)";
    }
    
    if (!empty($tagQuery)) {
        $sql .= " AND FIND_IN_SET(:tagQuery, s.tags)";
    }

    $sql .= " GROUP BY s.id ORDER BY $orderBy";

    $stmt = $db->prepare($sql);

    if (!empty($searchQuery)) {
        $stmt->bindValue(':searchQuery', '%' . $searchQuery . '%');
    }

    if (!empty($tagQuery)) {
        $stmt->bindValue(':tagQuery', $tagQuery);
    }
    
    $stmt->execute();
    $servers = $stmt->fetchAll();

    // Davet kodlarını getir
    $server_ids = array_column($servers, 'id');
    if (!empty($server_ids)) {
        $placeholders = implode(',', array_fill(0, count($server_ids), '?'));
        $stmt = $db->prepare("SELECT server_id, invite_code FROM server_invites WHERE server_id IN ($placeholders)");
        $stmt->execute($server_ids);
        $invites = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN);
    } else {
        $invites = [];
    }

    // Kullanıcının üyeliklerini kontrol et
    $user_memberships = [];
    if ($isLoggedIn) {
        $stmt = $db->prepare("SELECT server_id FROM server_members WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_memberships = array_column($stmt->fetchAll(), 'server_id');
    }
} catch (PDOException $e) {
    error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
    $servers = [];
    $errorMessage = !empty($searchQuery) 
        ? str_replace('{query}', htmlspecialchars($searchQuery), $translations['communities']['no_results_with_query'])
        : $translations['communities']['no_results'];
}

// Bump isteğini işle
$bumpMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bump_server']) && $isLoggedIn) {
    $server_id = filter_input(INPUT_POST, 'server_id', FILTER_VALIDATE_INT);
    
    if (!$server_id) {
        $bumpMessage = 'Geçersiz sunucu ID.';
    } elseif (!in_array($server_id, $user_memberships)) {
        $bumpMessage = $translations['communities']['bump_not_member'];
    } else {
        // Son bump zamanını kontrol et
        $stmt = $db->prepare("SELECT last_bump FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        $last_bump = $stmt->fetchColumn();

        $cooldown_hours = 2; // 2 saatlik bekleme süresi
        if ($last_bump && (time() - strtotime($last_bump)) < ($cooldown_hours * 3600)) {
            $bumpMessage = str_replace('{hours}', $cooldown_hours, $translations['communities']['bump_cooldown']);
        } else {
            // Bump bilgilerini güncelle
            $stmt = $db->prepare("UPDATE servers SET last_bump = NOW(), bump_count = bump_count + 1 WHERE id = ?");
            $stmt->execute([$server_id]);
            $bumpMessage = $translations['communities']['bump_success'];
            
            // Başarılı bump sonrası sayfayı yenile ve animasyon için parametre ekle
            header('Location: /topluluklar?bumpSuccess=true&server_id=' . $server_id);
            exit;
        }
    }
}

// Katıl isteğini işle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_server'])) {
    $server_id = filter_input(INPUT_POST, 'server_id', FILTER_VALIDATE_INT);
    
    if ($server_id && isset($invites[$server_id][0])) {
        $invite_code = $invites[$server_id][0];
        header("Location: https://lakeban.com/join_server?code=$invite_code");
        exit;
    } else {
        $bumpMessage = "Davet kodu bu sunucu için bulunamadı.";
    }
}

// Header content starts here
$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";
$profilePicture = $defaultProfilePicture;

// Create MySQLi connection for header
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Veritabanı bağlantı hatası: " . $conn->connect_error);
}

if (isset($_SESSION['user_id']) && isset($conn)) {
    $stmt = $conn->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $profilePicture = !empty($row['avatar_url']) ? "../" . htmlspecialchars($row['avatar_url']) : $defaultProfilePicture;
    }
    $stmt->close();
}

$username = $_SESSION['username'] ?? 'Misafir';
?>

<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <title><?php echo $translations['communities']['title']; ?> - <?php echo $translations['header']['title']; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <style>
        :root {
            --primary-bg: #1A1B1E;
            --secondary-bg: #282A2E;
            --card-bg: #2D2F34;
            --accent-color: #4CAF50;
            --accent-hover: #45A049;
            --text-primary: #EBEBEB;
            --text-secondary: #B0B0B0;
            --border-color: #383A3F;
            --vote-active: #4CAF50;
            --vote-down: #ED4245;
            --dropdown-bg: #383A3F;
            --dropdown-item-hover: rgba(76, 175, 80, 0.2);
            --danger-color: #ED4245;
            --success-color: #4CAF50;
            --gradient-start: #4CAF50;
            --gradient-end: #388E3C;
            --custom-background-color: <?php echo htmlspecialchars($currentCustomColor); ?>;
            --custom-secondary-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
        }

        /* Dark Theme (Current Colors) */
        .dark-theme body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
        }
        .dark-theme .header,
        .dark-theme .sidebar-left {
            background-color: var(--secondary-bg);
            border-color: var(--border-color);
        }
        .dark-theme .header-main-nav a {
            color: var(--text-secondary);
        }
        .dark-theme .header-main-nav a:hover,
        .dark-theme .header-main-nav a.active {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--accent-color);
        }
        .dark-theme .search-form,
        .dark-theme .search-bar input,
        .dark-theme .profile-btn {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .dark-theme .search-input::placeholder,
        .dark-theme .search-bar input::placeholder {
            color: var(--text-secondary);
        }
        .dark-theme .header-btn,
        .dark-theme .profile-btn i,
        .dark-theme .search-icon,
        .dark-theme .clear-search {
            color: var(--text-secondary);
        }
        .dark-theme .header-btn:hover,
        .dark-theme .profile-btn:hover i,
        .dark-theme .search-icon:hover,
        .dark-theme .clear-search:hover {
            color: var(--accent-color);
        }
        .dark-theme .profile-btn:hover,
        .dark-theme .profile-btn.active {
            background-color: rgba(76, 175, 80, 0.1);
            border-color: var(--accent-color);
        }
        .dark-theme .profile-dropdown {
            background-color: var(--dropdown-bg);
        }
        .dark-theme .profile-dropdown ul li a {
            color: var(--text-primary);
        }
        .dark-theme .profile-dropdown ul li a:hover {
            background-color: var(--dropdown-item-hover);
        }
        .dark-theme .profile-dropdown .divider,
        .dark-theme hr {
            background-color: var(--border-color);
        }
        .dark-theme .card,
        .dark-theme .empty-state {
            background-color: var(--card-bg);
        }
        .dark-theme .banner-placeholder,
        .dark-theme .logo-container {
            background-color: var(--secondary-bg);
        }
        .dark-theme .logo-placeholder {
            color: var(--text-secondary);
        }
        .dark-theme .description,
        .dark-theme .stats,
        .dark-theme .header-content p,
        .dark-theme .empty-state p {
            color: var(--text-secondary);
        }
        .dark-theme .sort-btn {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-secondary);
        }
        .dark-theme .sort-btn:hover {
            background-color: var(--secondary-bg);
            color: var(--text-primary);
        }
        .dark-theme .sort-btn.active {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: var(--text-primary);
        }
        .dark-theme .back-button {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .dark-theme .back-button:hover {
            background-color: var(--secondary-bg);
            border-color: var(--accent-color);
        }
        .dark-theme .message-box.success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success-color);
        }
        .dark-theme .message-box.error {
            background-color: rgba(237, 66, 69, 0.2);
            color: var(--danger-color);
        }
        .dark-theme .tag {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--accent-color);
        }
        .dark-theme .tag:hover {
            background-color: rgba(76, 175, 80, 0.3);
        }
        .dark-theme .actions button[name="join_server"] {
            background-color: var(--accent-color);
        }
        .dark-theme .actions button[name="join_server"]:hover {
            background-color: var(--accent-hover);
        }
        .dark-theme .actions button[name="bump_server"] {
            background-color: var(--secondary-bg);
            color: var(--text-primary);
        }
        .dark-theme .actions button[name="bump_server"]:hover {
            background-color: var(--card-bg);
        }
        .dark-theme .empty-state a {
            background-color: var(--accent-color);
            color: white;
        }
        .dark-theme .empty-state a:hover {
            background-color: var(--accent-hover);
        }
        .dark-theme .bumped-card {
            border-color: var(--accent-color);
        }

        /* Light Theme */
        .light-theme body {
            background-color: #F2F3F5;
            color: #2E3338;
        }
        .light-theme .header,
        .light-theme .sidebar-left {
            background-color: #FFFFFF;
            border-color: #e3e5e8;
        }
        .light-theme .header-main-nav a {
            color: #4F5660;
        }
        .light-theme .header-main-nav a:hover,
        .light-theme .header-main-nav a.active {
            background-color: #e3e5e8;
            color: #060607;
        }
        .light-theme .search-form,
        .light-theme .search-bar input,
        .light-theme .profile-btn {
            background-color: #F8F9FA;
            border-color: #e3e5e8;
            color: #2E3338;
        }
        .light-theme .search-input::placeholder,
        .light-theme .search-bar input::placeholder {
            color: #4F5660;
        }
        .light-theme .header-btn,
        .light-theme .profile-btn i,
        .light-theme .search-icon,
        .light-theme .clear-search {
            color: #4F5660;
        }
        .light-theme .header-btn:hover,
        .light-theme .profile-btn:hover i,
        .light-theme .search-icon:hover,
        .light-theme .clear-search:hover {
            color: #007bff;
        }
        .light-theme .profile-btn:hover,
        .light-theme .profile-btn.active {
            background-color: #e3e5e8;
            border-color: #007bff;
        }
        .light-theme .profile-dropdown {
            background-color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .light-theme .profile-dropdown ul li a {
            color: #2E3338;
        }
        .light-theme .profile-dropdown ul li a:hover {
            background-color: #e3e5e8;
        }
        .light-theme .profile-dropdown .divider,
        .light-theme hr {
            background-color: #e3e5e8;
        }
        .light-theme .card,
        .light-theme .empty-state {
            background-color: #F8F9FA;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .light-theme .banner-placeholder,
        .light-theme .logo-container {
            background-color: #e3e5e8;
        }
        .light-theme .logo-placeholder {
            color: #4F5660;
        }
        .light-theme .description,
        .light-theme .stats,
        .light-theme .header-content p,
        .light-theme .empty-state p {
            color: #4F5660;
        }
        .light-theme .sort-btn {
            background-color: #F8F9FA;
            border-color: #e3e5e8;
            color: #4F5660;
        }
        .light-theme .sort-btn:hover {
            background-color: #e3e5e8;
            color: #2E3338;
        }
        .light-theme .sort-btn.active {
            background-color: #007bff;
            border-color: #007bff;
            color: #FFFFFF;
        }
        .light-theme .back-button {
            background-color: #F8F9FA;
            border-color: #e3e5e8;
            color: #2E3338;
        }
        .light-theme .back-button:hover {
            background-color: #e3e5e8;
            border-color: #007bff;
        }
        .light-theme .message-box.success {
            background-color: rgba(0, 123, 255, 0.2);
            color: #007bff;
        }
        .light-theme .message-box.error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        .light-theme .tag {
            background-color: rgba(0, 123, 255, 0.2);
            color: #007bff;
        }
        .light-theme .tag:hover {
            background-color: rgba(0, 123, 255, 0.3);
        }
        .light-theme .actions button[name="join_server"] {
            background-color: #007bff;
        }
        .light-theme .actions button[name="join_server"]:hover {
            background-color: #0056b3;
        }
        .light-theme .actions button[name="bump_server"] {
            background-color: #e3e5e8;
            color: #2E3338;
        }
        .light-theme .actions button[name="bump_server"]:hover {
            background-color: #d3d5d8;
        }
        .light-theme .empty-state a {
            background-color: #007bff;
            color: #FFFFFF;
        }
        .light-theme .empty-state a:hover {
            background-color: #0056b3;
        }
        .light-theme .bumped-card {
            border-color: #007bff;
        }

        /* Custom Theme */
        .custom-theme body {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            color: #FFFFFF;
        }
        .custom-theme .header,
        .custom-theme .sidebar-left {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .header-main-nav a {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .header-main-nav a:hover,
        .custom-theme .header-main-nav a.active {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            color: #FFFFFF;
        }
        .custom-theme .search-form,
        .custom-theme .search-bar input,
        .custom-theme .profile-btn {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            color: #FFFFFF;
        }
        .custom-theme .search-input::placeholder,
        .custom-theme .search-bar input::placeholder {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .header-btn,
        .custom-theme .profile-btn i,
        .custom-theme .search-icon,
        .custom-theme .clear-search {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .header-btn:hover,
        .custom-theme .profile-btn:hover i,
        .custom-theme .search-icon:hover,
        .custom-theme .clear-search:hover {
            color: var(--custom-secondary-color);
        }
        .custom-theme .profile-btn:hover,
        .custom-theme .profile-btn.active {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            border-color: var(--custom-secondary-color);
        }
        .custom-theme .profile-dropdown {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .custom-theme .profile-dropdown ul li a {
            color: #FFFFFF;
        }
        .custom-theme .profile-dropdown ul li a:hover {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
        }
        .custom-theme .profile-dropdown .divider,
        .custom-theme hr {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .card,
        .custom-theme .empty-state {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .custom-theme .banner-placeholder,
        .custom-theme .logo-container {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
        }
        .custom-theme .logo-placeholder {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .description,
        .custom-theme .stats,
        .custom-theme .header-content p,
        .custom-theme .empty-state p {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .sort-btn {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .sort-btn:hover {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            color: #FFFFFF;
        }
        .custom-theme .sort-btn.active {
            background-color: var(--custom-secondary-color);
            border-color: var(--custom-secondary-color);
            color: #FFFFFF;
        }
        .custom-theme .back-button {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            color: #FFFFFF;
        }
        .custom-theme .back-button:hover {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            border-color: var(--custom-secondary-color);
        }
        .custom-theme .message-box.success {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 20%, transparent 80%);
            color: var(--custom-secondary-color);
        }
        .custom-theme .message-box.error {
            background-color: color-mix(in srgb, #dc3545 20%, transparent 80%);
            color: #dc3545;
        }
        .custom-theme .tag {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 20%, transparent 80%);
            color: var(--custom-secondary-color);
        }
        .custom-theme .tag:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 30%, transparent 70%);
        }
        .custom-theme .actions button[name="join_server"] {
            background-color: var(--custom-secondary-color);
        }
        .custom-theme .actions button[name="join_server"]:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%);
        }
        .custom-theme .actions button[name="bump_server"] {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            color: #FFFFFF;
        }
        .custom-theme .actions button[name="bump_server"]:hover {
            background-color: color-mix(in srgb, var(--custom-background-color) 50%, var(--custom-secondary-color) 50%);
        }
        .custom-theme .empty-state a {
            background-color: var(--custom-secondary-color);
            color: #FFFFFF;
        }
        .custom-theme .empty-state a:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%);
        }
        .custom-theme .bumped-card {
            border-color: var(--custom-secondary-color);
        }

        /* Base Styles */
        body {
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            margin: 0;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .header {
            padding: 0.5rem 1rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-nav {
            display: flex;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            height: 48px;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            margin-right: 1rem;
        }

        .header-logo img {
            width: 32px;
            height: 32px;
        }

        .header-logo span {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--custom-secondary-color);
        }

        .header-main-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-main-nav a {
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem 0.75rem;
            border-radius: 5px;
            transition: background-color 0.2s, color 0.2s;
        }

        .header-search {
            flex: 1;
            margin: 0 1rem;
        }

        .search-form {
            display: flex;
            align-items: center;
            border-radius: 25px;
            padding: 0.5rem 1rem;
            border: 1px solid transparent;
            transition: border-color 0.2s;
        }

        .search-form:focus-within {
            border-color: var(--accent-color);
        }

        .search-input {
            background: none;
            border: none;
            width: 100%;
            outline: none;
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-btn {
            background: none;
            border: none;
            padding: 0.5rem;
            cursor: pointer;
            font-size: 1.25rem;
            position: relative;
            transition: color 0.2s;
        }

        .notification-badge {
            position: absolute;
            top: 0px;
            right: 0px;
            background-color: red;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7em;
            line-height: 1;
        }

        .profile-menu-container {
            position: relative;
            margin-left: 1rem;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 25px;
            cursor: pointer;
            transition: background-color 0.2s, border-color 0.2s;
            border: 1px solid transparent;
        }

        .profile-btn img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.5rem;
        }

        .profile-btn span {
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .profile-btn i {
            font-size: 0.8rem;
            transition: transform 0.2s;
        }

        .profile-btn.active i {
            transform: rotate(180deg);
        }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease;
            z-index: 1001;
        }

        .profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .profile-dropdown ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .profile-dropdown ul li a {
            display: block;
            padding: 0.75rem 1rem;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }

        .hamburger-btn {
            display: none;
        }

        .sidebar-left {
            position: fixed;
            top: 0;
            left: -300px;
            width: 250px;
            height: 100vh;
            z-index: 999;
            transition: left 0.3s ease-in-out;
            box-shadow: 2px 0 5px rgba(0,0,0,0.5);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sidebar-left.active {
            left: 0;
        }

        .sidebar-left .header-main-nav {
            flex-direction: column;
            gap: 0;
            display: flex;
        }

        .sidebar-left .header-main-nav a {
            width: 100%;
            padding: 1rem;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 998;
            display: none;
        }

        .overlay.active {
            display: block;
        }

        @media (max-width: 768px) {
            .hamburger-btn {
                display: block;
            }
            .header-search,
            .header-main-nav {
                display: none;
            }
            .header-logo {
                margin-right: auto;
            }
            .header-actions {
                gap: 0.5rem;
            }
        }

        /* Main content styles */
        .container {
            display: flex;
            min-height: calc(100vh - 60px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .header-content {
            margin-bottom: 30px;
            text-align: center;
        }

        .header-content h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--custom-secondary-color);
            margin-bottom: 0.5rem;
        }

        .search-container {
            margin-bottom: 20px;
        }

        .search-bar {
            display: flex;
            position: relative;
            margin-bottom: 15px;
        }

        .search-bar input {
            flex: 1;
            padding: 12px 20px;
            border-radius: 25px;
            border: 1px solid transparent;
            font-size: 16px;
            transition: border-color 0.2s;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .search-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            transition: color 0.2s;
        }

        .clear-search {
            position: absolute;
            right: 40px;
            top: 50%;
            transform: translateY(-50%);
            text-decoration: none;
            transition: color 0.2s;
        }

        .sort-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .sort-btn {
            padding: 8px 15px;
            border-radius: 20px;
            text-decoration: none;
            transition: background-color 0.2s, color 0.2s, border-color 0.2s;
            font-weight: 600;
        }

        .order-icon {
            margin-left: 5px;
            font-size: 12px;
        }

        .back-button {
            margin-top: 20px;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: background-color 0.2s, border-color 0.2s;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .message-box {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .featured {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }

        .card {
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
        }

        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .banner-container {
            position: relative;
        }

        .banner {
            width: 100%;
            height: 120px;
            object-fit: cover;
            filter: brightness(0.7);
        }

        .banner-placeholder {
            width: 100%;
            height: 120px;
        }

        .card-content {
            padding: 20px;
        }

        .title {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 15px;
        }

        .logo-container {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid transparent;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .logo-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
        }

        .name {
            font-weight: 700;
            font-size: 20px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .verified {
            color: #3ba55c;
            margin-left: 8px;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .description {
            margin-bottom: 15px;
            font-size: 14px;
            height: 40px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0;
        }

        .tag {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: background-color 0.2s, transform 0.2s;
            cursor: pointer;
        }

        .tag:hover {
            transform: translateY(-2px);
        }

        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            font-size: 14px;
            flex-wrap: wrap;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .actions form {
            display: flex;
            gap: 10px;
        }

        .actions button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: background-color 0.2s, transform 0.2s;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            border-radius: 12px;
            margin-top: 20px;
        }

        .empty-state img {
            max-width: 250px;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .header-logo {
                margin-right: auto;
            }
            .header-actions {
                gap: 0.5rem;
            }
            .featured {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                justify-content: center;
            }
            .main-content {
                padding: 15px;
            }
            .card {
                max-width: 100%;
            }
            .header-content h1 {
                font-size: 2rem;
            }
            .card .title {
                gap: 10px;
            }
            .card .name {
                font-size: 18px;
            }
            .sort-options {
                justify-content: flex-start;
            }
        }

        /* Animasyonlar */
        @keyframes bumpAnimation {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .bumped-card {
            animation: bumpAnimation 0.5s ease-in-out;
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="header-nav">
            <a href="/posts" class="header-logo">
                <img src="/icon.ico" alt="Lakeban Logo">
                <span>Lakeban</span>
            </a>

            <div class="header-main-nav">
                <a href="/posts"><?php echo $translations['header']['nav']['posts']; ?></a>
                <a href="/topluluklar" class="active"><?php echo $translations['header']['nav']['communities']; ?></a>
                <a href="/directmessages"><?php echo $translations['header']['nav']['messages']; ?></a>
                <a href="/explore"><?php echo $translations['header']['nav']['explore']; ?></a>
            </div>

            <div class="header-search">
                <form action="" method="GET" class="search-form">
                    <i class="fas fa-search text-gray-400 mr-2"></i>
                    <input type="text" name="search" placeholder="<?php echo $translations['header']['search_placeholder']; ?>" class="search-input">
                    <?php if (!empty($tagQuery)): ?>
                        <input type="hidden" name="tag" value="<?php echo htmlspecialchars($tagQuery, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                </form>
            </div>

            <div class="header-actions">
                <button class="header-btn" title="Bildirimler">
                    <i class="fas fa-bell"></i>
                </button>

                <div class="profile-menu-container">
                    <button class="profile-btn" id="profileMenuBtn">
                        <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profil Resmi">
                        <span><?php echo htmlspecialchars($username); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="profile-dropdown" id="profileDropdown">
                        <ul>
                            <li><a href="/profile-page?username=<?php echo urlencode($username); ?>"><?php echo $translations['header']['profile_menu']['my_profile']; ?></a></li>
                            <li><a href="/profile"><?php echo $translations['header']['profile_menu']['account_details']; ?></a></li>
                            <li class="divider"></li>
                            <li><a href="/logout"><?php echo $translations['header']['profile_menu']['logout']; ?></a></li>
                        </ul>
                    </div>
                </div>

                <button class="header-btn hamburger-btn" id="hamburgerBtn" title="Menü">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </nav>
    </header>

    <div class="sidebar-left" id="sidebarLeft">
        <div class="header-main-nav">
            <a href="/posts"><?php echo $translations['header']['nav']['posts']; ?></a>
            <a href="/topluluklar" class="active"><?php echo $translations['header']['nav']['communities']; ?></a>
            <a href="/directmessages"><?php echo $translations['header']['nav']['messages']; ?></a>
            <a href="/explore"><?php echo $translations['header']['nav']['explore']; ?></a>
        </div>
    </div>

    <div class="overlay" id="overlay"></div>

    <div class="container">
        <div class="main-content">
            <div class="header-content">
                <h1><?php echo $translations['communities']['title']; ?></h1>
                <p><?php echo $translations['communities']['subtitle']; ?></p>

                <div class="search-container">
                    <form method="GET" action="" class="search-bar">
                        <input 
                            placeholder="<?php echo $translations['communities']['search_placeholder']; ?>" 
                            type="text" 
                            name="search" 
                            value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                        />
                        <?php if (!empty($searchQuery)): ?>
                            <a href="?sort=<?php echo $sort; ?>&order=<?php echo $order; ?>" class="clear-search" title="<?php echo $translations['communities']['clear_search']; ?>">
                                <i class="fas fa-times-circle"></i>
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="search-icon">
                            <i class="fas fa-search"></i>
                        </button>
                        <input type="hidden" name="sort" value="<?php echo $sort; ?>">
                        <input type="hidden" name="order" value="<?php echo $order; ?>">
                    </form>

                    <div class="sort-options">
                        <a 
                            href="?search=<?php echo urlencode($searchQuery); ?>&sort=popular&order=<?php echo ($sort === 'popular') ? ($order === 'DESC' ? 'ASC' : 'DESC') : 'DESC'; ?>" 
                            class="sort-btn <?php echo ($sort === 'popular') ? 'active' : ''; ?>"
                        >
                            <?php echo $translations['communities']['sort']['popular']; ?>
                            <i class="fas fa-<?php echo ($sort === 'popular') ? ($order === 'DESC' ? 'arrow-down' : 'arrow-up') : ''; ?> order-icon"></i>
                        </a>
                        <a 
                            href="?search=<?php echo urlencode($searchQuery); ?>&sort=date&order=<?php echo ($sort === 'date') ? ($order === 'DESC' ? 'ASC' : 'DESC') : 'DESC'; ?>" 
                            class="sort-btn <?php echo ($sort === 'date') ? 'active' : ''; ?>"
                        >
                            <?php echo $translations['communities']['sort']['date']; ?>
                            <i class="fas fa-<?php echo ($sort === 'date') ? ($order === 'DESC' ? 'arrow-down' : 'arrow-up') : ''; ?> order-icon"></i>
                        </a>
                        <a 
                            href="?search=<?php echo urlencode($searchQuery); ?>&sort=name&order=<?php echo ($sort === 'name') ? ($order === 'DESC' ? 'ASC' : 'DESC') : 'DESC'; ?>" 
                            class="sort-btn <?php echo ($sort === 'name') ? 'active' : ''; ?>"
                        >
                            <?php echo $translations['communities']['sort']['name']; ?>
                            <i class="fas fa-<?php echo ($sort === 'name') ? ($order === 'DESC' ? 'arrow-down' : 'arrow-up') : ''; ?> order-icon"></i>
                        </a>
                        <a 
                            href="?search=<?php echo urlencode($searchQuery); ?>&sort=bump&order=<?php echo ($sort === 'bump') ? ($order === 'DESC' ? 'ASC' : 'DESC') : 'DESC'; ?>" 
                            class="sort-btn <?php echo ($sort === 'bump') ? 'active' : ''; ?>"
                        >
                            <?php echo $translations['communities']['sort']['bump']; ?>
                            <i class="fas fa-<?php echo ($sort === 'bump') ? ($order === 'DESC' ? 'arrow-down' : 'arrow-up') : ''; ?> order-icon"></i>
                        </a>
                        <a 
                            href="?search=<?php echo urlencode($searchQuery); ?>&sort=random" 
                            class="sort-btn <?php echo ($sort === 'random') ? 'active' : ''; ?>"
                        >
                            <?php echo $translations['communities']['sort']['random']; ?>
                            <?php if ($sort === 'random'): ?>
                                <i class="fas fa-random order-icon"></i>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!empty($bumpMessage)): ?>
                <div class="message-box <?php echo (strpos($bumpMessage, 'başarıyla bump') !== false) ? 'success' : 'error'; ?>">
                    <?php echo (strpos($bumpMessage, 'başarıyla bump') !== false) ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-triangle"></i>'; ?>
                    <span><?php echo htmlspecialchars($bumpMessage, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($errorMessage) || empty($servers)): ?>
                <div class="empty-state">
                    <img src="https://cdni.iconscout.com/illustration/premium/thumb/empty-state-6366579-5285745.png" alt="Boş Durum İllüstrasyonu">
                    <h3><?php echo $translations['communities']['empty_state_title']; ?></h3>
                    <p><?php echo $translations['communities']['empty_state_text']; ?></p>
                    <a href="/create-community"><?php echo $translations['communities']['create_community']; ?></a>
                </div>
            <?php else: ?>
                <div class="featured">
                    <?php foreach ($servers as $server): ?>
                    <div 
                        class="card"
                        data-server-id="<?php echo $server['id']; ?>"
                    >
                        <div class="banner-container">
                            <?php if (empty($server['banner'])): ?>
                                <div class="banner-placeholder"></div>
                            <?php else: ?>
                                <img 
                                    alt="Sunucu banner" 
                                    class="banner" 
                                    src="<?php echo htmlspecialchars($server['banner'], ENT_QUOTES, 'UTF-8'); ?>" 
                                    loading="lazy"
                                />
                            <?php endif; ?>
                        </div>
                        <div class="card-content">
                            <div class="title">
                                <div class="logo-container">
                                    <?php if (empty($server['profile_picture'])): ?>
                                        <div class="logo-placeholder">
                                            <?php echo strtoupper(substr($server['name'], 0, 1)); ?>
                                        </div>
                                    <?php else: ?>
                                        <img 
                                            alt="Sunucu logosu" 
                                            class="logo" 
                                            src="<?php echo htmlspecialchars($server['profile_picture'], ENT_QUOTES, 'UTF-8'); ?>"
                                            loading="lazy"
                                        />
                                    <?php endif; ?>
                                </div>
                                <span class="name"><?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($server['verified']): ?>
                                    <i class="fas fa-check-circle verified" title="<?php echo $translations['communities']['verified']; ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="description">
                                <?php echo htmlspecialchars($server['description'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>

                            <?php if (!empty($server['tags'])): ?>
                            <div class="tags">
                                <?php 
                                $tags = explode(',', $server['tags']);
                                foreach ($tags as $tag): 
                                    $cleanTag = trim($tag);
                                    if (!empty($cleanTag)):
                                ?>
                                    <span class="tag" onclick="filterByTag('<?php echo htmlspecialchars($cleanTag, ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars($cleanTag, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                            <?php endif; ?>

                            <div class="stats">
                                <div class="stat">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo htmlspecialchars($server['member_count'] ?? '0', ENT_QUOTES, 'UTF-8'); ?> üye</span>
                                </div>
                                <div class="stat">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo htmlspecialchars(date('d M Y', strtotime($server['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="stat">
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo $translations['communities']['last_bump']; ?>: <?php echo $server['last_bump'] ? htmlspecialchars(timeAgo($server['last_bump']), ENT_QUOTES, 'UTF-8') : 'Yok'; ?></span>
                                </div>
                            </div>
                            <div class="actions">
                                <form method="POST" action="">
                                    <input type="hidden" name="server_id" value="<?php echo $server['id']; ?>">
                                    <button type="submit" name="join_server"><?php echo $translations['communities']['join_button']; ?></button>
                                    <?php if ($isLoggedIn && in_array($server['id'], $user_memberships)): ?>
                                        <button type="submit" name="bump_server"><?php echo $translations['communities']['bump_button']; ?></button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const profileMenuBtn = document.getElementById("profileMenuBtn");
            const profileDropdown = document.getElementById("profileDropdown");
            const hamburgerBtn = document.getElementById("hamburgerBtn");
            const sidebarLeft = document.getElementById("sidebarLeft");
            const overlay = document.getElementById("overlay");

            if (profileMenuBtn && profileDropdown) {
                profileMenuBtn.addEventListener("click", (event) => {
                    event.stopPropagation();
                    profileDropdown.classList.toggle("show");
                    profileMenuBtn.classList.toggle("active");
                });

                document.addEventListener("click", (event) => {
                    if (!profileMenuBtn.contains(event.target) && !profileDropdown.contains(event.target)) {
                        profileDropdown.classList.remove("show");
                        profileMenuBtn.classList.remove("active");
                    }
                });
            }

            if (hamburgerBtn && sidebarLeft && overlay) {
                hamburgerBtn.addEventListener("click", () => {
                    sidebarLeft.classList.add("active");
                    overlay.classList.add("active");
                });

                overlay.addEventListener("click", () => {
                    sidebarLeft.classList.remove("active");
                    overlay.classList.remove("active");
                });

                document.addEventListener("click", (event) => {
                    if (!sidebarLeft.contains(event.target) && !hamburgerBtn.contains(event.target) && sidebarLeft.classList.contains("active")) {
                        sidebarLeft.classList.remove("active");
                        overlay.classList.remove("active");
                    }
                });
            }

            // Animasyonlu Bump Butonu için JavaScript
            const urlParams = new URLSearchParams(window.location.search);
            const bumpSuccessParam = urlParams.get('bumpSuccess');
            const bumpedServerId = urlParams.get('server_id');
            if (bumpSuccessParam === 'true' && bumpedServerId) {
                const bumpedCard = document.querySelector(`.card[data-server-id="${bumpedServerId}"]`);
                if (bumpedCard) {
                    bumpedCard.classList.add('bumped-card');
                    bumpedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => {
                        bumpedCard.classList.remove('bumped-card');
                    }, 500); // Animasyon süresi kadar bekle
                }
            }
        });

        // Dinamik Etiket Sistemi için JavaScript
        function filterByTag(tag) {
            window.location.href = `?tag=${encodeURIComponent(tag)}`;
        }
    </script>
</body>
</html>