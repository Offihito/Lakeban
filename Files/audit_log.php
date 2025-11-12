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

// Get server ID from URL
if (!isset($_GET['id'])) {
    die("Sunucu ID'si eksik.");
}

$server_id = $_GET['id'];

// Check if the user is the owner or has manage_roles permission
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND (owner_id = ? OR id IN (SELECT server_id FROM user_roles WHERE user_id = ? AND role_id IN (SELECT id FROM roles WHERE permissions LIKE '%manage_roles%')))");
$stmt->execute([$server_id, $_SESSION['user_id'], $_SESSION['user_id']]);
if ($stmt->rowCount() === 0) {
    header("Location: sayfabulunamadı");
    exit();
}

// Fetch server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Fetch audit logs
$filter_action = isset($_GET['action']) ? $_GET['action'] : '';
$filter_user = isset($_GET['user_id']) ? $_GET['user_id'] : '';

$sql = "SELECT al.*, u.username FROM role_audit_log al LEFT JOIN users u ON al.user_id = u.id WHERE al.server_id = ?";
$params = [$server_id];

if ($filter_action) {
    $sql .= " AND al.action = ?";
    $params[] = $filter_action;
}
if ($filter_user) {
    $sql .= " AND al.user_id = ?";
    $params[] = $filter_user;
}

$sql .= " ORDER BY al.created_at DESC LIMIT 50";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$audit_logs = $stmt->fetchAll();

// Fetch users for filter
$stmt = $db->prepare("SELECT DISTINCT u.id, u.username FROM users u JOIN user_roles ur ON u.id = ur.user_id WHERE ur.server_id = ?");
$stmt->execute([$server_id]);
$users = $stmt->fetchAll();

// Tema ayarlarını yükleme
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
    error_log("Tema ayarları alınırken bir hata oluştu: " . $e->getMessage());
}

// Language settings
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
    'create_role' => 'Rol Oluşturma',
    'edit_role' => 'Rol Düzenleme',
    'delete_role' => 'Rol Silme',
    'create_category' => 'Kategori Oluşturma',
    'delete_category' => 'Kategori Silme',
    'update_roles_order' => 'Rol Sıralama Güncelleme',
    'add_users_to_role' => 'Kullanıcıları Role Ekleme',
    'remove_users_from_role' => 'Kullanıcıları Rolden Kaldırma'
];
?>

<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh; --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>; --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Denetim Kaydı - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
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

        /* === AYDINLIK TEMA === */
        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
        }

        /* === KOYU TEMA === */
        .dark-theme {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
        }

        /* === ÖZEL TEMA === */
        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            --accent-color: var(--custom-secondary-color);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            overflow: hidden;
        }

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
                <a href="audit_log?id=<?php echo $server_id; ?>" class="nav-item active">
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

    <div id="main-content" class="flex flex-col">
        <div class="max-w-4xl mx-auto w-full">
            <h2 class="text-lg font-semibold mb-6">Denetim Kaydı</h2>
            <div class="form-section flex gap-4">
                <div class="flex-1">
                    <label for="filter-action" class="form-label">Eylem Türü</label>
                    <select id="filter-action" class="form-input">
                        <option value="">Tümü</option>
                        <option value="create_role" <?php echo $filter_action === 'create_role' ? 'selected' : ''; ?>>Rol Oluşturma</option>
                        <option value="edit_role" <?php echo $filter_action === 'edit_role' ? 'selected' : ''; ?>>Rol Düzenleme</option>
                        <option value="delete_role" <?php echo $filter_action === 'delete_role' ? 'selected' : ''; ?>>Rol Silme</option>
                        <option value="create_category" <?php echo $filter_action === 'create_category' ? 'selected' : ''; ?>>Kategori Oluşturma</option>
                        <option value="delete_category" <?php echo $filter_action === 'delete_category' ? 'selected' : ''; ?>>Kategori Silme</option>
                        <option value="update_roles_order" <?php echo $filter_action === 'update_roles_order' ? 'selected' : ''; ?>>Rol Sıralama Güncelleme</option>
                        <option value="add_users_to_role" <?php echo $filter_action === 'add_users_to_role' ? 'selected' : ''; ?>>Kullanıcıları Role Ekleme</option>
                        <option value="remove_users_from_role" <?php echo $filter_action === 'remove_users_from_role' ? 'selected' : ''; ?>>Kullanıcıları Rolden Kaldırma</option>
                    </select>
                </div>
                <div class="flex-1">
                    <label for="filter-user" class="form-label">Kullanıcı</label>
                    <select id="filter-user" class="form-input">
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_user === $user['id'] ? 'selected' : ''; ?>>
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
                        <p class="text-sm text-gray-400">Henüz denetim kaydı bulunmamaktadır.</p>
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
                var url = "audit_log?id=<?php echo $server_id; ?>";
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