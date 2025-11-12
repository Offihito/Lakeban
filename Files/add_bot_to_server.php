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
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.");
}

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    error_log("Session user_id not set. Redirecting to login.");
    header("Location: login");
    exit;
}
error_log("Current user_id: " . $_SESSION['user_id']);

// Tema ayarlarını alma
$currentTheme = 'dark';
$currentCustomColor = '#663399';
$currentSecondaryColor = '#3CB371';

try {
    $themeStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $themeStmt->execute([$_SESSION['user_id']]);
    $userTheme = $themeStmt->fetch();
    
    if ($userTheme) {
        $currentTheme = $userTheme['theme'] ?? 'dark';
        $currentCustomColor = $userTheme['custom_color'] ?? '#663399';
        $currentSecondaryColor = $userTheme['secondary_color'] ?? '#3CB371';
    }
} catch (PDOException $e) {
    error_log("Tema ayarları hatası: " . $e->getMessage());
}

// Sunucu ID'sini alma
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    error_log("Invalid server_id provided.");
    die("Geçersiz sunucu ID.");
}
$server_id = (int)$_GET['id'];
error_log("Current server_id: " . $server_id);

// Kullanıcının sunucu sahibi olduğunu kontrol etme
$stmt = $db->prepare("SELECT s.*, s.owner_id = :user_id AS is_owner FROM servers s WHERE s.id = :server_id");
$stmt->execute(['server_id' => $server_id, 'user_id' => $_SESSION['user_id']]);
$server_access = $stmt->fetch();

if (!$server_access || !$server_access['is_owner']) {
    error_log("User {$_SESSION['user_id']} is not owner of server {$server_id}. Redirecting.");
    header("Location: sayfabulunamadi");
    exit();
}

// Sunucu detaylarını alma
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Genel botları alma (sunucuda olmayanlar)
$available_bots = [];
try {
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.avatar_url
        FROM users u
        WHERE u.is_bot = 1 
        AND u.public_access = 1
        AND u.id NOT IN (
            SELECT user_id 
            FROM server_members 
            WHERE server_id = ?
        )
        ORDER BY u.username
    ");
    $stmt->execute([$server_id]);
    $available_bots = $stmt->fetchAll();
    foreach ($available_bots as &$bot) {
        $bot['avatar_url'] = $bot['avatar_url'] ?? '/Uploads/avatars/default.png';
    }
    error_log("Available bots for server {$server_id}: " . count($available_bots));
} catch (PDOException $e) {
    error_log("Botları getirirken hata: " . $e->getMessage());
    $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Botlar getirilirken bir hata oluştu.'];
}

