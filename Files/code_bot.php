<?php
session_start();
require 'db_connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

if (!isset($_SESSION['user_id'])) {
    error_log("Session user_id not set. Redirecting to login.");
    header("Location: login");
    exit;
}

if (!isset($_GET['id']) || !isset($_GET['bot_id'])) {
    error_log("Server ID or Bot ID missing in URL.");
    die("Sunucu veya bot ID'si eksik.");
}
$server_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
$bot_id = filter_var($_GET['bot_id'], FILTER_VALIDATE_INT);
if ($server_id === false || $bot_id === false) {
    error_log("Invalid server ID: {$_GET['id']} or bot ID: {$_GET['bot_id']}");
    die("Geçersiz sunucu veya bot ID'si.");
}

// Kullanıcının sunucu sahibi olup olmadığını kontrol et
try {
    $stmt = $db->prepare("SELECT owner_id, name FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching server data: " . $e->getMessage());
    die("Sunucu bilgisi alınamadı.");
}

if (!$server || $server['owner_id'] != $_SESSION['user_id']) {
    error_log("User {$_SESSION['user_id']} is not the owner of server {$server_id}.");
    header("Location: server");
    exit();
}

// Bot bilgilerini al
try {
    $stmt = $db->prepare("SELECT u.username, u.prefix, bs.script_content FROM users u LEFT JOIN bot_scripts bs ON u.id = bs.bot_id WHERE u.id = ? AND u.is_bot = 1");
    $stmt->execute([$bot_id]);
    $bot = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching bot data: " . $e->getMessage());
    die("Bot bilgisi alınamadı.");
}

if (!$bot) {
    error_log("Bot not found: {$bot_id}");
    die("Bot bulunamadı.");
}

// Bot scriptini güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_script'])) {
    try {
        $db->beginTransaction();
        $script_content = trim($_POST['script_content'] ?? '');
        if (empty($script_content)) throw new Exception('Bot kodu boş olamaz.');

        // Güvenlik: Tehlikeli JavaScript fonksiyonlarını kontrol et
        $dangerous_functions = ['eval', 'Function', 'setTimeout', 'setInterval', 'require', 'import'];
        foreach ($dangerous_functions as $func) {
            if (strpos($script_content, $func) !== false) {
                throw new Exception("Güvenlik hatası: '$func' fonksiyonu kullanılamaz.");
            }
        }

        $stmt = $db->prepare("INSERT INTO bot_scripts (bot_id, script_content) VALUES (?, ?) ON DUPLICATE KEY UPDATE script_content = ?");
        $stmt->execute([$bot_id, $script_content, $script_content]);

        // Denetim kaydı
        $audit_details = ['bot_id' => $bot_id, 'bot_username' => $bot['username'], 'action' => 'update_script'];
        $stmt = $db->prepare("INSERT INTO role_audit_log (server_id, user_id, action, details) VALUES (?, ?, 'update_bot_script', ?)");
        $stmt->execute([$server_id, $_SESSION['user_id'], json_encode($audit_details)]);

        $db->commit();
        header("Location: edit_bot?id=$server_id&bot_id=$bot_id&success=updated");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Bot script update error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fi', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}
$lang = $_SESSION['lang'];

function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    error_log("Language file not found: $langFile");
    return [];
}

