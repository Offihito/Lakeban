<?php
session_start();

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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, $options);
    $db->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    die("Unable to connect to the database. Please try again later.");
}

header('Content-Type: text/html; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Fetch servers the user is a member of
try {
    $stmt = $db->prepare("
        SELECT s.id, s.name
        FROM servers s
        JOIN server_members sm ON s.id = sm.server_id
        WHERE sm.user_id = ?
        ORDER BY s.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $servers = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching servers: " . $e->getMessage());
    $servers = [];
}

// Determine selected server
$server_id = null;
$server_name = 'Select a server';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_id'])) {
    $server_id = filter_var($_POST['server_id'], FILTER_VALIDATE_INT);
} elseif (!empty($servers)) {
    $server_id = $servers[0]['id'];
    $server_name = $servers[0]['name'];
}

// Verify server_id (if selected)
if ($server_id) {
    $stmt = $db->prepare("SELECT name FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch();
    if ($server) {
        $server_name = $server['name'];
    } else {
        $server_id = null;
        $server_name = 'Invalid server';
        error_log("Invalid server_id selected: server_id=$server_id, user_id={$_SESSION['user_id']}");
    }
}

// Fetch bots in the selected server
$bots = [];
if ($server_id) {
    try {
        $stmt = $db->prepare("
            SELECT u.id, u.username, up.avatar_url
            FROM users u
            JOIN server_members sm ON u.id = sm.user_id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE u.is_bot = 1 AND sm.server_id = ?
        ");
        $stmt->execute([$server_id]);
        $bots = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching bots: " . $e->getMessage());
    }
}

// Handle bot appearance update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $server_id && isset($_POST['action']) && $_POST['action'] === 'update_appearance') {
    try {
        $db->beginTransaction();
        $bot_id = filter_var($_POST['bot_id'], FILTER_VALIDATE_INT);
        $username = trim($_POST['username']);

        if (!$bot_id || empty($username)) {
            throw new Exception('Bot ID veya kullanıcı adı eksik.');
        }

        // Validate username (alphanumeric, underscores, max 32 chars)
        if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $username)) {
            throw new Exception('Kullanıcı adı yalnızca harf, rakam ve alt çizgi içerebilir (maks. 32 karakter).');
        }

        // Verify bot exists and is in server
        $stmt = $db->prepare("
            SELECT u.id
            FROM users u
            JOIN server_members sm ON u.id = sm.user_id
            WHERE u.id = ? AND u.is_bot = 1 AND sm.server_id = ?
        ");
        $stmt->execute([$bot_id, $server_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Bot sunucuda değil veya geçersiz.');
        }

        // Handle avatar upload
        $avatar_url = null;
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $upload_dir = 'Uploads/';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                throw new Exception('Dosya dizini oluşturulamadı.');
            }

            $file_info = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $file_info->file($_FILES['avatar']['tmp_name']);
            $allowed_types = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif'
            ];

            if (!in_array($mime_type, array_keys($allowed_types))) {
                throw new Exception('Geçersiz dosya türü. Sadece JPEG, PNG veya GIF yükleyebilirsiniz.');
            }

            if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) { // 2MB limit
                throw new Exception('Dosya boyutu 2MB\'ı geçemez.');
            }

            $extension = $allowed_types[$mime_type];
            $file_name = bin2hex(random_bytes(16)) . '.' . $extension;
            $target_file = $upload_dir . $file_name;

            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $target_file)) {
                throw new Exception('Dosya yükleme hatası.');
            }

            $avatar_url = $target_file;
        }

        // Update username in users table
        $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->execute([$username, $bot_id]);

        // Update or insert avatar_url in user_profiles
        $stmt = $db->prepare("SELECT user_id FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$bot_id]);
        if ($stmt->fetch()) {
            if ($avatar_url) {
                $stmt = $db->prepare("UPDATE user_profiles SET avatar_url = ? WHERE user_id = ?");
                $stmt->execute([$avatar_url, $bot_id]);
            }
        } else {
            if ($avatar_url) {
                $stmt = $db->prepare("INSERT INTO user_profiles (user_id, avatar_url) VALUES (?, ?)");
                $stmt->execute([$bot_id, $avatar_url]);
            }
        }

        $db->commit();
        error_log("Bot appearance updated: bot_id=$bot_id, username=$username, avatar_url=" . ($avatar_url ?: 'null') . ", server_id=$server_id");
        header("Location: edit_bot_appearance.php?success=1");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Appearance update error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Görünümünü Düzenle</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <style>
        body {
            background-color: #36393f;
            color: #dcddde;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #2f3136;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #202225;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #2f3136;
        }
        .form-input {
            background-color: #2f3136;
            border: 1px solid #202225;
            color: #dcddde;
            padding: 0.75rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        .form-input:focus {
            border-color: #3CB371;
            outline: none;
            box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
        }
        .btn-primary {
            background-color: #3CB371;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #2E8B57;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background-color: #36393f;
            color: #dcddde;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #40444b;
        }
        select.form-input {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23dcddde' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1.5rem;
        }
        .avatar-preview {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #3CB371;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-2xl p-6 bg-[#2f3136] rounded-lg shadow-lg">
        <h1 class="text-2xl font-semibold mb-4">Bot Görünümünü Düzenle</h1>
        <?php if (isset($error)): ?>
            <div class="bg-red-500/20 text-red-400 p-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-500/20 text-green-400 p-3 rounded-lg mb-4">
                Görünüm güncellendi!
            </div>
        <?php endif; ?>

        <!-- Server Selection -->
        <div class="mb-6">
            <h2 class="text-xl font-medium mb-2">Sunucu Seç</h2>
            <?php if (empty($servers)): ?>
                <p class="text-gray-400">Hiçbir sunucuya üye değilsiniz.</p>
            <?php else: ?>
                <form method="POST" action="">
                    <select name="server_id" class="form-input w-full mb-2" onchange="this.form.submit()">
                        <?php foreach ($servers as $server): ?>
                            <option value="<?php echo $server['id']; ?>" <?php echo $server['id'] == $server_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($server_id): ?>
            <!-- Bot List with Appearance Edit -->
            <div class="mb-6">
                <h2 class="text-xl font-medium mb-2">Sunucudaki Botlar (<?php echo htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8'); ?>)</h2>
                <?php if (empty($bots)): ?>
                    <p class="text-gray-400">Bu sunucuda bot bulunmuyor.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($bots as $bot): ?>
                            <div class="p-4 bg-[#36393f] rounded-lg">
                                <form method="POST" action="" enctype="multipart/form-data">
                                    <input type="hidden" name="server_id" value="<?php echo $server_id; ?>">
                                    <input type="hidden" name="bot_id" value="<?php echo $bot['id']; ?>">
                                    <input type="hidden" name="action" value="update_appearance">
                                    <div class="flex items-center gap-4 mb-4">
                                        <div>
                                            <?php if ($bot['avatar_url']): ?>
                                                <img src="<?php echo htmlspecialchars($bot['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" class="avatar-preview">
                                            <?php else: ?>
                                                <div class="avatar-preview bg-gray-600 flex items-center justify-center">
                                                    <i data-lucide="user" class="w-8 h-8"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <p class="font-medium"><?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?> (ID: <?php echo $bot['id']; ?>)</p>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1">Kullanıcı Adı</label>
                                        <input type="text" name="username" class="form-input w-full" value="<?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="32">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium mb-1">Avatar (JPEG, PNG, GIF, maks. 2MB)</label>
                                        <input type="file" name="avatar" class="form-input w-full" accept="image/jpeg,image/png,image/gif">
                                    </div>
                                    <button type="submit" class="btn-primary">
                                        <i data-lucide="save" class="w-4 h-4 mr-2"></i> Kaydet
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="flex justify-end mt-4 gap-2">
            <a href="manage_bots.php" class="btn-secondary">
                <i data-lucide="bot" class="w-4 h-4 mr-2"></i> Bot Komutları
            </a>
            <a href="server" class="btn-secondary">
                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Geri
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
        });
    </script>
</body>
</html>