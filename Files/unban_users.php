<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Veritabanı bağlantısı
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Unable to connect to the database. Please try again later.");
}

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Sunucu ID'sini al
if (!isset($_GET['id'])) {
    die("Sunucu ID'si eksik.");
}
$server_id = $_GET['id'];

// Kullanıcının sunucu sahibi veya moderatör olup olmadığını kontrol et
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND (owner_id = ? OR id IN (SELECT server_id FROM user_roles WHERE user_id = ? AND role_id IN (SELECT id FROM roles WHERE permissions LIKE '%moderate%')))");
$stmt->execute([$server_id, $_SESSION['user_id'], $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {  
    header("Location: sayfabulunamadı");
    exit();
}

// Sunucu bilgilerini al
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// TEMA AYARLARI
// Varsayılan değerler
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

// Mevcut tema ayarlarını veritabanından yükle
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

try {
    $userStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($userData) {
        $currentTheme = $userData['theme'] ?? $defaultTheme;
        $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
        $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
    }
} catch (PDOException $e) {
    // Hata durumunda varsayılan değerleri kullan
}

// Sayfalandırma ayarları
$per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Filtreleme parametreleri
$search_username = isset($_GET['search_username']) ? $_GET['search_username'] : '';
$search_banned_by = isset($_GET['search_banned_by']) ? $_GET['search_banned_by'] : '';

// Banlanmış kullanıcıları çek (filtrelerle)
$query = "
    SELECT bu.id AS ban_id, bu.user_id, bu.banned_by, bu.ban_date, 
           u.username, ub.username AS banned_by_username
    FROM banned_users bu
    JOIN users u ON bu.user_id = u.id
    JOIN users ub ON bu.banned_by = ub.id
    WHERE bu.server_id = ?
";

$params = [$server_id];

if (!empty($search_username)) {
    $query .= " AND u.username LIKE ?";
    $params[] = "%$search_username%";
}

if (!empty($search_banned_by)) {
    $query .= " AND ub.username LIKE ?";
    $params[] = "%$search_banned_by%";
}

$query .= " ORDER BY bu.ban_date DESC LIMIT $offset, $per_page";

$stmt = $db->prepare($query);
$stmt->execute($params);
$banned_users = $stmt->fetchAll();

// Toplam kayıt sayısı (sayfalandırma için)
$count_query = "
    SELECT COUNT(*) as total 
    FROM banned_users bu
    JOIN users u ON bu.user_id = u.id
    JOIN users ub ON bu.banned_by = ub.id
    WHERE bu.server_id = ?
";

$count_params = [$server_id];

if (!empty($search_username)) {
    $count_query .= " AND u.username LIKE ?";
    $count_params[] = "%$search_username%";
}

if (!empty($search_banned_by)) {
    $count_query .= " AND ub.username LIKE ?";
    $count_params[] = "%$search_banned_by%";
}

$stmt = $db->prepare($count_query);
$stmt->execute($count_params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Ban istatistiklerini al (SUNUCUYA ÖZEL)
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM banned_users WHERE server_id = ?) as total_bans,
        (SELECT username FROM users WHERE id = (
            SELECT banned_by FROM banned_users WHERE server_id = ? GROUP BY banned_by ORDER BY COUNT(*) DESC LIMIT 1
        )) as top_banner,
        (SELECT COUNT(*) FROM banned_users WHERE server_id = ? AND DATE(ban_date) = CURDATE()) as today_bans,
        (SELECT COUNT(DISTINCT user_id) FROM banned_users WHERE server_id = ?) as unique_banned_users
";

$stmt = $db->prepare($stats_query);
$stmt->execute([$server_id, $server_id, $server_id, $server_id]);
$stats = $stmt->fetch();

// Son 30 günün ban grafiği verisi (SUNUCUYA ÖZEL)
$chart_query = "
    SELECT DATE(ban_date) as date, COUNT(*) as count 
    FROM banned_users 
    WHERE server_id = ? 
    AND ban_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(ban_date)
    ORDER BY date ASC
";

$stmt = $db->prepare($chart_query);
$stmt->execute([$server_id]);
$chart_data = $stmt->fetchAll();

// Sunucudaki kullanıcıları listele (banlama formu için)
$stmt = $db->prepare("
    SELECT u.id, u.username 
    FROM users u 
    JOIN server_members sm ON u.id = sm.user_id 
    WHERE sm.server_id = ?
    ORDER BY u.username ASC
");
$stmt->execute([$server_id]);
$server_users = $stmt->fetchAll();

// Toplu işlemler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['unban_user'])) {
        // Tekli unban
        $ban_id = $_POST['ban_id'];
        $user_id = $_POST['user_id'];
        
        $stmt = $db->prepare("DELETE FROM banned_users WHERE id = ?");
        $stmt->execute([$ban_id]);
        
        $stmt = $db->prepare("INSERT INTO server_members (server_id, user_id) VALUES (?, ?)");
        $stmt->execute([$server_id, $user_id]);
        
        header("Location: unban_users?id=$server_id&page=$page&search_username=$search_username&search_banned_by=$search_banned_by");
        exit;
    }
    elseif (isset($_POST['mass_unban'])) {
        // Toplu unban
        $selected_bans = $_POST['selected_bans'] ?? [];
        
        if (!empty($selected_bans)) {
            // Ban kayıtlarını sil
            $placeholders = implode(',', array_fill(0, count($selected_bans), '?'));
            $stmt = $db->prepare("DELETE FROM banned_users WHERE id IN ($placeholders)");
            $stmt->execute($selected_bans);
            
            // Kullanıcıları sunucuya geri ekle
            $user_ids = [];
            foreach ($selected_bans as $ban_id) {
                $stmt = $db->prepare("SELECT user_id FROM banned_users WHERE id = ?");
                $stmt->execute([$ban_id]);
                if ($user = $stmt->fetch()) {
                    $user_ids[] = $user['user_id'];
                }
            }
            
            if (!empty($user_ids)) {
                $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                $stmt = $db->prepare("INSERT INTO server_members (server_id, user_id) VALUES $server_id, ?), " . str_repeat("($server_id, ?), ", count($user_ids)-1) . "($server_id, ?)");
                $stmt->execute($user_ids);
            }
        }
        
        header("Location: unban_users?id=$server_id&page=$page&search_username=$search_username&search_banned_by=$search_banned_by");
        exit;
    }
}


// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı langını al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    // Desteklenen dilleri kontrol et
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Dil seçeneğini kontrol et
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang; // Tarayıcı dilini varsayılan olarak ayarla
}