$translations = loadLanguage($lang);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Düzenle - <?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.7/codemirror.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.7/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.7/mode/javascript/javascript.min.js"></script>
    <style>
        :root {
            --primary-bg: #1a1b1e; --secondary-bg: #2d2f34; --accent-color: #3CB371;
            --text-primary: #ffffff; --text-secondary: #b9bbbe;
        }
        body { background-color: var(--primary-bg); color: var(--text-primary); font-family: 'Inter', sans-serif; overflow: hidden; }
        #movesidebar {
            position: absolute;
            height: 100vh;
            width: 20%;
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            left: 0;
        }
        #main-content {
            position: absolute;
            height: 100vh;
            width: 80%;
            margin-left: 20%;
            left: 0;
        }
        .nav-item { padding: 6px 10px; margin: 2px 8px; border-radius: 4px; font-size: 14px; display: flex; align-items: center; gap: 8px; color: var(--text-secondary); transition: all 0.1s ease; }
        .nav-item:hover { background-color: rgba(79, 84, 92, 0.4); color: var(--text-primary); }
        .nav-item.active { background-color: rgba(79, 84, 92, 0.6); color: var(--text-primary); }
        .form-input { background-color: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; padding: 12px; width: 100%; color: var(--text-primary); transition: border-color 0.2s ease; }
        .form-input:focus { border-color: var(--accent-color); outline: none; }
        .btn { padding: 10px 18px; border-radius: 6px; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; border: none; transition: all 0.2s ease; }
        .btn-primary { background-color: var(--accent-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-secondary { background-color: rgba(79, 84, 92, 0.5); color: var(--text-primary); }
        .btn-secondary:hover { background-color: rgba(79, 84, 92, 0.7); }
        .error-message { display: none; color: #f87171; font-size: 0.875rem; margin-top: 0.25rem; }
        .CodeMirror { height: 500px; border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; }
    </style>
</head>
<body class="flex h-screen">
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-lg"><?php echo $translations['server_settings']['server_setting'] ?? 'Sunucu Ayarları'; ?></h1>
            <p class="text-xs text-gray-400 mt-1 truncate"><?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <nav class="flex-1 p-2 overflow-y-auto">
            <div class="space-y-1">
                <a href="server_settings?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-cog w-5 text-center"></i> <span><?php echo $translations['server_settings']['general'] ?? 'Genel'; ?></span></a>
                <a href="server_emojis?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-smile w-5 text-center"></i> <span><?php echo $translations['server_settings']['emojis'] ?? 'Emojiler'; ?></span></a>
                <a href="server_stickers?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-sticky-note w-5 text-center"></i> <span><?php echo $translations['server_settings']['stickers'] ?? 'Çıkartmalar'; ?></span></a>
                <a href="assign_role?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-user-tag w-5 text-center"></i> <span><?php echo $translations['server_settings']['roles'] ?? 'Roller'; ?></span></a>
                <a href="audit_log?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-history w-5 text-center"></i> <span><?php echo $translations['server_settings']['audit_log'] ?? 'Denetim Kaydı'; ?></span></a>
                <a href="server_url?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-link w-5 text-center"></i> <span><?php echo $translations['server_settings']['server_url'] ?? 'Sunucu URL'; ?></span></a>
                <a href="unban_users?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-shield-alt w-5 text-center"></i> <span><?php echo $translations['server_settings']['moderation'] ?? 'Moderasyon'; ?></span></a>
                <a href="server_category?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-users w-5 text-center"></i> <span><?php echo $translations['server_settings']['community'] ?? 'Topluluk'; ?></span></a>
                <h3 class="text-xs font-bold text-gray-500 uppercase px-4 mt-4 mb-2">Bot Yönetimi</h3>
                <a href="create_bot?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-robot w-5 text-center"></i> <span><?php echo $translations['server_settings']['create_bot'] ?? 'Bot Oluştur'; ?></span></a>
                <a href="manage_bots?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-cogs w-5 text-center"></i> <span><?php echo $translations['server_settings']['manage_bots'] ?? 'Botları Yönet'; ?></span></a>
                <a href="code_bot?id=<?php echo $server_id; ?>&bot_id=<?php echo $bot_id; ?>" class="nav-item active"><i class="fas fa-code w-5 text-center"></i> <span><?php echo $translations['server_settings']['edit_bot'] ?? 'Botu Düzenle'; ?></span></a>
            </div>
        </nav>
        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-arrow-left w-5 text-center"></i> <span><?php echo $translations['server_settings']['back_server'] ?? 'Sunucuya Geri Dön'; ?></span></a>
        </div>
    </div>
    <main id="main-content">
        <div class="max-w-4xl mx-auto">
            <h2 class="text-2xl font-bold mb-6">Bot Düzenle: <?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if (isset($error)): ?>
                <div class="bg-red-500/20 text-red-300 p-3 rounded-lg mb-4 text-center">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['success']) && $_GET['success'] === 'updated'): ?>
                <div class="bg-green-500/20 text-green-300 p-3 rounded-lg mb-4 text-center">
                    Bot kodu başarıyla güncellendi!
                </div>
            <?php endif; ?>
            <form method="POST" action="edit_bot?id=<?php echo $server_id; ?>&bot_id=<?php echo $bot_id; ?>" class="bg-gray-800/50 p-6 rounded-lg shadow-lg space-y-5">
                <input type="hidden" name="update_script" value="1">
                <div>
                    <label class="block text-sm font-medium mb-2" for="script_content">Bot Kodu (JavaScript)</label>
                    <textarea name="script_content" id="script_content" class="form-input"><?php echo htmlspecialchars($bot['script_content'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <p class="text-xs text-gray-400 mt-1">Botunuzun komut işleme mantığını tanımlayan JavaScript kodu. handleCommand fonksiyonunu kullanın.</p>
                </div>
                <div class="flex justify-end gap-3 pt-4 border-t border-gray-700/50">
                    <a href="manage_bots?id=<?php echo $server_id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> İptal</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kodu Kaydet</button>
                </div>
            </form>
        </div>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const editor = CodeMirror.fromTextArea(document.getElementById('script_content'), {
                mode: 'javascript',
                theme: 'monokai',
                lineNumbers: true,
                indentUnit: 4,
                indentWithTabs: true,
                matchBrackets: true,
                autoCloseBrackets: true
            });
            editor.setSize('100%', '500px');
        });
    </script>
</body>
</html>