<?php
session_start();
require 'db_connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    error_log("Session user_id not set. Redirecting to login.");
    header("Location: login");
    exit;
}

// URL'den sunucu ID'sini al
if (!isset($_GET['id'])) {
    error_log("Server ID missing in URL.");
    die("Sunucu ID'si eksik.");
}
$server_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if ($server_id === false) {
    error_log("Invalid server ID provided: " . $_GET['id']);
    die("Geçersiz sunucu ID'si.");
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

// Bot oluşturma işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_bot'])) {
    try {
        $db->beginTransaction();

        $bot_username = trim($_POST['bot_username'] ?? '');
        $bot_prefix = trim($_POST['bot_prefix'] ?? '');
        $initial_script = trim($_POST['initial_script'] ?? '');

        // Bot kullanıcı adı doğrulaması
        if (empty($bot_username)) throw new Exception('Bot kullanıcı adı zorunludur.');
        if (strpos($bot_username, ' ') !== false) throw new Exception('Bot kullanıcı adı boşluk içeremez.');
        if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $bot_username)) throw new Exception('Kullanıcı adı yalnızca harf, rakam ve alt çizgi içerebilir (maks. 32 karakter).');
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$bot_username]);
        if ($stmt->fetch()) throw new Exception('Bu kullanıcı adı zaten alınmış.');

        // Bot öneki doğrulaması
        if (empty($bot_prefix)) throw new Exception('Bot öneki (prefix) zorunludur.');
        if (!preg_match('/^.{1,16}$/', $bot_prefix)) throw new Exception('Bot öneki 1 ila 16 karakter arasında olmalıdır.');

        // Avatar Yükleme Mantığı
        $avatar_url = '/Uploads/avatars/default.png';
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'Uploads/avatars/';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) throw new Exception('Yükleme dizini oluşturulamadı.');
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = mime_content_type($_FILES['avatar']['tmp_name']);
            $file_size = $_FILES['avatar']['size'];
            if (!in_array($file_type, $allowed_types)) throw new Exception('Geçersiz dosya türü. Sadece JPG, PNG, GIF kabul edilir.');
            if ($file_size >= 2000000) throw new Exception('Avatar dosyası 2MB\'den büyük olamaz.');
            $file_extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid('avatar_', true) . '.' . $file_extension;
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_dir . $new_filename)) throw new Exception('Avatar dosyası yüklenemedi.');
            $avatar_url = '/' . $upload_dir . $new_filename;
        }

        // Botu users tablosuna ekle
        $dummy_email = "bot_" . strtolower($bot_username) . "@" . bin2hex(random_bytes(4)) . ".com";
        $dummy_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password, is_bot, avatar_url, prefix, created_by, created_at)
            VALUES (?, ?, ?, 1, ?, ?, ?, NOW())
        ");
        $stmt->execute([$bot_username, $dummy_email, $dummy_password, $avatar_url, $bot_prefix, $_SESSION['user_id']]);
        $bot_id = $db->lastInsertId();

        // Botu user_profiles tablosuna ekle
        if ($avatar_url !== '/Uploads/avatars/default.png') {
            $stmt = $db->prepare("INSERT INTO user_profiles (user_id, avatar_url) VALUES (?, ?)");
            $stmt->execute([$bot_id, $avatar_url]);
        }

        // Botu sunucuya ekle
        $stmt = $db->prepare("INSERT INTO server_members (server_id, user_id) VALUES (?, ?)");
        $stmt->execute([$server_id, $bot_id]);

        // Varsayılan bot scripti (JavaScript)
        $default_script = '// Botunuzun komut işleme mantığı burada yer alır