// Kullanıcının tüm sunucularını alma
$all_servers = [];
try {
    $stmt = $db->prepare("
        SELECT id, name, owner_id
        FROM servers
        WHERE owner_id = ?
        ORDER BY name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $all_servers = $stmt->fetchAll();
    error_log("All servers for user {$_SESSION['user_id']}: " . count($all_servers));
    foreach ($all_servers as $srv) {
        error_log("All Server ID: {$srv['id']}, Name: {$srv['name']}, Owner ID: {$srv['owner_id']}");
    }
} catch (PDOException $e) {
    error_log("Tüm sunucuları getirirken hata: " . $e->getMessage());
}

// Sunucuları alma (tüm sunucular, filtreleme olmadan)
$available_servers = [];
try {
    $stmt = $db->prepare("
        SELECT id, name
        FROM servers
        WHERE owner_id = ?
        ORDER BY name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $available_servers = $stmt->fetchAll();
    error_log("Available servers for user {$_SESSION['user_id']}: " . count($available_servers));
    foreach ($available_servers as $srv) {
        error_log("Server ID: {$srv['id']}, Name: {$srv['name']}");
    }
} catch (PDOException $e) {
    error_log("Sunucuları getirirken hata: " . $e->getMessage());
    $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Sunucular getirilirken bir hata oluştu.'];
}

// Lakeban sunucusunu özel olarak kontrol etme
$lakeban_server = [];
try {
    $stmt = $db->prepare("SELECT id, name, owner_id FROM servers WHERE name LIKE '%Lakeban%'");
    $stmt->execute();
    $lakeban_server = $stmt->fetch();
    if ($lakeban_server) {
        error_log("Lakeban server found: ID: {$lakeban_server['id']}, Name: {$lakeban_server['name']}, Owner ID: {$lakeban_server['owner_id']}");
    } else {
        error_log("Lakeban server not found in database.");
    }
} catch (PDOException $e) {
    error_log("Lakeban sunucusunu kontrol ederken hata: " . $e->getMessage());
}

// Form gönderimini işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $server_access['is_owner']) {
    try {
        $db->beginTransaction();
        $bot_id = filter_var($_POST['bot_id'], FILTER_VALIDATE_INT);
        $target_server_id = filter_var($_POST['target_server_id'], FILTER_VALIDATE_INT);
        if (!$bot_id || !$target_server_id) {
            throw new Exception('Geçersiz bot veya sunucu ID.');
        }

        // Botun genel ve sunucuda olmadığını doğrulama
        $stmt = $db->prepare("
            SELECT id 
            FROM users 
            WHERE id = ? AND is_bot = 1 AND public_access = 1
            AND id NOT IN (
                SELECT user_id 
                FROM server_members 
                WHERE server_id = ?
            )
        ");
        $stmt->execute([$bot_id, $target_server_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Seçilen bot eklenemez veya zaten sunucuda mevcut.');
        }

        // Hedef sunucunun kullanıcıya ait olduğunu doğrulama
        $stmt = $db->prepare("SELECT id FROM servers WHERE id = ? AND owner_id = ?");
        $stmt->execute([$target_server_id, $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Hedef sunucuya erişim yetkiniz yok.');
        }

        // Botu server_members tablosuna ekleme
        $stmt = $db->prepare("INSERT INTO server_members (server_id, user_id) VALUES (?, ?)");
        $stmt->execute([$target_server_id, $bot_id]);

        // Denetim günlüğünü kaydetme
        try {
            $stmt = $db->query("SHOW TABLES LIKE 'audit_log'");
            if ($stmt->rowCount() > 0) {
                $audit_action = 'add_bot_to_location';
                $audit_details = ['bot_id' => $bot_id, 'server_id' => $target_server_id];
                $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, details, server_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $audit_action, json_encode($audit_details), $target_server_id]);
            } else {
                error_log("Audit log table does not exist. Skipping audit log entry for action: add_bot_to_location");
            }
        } catch (PDOException $e) {
            error_log("Error inserting into audit_log: " . $e->getMessage());
        }

        $db->commit();
        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Bot başarıyla sunucuya eklendi.'];
        header("Location: manage_bots.php?id=$server_id");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Bot ekleme hatası: " . $e->getMessage());
        $_SESSION['feedback'] = ['type' => 'error', 'message' => $e->getMessage()];
        header("Location: add_bot_location.php?id=$server_id");
        exit;
    }
}

// Geri bildirim gösterme
$feedback = '';
if (isset($_SESSION['feedback'])) {
    $feedback_type = $_SESSION['feedback']['type'];
    $feedback_message = htmlspecialchars($_SESSION['feedback']['message'], ENT_QUOTES, 'UTF-8');
    $feedback = "<div class='feedback-message bg-$feedback_type'>$feedback_message</div>";
    unset($_SESSION['feedback']);
}

// Dil ayarları
$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fr', 'de', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (!file_exists($langFile)) {
        error_log("Language file not found: $langFile");
        return [];
    }
    $content = file_get_contents($langFile);
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parse error in $langFile: " . json_last_error_msg());
        return [];
    }
    return $data;
}

$translations = loadLanguage($lang);
?>
<!DOCTYPE html>
<html lang="tr" class="<?php echo $currentTheme; ?>-theme" style="--custom-background-color: <?php echo $currentCustomColor; ?>; --custom-secondary-color: <?php echo $currentSecondaryColor; ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Ekleme Yeri - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
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

        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
            --scrollbar-thumb: #c1c3c7;
            --scrollbar-track: #F2F3F5;
        }

        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 85%, var(--custom-secondary-color) 15%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 75%, var(--custom-secondary-color) 25%);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 30%, white);
            --scrollbar-thumb: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            --scrollbar-track: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            --accent-color: var(--custom-secondary-color);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

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

        #movesidebar {
            position: relative;
            width: clamp(200px, 20%, 300px);
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            padding: 20px 10px;
        }

        #main-content {
            flex: 1;
            background-color: var(--primary-bg);
            padding: 20px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
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
            cursor: pointer;
        }

        .nav-item:hover {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .nav-item.active {
            background-color: rgba(79, 84, 92, 0.6);
            color: var(--text-primary);
        }

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

        .container__68f37 {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(79, 84, 92, 0.3);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding-left: 20px;
            padding-right: 20px;
        }

        .column__68f37 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .h5_b717a1 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .eyebrow_b717a1 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
        }

        .title__68f37 {
            color: var(--text-primary);
        }

        .text__68f37 {
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .feedback-message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .feedback-message.bg-success {
            background-color: var(--success-color);
        }

        .feedback-message.bg-error {
            background-color: var(--danger-color);
        }

        .bot-item {
            padding: 8px 12px;
            margin: 4px 0;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background-color 0.2s ease;
        }

        .bot-item img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .bot-list-container {
            max-height: 300px;
            overflow-y: auto;
            scrollbar-width: thin;
        }

        .bot-list-container::-webkit-scrollbar {
            width: 8px;
        }

        .bot-list-container::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar-thumb);
            border-radius: 4px;
        }

        .bot-list-container::-webkit-scrollbar-track {
            background-color: var(--scrollbar-track);
        }

        .select2-results__options {
            max-height: 300px;
            overflow-y: auto;
            scrollbar-width: thin;
        }

        .select2-results__options::-webkit-scrollbar {
            width: 8px;
        }

        .select2-results__options::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar-thumb);
            border-radius: 4px;
        }

        .select2-results__options::-webkit-scrollbar-track {
            background-color: var(--scrollbar-track);
        }
    </style>
