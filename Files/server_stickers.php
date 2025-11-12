<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
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

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Get server ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid server ID.");
}

$server_id = (int)$_GET['id'];

// Check if the user is the owner of the server
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND owner_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {
    header("Location: sayfabulunamadı");
    exit();
}

// Fetch the server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Fetch server stickers
$stmt = $db->prepare("SELECT * FROM server_stickers WHERE server_id = ?");
$stmt->execute([$server_id]);
$stickers = $stmt->fetchAll();

// Define max sticker count and file size
$max_stickers = 75; // Keeping the same limit as emojis for consistency
$max_file_size = 2.5 * 1024 * 1024; // 2.5MB

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_sticker'])) {
        $sticker_name = trim($_POST['sticker_name']);
        $sticker_file = $_FILES['sticker_file'];

        // Validate sticker name
        if (empty($sticker_name) || !preg_match('/^[a-zA-Z0-9_]{2,}$/', $sticker_name)) {
            $error = "Geçersiz sticker adı. Sadece harf, rakam ve alt çizgi kullanılabilir ve minimum 2 karakter gereklidir.";
        } elseif (count($stickers) >= $max_stickers) {
            $error = "En fazla " . $max_stickers . " sticker yükleyebilirsiniz.";
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM server_stickers WHERE server_id = ? AND sticker_name = ?");
            $stmt->execute([$server_id, $sticker_name]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Bu isimde bir sticker zaten var.";
            } else {
                if ($sticker_file['error'] === UPLOAD_ERR_OK) {
                    $allowed_types = ['image/png', 'image/gif', 'image/webp']; // Common sticker formats
                    
                    if (in_array($sticker_file['type'], $allowed_types) && $sticker_file['size'] <= $max_file_size) {
                        $target_dir = "uploads/stickers/";
                        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
                        $target_file = $target_dir . uniqid() . '_' . basename($sticker_file['name']);

                        if (move_uploaded_file($sticker_file['tmp_name'], $target_file)) {
                            $stmt = $db->prepare("INSERT INTO server_stickers (server_id, sticker_name, sticker_url) VALUES (?, ?, ?)");
                            $stmt->execute([$server_id, $sticker_name, $target_file]);
                            header("Location: server_stickers.php?id=" . $server_id . "&success=1");
                            exit;
                        } else {
                            $error = "Dosya yüklenemedi.";
                        }
                    } else {
                        $error = "Geçersiz dosya türü veya boyutu. PNG, GIF veya WEBP, max 2.5MB.";
                    }
                } else {
                    $error = "Dosya yükleme hatası: " . $sticker_file['error'];
                }
            }
        }
    } elseif (isset($_POST['delete_sticker'])) {
        $sticker_id = $_POST['delete_sticker'];
        $stmt = $db->prepare("SELECT sticker_url FROM server_stickers WHERE id = ? AND server_id = ?");
        $stmt->execute([$sticker_id, $server_id]);
        $sticker = $stmt->fetch();
        if ($sticker) {
            if (file_exists($sticker['sticker_url'])) unlink($sticker['sticker_url']);
            $stmt = $db->prepare("DELETE FROM server_stickers WHERE id = ?");
            $stmt->execute([$sticker_id]);
            header("Location: server_stickers.php?id=" . $server_id . "&success=1");
            exit;
        } else {
            $error = "Sticker bulunamadı.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_sticker_name') {
        $sticker_id = $_POST['sticker_id'];
        $new_name = trim($_POST['new_name']);

        // Validate new name
        if (empty($new_name) || !preg_match('/^[a-zA-Z0-9_]{2,}$/', $new_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Geçersiz sticker adı. Sadece harf, rakam ve alt çizgi kullanılabilir ve minimum 2 karakter gereklidir.']);
            exit;
        }

        // Check if the sticker belongs to the server and the user is the owner
        $stmt = $db->prepare("SELECT server_id FROM server_stickers WHERE id = ?");
        $stmt->execute([$sticker_id]);
        $fetched_server_id = $stmt->fetchColumn();

        if ($fetched_server_id != $server_id) {
            echo json_encode(['status' => 'error', 'message' => 'Yetkisiz işlem.']);
            exit;
        }

        // Check if new name already exists for this server (excluding current sticker)
        $stmt = $db->prepare("SELECT COUNT(*) FROM server_stickers WHERE server_id = ? AND sticker_name = ? AND id != ?");
        $stmt->execute([$server_id, $new_name, $sticker_id]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Bu isimde başka bir sticker zaten var.']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE server_stickers SET sticker_name = ? WHERE id = ?");
            $stmt->execute([$new_name, $sticker_id]);
            echo json_encode(['status' => 'success', 'message' => 'Sticker adı başarıyla güncellendi.', 'new_name' => $new_name]);
            exit;
        } catch (PDOException $e) {
            error_log("Sticker name update error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Sticker adı güncellenirken bir hata oluştu.']);
            exit;
        }
    }
}

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

// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı dilini al
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
    <title>Sticker Yönetimi - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        .form-textarea {
            min-height: 80px;
            resize: vertical;
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

        .upload-area {
            border: 2px dashed rgba(79, 84, 92, 0.6);
            border-radius: 4px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.1s ease;
            background-color: rgba(0, 0, 0, 0.1);
        }

        .upload-area:hover {
            border-color: var(--accent-color);
        }

        .sticker-grid { /* Changed from .emoji-grid */
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 16px;
        }

        .sticker-card { /* Changed from .emoji-card */
            background-color: var(--secondary-bg);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            transition: transform 0.2s;
        }

        .sticker-card:hover { /* Changed from .emoji-card:hover */
            transform: translateY(-5px);
        }

        /* Styles for inline editing */
        .editable-sticker-name { /* Changed from .editable-emoji-name */
            cursor: pointer;
            border-bottom: 1px dashed var(--text-secondary); /* Optional: visual cue for editable */
        }
        .editable-sticker-name:hover { /* Changed from .editable-emoji-name:hover */
            color: var(--accent-color);
        }
        .sticker-name-input { /* Changed from .emoji-name-input */
            background-color: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--accent-color);
            border-radius: 3px;
            padding: 2px 4px;
            color: var(--text-primary);
            font-size: 14px;
            width: calc(100% - 8px); /* Adjust width to fit */
            text-align: center;
        }

        /* Light theme specific adjustments */
        .light-theme .form-input {
            background-color: rgba(255, 255, 255, 0.5);
            border-color: rgba(0, 0, 0, 0.1);
        }
        
        .light-theme .upload-area {
            background-color: rgba(255, 255, 255, 0.5);
            border-color: rgba(0, 0, 0, 0.1);
        }
        
        .light-theme .upload-area:hover {
            border-color: var(--accent-color);
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
            .sticker-grid { /* Changed from .emoji-grid */
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body class="flex h-screen">
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
        <a href="server_stickers?id=<?php echo $server_id; ?>" class="nav-item active">
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
            </div>
        </nav>

        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item">
                <i class="fas fa-arrow-left w-5 text-center"></i>
                <span><?php echo $translations['server_settings']['back_server']; ?></span>
            </a>
        </div>
    </div>

    <div id="main-content" class="flex-1 flex flex-col overflow-hidden">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-4xl mx-auto">
                <div id="message-container">
                    <?php if (isset($error)): ?>
                        <div class="bg-red-900/50 text-red-200 p-3 rounded mb-4 text-sm">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php elseif (isset($_GET['success']) && $_GET['success'] == 1): ?>
                        <div class="bg-green-900/50 text-green-200 p-3 rounded mb-4 text-sm">
                            <i class="fas fa-check-circle mr-2"></i>
                            İşlem başarıyla tamamlandı!
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold">Sticker Yönetimi</h2>
                    <div class="text-sm text-gray-400">
                        <span>Toplam: </span>
                        <span id="sticker-count"><?php echo count($stickers); ?></span>
                        <span>/<?php echo $max_stickers; ?> sticker</span>
                    </div>
                </div>
                
                <div class="bg-gray-800/30 rounded-lg p-4 mb-6 text-sm text-gray-300">
                    <h3 class="font-semibold mb-2">Sticker Yükleme Gereksinimleri:</h3>
                    <ul class="list-disc list-inside space-y-1">
                        <li>En fazla <?php echo $max_stickers; ?> sticker yüklenebilir (Şu an: <?php echo count($stickers); ?>).</li>
                        <li>Dosya türleri: PNG, GIF, WEBP</li>
                        <li>Maksimum dosya boyutu: 2.5MB.</li>
                        <li>Stickerlar farklı boyutlarda görüntülenebilir (genellikle daha büyük).</li>
                        <li>Sticker adı sadece harf, rakam ve alt çizgi içerebilir.</li>
                        <li>Minimum 2 karakterlik sticker adı gereklidir.</li>
                        <li>Sticker adını değiştirmek için ismine çift tıklayın.</li>
                    </ul>
                </div>

                <div class="bg-gray-800/30 rounded-lg p-6 mb-8">
                    <h3 class="text-lg font-semibold mb-4">Yeni Sticker Ekle</h3>
                    <form method="POST" action="server_stickers.php?id=<?php echo $server_id; ?>" enctype="multipart/form-data">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="sticker_name" class="form-label">Sticker Adı</label>
                                <input type="text" name="sticker_name" id="sticker_name" class="form-input" 
                                    placeholder="sticker_adi" required>
                                <p class="text-xs text-gray-400 mt-2">Sadece harf, rakam ve alt çizgi kullanabilirsiniz, min. 2 karakter</p>
                            </div>
                            
                            <div>
                                <label class="form-label">Sticker Resmi</label>
                                <div class="upload-area" id="sticker-upload-area">
                                    <input type="file" name="sticker_file" id="sticker_file" class="hidden" accept="image/png, image/gif, image/webp" required>
                                    <div class="flex flex-col items-center">
                                        <div class="w-20 h-20 bg-gray-700 rounded-lg flex items-center justify-center mb-2">
                                            <i class="fas fa-file-upload text-2xl text-gray-500"></i>
                                        </div>
                                        <div class="text-xs text-gray-400 mb-2">PNG, GIF veya WEBP (Max. 2.5MB)</div>
                                        <button type="button" class="btn btn-secondary text-xs">
                                            <i class="fas fa-upload text-xs"></i>
                                            <span>Dosya Seç</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6">
                            <button type="submit" name="upload_sticker" class="btn btn-primary">
                                <i class="fas fa-plus"></i>
                                <span>Sticker Ekle</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div>
                    <h3 class="text-lg font-semibold mb-4">Mevcut Stickerlar</h3>
                    
                    <?php if (count($stickers) > 0): ?>
                        <div class="sticker-grid">
                            <?php foreach ($stickers as $sticker): ?>
                                <div class="sticker-card" data-sticker-id="<?php echo $sticker['id']; ?>">
                                    <img src="<?php echo htmlspecialchars($sticker['sticker_url'], ENT_QUOTES, 'UTF-8'); ?>" 
                                         alt="<?php echo htmlspecialchars($sticker['sticker_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                         class="w-16 h-16 mx-auto object-contain">
                                    <div class="mt-3 mb-2">
                                        <span class="font-medium editable-sticker-name" data-sticker-id="<?php echo $sticker['id']; ?>">:<?php echo htmlspecialchars($sticker['sticker_name'], ENT_QUOTES, 'UTF-8'); ?>:</span>
                                    </div>
                                    <form method="POST" action="server_stickers.php?id=<?php echo $server_id; ?>">
                                        <input type="hidden" name="delete_sticker" value="<?php echo $sticker['id']; ?>">
                                        <button type="submit" class="btn btn-danger text-xs w-full">
                                            <i class="fas fa-trash-alt"></i>
                                            <span>Kaldır</span>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 bg-gray-800/30 rounded-lg">
                            <i class="fas fa-box-open text-4xl text-gray-500 mb-4"></i>
                            <p class="text-gray-400">Henüz hiç sticker eklenmemiş</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const serverId = <?php echo json_encode($server_id); ?>; // Pass server_id to JavaScript

            // Function to display messages (success/error)
            function displayMessage(type, message) {
                const messageContainer = document.getElementById('message-container');
                let className = '';
                let icon = '';
                if (type === 'success') {
                    className = 'bg-green-900/50 text-green-200';
                    icon = 'fas fa-check-circle';
                } else if (type === 'error') {
                    className = 'bg-red-900/50 text-red-200';
                    icon = 'fas fa-exclamation-circle';
                }

                messageContainer.innerHTML = `
                    <div class="${className} p-3 rounded mb-4 text-sm">
                        <i class="${icon} mr-2"></i>
                        ${message}
                    </div>
                `;
                // Clear message after some time
                setTimeout(() => {
                    messageContainer.innerHTML = '';
                }, 5000);
            }

            // Clear any existing PHP-generated messages on page load after a short delay
            setTimeout(() => {
                const initialMessages = document.querySelectorAll('#message-container > div');
                initialMessages.forEach(msg => msg.remove());
            }, 3000);

            // Sticker upload preview
            const stickerUploadArea = document.getElementById('sticker-upload-area');
            const stickerFileInput = document.getElementById('sticker_file');
            const stickerPreview = stickerUploadArea.querySelector('div > div');
            
            stickerFileInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        stickerPreview.innerHTML = `
                            <img src="${e.target.result}" 
                                 alt="Sticker önizleme" 
                                 class="w-20 h-20 mx-auto object-contain rounded-lg">
                            <div class="text-xs text-gray-400 mt-1 truncate">${file.name}</div>
                        `;
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Click to open file dialog
            stickerUploadArea.addEventListener('click', function(e) {
                if (e.target !== stickerFileInput) {
                    stickerFileInput.click();
                }
            });

            // Drag and drop for sticker upload
            stickerUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('border-accent-color');
            });

            stickerUploadArea.addEventListener('dragleave', function() {
                this.classList.remove('border-accent-color');
            });

            stickerUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('border-accent-color');
                
                if (e.dataTransfer.files.length) {
                    stickerFileInput.files = e.dataTransfer.files;
                    stickerFileInput.dispatchEvent(new Event('change'));
                }
            });
            
            // Mobile swipe functionality
            const movesidebar = document.getElementById("movesidebar");
            
            if (window.innerWidth <= 768) {
                let startX, endX;
                
                document.addEventListener("touchstart", (e) => {
                    startX = e.touches[0].clientX;
                });

                document.addEventListener("touchend", (e) => {
                    endX = e.changedTouches[0].clientX;
                    handleSwipe();
                });

                function handleSwipe() {
                    const deltaX = startX - endX;
                    
                    if (Math.abs(deltaX) < 100) return;
                    
                    if (deltaX > 100) {
                        closeSidebar();
                    } else if (deltaX < -100) {
                        openSidebar();
                    }
                }

                function openSidebar() {
                    movesidebar.style.left = "0";
                }

                function closeSidebar() {
                    movesidebar.style.left = "-100%";
                }
            }

            // Inline editing for sticker names
            document.querySelectorAll('.editable-sticker-name').forEach(nameSpan => {
                nameSpan.addEventListener('dblclick', function() {
                    const originalSpan = this;
                    const stickerId = originalSpan.dataset.stickerId; // Changed from emojiId
                    const currentName = originalSpan.textContent.replace(/^:|:$/g, ''); // Remove leading/trailing ':'

                    // Create an input element
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'sticker-name-input'; // Changed from emoji-name-input
                    input.value = currentName;
                    input.maxLength = 32; // Set a reasonable max length for sticker names
                    input.pattern = '^[a-zA-Z0-9_]{2,}$'; // Keep name validation consistent with server

                    // Replace the span with the input
                    originalSpan.parentNode.replaceChild(input, originalSpan);
                    input.focus();

                    // Function to save the new name
                    const saveName = async () => {
                        const newName = input.value.trim();
                        if (newName === currentName || !newName.match(/^[a-zA-Z0-9_]{2,}$/)) {
                            // If name is unchanged or invalid, revert without saving
                            input.parentNode.replaceChild(originalSpan, input);
                            if (!newName.match(/^[a-zA-Z0-9_]{2,}$/)) {
                                displayMessage('error', 'Geçersiz sticker adı. Sadece harf, rakam ve alt çizgi kullanılabilir ve minimum 2 karakter gereklidir.');
                            }
                            return;
                        }

                        // Send AJAX request
                        try {
                            const response = await fetch(`server_stickers.php?id=${serverId}`, { // Changed URL
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=update_sticker_name&sticker_id=${stickerId}&new_name=${encodeURIComponent(newName)}` // Changed parameters
                            });
                            const data = await response.json();

                            if (data.status === 'success') {
                                originalSpan.textContent = `:${data.new_name}:`;
                                displayMessage('success', data.message);
                            } else {
                                displayMessage('error', data.message);
                            }
                        } catch (error) {
                            console.error('Error updating sticker name:', error); // Changed message
                            displayMessage('error', 'Sticker adı güncellenirken bir ağ hatası oluştu.'); // Changed message
                        } finally {
                            // Revert to span regardless of success/failure
                            input.parentNode.replaceChild(originalSpan, input);
                        }
                    };

                    // Save on blur
                    input.addEventListener('blur', saveName);

                    // Save on Enter key press
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            input.blur(); // Trigger blur to save
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>