function handleCommand(command, args, db, server_id, channel_id, sender_id) {
    // Örnek: !selam komutuna yanıt
    if (command === "selam") {
        return { response: "Merhaba, dünya!" };
    }
    // Örnek: JSON veri kullanımı
    if (command === "veriekle") {
        let data = db.getData();
        data[args] = (data[args] || 0) + 1;
        db.setData(data);
        return { response: `${args} eklendi, toplam: ${data[args]}` };
    }
    return null;
}';
        $script_content = empty($initial_script) ? $default_script : $initial_script;

        // Bot scriptini ve JSON verisini kaydet
        $stmt = $db->prepare("INSERT INTO bot_scripts (bot_id, script_content, json_data) VALUES (?, ?, ?)");
        $stmt->execute([$bot_id, $script_content, '{}']);

        // Denetim kaydı ekle
        $audit_details = ['bot_username' => $bot_username, 'bot_id' => $bot_id, 'prefix' => $bot_prefix, 'avatar_url' => $avatar_url];
        $stmt = $db->prepare("INSERT INTO role_audit_log (server_id, user_id, action, details) VALUES (?, ?, 'create_bot', ?)");
        $stmt->execute([$server_id, $_SESSION['user_id'], json_encode($audit_details)]);

        $db->commit();
        header("Location: edit_bot?id=$server_id&bot_id=$bot_id&success=created");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Bot creation error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Dil dosyalarını yükleme
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
    <title>Bot Oluştur - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
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
                <a href="create_bot?id=<?php echo $server_id; ?>" class="nav-item active"><i class="fas fa-robot w-5 text-center"></i> <span><?php echo $translations['server_settings']['create_bot'] ?? 'Bot Oluştur'; ?></span></a>
                <a href="manage_bots?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-cogs w-5 text-center"></i> <span><?php echo $translations['server_settings']['manage_bots'] ?? 'Botları Yönet'; ?></span></a>
            </div>
        </nav>
        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item"><i class="fas fa-arrow-left w-5 text-center"></i> <span><?php echo $translations['server_settings']['back_server'] ?? 'Sunucuya Geri Dön'; ?></span></a>
        </div>
    </div>
    <main id="main-content">
        <div class="max-w-3xl mx-auto">
            <h2 class="text-2xl font-bold mb-6">Yeni Bot Oluştur</h2>
            <?php if (isset($error)): ?>
                <div class="bg-red-500/20 text-red-300 p-3 rounded-lg mb-4 text-center">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="create_bot?id=<?php echo $server_id; ?>" class="bg-gray-800/50 p-6 rounded-lg shadow-lg space-y-5" enctype="multipart/form-data" id="bot-form">
                <input type="hidden" name="create_bot" value="1">
                <div>
                    <label class="block text-sm font-medium mb-2" for="bot_username">Bot Kullanıcı Adı</label>
                    <input type="text" name="bot_username" id="bot_username" class="form-input" required placeholder="Örn: YardimciBot" maxlength="32">
                    <p id="username-error" class="error-message">Bot kullanıcı adı boşluk içeremez.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2" for="bot_prefix">Bot Öneki (Prefix)</label>
                    <input type="text" name="bot_prefix" id="bot_prefix" class="form-input" required placeholder="Örn: !" maxlength="16">
                    <p class="text-xs text-gray-400 mt-1">Botunuzun komutları tanıyacağı ön ek (örn: !yardımcı)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2" for="avatar">Bot Avatarı (İsteğe Bağlı, Maks 2MB)</label>
                    <input type="file" name="avatar" id="avatar" class="form-input" accept="image/png, image/jpeg, image/gif">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2" for="initial_script">Başlangıç Bot Kodu (JavaScript, İsteğe Bağlı)</label>
                    <textarea name="initial_script" id="initial_script" class="form-input" rows="10" placeholder="Botunuzun başlangıç JavaScript kodunu buraya yazın. Varsayılan bir şablon kullanılacak."></textarea>
                    <p class="text-xs text-gray-400 mt-1">Botunuzun komut işleme mantığını tanımlayan JavaScript kodu. handleCommand fonksiyonunu kullanın.</p>
                </div>
                <div class="flex justify-end gap-3 pt-4 border-t border-gray-700/50">
                    <a href="server?id=<?php echo $server_id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> İptal</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-robot"></i> Botu Oluştur</button>
                </div>
            </form>
        </div>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const botForm = document.getElementById('bot-form');
            const botUsernameInput = document.getElementById('bot_username');
            const usernameError = document.getElementById('username-error');
            const editor = CodeMirror.fromTextArea(document.getElementById('initial_script'), {
                mode: 'javascript',
                theme: 'monokai',
                lineNumbers: true,
                indentUnit: 4,
                indentWithTabs: true,
                matchBrackets: true,
                autoCloseBrackets: true
            });
            editor.setSize('100%', '500px');
            botUsernameInput.addEventListener('input', () => {
                if (botUsernameInput.value.includes(' ')) {
                    usernameError.style.display = 'block';
                    botUsernameInput.setCustomValidity('Bot kullanıcı adı boşluk içeremez.');
                } else {
                    usernameError.style.display = 'none';
                    botUsernameInput.setCustomValidity('');
                }
            });
            botForm.addEventListener('submit', (e) => {
                if (botUsernameInput.value.includes(' ')) {
                    e.preventDefault();
                    usernameError.style.display = 'block';
                }
            });
        });
    </script>
</body>
</html>