</head>
<body>
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-lg"><?php echo isset($translations['server_settings']['server_setting']) ? htmlspecialchars($translations['server_settings']['server_setting'], ENT_QUOTES, 'UTF-8') : 'Sunucu Ayarları'; ?></h1>
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
                <a href="unban_users?id=<?php echo $server_id; ?>" class="nav-item">
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
                <a href="add_bot_to_server?id=<?php echo $server_id; ?>" class="nav-item active">
                    <i class="fas fa-robot w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['add_bot_to_server'] ?? 'Bot Ekle'; ?></span>
                </a>
            </div>
        </nav>
    </div>
    
    <div id="main-content">
        <?php echo $feedback; ?>

        <div class="container__68f37">
            <div class="column__68f37">
                <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Ekleme Yeri</h2>
                <p class="text__68f37 mb-4">Bir botu başka bir sunucuya eklemek için seçim yapın.</p>
                <?php if (empty($available_bots) || empty($available_servers)): ?>
                    <p class="text__68f37">Eklenmek için uygun bot veya sunucu bulunmuyor.</p>
                <?php else: ?>
                    <form method="POST" action="" class="space-y-4">
                        <div class="form-section">
                            <label class="form-label" for="bot_id">Bot Seçin</label>
                            <select name="bot_id" id="bot_id" class="form-input select2" required>
                                <option value="">Bir bot seçin...</option>
                                <?php foreach ($available_bots as $bot): ?>
                                    <option value="<?php echo $bot['id']; ?>">
                                        <?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="target_server_id">Hedef Sunucu</label>
                            <select name="target_server_id" id="target_server_id" class="form-input select2" required>
                                <option value="">Bir sunucu seçin...</option>
                                <?php foreach ($available_servers as $srv): ?>
                                    <option value="<?php echo $srv['id']; ?>">
                                        <?php echo htmlspecialchars($srv['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Botu Ekle</button>
                    </form>
                    <div class="mt-6">
                        <h3 class="h5_b717a1">Mevcut Botlar</h3>
                        <div class="bot-list-container space-y-2">
                            <?php foreach ($available_bots as $bot): ?>
                                <div class="bot-item">
                                    <img src="<?php echo htmlspecialchars($bot['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <span><?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                theme: 'default',
                width: '100%',
                placeholder: 'Seçin...',
                allowClear: true,
                maximumSelectionLength: 0,
                maximumResultsForSearch: -1
            });

            // Tüm sunucuları konsola yazdırma
            const allServers = <?php echo json_encode($all_servers); ?>;
            console.log('All Servers for user_id: <?php echo $_SESSION['user_id']; ?>', allServers);
            console.log('Total All Servers:', allServers.length);
            allServers.forEach(server => {
                console.log(`All Server ID: ${server.id}, Name: ${server.name}, Owner ID: ${server.owner_id}`);
            });

            // Mevcut sunucuları konsola yazdırma
            const availableServers = <?php echo json_encode($available_servers); ?>;
            console.log('Available Servers (user_id: <?php echo $_SESSION['user_id']; ?>):', availableServers);
            console.log('Total Available Servers:', availableServers.length);
            availableServers.forEach(server => {
                console.log(`Server ID: ${server.id}, Name: ${server.name}`);
            });

            // Lakeban sunucusunu konsola yazdırma
            const lakebanServer = <?php echo json_encode($lakeban_server); ?>;
            console.log('Lakeban Server:', lakebanServer || 'Not found');

            // Dropdown'daki option'ları konsola yazdırma
            const targetServerOptions = $('#target_server_id option').map(function() {
                return { value: $(this).val(), text: $(this).text() };
            }).get();
            console.log('Target Server Dropdown Options:', targetServerOptions);
        });
    </script>
</body>
</html>