$lang = $_SESSION['lang'];

// Dil dosyalarını yükleme fonksiyonu
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);

?>
<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh; --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>; --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['server_settings']['title_four']; ?> - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --accent-color: #3CB371;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --danger-color: #ed4245;
            --success-color: #3ba55c;
            --warning-color: #faa61a;
            --scrollbar-thumb: #202225;
            --scrollbar-track: #2e3338;
        }

        /* === AYDINLIK TEMA === */
        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
            --scrollbar-thumb: #C1C3C7;
            --scrollbar-track: #F2F3F5;
        }

        /* === KOYU TEMA === */
        .dark-theme {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --scrollbar-thumb: #202225;
            --scrollbar-track: #2e3338;
        }

        /* === ÖZEL TEMA === */
        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            --accent-color: var(--custom-secondary-color);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 40%, white);
            --scrollbar-thumb: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            --scrollbar-track: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        
        /* Koyu tema için select stilleri */
        select.form-input {
            color: var(--text-primary);
            background-color: var(--secondary-bg);
        }

        select.form-input option {
            color: var(--text-primary);
            background-color: var(--secondary-bg);
        }

        select.form-input:focus option {
            background-color: var(--primary-bg);
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar-thumb);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-track {
            background-color: var(--scrollbar-track);
        }

        /* Discord-like sidebar */
        #movesidebar {
            position: absolute;
            height: 100vh;
            width: 20%;
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }
        
        #main-content {
            position: absolute;
            height: 100vh;
            width: 80%;
            margin-left: 20%;
        }

        .nav-item {
            padding: 6px 10px;
            margin: 2px 8px;
            border-radius: 4px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            transition: all 0.1s ease;
        }

        .nav-item:hover {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .nav-item.active {
            background-color: rgba(79, 84, 92, 0.6);
            color: var(--text-primary);
        }

        /* Discord-like form elements */
        .form-section {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
        }

        .form-input {
            background-color: rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.3);
            border-radius: 3px;
            padding: 8px 10px;
            width: 100%;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:hover {
            border-color: rgba(0, 0, 0, 0.5);
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
        }

        /* Discord-like buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.1s ease;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2E8B57;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c03537;
        }

        .btn-secondary {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: rgba(79, 84, 92, 0.6);
        }

        /* Table styles */
        .table-container {
            background-color: var(--secondary-bg);
            border-radius: 4px;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background-color: rgba(0, 0, 0, 0.2);
            padding: 10px 15px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        tr:last-child td {
            border-bottom: none;
        }

        .text-success {
            color: var(--success-color);
        }

        .text-danger {
            color: var(--danger-color);
        }

        .text-warning {
            color: var(--warning-color);
        }

        .stats-card {
            background-color: var(--secondary-bg);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .stats-card h3 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .stats-card .value {
            font-size: 24px;
            font-weight: 700;
        }

        .pagination {
            display: flex;
            gap: 5px;
            margin-top: 20px;
        }

        .pagination a, .pagination span {
            padding: 5px 10px;
            border-radius: 3px;
            background-color: var(--secondary-bg);
        }

        .pagination a:hover {
            background-color: rgba(79, 84, 92, 0.4);
        }

        .pagination .active {
            background-color: var(--accent-color);
            color: white;
        }

        .chart-container {
            background-color: var(--secondary-bg);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        /* Light theme specific adjustments */
        .light-theme .form-input {
            background-color: rgba(255, 255, 255, 0.5);
            border-color: rgba(0, 0, 0, 0.1);
        }
        
        .light-theme select.form-input {
            background-color: rgba(255, 255, 255, 0.5);
        }
        
        .light-theme .table-container {
            background-color: rgba(255, 255, 255, 0.8);
        }
        
        .light-theme .stats-card {
            background-color: rgba(255, 255, 255, 0.8);
        }
        
        .light-theme .chart-container {
            background-color: rgba(255, 255, 255, 0.8);
        }
        
        @media (max-width: 768px) {
            #movesidebar{
              width: 100%;
              left: 0%;
              height: 100vh;
              z-index: 10;
            }
            #main-content {
                position: absolute;
                height: 100vh;
                left: -20%;
                width: 100%;
             }
            }
    </style>
</head>
<body class="flex h-screen">
    <!-- Sidebar - assign_role.php ile tamamen aynı -->
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-lg"><?php echo $translations['server_settings']['server_setting']; ?></h1>
            <p class="text-xs text-gray-400 mt-1 truncate"><?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        
        <nav class="flex-1 p-2 overflow-y-auto">
    <div class="space-y-1">
        <a href="server_settings?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-cog w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['general']; ?></span>
        </a>
        <a href="server_emojis?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-smile w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['emojis']; ?></span>
        </a>
        <a href="server_stickers?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-sticky-note w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['stickers']; ?></span>
        </a>
        <a href="assign_role?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-user-tag w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['roles']; ?></span>
        </a>
                <a href="audit_log?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-history w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['audit_log']; ?></span>
                </a>
                <a href="server_url?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-link w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['server_url']; ?></span>
                </a>
                <a href="unban_users?id=<?php echo $server_id; ?>" class="nav-item active">
                    <i class="fas fa-shield-alt w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['moderation']; ?></span>
                </a>
                <a href="server_category?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-users w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['community']; ?></span>
                </a>
                <h3 class="text-xs font-bold text-gray-500 uppercase px-4 mt-4 mb-2">Bot Yönetimi</h3>
                <a href="create_bot?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-robot w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['create_bot']; ?></span>
                </a>
                <a href="manage_bots?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-cogs w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['manage_bots']; ?></span>
                </a>
            </div>
        </nav>

        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item">
                <i class="fas fa-arrow-left w-5 text-center"></i>
                <span><?php echo $translations['server_settings']['back_server']; ?></span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div id="main-content" class="flex-1 flex flex-col overflow-hidden">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-xl font-semibold mb-6">Moderasyon Paneli</h2>
                
                <!-- İstatistikler -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="stats-card">
                        <h3>Toplam Ban</h3>
                        <div class="value"><?php echo $stats['total_bans']; ?></div>
                    </div>
                    <div class="stats-card">
                        <h3>En Çok Ban Atan</h3>
                        <div class="value"><?php echo htmlspecialchars($stats['top_banner'] ?? 'Yok', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="stats-card">
                        <h3>Bugünkü Banlar</h3>
                        <div class="value"><?php echo $stats['today_bans']; ?></div>
                    </div>
                    <div class="stats-card">
                        <h3>Farklı Kullanıcı</h3>
                        <div class="value"><?php echo $stats['unique_banned_users']; ?></div>
                    </div>
                </div>
                
                <!-- Ban Grafiği -->
                <div class="chart-container mb-6">
                    <h3 class="text-lg font-semibold mb-4">Son 30 Günün Ban Grafiği</h3>
                    <canvas id="banChart" height="150"></canvas>
                </div>
                
                <!-- Filtreleme Formu -->
                <div class="bg-secondary-bg rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold mb-4">Filtrele</h3>
                    <form id="filterForm" method="GET" action="unban_users">
                        <input type="hidden" name="id" value="<?php echo $server_id; ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-section">
                                <label for="search_username" class="form-label">Kullanıcı Adı</label>
                                <input type="text" name="search_username" id="search_username" class="form-input" 
                                       value="<?php echo htmlspecialchars($search_username, ENT_QUOTES, 'UTF-8'); ?>" 
                                       placeholder="Kullanıcı adıyla filtrele">
                            </div>
                            <div class="form-section">
                                <label for="search_banned_by" class="form-label">Banlayan Kullanıcı</label>
                                <input type="text" name="search_banned_by" id="search_banned_by" class="form-input" 
                                       value="<?php echo htmlspecialchars($search_banned_by, ENT_QUOTES, 'UTF-8'); ?>" 
                                       placeholder="Banlayan kullanıcıyla filtrele">
                            </div>
                            <div class="form-section flex items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i>
                                    <span>Filtrele</span>
                                </button>
                                <?php if (!empty($search_username) || !empty($search_banned_by)): ?>
                                    <a href="unban_users?id=<?php echo $server_id; ?>" class="btn btn-secondary ml-2">
                                        <i class="fas fa-times"></i>
                                        <span>Temizle</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Toplu İşlem Formu -->
                <form id="massActionForm" method="POST" action="unban_users?id=<?php echo $server_id; ?>">
                    <input type="hidden" name="page" value="<?php echo $page; ?>">
                    <input type="hidden" name="search_username" value="<?php echo htmlspecialchars($search_username, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="search_banned_by" value="<?php echo htmlspecialchars($search_banned_by, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Banlanmış Kullanıcılar</h3>
                        <div class="flex items-center gap-2">
                            <button type="button" id="selectAllBtn" class="btn btn-secondary text-sm">
                                <i class="fas fa-check-square"></i>
                                <span>Tümünü Seç</span>
                            </button>
                            <button type="submit" name="mass_unban" class="btn btn-success text-sm">
                                <i class="fas fa-undo"></i>
                                <span>Seçilenleri Unbanla</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Banlanmış Kullanıcılar Listesi -->
                    <div class="table-container mb-4">
                        <table>
                            <thead>
                                <tr>
                                    <th width="40px"><input type="checkbox" id="selectAllCheckbox"></th>
                                    <th>Kullanıcı</th>
                                    <th>Banlayan</th>
                                    <th>Ban Tarihi</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($banned_users)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-gray-400">Banlanmış kullanıcı bulunamadı.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($banned_users as $user): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_bans[]" value="<?php echo $user['ban_id']; ?>" class="ban-checkbox"></td>
                                            <td><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($user['banned_by_username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($user['ban_date'])); ?></td>
                                            <td>
                                                <form method="POST" action="unban_users?id=<?php echo $server_id; ?>" class="inline">
                                                    <input type="hidden" name="ban_id" value="<?php echo $user['ban_id']; ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <input type="hidden" name="page" value="<?php echo $page; ?>">
                                                    <input type="hidden" name="search_username" value="<?php echo htmlspecialchars($search_username, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="search_banned_by" value="<?php echo htmlspecialchars($search_banned_by, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" name="unban_user" class="btn btn-success">
                                                        <i class="fas fa-undo"></i>
                                                        <span>Unban</span>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                
                <!-- Sayfalandırma -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="unban_users?id=<?php echo $server_id; ?>&page=1&search_username=<?php echo urlencode($search_username); ?>&search_banned_by=<?php echo urlencode($search_banned_by); ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="unban_users?id=<?php echo $server_id; ?>&page=<?php echo $page-1; ?>&search_username=<?php echo urlencode($search_username); ?>&search_banned_by=<?php echo urlencode($search_banned_by); ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    if ($start > 1) echo '<span>...</span>';
                    
                    for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="unban_users?id=<?php echo $server_id; ?>&page=<?php echo $i; ?>&search_username=<?php echo urlencode($search_username); ?>&search_banned_by=<?php echo urlencode($search_banned_by); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; 
                    
                    if ($end < $total_pages) echo '<span>...</span>';
                    ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="unban_users?id=<?php echo $server_id; ?>&page=<?php echo $page+1; ?>&search_username=<?php echo urlencode($search_username); ?>&search_banned_by=<?php echo urlencode($search_banned_by); ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="unban_users?id=<?php echo $server_id; ?>&page=<?php echo $total_pages; ?>&search_username=<?php echo urlencode($search_username); ?>&search_banned_by=<?php echo urlencode($search_banned_by); ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Ban grafiği oluşturma
        const ctx = document.getElementById('banChart').getContext('2d');
        const banChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($item) { return "'" . date('d.m', strtotime($item['date'])) . "'"; }, $chart_data)); ?>],
                datasets: [{
                    label: 'Ban Sayısı',
                    data: [<?php echo implode(',', array_column($chart_data, 'count')); ?>],
                    backgroundColor: 'rgba(60, 179, 113, 0.2)',
                    borderColor: 'rgba(60, 179, 113, 1)',
                    borderWidth: 2,
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        // Tümünü seç/deselect
        $('#selectAllBtn, #selectAllCheckbox').on('click', function() {
            const isChecked = $('#selectAllCheckbox').prop('checked');
            $('.ban-checkbox').prop('checked', !isChecked);
            $('#selectAllCheckbox').prop('checked', !isChecked);
        });

        // Checkbox değiştiğinde "Tümünü Seç" durumunu güncelle
        $(document).on('change', '.ban-checkbox', function() {
            const allChecked = $('.ban-checkbox:checked').length === $('.ban-checkbox').length;
            $('#selectAllCheckbox').prop('checked', allChecked);
        });

        // AJAX ile filtreleme
        $(document).ready(function() {
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize();
                const url = $(this).attr('action') + '?' + formData;
                
                // Sayfayı yenilemeden filtreleme yap
                window.location.href = url;
            });
        });
        
// Mobil Kaydırma hareketi
const movesidebar = document.getElementById("movesidebar");

let startX, endX; // Hareket başlangıç ve bitiş noktaları

// Ekran genişliği 768px veya daha küçükse kaydırma işlemi etkinleştirilsin
if (window.innerWidth <= 768) {
  // Dokunma başlangıcını algıla
  document.addEventListener("touchstart", (e) => {
    startX = e.touches[0].clientX;
  });

  // Dokunma bitişini algıla ve hareketi kontrol et
  document.addEventListener("touchend", (e) => {
    endX = e.changedTouches[0].clientX;
    handleSwipe();
  });

  // Hareketi işleyen fonksiyon
  function handleSwipe() {
    const deltaX = startX - endX;

    // Minimum hassasiyet
    if (Math.abs(deltaX) < 100) return; // 100px altında hiçbir işlem yapma

    // Sağdan sola kaydırma: Sidebar kapanıyor
    if (deltaX > 100) {
      closeSidebar();
    }
    // Soldan sağa kaydırma: Sidebar açılıyor
    else if (deltaX < -100) {
      openSidebar();
    }
  }

  // Sidebar’ı açan fonksiyon
  function openSidebar() {
    movesidebar.style.left = "0"; // Sağdan sıfıra hareket
  }

  // Sidebar’ı kapatan fonksiyon
  function closeSidebar() {
    movesidebar.style.left = "-100%"; // Sağdan kaybolma
  }
}
    </script>
</body>
</html>