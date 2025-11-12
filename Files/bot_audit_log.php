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
    die("Veritabanına bağlanılamıyor. Lütfen daha sonra tekrar deneyin.");
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Get server ID and bot ID from URL
if (!isset($_GET['server_id']) || !isset($_GET['bot_id'])) {
    die("Sunucu ID veya Bot ID eksik.");
}

$server_id = filter_var($_GET['server_id'], FILTER_VALIDATE_INT);
$bot_id = filter_var($_GET['bot_id'], FILTER_VALIDATE_INT);

if (!$server_id || !$bot_id) {
    die("Geçersiz Sunucu ID veya Bot ID.");
}

// Check if the user is the owner of the bot or has manage_roles permission for the server
// Note: isBotOwner function is defined in manage_bots.php, if you want to reuse it here,
// you might need to include it or redefine it. For simplicity, I'm inline-checking here.
$is_bot_owner = false;
$stmt_owner = $db->prepare("SELECT created_by FROM users WHERE id = ? AND is_bot = 1");
$stmt_owner->execute([$bot_id]);
$created_by = $stmt_owner->fetchColumn();
if ($created_by == $_SESSION['user_id']) {
    $is_bot_owner = true;
}

$has_manage_roles_permission = false;
$stmt_perm = $db->prepare("
    SELECT 1 FROM user_roles ur
    JOIN roles r ON ur.role_id = r.id
    WHERE ur.user_id = ? AND ur.server_id = ? AND r.permissions LIKE '%manage_roles%'
");
$stmt_perm->execute([$_SESSION['user_id'], $server_id]);
if ($stmt_perm->fetch()) {
    $has_manage_roles_permission = true;
}

if (!$is_bot_owner && !$has_manage_roles_permission) {
    // If not bot owner and no manage_roles permission, redirect.
    // You might want a more specific redirect or error message.
    header("Location: sayfabulunamadı");
    exit();
}


// Fetch server and bot details
$server = null;
$bot = null;
try {
    $stmt = $db->prepare("SELECT name FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch();

    $stmt = $db->prepare("SELECT username FROM users WHERE id = ? AND is_bot = 1");
    $stmt->execute([$bot_id]);
    $bot = $stmt->fetch();

    if (!$server || !$bot) {
        die("Sunucu veya bot bulunamadı.");
    }
} catch (PDOException $e) {
    error_log("Error fetching server or bot details: " . $e->getMessage());
    die("Detaylar çekilirken bir hata oluştu.");
}


// Fetch audit logs
$filter_action = isset($_GET['action']) ? $_GET['action'] : '';
$filter_user = isset($_GET['user_id']) ? $_GET['user_id'] : '';

$sql = "SELECT bal.*, u.username FROM bot_audit_log bal LEFT JOIN users u ON bal.user_id = u.id WHERE bal.server_id = ? AND bal.bot_id = ?";
$params = [$server_id, $bot_id];

if ($filter_action) {
    $sql .= " AND bal.action = ?";
    $params[] = $filter_action;
}
if ($filter_user) {
    $sql .= " AND bal.user_id = ?";
    $params[] = $filter_user;
}

$sql .= " ORDER BY bal.created_at DESC LIMIT 50";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$audit_logs = $stmt->fetchAll();

// Fetch users for filter (users who performed actions on this bot in this server)
$stmt = $db->prepare("SELECT DISTINCT u.id, u.username FROM users u JOIN bot_audit_log bal ON u.id = bal.user_id WHERE bal.server_id = ? AND bal.bot_id = ?");
$stmt->execute([$server_id, $bot_id]);
$users = $stmt->fetchAll();

// Language settings (copy from audit_log.php or use a shared translation system)
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
    return file_exists($langFile) ? json_decode(file_get_contents($langFile), true) : [];
}

$translations = loadLanguage($lang);

// Action labels for better readability
$action_labels = [
    'add_bot_command' => 'Bot Komutu Ekleme',
    'edit_bot_command' => 'Bot Komutu Düzenleme',
    'delete_bot_command' => 'Bot Komutu Silme',
    'update_bot_settings' => 'Bot Ayarlarını Güncelleme'
    // Diğer botla ilgili eylemler buraya eklenebilir.
];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Denetim Kaydı - <?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?>)</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --accent-color: #3CB371;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --danger-color: #ed4245;
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            overflow: hidden;
        }

        #movesidebar {
            width: 220px;
            height: 100vh;
            background-color: var(--secondary-bg);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
        }

        #main-content {
            flex: 1;
            height: 100vh;
            overflow-y: auto;
            padding: 24px;
        }

        .nav-item {
            padding: 8px 12px;
            margin: 4px 8px;
            border-radius: 6px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }

        .nav-item:hover {
            background-color: rgba(79, 84, 92, 0.3);
            color: var(--text-primary);
            transform: translateX(4px);
        }

        .nav-item.active {
            background-color: var(--accent-color);
            color: var(--text-primary);
            font-weight: 500;
        }

        .form-input, select.form-input {
            background-color: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 10px;
            width: 100%;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-secondary {
            background-color: rgba(79, 84, 92, 0.5);
            color: var(--text-primary);
        }

        .log-item {
            display: flex;
            align-items: center;
            padding: 12px;
            background-color: rgba(0, 0, 0, 0.15);
            border-radius: 6px;
            margin-bottom: 8px;
            transition: background-color 0.2s ease;
        }

        .log-item:hover {
            background-color: rgba(0, 0, 0, 0.25);
        }

        .form-section {
            background-color: rgba(0, 0, 0, 0.1);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 6px;
            display: block;
        }

        .details-content {
            display: none;
            margin-top: 8px;
            padding: 10px;
            background-color: rgba(0, 0, 0, 0.2);
            border-radius: 6px;
            font-size: 12px;
        }

        .details-content pre {
            white-space: pre-wrap;
            word-break: break-word;
        }

        @media (max-width: 768px) {
            #movesidebar {
                width: 200px;
            }
            #main-content {
                padding: 16px;
            }
            .nav-item {
                font-size: 13px;
                padding: 6px 10px;
            }
            .btn {
                padding: 6px 12px;
                font-size: 13px;
            }
            .log-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 640px) {
            #movesidebar {
                width: 180px;
            }
            #main-content {
                padding: 12px;
            }
        }
    </style>
</head>
<body class="flex h-screen">
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-base">Bot Ayarları</h1>
            <p class="text-xs text-gray-400 mt-1 truncate"><?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?>)</p>
        </div>
        <nav class="flex-1 p-3 overflow-y-auto">
            <div class="space-y-2">
                <a href="manage_bots.php?server_id=<?php echo $server_id; ?>&bot_id=<?php echo $bot_id; ?>" class="nav-item">
                    <i class="fas fa-robot w-4"></i>
                    <span>Bot Komutları & Ayarları</span>
                </a>
                <a href="bot_audit_log.php?server_id=<?php echo $server_id; ?>&bot_id=<?php echo $bot_id; ?>" class="nav-item active">
                    <i class="fas fa-history w-4"></i>
                    <span>Denetim Kaydı</span>
                </a>
                </div>
        </nav>
        <div class="p-3 border-t border-gray-800">
            <a href="manage_bots.php?server_id=<?php echo $server_id; ?>" class="nav-item">
                <i class="fas fa-arrow-left w-4"></i>
                <span>Geri</span>
            </a>
        </div>
    </div>

    <div id="main-content" class="flex flex-col">
        <div class="max-w-4xl mx-auto w-full">
            <h2 class="text-lg font-semibold mb-6">Bot Denetim Kaydı</h2>
            <div class="form-section flex gap-4">
                <div class="flex-1">
                    <label for="filter-action" class="form-label">Eylem Türü</label>
                    <select id="filter-action" class="form-input">
                        <option value="">Tümü</option>
                        <?php foreach ($action_labels as $action_key => $action_label): ?>
                            <option value="<?php echo $action_key; ?>" <?php echo $filter_action === $action_key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action_label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-1">
                    <label for="filter-user" class="form-label">Kullanıcı</label>
                    <select id="filter-user" class="form-input">
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button id="apply-filters" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                </div>
            </div>
            <div class="form-section">
                <h3 class="text-base font-semibold mb-4">Kayıtlar</h3>
                <div id="audit-log-content" class="space-y-2">
                    <?php if (empty($audit_logs)): ?>
                        <p class="text-sm text-gray-400">Henüz bu bot için denetim kaydı bulunmamaktadır.</p>
                    <?php else: ?>
                        <?php foreach ($audit_logs as $log): ?>
                            <div class="log-item">
                                <div class="flex-1">
                                    <p class="text-sm font-medium">
                                        <span class="text-accent-color"><?php echo htmlspecialchars($log['username'] ?: 'Bilinmeyen Kullanıcı', ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php echo htmlspecialchars($action_labels[$log['action']] ?? $log['action'], ENT_QUOTES, 'UTF-8'); ?> işlemini yaptı
                                    </p>
                                    <p class="text-xs text-gray-400"><?php echo date('d.m.Y H:i', strtotime($log['created_at'])); ?></p>
                                    <div class="details-content">
                                        <pre><?php echo htmlspecialchars(json_encode(json_decode($log['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                                    </div>
                                </div>
                                <button class="toggle-details btn btn-secondary text-xs p-1 ml-2" data-log-id="<?php echo $log['id']; ?>">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(function() {
            // Apply filters
            $("#apply-filters").on("click", function() {
                var action = $("#filter-action").val();
                var userId = $("#filter-user").val();
                var url = "bot_audit_log.php?server_id=<?php echo $server_id; ?>&bot_id=<?php echo $bot_id; ?>";
                if (action) url += "&action=" + encodeURIComponent(action);
                if (userId) url += "&user_id=" + encodeURIComponent(userId);
                window.location.href = url;
            });

            // Toggle log details
            $(document).on("click", ".toggle-details", function() {
                var logId = $(this).data("log-id");
                var details = $(this).closest(".log-item").find(".details-content");
                var icon = $(this).find("i");
                details.slideToggle(150, function() {
                    icon.toggleClass("fa-chevron-down fa-chevron-up");
                });
            });
        });
    </script>
</body>
</html>