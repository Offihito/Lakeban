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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Unable to connect to the database. Please try again later.");
}

// Kullanıcının tema ayarlarını al
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
    error_log("Theme settings error: " . $e->getMessage());
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Get server ID from URL
if (!isset($_GET['id'])) {
    die("Server ID is missing.");
}

$server_id = $_GET['id'];

// 1. Check if the server exists
$stmt_server = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt_server->execute([$server_id]);
$server_info = $stmt_server->fetch();

if (!$server_info) {
    die("Sunucu bulunamadı.");
}
$server_owner_id = $server_info['owner_id'];

// 2. Check if the user is a member of the server
$stmt_member = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt_member->execute([$server_id, $_SESSION['user_id']]);
if ($stmt_member->rowCount() === 0) {
    header("Location: sayfabulunamadı");
    exit();
}

// 3. Check if the user is banned
$stmt_ban = $db->prepare("SELECT * FROM banned_users WHERE user_id = ? AND server_id = ?");
$stmt_ban->execute([$_SESSION['user_id'], $server_id]);
if ($stmt_ban->fetch()) {
    die("Bu sunucudan banlandınız. Sunucuya erişemezsiniz.");
}

// ==================================================================
// == ADIM 1: MERKEZİ İZİN SİSTEMİ EKLENDİ ==
// ==================================================================
$is_owner = ($server_owner_id == $_SESSION['user_id']);
$user_permissions = [];

$stmt_perms = $db->prepare("
    SELECT r.permissions
    FROM user_roles ur
    JOIN roles r ON ur.role_id = r.id
    WHERE ur.user_id = ? AND ur.server_id = ?
");
$stmt_perms->execute([$_SESSION['user_id'], $server_id]);
$roles_permissions = $stmt_perms->fetchAll(PDO::FETCH_COLUMN);

$has_administrator = false;
foreach ($roles_permissions as $perm_json) {
    $perms = json_decode($perm_json, true);
    if (is_array($perms)) {
        if (in_array('administrator', $perms)) {
            $has_administrator = true;
            break; 
        }
        $user_permissions = array_merge($user_permissions, $perms);
    }
}
$user_permissions = array_unique($user_permissions);

if ($is_owner || $has_administrator) {
    $all_possible_permissions = [
        'manage_channels', 'manage_roles', 'manage_server',
        'kick', 'ban', 'manage_messages',
        'view_channels', 'send_messages', 'attach_files',
        'administrator'
    ];
    $user_permissions = $all_possible_permissions;
}

// ==================================================================

// YENİ KONTROL - Yukarıdaki merkezi sistemden alınıyor
$hasKickPermission = in_array('kick', $user_permissions);

// Kullanıcı sunucu kurucusu mu kontrol et
$isOwner = ($server_owner_id == $_SESSION['user_id']);

// Bu değişkenleri JavaScript'e aktar (GÜNCELLENDİ)
echo "<script>";
echo "const currentUserId1 = " . json_encode($_SESSION['user_id']) . ";";
echo "var hasKickPermission = " . ($hasKickPermission ? 'true' : 'false') . ";";
echo "var isOwner = " . ($isOwner ? 'true' : 'false') . ";";
// Yeni eklenenler
echo "const userPermissions = " . json_encode(array_values($user_permissions)) . ";";
echo "</script>";

// Fetch the server name
$stmt = $db->prepare("SELECT name FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_name = $stmt->fetchColumn();

// Check if the user is the owner of the server
$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_owner_id = $stmt->fetchColumn();

$is_owner = ($server_owner_id == $_SESSION['user_id']);

// Handle ban user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ban_user'])) {
    $user_id = $_POST['user_id'];
    $server_id_post = $_POST['server_id'];

    // YENİ KONTROL
    $hasBanPermission = in_array('ban', $user_permissions);

    if ($hasBanPermission) {
        // Ban the user
        $stmt = $db->prepare("INSERT INTO banned_users (user_id, server_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $server_id_post]);

        // Redirect back to the server page
        header("Location: server?id=" . $server_id_post);
        exit;
    } else {
        die("You do not have permission to ban users.");
    }
}

// YENİ KONTROL
$hasBanPermission = in_array('ban', $user_permissions);

// Bu değişkeni JavaScript'e aktar
echo "<script>";
echo "var hasBanPermission = " . ($hasBanPermission ? 'true' : 'false') . ";";
echo "</script>";

// Fetch user roles for the server
$stmt = $db->prepare("SELECT role_id FROM user_roles WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);
$user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// YENİ KONTROL
$has_manage_channels_permission = in_array('manage_channels', $user_permissions);

// Fetch categories for the server
$stmt = $db->prepare("SELECT * FROM categories WHERE server_id = ? ORDER BY name ASC");
$stmt->execute([$server_id]);
$categories = $stmt->fetchAll();

// Fetch channels for the server, filtering based on user roles
if (empty($user_roles) && !$is_owner) {
    $query = "
        SELECT c.*,
               (SELECT COUNT(*)
                FROM messages1 m
                LEFT JOIN user_read_messages urm ON urm.channel_id = c.id AND urm.user_id = ?
                WHERE m.channel_id = c.id
                AND (urm.last_read_message_id IS NULL OR m.id > urm.last_read_message_id)) AS unread_count
        FROM channels c
        WHERE c.server_id = ?
        AND c.restricted_to_role_id IS NULL
        ORDER BY c.position ASC
    ";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['user_id'], $server_id]);
} else {
    $query = "
        SELECT c.*,
               (SELECT COUNT(*)
                FROM messages1 m
                LEFT JOIN user_read_messages urm ON urm.channel_id = c.id AND urm.user_id = ?
                WHERE m.channel_id = c.id
                AND (urm.last_read_message_id IS NULL OR m.id > urm.last_read_message_id)) AS unread_count
        FROM channels c
        WHERE c.server_id = ?
        AND (c.restricted_to_role_id IS NULL
             OR c.restricted_to_role_id IN (" . (empty($user_roles) ? '0' : implode(',', $user_roles)) . ")
             OR ? = (SELECT owner_id FROM servers WHERE id = ?))
        ORDER BY c.position ASC
    ";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['user_id'], $server_id, $_SESSION['user_id'], $server_id]);
}
$channels = $stmt->fetchAll();

// Varsayılan kanal seçimi
if (!isset($_GET['channel_id']) && !empty($channels)) {
    foreach ($channels as $channel) {
        if ($channel['restricted_to_role_id'] === null || in_array($channel['restricted_to_role_id'], $user_roles)) {
            $default_channel_id = $channel['id'];
            $channel_id = $default_channel_id;
            break;
        }
    }
    if (isset($default_channel_id)) {
        header("Location: server?id=" . $server_id . "&channel_id=" . $default_channel_id);
        exit;
    }
    if (!isset($default_channel_id)) {
        die("Bu sunucuda erişebileceğiniz bir kanal bulunamadı.");
    }
} else {
    $channel_id = $_GET['channel_id'] ?? null;
}

// Group channels by categories
$channels_by_category = [];
foreach ($channels as $channel) {
    $category_id = $channel['category_id'] ?? 'uncategorized';
    if (!isset($channels_by_category[$category_id])) {
        $channels_by_category[$category_id] = [];
    }
    $channels_by_category[$category_id][] = $channel;
}

$stmt = $db->prepare("SELECT name, banner FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_info_details = $stmt->fetch();
$server_name = $server_info_details['name'];
$banner_url = $server_info_details['banner'];

// Handle channel creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_channel'])) {
    if ($has_manage_channels_permission) {
        $channel_name = $_POST['channel_name'];
        $stmt = $db->prepare("INSERT INTO channels (server_id, name) VALUES (?, ?)");
        $stmt->execute([$server_id, $channel_name]);
        header("Location: server?id=" . $server_id);
        exit;
    } else {
        die("You do not have permission to create channels.");
    }
}

// Handle channel editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_channel'])) {
    if ($has_manage_channels_permission) {
        $channel_id_edit = $_POST['channel_id'];
        $new_channel_name = $_POST['new_channel_name'];
        $stmt = $db->prepare("UPDATE channels SET name = ? WHERE id = ?");
        $stmt->execute([$new_channel_name, $channel_id_edit]);
        header("Location: server?id=" . $server_id);
        exit;
    } else {
        die("You do not have permission to edit channels.");
    }
}

// Handle channel deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_channel'])) {
    if ($has_manage_channels_permission) {
        $channel_id_delete = $_POST['channel_id'];
        $stmt = $db->prepare("DELETE FROM channels WHERE id = ?");
        $stmt->execute([$channel_id_delete]);
        header("Location: server?id=" . $server_id);
        exit;
    } else {
        die("You do not have permission to delete channels.");
    }
}

// Fetch user profile data
$stmt = $db->prepare("
    SELECT u.avatar_url
    FROM user_profiles u
    JOIN server_members s ON u.user_id = s.user_id
    WHERE s.server_id = ? AND s.user_id = ?
");
$stmt->execute([$server_id, $_SESSION['user_id']]);
$user_profile = $stmt->fetch();
$avatar_url = $user_profile['avatar_url'] ?? null;

// Fetch current user's username
$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user_username = $stmt->fetchColumn();

// Fetch server members with their roles, colors, and last_activity
$stmt = $db->prepare("
    SELECT
        us.id AS user_id,
        up.avatar_url,
        us.status,
        us.username,
        up.display_username,
        GROUP_CONCAT(r.name ORDER BY r.importance DESC, r.id ASC) AS role_names,
        GROUP_CONCAT(r.color ORDER BY r.importance DESC, r.id ASC) AS role_colors,
        GROUP_CONCAT(r.id ORDER BY r.importance DESC, r.id ASC) AS role_ids,
        us.last_activity
    FROM
        users us
    JOIN
        server_members s ON us.id = s.user_id
    LEFT JOIN
        user_profiles up ON us.id = up.user_id
    LEFT JOIN
        user_roles ur ON us.id = ur.user_id AND ur.server_id = s.server_id
    LEFT JOIN
        roles r ON ur.role_id = r.id
    WHERE
        s.server_id = ?
    GROUP BY
        us.id
");
$stmt->execute([$server_id]);
$server_members = $stmt->fetchAll();

// Rollerin importance değerlerini çek
$stmt = $db->prepare("SELECT name, importance FROM roles WHERE server_id = ? ORDER BY importance DESC, id ASC");
$stmt->execute([$server_id]);
$role_importance = [];
foreach ($stmt->fetchAll() as $role) {
    $role_importance[$role['name']] = $role['importance'];
}

// Group members by their highest role (sadece online olanlar)
$role_groups = [];
$online_members = [];
$offline_members = [];
foreach ($server_members as $member) {
    if ($member['status'] === 'offline') {
        $offline_members[] = $member;
        continue;
    }
    $roles = explode(',', $member['role_names'] ?? '');
    $colors = explode(',', $member['role_colors'] ?? '');
    error_log("User {$member['username']} roles: " . print_r($roles, true));
    if (empty($roles[0])) {
        $online_members[] = $member;
    } else {
        $highest_role = $roles[0];
        $highest_color = $colors[0];
        if (!isset($role_groups[$highest_role])) {
            $role_groups[$highest_role] = [];
        }
        $member['highest_color'] = $highest_color;
        $role_groups[$highest_role][] = $member;
    }
}

uksort($role_groups, function($a, $b) use ($role_importance) {
    $importance_a = $role_importance[$a] ?? 0;
    $importance_b = $role_importance[$b] ?? 0;
    return $importance_b <=> $importance_a;
});

// Sunucunun doğrulanmış olup olmadığını kontrol et
$stmt_verified = $db->prepare("SELECT verified FROM servers WHERE id = ?");
$stmt_verified->execute([$server_id]);
$server_verified = $stmt_verified->fetchColumn() ?? false;

// Fetch current user's roles and their colors
$stmt = $db->prepare("
    SELECT r.name, r.color
    FROM roles r
    JOIN user_roles ur ON r.id = ur.role_id
    WHERE ur.user_id = ? AND ur.server_id = ?
    ORDER BY r.importance DESC
");
$stmt->execute([$_SESSION['user_id'], $server_id]);
$user_roles_with_color = $stmt->fetchAll();

$highest_role_color = $user_roles_with_color[0]['color'] ?? '#ffffff';
$highest_role_color = htmlspecialchars($highest_role_color, ENT_QUOTES, 'UTF-8');

// Sol sidebar için kullanıcının sunucu listesini çek
$stmt = $db->prepare("
SELECT
    s.id,
    s.name,
    s.profile_picture,
    sm.position,
    COALESCE((
        SELECT COUNT(DISTINCT c.id)
        FROM channels c
        LEFT JOIN user_read_messages urm ON urm.channel_id = c.id AND urm.user_id = ?
        LEFT JOIN (
            SELECT channel_id, MAX(id) as latest_message_id
            FROM messages1
            GROUP BY channel_id
        ) latest_msg ON c.id = latest_msg.channel_id
        WHERE c.server_id = s.id
            AND c.type IN ('text', 'announcement')
            AND (c.restricted_to_role_id IS NULL
                 OR c.restricted_to_role_id IN (
                     SELECT role_id FROM user_roles WHERE user_id = ? AND server_id = s.id
                 )
                 OR s.owner_id = ?)
            AND (latest_msg.latest_message_id IS NOT NULL
                 AND (urm.last_read_message_id IS NULL
                      OR latest_msg.latest_message_id > urm.last_read_message_id))
    ), 0) AS unread_channel_count
FROM servers s
JOIN server_members sm ON s.id = sm.server_id
WHERE sm.user_id = ?
GROUP BY s.id, s.name, s.profile_picture, sm.position
ORDER BY sm.position ASC
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$servers = $stmt->fetchAll();

// Başlangıç mesajları
$initialMessages = "[]";
if ($channel_id) {
    $stmt_messages = $db->prepare("SELECT * FROM messages1 WHERE channel_id = ? ORDER BY created_at ASC");
    $stmt_messages->execute([$channel_id]);
    $messages = $stmt_messages->fetchAll(PDO::FETCH_ASSOC);

    // JSON'a çevir ve hata kontrolü yap
    $json_output = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (json_last_error() === JSON_ERROR_NONE) {
        $initialMessages = $json_output;
    } else {
        error_log("json_encode hatası: " . json_last_error_msg());
        $initialMessages = "[]";
    }
}

// JavaScript'e güvenli aktarım
echo "<script>";
echo "var initialMessages = " . $initialMessages . ";";
echo "</script>";

// Check write permissions for the selected channel
$has_write_permission = $is_owner; // Owners always have write permission

if (!$has_write_permission) {
    $stmt = $db->prepare("SELECT permissions, restricted_to_role_id FROM channels WHERE id = ?");
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch();
    error_log("Channel data for channel {$channel_id}: " . print_r($channel, true));

    if ($channel) {
        // Check if user has access to the channel
        if ($channel['restricted_to_role_id'] === null || in_array($channel['restricted_to_role_id'], $user_roles)) {
            $permissions = json_decode($channel['permissions'] ?? '{}', true);
            error_log("Channel permissions: " . print_r($permissions, true));

            if (is_array($permissions)) {
                $write_allowed_roles = $permissions['write_allowed_roles'] ?? [];
                $write_denied_roles = $permissions['write_denied_roles'] ?? [];
                $write_allowed_users = $permissions['write_allowed_users'] ?? [];
                $write_denied_users = $permissions['write_denied_users'] ?? [];

                if (in_array($_SESSION['user_id'], $write_allowed_users)) {
                    $has_write_permission = true;
                    error_log("Write permission granted: User is in write_allowed_users");
                } elseif (in_array($_SESSION['user_id'], $write_denied_users)) {
                    $has_write_permission = false;
                    error_log("Write permission denied: User is in write_denied_users");
                } elseif (!empty($write_allowed_roles)) {
                    foreach ($user_roles as $role_id) {
                        if (in_array($role_id, $write_allowed_roles)) {
                            $has_write_permission = true;
                            error_log("Write permission granted: Role {$role_id} is in write_allowed_roles");
                            break;
                        }
                    }
                } else {
                    // Check manage_channels permission
                    $has_manage_channels_permission = false;
                    foreach ($user_roles as $role_id) {
                        $stmt = $db->prepare("SELECT permissions FROM roles WHERE id = ?");
                        $stmt->execute([$role_id]);
                        $role_permissions = json_decode($stmt->fetchColumn() ?? '{}', true);
                        error_log("Role {$role_id} permissions: " . print_r($role_permissions, true));
                        if (is_array($role_permissions) && in_array('manage_channels', $role_permissions)) {
                            $has_manage_channels_permission = true;
                            error_log("Manage channels permission found for role {$role_id}");
                            break;
                        }
                    }
                    // If no specific permissions, allow writing unless denied
                    if ($has_manage_channels_permission || empty($write_denied_roles)) {
                        $has_write_permission = true;
                        error_log("Write permission granted: Manage channels or no write_denied_roles");
                    }
                }
            } else {
                // No permissions set, check manage_channels
                foreach ($user_roles as $role_id) {
                    $stmt = $db->prepare("SELECT permissions FROM roles WHERE id = ?");
                    $stmt->execute([$role_id]);
                    $role_permissions = json_decode($stmt->fetchColumn() ?? '{}', true);
                    error_log("Role {$role_id} permissions (no channel permissions): " . print_r($role_permissions, true));
                    if (is_array($role_permissions) && in_array('manage_channels', $role_permissions)) {
                        $has_write_permission = true;
                        error_log("Write permission granted: Manage channels permission found");
                        break;
                    }
                }
            }
        } else {
            error_log("User has no access to channel {$channel_id} due to restricted_to_role_id");
        }
    } else {
        error_log("Channel {$channel_id} not found");
    }
}
function getServerEmojis($db, $server_id) {
    $stmt = $db->prepare("SELECT emoji_name, emoji_url FROM server_emojis WHERE server_id = ?");
    $stmt->execute([$server_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$stmt = $db->prepare("
    SELECT * FROM servers
    WHERE id = ?
    AND (
        owner_id = ?
        OR id IN (
            SELECT server_id
            FROM user_roles
            WHERE user_id = ?
            AND role_id IN (
                SELECT id
                FROM roles
                WHERE permissions LIKE '%manage_roles%'
            )
        )
    )
");
$stmt->execute([$server_id, $_SESSION['user_id'], $_SESSION['user_id']]);
$has_access = $stmt->rowCount() > 0;
// Pass write permission to JavaScript
echo "<script>";
echo "var hasWritePermission = " . ($has_write_permission ? 'true' : 'false') . ";";
echo "console.log('Initial hasWritePermission:', " . ($has_write_permission ? 'true' : 'false') . ");";
echo "</script>";

// Language settings (1. PHP kodundan alındı)
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
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
 <title><?php echo htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/twemoji@latest/dist/twemoji.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/emoji-mart@latest/dist/browser.js"></script> <!-- Emoji Mart Kütüphanesi -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
     <style>
            body { 
            background-color: #36393f; 
            color: #dcddde;
            height: 100vh;
            -webkit-tap-highlight-color: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 1px;
            height: 1px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #2f3136;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #202225;
            border-radius: 4px;
            border: 2px solid #2f3136; /* Track ile uyumlu bir border */
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #2f3136;
        }
        .custom-scrollbar {
            scrollbar-width: thin; /* "auto" veya "thin" */
            scrollbar-color: #202225 #161616; /* thumb ve track rengi */
        }
        .role-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .role-bar .role {
            background-color: #7289da; /* Discord's role color */
            color: white;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 12px;
        }
        .server-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            font-size: 14px; /* Yazı boyutunu küçültmek için */
        }
        .server-name:hover::after {
            content: attr(data-title);
            position: absolute;
            background: #333;
            color: #fff;
            padding: 5px;
            border-radius: 5px;
            z-index: 10;
            white-space: normal;
            max-width: 200px;
            text-align: center;
            font-size: 14px; /* Tooltip yazı boyutunu da küçültmek için */
        }
        .hoverchannel {
            background-color: #333333;
            transition: all 0.3s ease;
        }
        
        .hoverchannel:hover {
            background-color: #414141;
        }
        
        .bg-cover {
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            width: 100%; /* Afişin genişliği */
            height: 135px; /* Afişin yüksekliği */
            position: absolute;
            top: 0;
            left: 0;
            z-index: 0; /* Afişin arka plana yerleşmesini sağlar */
        }
        .channel-sidebar {
            position: relative;
            z-index: 1; /* Metinlerin üstte olmasını sağlar */
        }
        .channel-list {
            margin-top: 15px; /* Banner yüksekliği kadar boşluk bırakır */
        }
        .text-channels-header {
            margin-top: 25px; /* Text Channels başlığını aşağı kaydırır */
        }
         /* Server Sidebar */
.server-sidebar {
    width: 72px; /* Sunucu sidebar'ın genişliği */
    background-color: #121212;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.5rem;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    z-index: 100;
}
#hovermessage {
    background-color: rgba(30, 30, 30, 255);
}

#hovermessage:hover {
    background-color: #333333;
}


.message-bg {
        background-color: rgba(30, 30, 30, 255); /* Tamamen siyah yerine koyu gri */
    }
    .message-text {
    word-wrap: break-word;
}
.edit-message-form {
    margin-top: 10px;
}

.edit-message-form textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #444;
    background-color: #333;
    color: #fff;
    border-radius: 5px;
}

.edit-message-form button {
    margin-top: 10px;
}

.edit-message-form .cancel-edit {
    background-color: transparent;
    border: none;
    cursor: pointer;
}

/* Yanıtlanan Mesaj İçin Yeni CSS */
.reply-message-container {
    background-color: #2f3136; /* Daha koyu bir arka plan rengi */
    border-left: 4px solid #7289da; /* Mavi bir kenarlık */
    padding: 8px;
    margin-bottom: 8px;
}
/* Yanıtlanan mesaj stil */
.reply-message-container {
    border-left: 3px solid #3CB371;
    padding: 8px;
    margin: 8px 0;
    background: #2A2A2A;
    border-radius: 8px;
}
/* Context Menu */
.context-menu {
    position: absolute;
    background-color: #2f3136;
    border: 1px solid #444;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    display: none;
}

.context-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.context-menu li {
    padding: 8px 16px;
    color: #dcddde;
    cursor: pointer;
}

.context-menu li:hover {
    background-color: #7289da;
    color: white;
}
.options-menu {
    position: absolute;
    right: 0;
    bottom: 100%; /* Menüyü mesajın üstüne yerleştir */
    z-index: 1000; /* Diğer öğelerin üzerinde görünmesini sağla */
    opacity: 0;
    transform: translateY(-10px);
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.options-menu.hidden {
    display: none;
}

.options-menu:not(.hidden) {
    opacity: 1;
    transform: translateY(0);
}
/* Üç nokta butonunu varsayılan olarak gizle */
.more-options {
    display: none;
    color: white !important;
    
}

/* Mesajın üzerine gelindiğinde üç nokta butonunu göster */
.message-bg:hover .more-options {
    display: block;
}
/* Üç nokta menüsündeki yazıların ve ikonların gri olmasını sağlar */
.options-menu li {
    color: #9CA3AF !important; /* Yazı rengini gri yapar */
    background-color: rgba(31,41,55,var(--tw-bg-opacity));
}

.options-menu li:hover {
    background-color: rgba(55,65,81,var(--tw-bg-opacity)); /* Hover rengini korur */
    color: white !important; /* Hover durumunda yazı rengini beyaz yapar */
}

/* İkonların gri olmasını sağlar */
.options-menu li i {
    color: #9CA3AF !important;
}

/* Hover durumunda ikonların beyaz olmasını sağlar */
.options-menu li:hover i {
    color: white !important;
}
/* Üç nokta butonunu varsayılan olarak gizle */
.more-options {
    display: none;
    position: absolute;
    right: 0;
    top: -10px; /* İkonu 10px yukarı taşı */
    background-color: Transparent; /* DM Sayfası ile aynı. */
    padding: 4px; /* İsteğe bağlı: İkonun etrafında boşluk bırakır */
    border-radius: 4px; /* İsteğe bağlı: Köşeleri yuvarlar */
    color: rgba(156,163,175,var(--tw-text-opacity)) !important; /* İkon rengini gri yap */
}

/* Mesajın üzerine gelindiğinde üç nokta butonunu göster */
.message-bg:hover .more-options {
    display: block;
}

/* Hover durumunda ikon rengini beyaz yap */
.more-options:hover {
    color: white !important;
    background-color: Transparent;
}
/* Formun veya kapsayıcı div'in arkaplan rengi */

/* Mesaj Giriş Alanı */
#message-input {
    color: white; /* Yazı rengi */
    border: none;
    padding: 10px;
    width: 100%;
        background: #363636;
        border-radius: 15px;
        transition: all 0.3s;
        border: 2px solid transparent;
    }

    .message-input:focus {
        border-color: #6366f1;
        box-shadow: 0 0 15px rgba(99,102,241,0.2);
    }
}

/* Gönder Butonu */
#send-button {
    background-color: #5865F2; /* Direkt mesajdaki mavi renk */
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
}

#send-button:disabled {
    background-color: #4752c4; /* Pasif durumdaki arkaplan rengi */
    opacity:0.5;
    cursor: not-allowed;
}

/* Emoji ve Dosya Yükleme Butonları */
#emoji-button, label[for="file-input"] {
    background-color: transparent;
    border: none;
    color: #A0A0A0; /* Direkt mesajdaki gri renk */
    cursor: pointer;
}

#emoji-button:hover, label[for="file-input"]:hover {
    color: white; /* Hover durumunda yazı rengi */
    background-color: #4A4A4A; /* Hover durumunda arkaplan rengi */
}
#typing-indicator {
    color: #fff;
    font-size: 14px;
    margin-top: 5px;
}

.typing-dots {
    display: inline-flex;
    align-items: center;
}

.typing-dots span {
    display: inline-block;
    width: 4px;
    height: 4px;
    background-color: #fff;
    border-radius: 50%;
    margin: 0 2px;
    animation: blink 1.4s infinite;
}

.typing-dots span:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-dots span:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes blink {
    0%, 100% {
        opacity: 0;
    }
    50% {
        opacity: 1;
    }
}
/* Genel Form Stili */
.message-form {
    display: flex;
    align-items: center;
    padding: 1rem;
    background-color: #161616;
    border-top: 1px solid #515151;
    border-radius: 0; /* Köşeleri keskin yap */
    margin-top: 0; /* Varsayılan olarak üst boşluk yok */
    margin-bottom: 0px; /* Alt boşluk */
}

/* Formu Alta Kaydırmak İçin */
.message-form.align-bottom {
    margin-top: 20px; /* Formu biraz aşağı kaydır */
}

/* Emoji ve Dosya Butonları */
.emoji-button, .file-label {
    color: #9CA3AF;
    background: none;
    border: none;
    padding: 0.5rem;
    border-radius: 0; /* Köşeleri keskin yap */
    cursor: pointer;
    transition: color 0.2s, background-color 0.2s;
}

.emoji-button:hover, .file-label:hover {
    color: #FFFFFF;
    background-color: #4B5563;
}

.icon {
    width: 1.25rem;
    height: 1.25rem;
}


/* Server.php için mesaj giriş alanı rengi */
.message-form.server-form .message-input {
    background-color: #2F3136; /* Server.php'deki farklı renk */
}

.message-input::placeholder {
    color: #9CA3AF;
}

/* Dosya Yükleme Alanı */
.file-input {
    display: none;
}

/* Gönder Butonu */
.send-button {
    padding: 0.5rem 1rem;
    background-color: #3B82F6;
    color: #FFFFFF;
    border: none;
    border-radius: 0; /* Köşeleri keskin yap */
    cursor: pointer;
    transition: background-color 0.2s;
}

.send-button:disabled {
    background-color: #6B7280;
    cursor: not-allowed;
}

.send-button:not(:disabled):hover {
    background-color: #2563EB;
}
.more-options {
    position: absolute;
    right: 0;
    top: 30%; /* Mesaj konteynırının ortasına hizala */
    transform: translateY(-50%); /* Dikeyde tam ortala */
    background-color: transparent;
    padding: 4px;
    border-radius: 4px;
    color: #9CA3AF;
    display: none; /* Varsayılan olarak gizli */
}
/* Masaüstünde Send butonunu gizle */
@media (min-width: 768px) {
  #send-button {
    display: none !important;
  }
  
  /* Masaüstünde input genişliğini ayarla */
  #message-input {
    margin-right: 0 !important;
  }
  
  #main-content {
      width: 60.3%;
      right: 18.6%;
  }
  
  #user-sidebar {
        right: 0%;
    }
  
}

/* Mobilde göster */
@media (max-width: 767px) {
  #send-button {
    display: block !important;
  }
}
.avatar-container {
    width: 40px; /* veya istediğiniz boyut */
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    position: relative;
}

.avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover; /* Görüntüyü oranlı olarak kırpar */
    object-position: center; /* Görüntüyü merkezler */
}
#popup-avatar {
    object-fit: cover;
    object-position: center;
}
#input-area {
    position: relative;
}

#create-server-modal {
    --primary-bg: #1a1b1e;
    --secondary-bg: #2d2f34;
    --accent-color: #3CB371;
    --text-primary: #ffffff;
    --text-secondary: #b9bbbe;
}

#create-server-modal .bg-\[\#2d2f34\] { /* Modal içeriği */
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

#create-server-modal .upload-area {
    border: 2px dashed rgba(255, 255, 255, 0.2);
    border-radius: 0.75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

#create-server-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: rgba(60, 179, 113, 0.1);
}

#create-server-modal .form-input {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    color: var(--text-primary);
    transition: all 0.3s ease;
}

#create-server-modal .form-input:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
}

#create-server-modal .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

#create-server-modal .btn-primary {
    background-color: var(--accent-color);
    color: white;
}

#create-server-modal .btn-primary:hover {
    background-color: #2E8B57;
    transform: translateY(-1px);
}

#create-server-modal .bg-gray-600 { /* İptal butonu */
    background-color: #36393f;
}

#create-server-modal .bg-gray-600:hover {
    background-color: #40444b;
}
.offline-user {
    opacity: 0.5;
}
/* Medya Ögelerinin boyutu */
.uploaded-media {
    max-width: 400px !important; /* Maksimum genişlik */
    max-height: 300px !important; /* Maksimum yükseklik */
    width: auto !important;
    height: auto !important;
    border-radius: 4px;
    margin-top: 8px;
    object-fit: contain; /* Orantılı küçültme */
}

/* Video konteynırı için ekstra kontrol */
video.uploaded-media {
    max-width: 100% !important;
    height: auto !important;
}

@keyframes modalEntry {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
.modalentryanimation {
    animation: modalEntry 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

/* Mobil uyumluluk */
@media (max-width: 768px) {
    .uploaded-media {
        max-width: 250px !important;
        max-height: 200px !important;
    }
}
/* Mobil cihazlar için mesaj konteynırı optimizasyonu */
@media (max-width: 767px) {
    #message-container {
        width: 100vw !important;
        padding: 4px 6px !important;
        overflow-x: hidden;
    }


    .message-text {
        font-size: 16px !important;
        line-height: 1.4 !important;
    }

    .avatar-container {
        width: 36px !important;
        height: 36px !important;
        margin-right: 8px !important;
    }

    .uploaded-media {
        max-width: 280px !important;
        max-height: 200px !important;
    }
}
   /* Özelleştirilmiş Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #2a2a2a;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
        background: var(--primary-gradient);
        border-radius: 10px;
        border: 2px solid #2a2a2a;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: var(--hover-gradient);
    }
        :root {
        --primary-gradient: linear-gradient(135deg, #3CB371 0%, #121212 100%);
        --hover-gradient: linear-gradient(135deg, #3CB371 0%, #121212 100%);
    }

.channel-drag-handle {
    transition: opacity 0.2s;
}
.sortable-chosen {
    background: rgba(63, 63, 70, 0.3) !important;
}
/* Dosya Önizleme Stilleri */
.preview-image, .preview-video {
    max-width: 300px;
    max-height: 180px;
    border-radius: 4px;
    object-fit: contain;
}

.file-icon-wrapper {
    padding: 1rem;
    background: #2d2d2d;
    border-radius: 8px;
    text-align: center;
}

.download-link {
    transition: color 0.2s;
}

.download-link:hover {
    color: #3CB371;
}

#file-preview-container {
    left: 1200px; /* Konumlandırma için özel değer */
    bottom: 80px;
    box-shadow: 0 0 15px rgba(0,0,0,0.2);
    border: none !important; /* Border tamamen kaldırıldı */
    background: transparent !important; /* Tam saydam arkaplan */
    backdrop-filter: none !important; /* Blur efekti kaldırıldı */
}

@media (max-width: 768px) {
    #file-preview-container {
        left: 20px;
        bottom: 70px;
        max-width: 250px;
        background: transparent !important; /* Mobil için de saydam */
        box-shadow: none; /* Mobilde gölgeyi kaldır */
    }
    
    
    #main-content {
        width: 100%;
        z-index: 20;
        height: 100vh;
    }
    
    #user-sidebar {
        width: 100%;
        z-index: 30;
        height: 100vh;
    }
    #channel-sidebar-x {
      width: 320px;
  }
  body {
      overflow-x: hidden;
  }
}
/* Dosya Önizleme Stilleri */
#file-preview-container {
    border: 1px solid transparent !important; /* Saydam border */
    backdrop-filter: blur(8px);
}

.preview-image, .preview-video {
    max-width: 280px;
    max-height: 160px;
    border-radius: 4px;
    object-fit: contain;
    background: #2f3136; /* Görseller için koyu arkaplan */
}

.file-icon-wrapper {
    padding: 1rem;
    background: transparent !important; /* Tamamen saydam */
    border-radius: 8px;
    border: 1px solid transparent !important;
}


/* Düzenleme Formu Stilleri */
.edit-message-form {
    background-color: #2f3136;
    border-left: 4px solid #3ba55d;
    padding: 12px;
    margin-top: 8px;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.edit-message-form textarea {
    width: 100%;
    background: #40444b !important;
    border: 1px solid #202225 !important;
    color: #dcddde;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 8px;
    resize: vertical;
    min-height: 100px;
}

/* Ortak Button Stilleri */
.reply-message-form button,
.edit-message-form button {
    transition: all 0.2s ease;
    padding: 8px 16px;
    border-radius: 4px;
    font-weight: 500;
}

.edit-message-form button[type="submit"] {
    background-color: #5865f2;
    color: white;
}

.edit-message-form button[type="submit"]:hover {
    background-color: #4752c4;
}


.edit-message-form .cancel-edit {
    background-color: #747f8d;
    color: white;
    margin-left: 8px;
}

.reply-message-form .cancel-reply:hover,
.edit-message-form .cancel-edit:hover {
    background-color: #5d6772;
}

/* Form Konteynır Pozisyonlaması */
.message-and-forms-container {
    position: relative;
    margin-bottom: 1.5rem;
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .edit-message-form {
        padding: 10px;
        margin-left: -10px;
        margin-right: -10px;
    }
    
    .edit-message-form textarea {
        min-height: 60px;
    }
}

/* User Profile Popup Styles */
#user-profile-popup {
    transition: opacity 0.4s ease, transform 0.4s ease;
    backdrop-filter: blur(5px);
}

#add-friend-btn {
    margin-top: 2px;
    background-color: #3ba55d;
}
#add-friend-btn:hover {
    background-color: #2d8c4d;
}

#go-to-profile-btn {
    background-color: #5865f2;
}

#go-to-profile-btn:hover {
    background-color: #4752c4;
}

#confirm-friend-request {
    background-color: #5865f2;
}

#confirm-friend-request:hover {
    background-color: #4752c4;
}

#user-profile-popup:not(.hidden) {
    opacity: 1;
    transform: scale(1) translateY(0);
}

#user-profile-popup.hidden {
    opacity: 0;
    transform: scale(0.9) translateY(-40px);
}

#user-profile-popup .bg-[#2f3136] {
    background-color: #2f3136;
    border-radius: 8px;
    width: 520px !important;
    height: 620px !important;
    max-width: 520px !important;
    max-height: 620px !important;
    min-width: 520px !important;
    min-height: 620px !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

#user-profile-popup .bg-[#2f3136]:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.4);
}

#popup-background {
    transition: transform 0.3s ease;
    height: 128px;
    width: 100%;
    top: 0;
    left: 0;
    z-index: 0;
}

#user-profile-popup:hover #popup-background {
    transform: scale(1.02);
}

#popup-status-indicator.online {
    background-color: #3ba55c;
}

#popup-status-indicator.offline {
    background-color: #747f8d;
}

.online-pulse {
    animation: pulse 2s infinite ease-in-out;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

#popup-roles .role-tag {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    color: #fff;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

#popup-roles .role-tag:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

#popup-action-buttons {
    flex-wrap: wrap;
    gap: 0.5rem;
}

#popup-action-buttons button {
    font-size: 0.875rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    transition: transform 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}

#popup-username {
    line-height: 1.2;
    transition: color 0.2s ease;
}

#popup-username:hover {
    color: #5865f2;
}

#popup-bio {
    max-height: 80px;
    overflow-y: auto;
    word-break: break-word;
}

#popup-friends-list {
    max-height: 120px;
    overflow-y: auto;
}

#popup-friends-list::-webkit-scrollbar, #popup-bio::-webkit-scrollbar, .max-h-[400px]::-webkit-scrollbar {
    width: 6px;
}

#popup-friends-list::-webkit-scrollbar-track, #popup-bio::-webkit-scrollbar-track, .max-h-[400px]::-webkit-scrollbar-track {
    background: #2a2a2a;
    border-radius: 3px;
}

#popup-friends-list::-webkit-scrollbar-thumb, #popup-bio::-webkit-scrollbar-thumb, .max-h-[400px]::-webkit-scrollbar-thumb {
    background: #5865f2;
    border-radius: 3px;
}

#popup-friends-list .flex:hover {
    background-color: #36393f;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}
/* User Profile Popup Styles */
#user-profile-popup {
    --primary-bg: #0f0f0f;
    --secondary-bg: #1a1a1a;
    --accent-color: #3CB371;
    --text-primary: #e0e0e0;
    --text-secondary: #909090;
    font-family: 'Segoe UI', system-ui, sans-serif;
}

#user-profile-popup .bg-\[\#2f3136\] {
    background: var(--primary-bg);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 12px;
    box-shadow: 0 12px 24px rgba(0,0,0,0.3);
    overflow: hidden;
}



/* Avatar Section */
#user-profile-popup .relative .w-16 {
    width: 96px;
    height: 96px;
    border: 3px solid var(--accent-color);
    box-shadow: 0 0 24px rgba(60, 179, 113, 0.3);
}

/* Status Indicator */
#popup-status-indicator {
    width: 20px;
    height: 20px;
    border-width: 3px;
    bottom: 6px;
    right: 6px;
}

/* User Info Section */
#popup-username {
    font-size: 24px;
    font-weight: 700;
    color: var(--accent-color);
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Action Buttons */
#popup-action-buttons button {
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 600;
    transition: all 0.2s ease;
}

/* Details Container */
#user-profile-popup .bg-\[\#232428\] {
    background: var(--secondary-bg);
    border-radius: 12px;
    margin: 16px;
    border: 1px solid rgba(255,255,255,0.1);
}

/* Friends List Hover Effect */
#popup-friends-list .flex:hover {
    background: rgba(60, 179, 113, 0.1);
}

/* Scrollbar Styling */
#popup-friends-list::-webkit-scrollbar {
    width: 6px;
}

#popup-friends-list::-webkit-scrollbar-thumb {
    background: var(--accent-color);
    border-radius: 4px;
}

/* Animations */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#user-profile-popup:not(.hidden) {
    animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.voice-participants {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.voice-participant {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    border-radius: 4px;
    background-color: #2a2a2a;
}
.voice-participant img {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
}
.voice-participant .username {
    color: #dcddde;
    font-size: 14px;
}
.voice-participant .status-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: #43b581;
}

#voice-controls {
    background: linear-gradient(145deg, #1f2226, #2a2d33);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    max-width: 234px; /* Sağdan taşmayı önlemek için maksimum genişlik */
    width: calc(100% - 2rem); /* Ekran genişliğine göre esnek */
    right: 1rem; /* Sağ kenardan boşluk */
    bottom: 4rem; /* Alttan daha yakın pozisyon */
    border-radius: 12px;
    padding: 12px;
    transition: transform 0.3s ease, opacity 0.3s ease;
}

#voice-controls:not(.hidden) {
    transform: translateY(0);
    opacity: 1;
}

#voice-controls.hidden {
    transform: translateY(20px);
    opacity: 0;
    pointer-events: none;
}

#voice-controls #channel-name {
    font-size: 14px;
    color: #ffffff;
    font-weight: 600;
}

#voice-controls button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    transition: all 0.2s ease;
}

#voice-controls button:hover {
    transform: scale(1.05);
}

#toggle-mic.bg-red-600 i[data-lucide="mic"] {
    display: none;
}

#toggle-mic.bg-red-600::before {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    background: url('/images/mic-off.png') no-repeat center;
    background-size: contain;
}

#toggle-deafen.bg-red-600 i[data-lucide="headphones"] {
    display: none;
}

#toggle-deafen.bg-red-600::before {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    background: url('/images/headphones-off.png') no-repeat center;
    background-size: contain;
}

#call-time-counter {
    background: rgba(0, 0, 0, 0.2);
    padding: 4px 8px;
    border-radius: 6px;
    color: #a3a6aa;
}

@media (max-width: 768px) {
    #voice-controls {
        width: calc(100% - 1rem);
        right: 0.5rem;
        bottom: 3rem;
        padding: 8px;
    }

    #voice-controls button {
        width: 32px;
        height: 32px;
    }

    #call-time-counter {
        font-size: 0.65rem;
    }
}

.user-profile {
    position: fixed;
    bottom: 0;
    left: auto;
    width: 240px;
    background-color: #2f3136;
    padding: 12px;
    border-top: 1px solid #202225;
    z-index: 1000;
}

#remote-screen-share-modal {
    transition: opacity 0.3s ease;
}

#remote-screen-share-modal:not(.hidden) {
    opacity: 1;
}

#remote-screen-share-modal.hidden {
    opacity: 0;
    pointer-events: none;
}

#remote-screen-preview video {
    border-radius: 8px;
}
.message-avatar { /* Bu sınıfı avatarı içeren div'e ekleyeceğiz */
        /* Normalde avatarın görünmesi için gerekli stiller, örneğin: */
        /* display: flex; */
    }

    .message-header { /* Bu sınıfı kullanıcı adı ve zamanı içeren div'e ekleyeceğiz */
        /* Normalde başlığın görünmesi için gerekli stiller, örneğin: */
        /* display: flex; */
    }

    .stacked-message .message-avatar {
        display: none !important;
    }

    .stacked-message .message-header {
        display: none !important;
    }

    .stacked-message {
    margin-top: 0px !important; /* 2px yerine 1px */
    padding-top: 0px !important; /* 2px yerine 1px */
    padding-left: calc(1.94rem + 0.75rem) !important; /* Mevcut hizalamayı koru */
}


    /* Mobil için padding-left ayarı (opsiyonel, avatar boyutuna göre ayarlayın) */
    @media (max-width: 767px) {
        .stacked-message {
             padding-left: calc(37px + 8px) !important; /* Mobil avatar genişliği + boşluk */
        }
    }

    /* Avatarın olmadığı durumda yığılmış mesajın sola yaslanmaması için ek stil */
    .message-bg:not(.stacked-message) {
        padding: 4px !important; /* Veya orijinal padding değeri */
    }
    /* Mesaj konteynırı için temel stiller */
.message-and-forms-container {
    position: relative;
    margin-bottom: 0px; /* Normal mesajlar arası boşluk */
}


/* Stack'lenmiş mesaj konteynırları için margin ayarı */
.message-and-forms-container.consecutive-message-container {
    margin-bottom: 0px; /* Alttaki boşluğu azalt */
    
}

/* Normal mesajlar için border */
.message-container {
    border-radius: 2px !important;
}


/* Üç nokta butonu için düzeltmeler */
.more-options-button {
    position: absolute !important;
    right: 8px;
    top: 8px;
    margin-left: 0 !important;
    background: rgba(30, 30, 30, 0.9) !important;
    backdrop-filter: blur(2px);
    z-index: 1;
}

/* Hover durumunda boyut sabit */
.more-options-button:hover {
    transform: none !important;
    padding: 4px !important;
}

/* Açılır menü pozisyonu */
.options-menu {
    right: 8px !important;
    bottom: 100% !important;
    z-index: 1000;
}

/* Normal mesajlarla hizalama için */
.message-container:not(.consecutive-message) {
    margin-left: 4px !important;
    padding: 8px;
}

 #message-container {
    background-color: #1E1E1E; /* Açık gri tonu */
  }
  .message-bg {
    border-radius: 4px !important;
    transition: all 0.3s ease !important;
    padding: 0px 0px !important; /* Padding değerlerini azaltarak yüksekliği küçült */
    line-height: 1.3 !important; /* Satır yüksekliğini azalt */
}

.stacked-message {
    margin-top: 0px !important; /* Yığılmış mesajlar için üst boşluğu koru */
    padding-top: 0px !important; /* Üst dolguyu sıfırla */
    padding-left: calc(1.94rem + 0.75rem) !important; /* Hizalamayı koru */
}

/* Mobil cihazlar için ayarlar */
@media (max-width: 767px) {
    .message-bg {
        padding: 0px !important; /* Mobil için daha küçük padding */
        width: 99%; !important;
        margin-left: 0px !important;
        margin-right: 0px !important;
    }
    .stacked-message {
        padding-left: calc(37px + 8px) !important; /* Mobil avatar genişliği + boşluk */
    }
    .message-text {
        font-size: 15px !important; /* Mobil için yazı boyutunu biraz küçült */
        line-height: 1.2 !important; /* Satır yüksekliğini daha da azalt */
    }
}
/* Mesaj konteyneri için */
.message-container {
    padding-top: 0.5rem; /* p-3 yerine daha az padding */
    padding-bottom: 0rem;
    margin-bottom: 0rem; /* mb-4 yerine daha az margin */
}

/* Mesaj içeriği için */
.message-text {
    line-height: 1.3; /* Satır aralığını azalt */
    margin-top: 0rem; /* mt-1 yerine daha az margin */
}

/* Header için */
.message-header {
    margin-bottom: 0.25rem; /* mb-1 yerine daha az margin */
}
/* Mesaj metni font boyutu */
.message-text {
    font-size: 0.85rem; /* text-sm'den biraz daha küçük */
}
#screen-preview:fullscreen {
    background-color: #000;
    width: 100vw;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}

#screen-preview:fullscreen video {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

#toggle-fullscreen, #toggle-remote-fullscreen {
    transition: background-color 0.2s ease, transform 0.2s ease;
}
#toggle-fullscreen:hover, #toggle-remote-fullscreen:hover {
    transform: scale(1.05);
}

@media (max-width: 768px) {
    #toggle-fullscreen, #toggle-remote-fullscreen {
        padding: 8px 12px;
        font-size: 0.875rem;
    }
}

/* RTC Status Styling */
#rtc-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background-color: #2f3136; /* Discord tarzı koyu arka plan */
    border-radius: 4px;
    transition: background-color 0.3s ease;
}

#connection-status,
#ping-latency {
    font-size: 0.85rem;
    font-weight: 500;
    transition: color 0.3s ease;
}

/* Hover efekti */
#rtc-status:hover {
    background-color: #36393f;
}

/* Durum için animasyonlu nokta */
#connection-status::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
    transition: background-color 0.3s ease;
}

/* Durum renkleri */
#connection-status[data-state="Bağlandı"]::before,
#connection-status[data-state="Tamamlandı"]::before {
    background-color: #3BA55C; /* Yeşil */
}

#connection-status[data-state="Kontrol Ediliyor"]::before {
    background-color: #F1C40F; /* Sarı */
}

#connection-status[data-state="Bağlantı Kesildi"]::before,
#connection-status[data-state="Başarısız"]::before {
    background-color: #ED4245; /* Kırmızı */
}

#connection-status[data-state="Yeni"]::before,
#connection-status[data-state="Kapalı"]::before,
#connection-status[data-state="Bağlantı yok"]::before {
    background-color: #747F8D; /* Gri */
}
.channel-settings-menu {
    position: absolute;
    background-color: #1a1a1a; /* Modern koyu arka plan */
    border: 1px solid rgba(255, 255, 255, 0.1); /* İnce saydam kenarlık */
    border-radius: 8px; /* Yuvarlak köşeler */
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3); /* Derin gölge */
    z-index: 50; /* Diğer öğelerin üzerinde */
    min-width: 180px; /* Minimum genişlik */
    font-family: 'Segoe UI', system-ui, sans-serif; /* Modern font */
    overflow: hidden; /* Taşmaları önler */
    animation: slideIn 0.2s ease-out; /* Açılma animasyonu */
}

.channel-settings-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.channel-settings-menu li {
    display: flex;
    align-items: center;
    gap: 8px; /* İkon ile metin arası boşluk */
    padding: 10px 16px; /* Geniş tıklama alanı */
    color: #dcddde; /* Açık gri metin */
    font-size: 14px; /* Okunabilir font boyutu */
    font-weight: 500; /* Hafif kalın font */
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease, transform 0.1s ease; /* Pürüzsüz geçiş */
}

.channel-settings-menu li:hover {
    background-color: #5865f2; /* Discord mavisi hover efekti */
    color: #ffffff; /* Beyaz metin */
    transform: translateX(4px); /* Hafif kayma efekti */
}

.channel-settings-menu li i {
    color: #9ca3af; /* İkonlar için gri renk */
    width: 20px; /* Sabit ikon genişliği */
    transition: color 0.2s ease; /* İkonlar için renk geçişi */
}

.channel-settings-menu li:hover i {
    color: #ffffff; /* Hover'da ikonlar beyaz */
}

.channel-settings-menu li form button {
    background: transparent; /* Form butonu şeffaf */
    color: inherit; /* Metin rengini miras alır */
    border: none; /* Kenarlık yok */
    width: 100%; /* Tam genişlik */
    text-align: left; /* Sola hizalı metin */
    font-size: inherit; /* Font boyutunu miras alır */
    font-weight: inherit; /* Font kalınlığını miras alır */
}

.channel-settings-menu li form button:hover {
    background: transparent; /* Hover'da arka plan değişmez */
}

/* Açılma animasyonu */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Mobil uyumluluk */
@media (max-width: 768px) {
    .channel-settings-menu {
        min-width: 160px; /* Mobilde daha küçük menü */
        border-radius: 6px; /* Daha küçük yuvarlaklık */
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4); /* Daha hafif gölge */
    }
    
    .channel-settings-menu li {
        padding: 8px 12px; /* Mobilde daha küçük tıklama alanı */
        font-size: 13px; /* Daha küçük font */
    }
    #close-popup-btn {
        top: 34px;
    }
}
.message-bg:hover {
    background-color: rgba(255, 255, 255, 0.1) !important; /* Beyaz ve %10 opaklık */
}

/* Arama Paneli için Geçiş Efekti */
#search-panel {
    transition: opacity 0.3s ease-in-out;
}

/* Arama sonucundaki vurgulanan metin */
.highlight {
    background-color: rgba(250, 204, 21, 0.3); /* Tailwind yellow-400 %30 opaklıkta */
    color: #FBBF24; /* Tailwind yellow-400 */
    border-radius: 3px;
    padding: 1px 3px;
}

/* Arama sonucu öğesi için hover efekti */
.search-result-item:hover {
    background-color: #333333;
}
/* Mesajın üzerine gelince sabitleme butonunu göster */
.message-wrapper:hover .pin-button {
    opacity: 1;
}
.pin-button {
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
}
/* Sabitlenen mesajlar paneli */
#pinned-messages-bar {
    height: 300px;
    display: flex;
    flex-direction: column;
}

.pinned-message {
    background-color: #202225;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 8px;
    transition: background-color 0.2s;
}

.pinned-message:hover {
    background-color: #3d4147;
}

.pinned-message-header {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}

.pinned-message-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    margin-right: 8px;
}

.pinned-message-username {
    font-weight: 600;
    font-size: 14px;
    margin-right: 8px;
}

.pinned-message-date {
    font-size: 12px;
    color: #a3a6aa;
}

.pinned-message-content {
    font-size: 14px;
    line-height: 1.4;
    word-break: break-word;
}

.pinned-message-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 8px;
}

.pinned-message-actions button {
    background: none;
    border: none;
    color: #a3a6aa;
    cursor: pointer;
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 4px;
}

.pinned-message-actions button:hover {
    background-color: #4f545c;
    color: white;
}
.pinned-message-item {
    padding: 12px;
    border-radius: 8px;
    transition: background-color 0.2s ease;
    cursor: pointer;
}
.pinned-message-item:hover {
    background-color: #36393f;
}
.pinned-message-item .highlight {
    background-color: #5865f2;
    color: white;
    padding: 0 2px;
    border-radius: 2px;
}
.pinned-message-actions {
    display: none;
    margin-top: 8px;
}
.pinned-message-item:hover .pinned-message-actions {
    display: flex;
    gap: 8px;
}
.server-item {
    position: relative;
    transition: transform 0.2s ease;
}

.server-item.dragging {
    opacity: 0.5;
    transform: scale(0.9);
}

.server-item.drag-over {
    border: 2px dashed #3CB371;
    background: rgba(60, 179, 113, 0.1);
}
.video-thumbnail-container {
    position: relative;
    cursor: pointer;
    max-width: 400px;
    height: 225px;
    overflow: hidden;
    border-radius: 8px;
    background: #000;
}

.video-thumbnail-container > div {
    width: 100%;
    height: 100%;
    background-size: cover;
    background-position: center;
}

.play-button div {
    transition: transform 0.2s, opacity 0.2s;
}

.video-thumbnail-container:hover .play-button div {
    transform: scale(1.1);
    opacity: 0.9;
}
.youtube-preview {
    max-width: 400px;
    margin: 8px 0;
    border-radius: 8px;
    overflow: hidden;
}

.youtube-preview iframe {
    width: 100%;
    height: auto;
    aspect-ratio: 16/9;
}

@media (max-width: 768px) {
    .youtube-preview {
        max-width: 250px;
    }
}
.sticker-img {
    max-height: 120px;
    vertical-align: middle;
    margin: 4px 2px;
    object-fit: contain;
}
   @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(10px); }
        }
        
        .typing-animation {
            display: inline-flex;
            align-items: center;
            height: 20px;
        }
        
 @keyframes typing-bounce {
  0%, 60%, 100% { transform: translateY(0); }
  30% { transform: translateY(-4px); }
}

.typing-animation span {
  display: inline-block;
  width: 8px;  /* Önceki 4px'ten büyük */
  height: 8px; /* Önceki 4px'ten büyük */
  background: #7289da;
  border-radius: 50%;
  margin: 0 3px; /* Noktalar arası boşluk artırıldı */
  animation: typing-bounce 1.4s infinite ease-in-out;
}

.typing-animation span:nth-child(2) {
  animation-delay: 0.2s;
}

.typing-animation span:nth-child(3) {
  animation-delay: 0.4s;
}
@keyframes slide-in {
  from {
    transform: translateX(-10px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}
/* Yazıyor... animasyonu */
.typing-animation span {
    display: inline-block;
    width: 6px;
    height: 6px;
    margin-right: 2px;
    background: #3CB371;
    border-radius: 50%;
    animation: typing 1.4s infinite ease-in-out;
}

@keyframes typing {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}
/* Anket Oluşturma Modal Stilleri */
#create-poll-modal {
    --modal-bg: #2f3136;
    --input-bg: #40444b;
    --accent-color: #5865f2;
    --text-primary: #ffffff;
    --text-secondary: #b9bbbe;
}

#create-poll-modal .bg-\[\#2f3136\] {
    background: var(--modal-bg);
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    transform: translateY(0);
    transition: transform 0.3s ease, opacity 0.3s ease;
}

#create-poll-modal.hidden {
    opacity: 0;
    pointer-events: none;
}

#create-poll-modal:not(.hidden) {
    opacity: 1;
    animation:  modalEntry 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

#create-poll-modal .form-input {
    background: var(--input-bg);
    border: none;
    color: var(--text-primary);
    transition: all 0.2s ease;
}

#create-poll-modal .form-input:focus {
    outline: none;
    box-shadow: 0 0 0 2px var(--accent-color);
}

#create-poll-modal .btn {
    padding: 0.75rem 1.5rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

#create-poll-modal .btn:hover {
    transform: translateY(-1px);
}

/* Anket Konteynırı */
.poll-container {
    background: #2f3136;
    border-radius: 16px;
    padding: 1.25rem;
    margin: 0.75rem 0;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.poll-container:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.3);
}

/* Anket Başlığı */
.poll-container p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #ffffff;
    margin-bottom: 1rem;
    line-height: 1.4;
}

/* Anket Seçenekleri */
.poll-options {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.poll-option {
    position: relative;
    background: #40444b;
    border-radius: 10px;
    padding: 1rem;
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.15s ease;
    user-select: none;
}

.poll-option:hover {
    background: #4b5563;
    transform: translateX(4px);
}

.poll-option:active {
    transform: scale(0.98);
}

/* Seçilen Seçenek (Yeşil Vurgu) */
.poll-option.voted {
    background: #3ba55c;
    transform: scale(1.02);
    transition: background-color 0.3s ease, transform 0.3s ease;
}

.poll-option.voted:hover {
    background: #2d8f4a;
    transform: scale(1.02) translateX(4px);
}

.poll-option.voted .text-gray-400 {
    color: #e6f0e9;
}

/* Progress Bar */
.progress-bar {
    background: #36393f;
    height: 0.6rem;
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 0.5rem;
    transition: background 0.2s ease;
}

.progress {
    background: linear-gradient(90deg, #5865f2, #7b8bff);
    height: 100%;
    border-radius: 9999px;
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.poll-option.voted .progress {
    background: linear-gradient(90deg, #3ba55c, #4cc774);
}

/* Oy Sayısı */
.poll-option .text-gray-400 {
    font-size: 0.85rem;
    color: #b9bbbe;
    transition: color 0.2s ease;
}

.poll-option:hover .text-gray-400 {
    color: #ffffff;
}

/* Erişilebilirlik */
.poll-option:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.3);
}

#cancel-poll {
    background-color: #4b5563;
}

#sumbit-poll {
    background-color: #5865f2;
}

#cancel-poll:hover {
    background-color: #6b7280;
}

#sumbit-poll:hover {
    background-color:#4752c4;
}

#mobile-profile {
    display: none;
}
/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .poll-container {
        padding: 1rem;
        margin: 0.5rem;
        border-radius: 12px;
    }

    .poll-option {
        padding: 0.75rem;
        border-radius: 8px;
    }

    .progress-bar {
        height: 0.5rem;
    }

    .poll-container p {
        font-size: 1rem;
    }

    .poll-option .text-gray-400 {
        font-size: 0.8rem;
    }
        #screen-share-container .flex {
        flex-direction: column;
        gap: 16px;
    }
    
    /*Mobil Arayüz*/
    
    #user-profile {
        display: none;
    }
    #mobile-profile {
        position: fixed;
        display: flex;
        left: 0px;
        bottom: 0px;
        height: 8vh;
        width: 100%;
        z-index: 16;
        backdrop-filter: blur(10px);
        background: linear-gradient(to top, rgba(18,18,18,0.85), rgba(60,179,113, 0.15));
        background-size: 800% 800%;
        animation: waveGradient 2s ease infinite;
    }
    @keyframes waveGradient {
      0% {
        background-position: 0% 50%;
      }
      50% {
        background-position: 100% 50%;
      }
      100% {
        background-position: 0% 50%;
      }
    }
    .text-gradient {
        color: #ffffff;
    }
    .bg-nav-card {
        background-color: rgba(33,147,124,0.5);
    }
    .gradient-border {
        border-top: 2px solid;
        border-image: linear-gradient(135deg, #3CB371, #FFFFFF, #B0B0B0, #3CB371) 0.6;
        color: #C9DBD2;
   }
   .nav-shadow {
       box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
       border-radius: 16px;
       transition: box-shadow 0.5s ease;
   }
   .nav-shadow:hover {
       box-shadow: 0 2px 6px rgba(255, 255, 255, 0.7);
   }
   
    #channel-sidebar-x {
        padding-bottom: 8vh;
    }
    #server-sidebar {
        padding-bottom: 8vh;
    }
}

/* Ekran Okuyucular için */
.poll-option[aria-selected="true"] {
    background: #3ba55c;
    color: #ffffff;
}

.poll-option[aria-selected="true"] .text-gray-400 {
    color: #e6f0e9;
}
/* Ses Oynatıcı Stilleri */
.audio-player-container {
    background: #2a2a2a;
    border-radius: 12px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 400px;
    width: 100%;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.audio-player-container:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.audio-control {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.2s ease;
}

.audio-control:hover {
    transform: scale(1.05);
}

.audio-progress-bar {
    height: 6px;
    background: #4a5568;
    border-radius: 9999px;
    overflow: hidden;
    position: relative;
    cursor: pointer;
}

.audio-progress-bar .progress {
    height: 100%;
    background: linear-gradient(90deg, #3CB371, #2E8B57);
    transition: width 0.1s linear;
}

.volume-control {
    display: flex;
    align-items: center;
    gap: 8px;
}

.volume-slider {
    -webkit-appearance: none;
    width: 80px;
    height: 6px;
    background: #4a5568;
    border-radius: 9999px;
    outline: none;
    transition: background 0.2s ease;
}

.volume-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 14px;
    height: 14px;
    background: #3CB371;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.volume-slider::-webkit-slider-thumb:hover {
    background: #2E8B57;
}

.volume-slider::-moz-range-thumb {
    width: 14px;
    height: 14px;
    background: #3CB371;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.volume-slider::-moz-range-thumb:hover {
    background: #2E8B57;
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .audio-player-container {
        max-width: 300px;
        padding: 10px;
    }

    .audio-control {
        width: 36px;
        height: 36px;
    }

    .volume-slider {
        width: 60px;
    }
    #notification-panel {
        left: 0vh !important;
        width: 45vh !important;
    }
}

/*Bildirim paneli animasyonları*/
@keyframes fadeSlideIn {
  0% {
    opacity: 0;
    transform: translateY(-200px);
  }
  100% {
    opacity: 1;
    transform: translateY(0px);
  }
}
@keyframes fadeSlideOut {
  0% {
    opacity: 1;
    transform: translateY(0px);
  }
  100% {
    opacity: 0;
    transform: translateY(-200px);
  }
}
.notific-anim-in {
  animation: fadeSlideIn 0.5s ease-out;
}
.notific-anim-out {
  animation: fadeSlideOut 0.5s ease-out forwards;
}
#notification-panel {
    background: rgba(42, 42, 42, 0.5);
    border-radius: 6px;
    perspective: 1500px;
    backdrop-filter: blur(4px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
    border: 1px solid #3CB371;
    box-shadow: 0px 4px 12px 3px rgba(60, 179, 113, 0.4);
    left: 10vh; 
    width: 100vh; 
    right: 10vh;
}
/* Add to existing style block or create a new one */
#popup-roles .role-tag {
    position: relative;
    padding-right: 20px; /* Space for the X button */
}

#popup-roles .role-tag .remove-role-btn {
    display: none;
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    background-color: #ef4444;
    color: white;
    border-radius: 50%;
    text-align: center;
    line-height: 16px;
    font-size: 12px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

#popup-roles .role-tag:hover .remove-role-btn {
    display: block;
}

#popup-roles .role-tag .remove-role-btn:hover {
    background-color: #dc2626;
}
#gif-results img {
    width: 100%;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s ease-in-out;
}

#gif-results img:hover {
    transform: scale(1.05);
    border: 2px solid #3CB371;
}

#gif-modal {
    backdrop-filter: blur(5px);
}

#gif-modal .custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

#gif-modal .custom-scrollbar::-webkit-scrollbar-track {
    background: #2a2a2a;
}

#gif-modal .custom-scrollbar::-webkit-scrollbar-thumb {
    background: #3CB371;
    border-radius: 10px;
}
/* GIF Modal Custom Styles */
#gif-modal {
    backdrop-filter: blur(8px);
}

#gif-results img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
}

/* Custom Scrollbar for GIF Results */
#gif-results.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

#gif-results.custom-scrollbar::-webkit-scrollbar-track {
    background: #2a2a2a;
    border-radius: 10px;
}

#gif-results.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #3CB371;
    border-radius: 10px;
}
#gif-button {
    margin-top:10px;
}
.avatar-image.lazy-load {
    opacity: 0;
    transition: opacity 0.3s ease-in-out;
}

.avatar-image.lazy-load.loaded {
    opacity: 1;
}
.unread-dot {
    position: absolute;
    right: 38px;
    top: 50%;
    transform: translateY(-50%);
    width: 8px;
    height: 8px;
    background-color: #ffffff;
    border-radius: 50%;
    box-shadow: 0 0 4px rgba(0, 0, 0, 0.3);
}
     </style>
</head>
<body>
    <div class="main-content">
    <div class="flex h-screen">
           <!-- Server Sidebar (your provided code) -->
    <div id="server-sidebar" class="w-18 bg-gray-900 flex flex-col items-center px-2 py-3 space-y-2" style="background-color: #121212;">
        <a href="directmessages" class="w-12 h-12 rounded-full flex items-center justify-center hover:bg-indigo-500 cursor-pointer relative dm-link" style="background-color: #2A2A2A;">
            <i data-lucide="home" class="w-6 h-6 text-green-500"></i>
            <span id="unread-message-counter" class="absolute top-0 right-0 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center hidden">0</span>
        </a>
        <!-- Notification button and panel (unchanged) -->
        <div class="relative" id="notification-icon-container">
            <button id="notification-bell" class="w-12 h-12 bg-gray-700 rounded-full flex items-center justify-center hover:bg-indigo-500 cursor-pointer group" style="background-color: #2A2A2A;">
                <i data-lucide="bell" class="w-6 h-6 text-gray-400 group-hover:text-white"></i>
                <span id="notification-counter" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center hidden">0</span>
            </button>
            <div id="notification-panel" draggable="true" class="hidden absolute shadow-lg z-30">
                <div class="p-3 border-b border-gray-700 flex justify-between items-center">
                    <h3 class="text-white font-semibold">Bildirimler</h3>
                    <button id="mark-all-read" class="text-sm text-blue-400 hover:underline">Tümünü Okundu Say</button>
                </div>
                <div id="notification-list" class="max-h-96 overflow-y-auto custom-scrollbar"></div>
            </div>
        </div>
        <div class="w-8 h-0.5 bg-gray-700 rounded-full mb-2"></div>
        <div id="server-list" class="flex-1 overflow-y-auto custom-scrollbar flex flex-col items-center space-y-2">
            <?php foreach ($servers as $server): ?>
            <div class="relative group server-item" data-server-id="<?php echo $server['id']; ?>" draggable="true">
                <div class="absolute -left-3 w-2 h-2 bg-white rounded-full scale-0 group-hover:scale-100 transition-transform"></div>
                <a href="server?id=<?php echo $server['id']; ?>" class="server-link">
                    <div class="w-12 h-12 bg-gray-700 rounded-3xl group-hover:rounded-2xl flex items-center justify-center hover:bg-indigo-500 cursor-pointer transition-all duration-200">
                        <?php if ($server['unread_channel_count'] > 0): ?>
                            <span class="absolute left-0 top-1/2 transform -translate-y-1/2 w-2 h-2 bg-white rounded-full"></span>
                        <?php endif; ?>
                        <?php if (!empty($server['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($server['profile_picture']); ?>" 
                                 alt="<?php echo htmlspecialchars($server['name']); ?>'s profile picture"
                                 class="w-full h-full object-cover rounded-3xl">
                        <?php else: ?>
                            <span class="text-white font-medium"><?php echo strtoupper(substr($server['name'], 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
            
         

            
            <!-- Sunucu Oluşturma Butonu -->
            <div class="w-12 h-12 bg-gray-700 rounded-full flex items-center justify-center hover:bg-green-500 cursor-pointer group" style="background-color: #2A2A2A;">
                <button onclick="openCreateServerModal()">
                    <i data-lucide="plus" class="w-6 h-6 text-green-500 group-hover:text-white"></i>
                </button>
            </div>
        </div>
<!-- Create Server Modal -->
<div id="create-server-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modalentryanimation">
    <div class="bg-[#2d2f34] rounded-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold"><?php echo $translations['CreateServer']; ?></h3>
            <button onclick="closeCreateServerModal()" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <form id="create-server-form" method="POST" enctype="multipart/form-data">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2"><?php echo $translations['ServerName']; ?></label>
                <input type="text" name="server_name" class="form-input w-full" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2"><?php echo $translations['ServerDescription']; ?></label>
                <textarea name="server_description" class="form-input w-full"></textarea>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2"><?php echo $translations['ServerProfile']; ?></label>
                <div class="upload-area cursor-pointer" id="modal-pp-upload">
                    <input type="file" name="server_pp" class="hidden" accept="image/*">
                    <i class="fas fa-upload text-2xl mb-2 text-gray-400"></i>
                    <div class="text-sm text-gray-400"><?php echo $translations['ServerFile']; ?></div>
                </div>
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeCreateServerModal()" class="btn bg-gray-600 hover:bg-gray-700"><?php echo $translations['Decline']; ?></button>
                <button type="submit" class="btn btn-primary"><?php echo $translations['Create']; ?></button>
            </div>
        </form>
    </div>
</div>

        
<!-- Channel Sidebar -->
<div id="channel-sidebar-x" class="w-60 bg-gray-800 flex flex-col" style="background-color: #181818;">
    <div class="h-16 bg-[#141414] border-b border-gray-900 flex items-center px-4 justify-between relative z-10 ">
        <?php if (!empty($banner_url)): ?>
            <div 
                class="absolute inset-0 bg-cover bg-center opacity-50" 
                style="background-image: url('<?php echo htmlspecialchars($banner_url, ENT_QUOTES, 'UTF-8'); ?>'); z-index: -1;"
            ></div>
        <?php endif; ?>
        <div class="flex items-center">
            <?php if ($server_verified): ?>
                <i class="fas fa-check-circle text-blue-500 mr-2"></i>
            <?php endif; ?>
            <h3 class="text-white text-lg font-bold server-name" data-title="<?php echo htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8'); ?>
            </h3>
        </div>
        <div class="flex items-center space-x-2">
            <?php if ($has_access): ?>
    <a href="server_settings.php?id=<?php echo htmlspecialchars($server_id, ENT_QUOTES, 'UTF-8'); ?>" 
       class="w-8 h-8 bg-gray-700 rounded-full flex items-center justify-center hover:bg-gray-600 cursor-pointer" 
       style="box-shadow: 0 0 0px #000000; transition: box-shadow 0.7s ease;" 
       onmouseover="this.style.boxShadow='0 0 10px #222222, 0 0 30px #222222';" 
       onmouseout="this.style.boxShadow='0px 0px 0px #000000';">
        <i data-lucide="settings" class="w-4 h-4 text-gray-400"></i>
    </a>
<?php endif; ?>
            <button id="leave-server" data-server-id="<?php echo $server_id; ?>" class="text-red-500 hover:text-red-600  p-1 rounded hover:bg-gray-700 ml-2" style="box-shadow: 0 0 0px #00000; transition: box-shadow 0.7s ease;" onmouseover="this.style.boxShadow='0 0 10px #8c0000, 0 0 30px #8c0000';" onmouseout="this.style.boxShadow='0px 0px 0px #000000';">
                <i data-lucide="log-out" class="w-4 h-4"></i>
            </button>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto custom-scrollbar p-4 relative z-10 mt-8 channel-sidebar">
        <div class="text-gray-400 font-bold text-sm mb-2 flex items-center justify-between text-channels-header">
            <span><?php echo $translations['TextChannels']; ?></span>
            <?php if ($is_owner || $has_manage_channels_permission): ?>
                <div class="flex items-center space-x-2">
                    <a href="create_channel?server_id=<?php echo $server_id; ?>" class="ml-2 cursor-pointer">
                        <i data-lucide="plus" class="w-4 h-4 text-gray-400"></i>
                    </a>
                    <a href="create_category?server_id=<?php echo $server_id; ?>" class="ml-2 cursor-pointer">
                        <i data-lucide="folder-plus" class="w-4 h-4 text-gray-400"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
<div id="edit-category-modal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 hidden transition-opacity duration-300">
    <div style="background-color: #2f3136; width: 440px; padding: 24px; border-radius: 8px; color: #dcddde; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);">
        <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #ffffff;">Kategoriyi Düzenle</h2>
        <form id="edit-category-form">
            <input type="hidden" id="edit-category-id">
            <input type="hidden" id="edit-server-id" value="<?php echo htmlspecialchars($server_id, ENT_QUOTES, 'UTF-8'); ?>">
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; color: #b9bbbe;">Kategori Adı</label>
                <input type="text" id="edit-category-name" style="width: 100%; padding: 10px; background-color: #202225; border: 1px solid #1a1c1f; border-radius: 4px; color: #dcddde; font-size: 14px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#5865f2';" onblur="this.style.borderColor='#1a1c1f';" required>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="submit" style="background-color: #5865f2; color: #ffffff; padding: 10px 20px; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#4752c4';" onmouseout="this.style.backgroundColor='#5865f2';">Kaydet</button>
                <button type="button" id="delete-category-btn" style="background-color: #ed4245; color: #ffffff; padding: 10px 20px; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#c73538';" onmouseout="this.style.backgroundColor='#ed4245';">Sil</button>
                <button type="button" id="cancel-category-btn" style="background-color: #4f545c; color: #ffffff; padding: 10px 20px; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#41454a';" onmouseout="this.style.backgroundColor='#4f545c';">İptal</button>
            </div>
        </form>
    </div>
</div>
    <!-- Category List -->
<?php foreach ($categories as $category): ?>
    <div class="mb-4">
        <div class="text-gray-400 text-sm mb-2 font-bold flex items-center category-item" data-category-id="<?php echo $category['id']; ?>">
            <?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($is_owner || $has_manage_channels_permission): ?>
                <button class="edit-category-btn text-gray-400 hover:text-gray-300 p-1 rounded hover:bg-gray-700" style="background: none; border: none; cursor: pointer; margin-left: 8px;" data-category-id="<?php echo $category['id']; ?>" onclick="openEditCategoryModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>')">
                    <i data-lucide="edit" class="w-4 h-4"></i>
                </button>
            <?php endif; ?>
        </div>
        <?php if (isset($channels_by_category[$category['id']])): ?>
            <?php foreach ($channels_by_category[$category['id']] as $channel): ?>
                <div class="flex items-center justify-between mb-2 group" data-channel-id="<?php echo $channel['id']; ?>" data-type="<?php echo $channel['type']; ?>">
                    <?php if ($is_owner || $has_manage_channels_permission): ?>
                        <div class="channel-drag-handle cursor-move opacity-50 hover:opacity-100 mr-2">
                            <i data-lucide="grip-vertical" class="w-4 h-4 text-gray-400"></i>
                        </div>
                    <?php endif; ?>
                    <div class="flex items-center w-full relative">
                        <?php if ($channel['type'] === 'voice'): ?>
                            <div class="voice-channel-btn flex-1 rounded-lg p-2 flex items-center mr-2 cursor-pointer transition-all relative overflow-hidden hover:bg-gray-600 border border-gray-700 h-8" style="background-color: #333333;" data-channel-id="<?php echo $channel['id']; ?>" data-type="voice" data-max-users="<?php echo $channel['max_users'] ?? 10; ?>">
                                <i data-lucide="volume-2" class="w-4 h-4 text-gray-400 mr-2"></i>
                                <span class="text-white flex-1 truncate"><?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <div class="flex items-center ml-2">
                                    <button class="join-button text-xs font-semibold px-3 py-1 rounded-full bg-green-600 hover:bg-green-700 text-white transition-colors flex items-center" data-joined="<?php echo isset($channel['is_joined']) && $channel['is_joined'] ? 'true' : 'false'; ?>">
                                        <?php if (isset($channel['is_joined']) && $channel['is_joined']): ?>
                                            <i data-lucide="phone-off" class="w-4 h-4 mr-1"></i>
                                            <span>Ayrıl</span>
                                        <?php else: ?>
                                            <i data-lucide="phone" class="w-4 h-4 mr-1"></i>
                                            <span>Katıl</span>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="#" onclick="changeChannel(<?php echo $channel['id']; ?>, '<?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?>'); return false;" class="block rounded-lg p-2 w-full flex items-center mr-2 cursor-pointer hover:bg-gray-600 border border-gray-700 h-8 hoverchannel">
                                <?php 
                                $icon = 'hash';
                                switch($channel['type']) {
                                    case 'voice': $icon = 'volume-2'; break;
                                    case 'announcement': $icon = 'megaphone'; break;
                                    case 'important': $icon = 'star'; break;
                                }
                                ?>
                                <i data-lucide="<?php echo $icon; ?>" class="w-4 h-4 text-gray-400 mr-2"></i>
                                <span class="text-white"><?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($channel['unread_count'] > 0): ?>
                                    <span class="unread-dot"></span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="relative">
                        <?php if ($is_owner || $has_manage_channels_permission): ?>
                            <button class="settings-channel text-gray-400 hover:text-gray-300 p-1 rounded hover:bg-gray-700" data-channel-id="<?php echo $channel['id']; ?>">
                                <i data-lucide="settings" class="w-4 h-4"></i>
                            </button>
                            <div class="channel-settings-menu absolute right-0 mt-2 w-52 bg-[#1a1a1a] rounded-lg shadow-xl hidden z-50" data-channel-id="<?php echo $channel['id']; ?>">
                                <ul class="py-2">
                                    <li class="flex items-center gap-2 px-4 py-2 text-sm text-[#dcddde] hover:bg-[#5865f2] hover:text-white transition-all duration-200 cursor-pointer">
                                        <i data-lucide="edit" class="w-4 h-4"></i>
                                        <a href="edit_channel?id=<?php echo $channel['id']; ?>" class="w-full">Kanalı Düzenle</a>
                                    </li>
                                    <li class="flex items-center gap-2 px-4 py-2 text-sm text-[#dcddde] hover:bg-[#5865f2] hover:text-white transition-all duration-200 cursor-pointer">
                                        <i data-lucide="trash" class="w-4 h-4"></i>
                                        <form method="POST" action="server?id=<?php echo $server_id; ?>" class="w-full">
                                            <input type="hidden" name="channel_id" value="<?php echo $channel['id']; ?>">
                                            <button type="submit" name="delete_channel" class="w-full text-left bg-transparent">Kanalı Sil</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($channel['type'] === 'voice'): ?>
                    <div class="voice-participants ml-6 mt-1 hidden" data-channel-id="<?php echo $channel['id']; ?>">
                        <!-- Katılımcılar burada listelenecek -->
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

        <!-- Voice Controls -->
        <div id="voice-controls" class="hidden fixed bottom-[calc(4rem+60px)] left-20 w-80 bg-gray-900 rounded-lg shadow-lg p-3 z-50 transition-all duration-300 border border-gray-700">
    <div class="flex items-center justify-between mb-2">
        <span id="channel-name" class="text-white font-semibold text-sm truncate"></span>
        <span id="call-time-counter" class="text-gray-400 text-xs font-medium">00:00:00</span>
    </div>

    <div id="rtc-status" class="text-gray-400 text-xs mb-2 flex items-center">
        <span id="connection-status" class="mr-2" data-state="Bağlantı yok">Bağlantı bekleniyor...</span>
        <span id="ping-latency"></span>
    </div>

    <div class="flex items-center space-x-2">
        <button id="toggle-mic" class="bg-gray-700 p-2 rounded-full hover:bg-indigo-600 transition-colors duration-200" title="Mikrofonu Aç/Kapat">
            <i data-lucide="mic" class="w-5 h-5 text-white"></i>
        </button>
        <button id="toggle-deafen" class="bg-gray-700 p-2 rounded-full hover:bg-indigo-600 transition-colors duration-200" title="Sesi Kapat/Aç">
            <i data-lucide="headphones" class="w-5 h-5 text-white"></i>
        </button>
        <button id="start-screen-share" class="bg-blue-600 text-white p-2 rounded-full hover:bg-blue-700 transition-colors duration-200" title="Ekran Paylaş">
            <i data-lucide="monitor" class="w-5 h-5"></i>
        </button>
        <button id="stop-screen-share" class="bg-red-600 text-white p-2 rounded-full hover:bg-red-700 hidden transition-colors duration-200" title="Paylaşımı Durdur">
            <i data-lucide="monitor-off" class="w-5 h-5"></i>
        </button>
        <button id="disconnect-voice" class="bg-red-600 p-2 rounded-full hover:bg-red-700 transition-colors duration-200" title="Bağlantıyı Kes">
            <i data-lucide="phone-off" class="w-5 h-5 text-white"></i>
        </button>
    </div>
</div>

<div id="screen-preview-modal" class="hidden fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 p-4 rounded-lg z-50 w-1/2 h-1/2" style="background-color: #333333">
  <video id="screen-preview" autoplay class="max-w-lg max-h-[80vh]"></video>
  <button id="toggle-fullscreen" class="text-white px-4 py-2 mt-2 rounded" style="background-color: #123b94"><i data-lucide="maximize" class="w-4 h-4 mr-2"></i> Tam Ekran</button>
</div>
<div id="remote-screen-share-modal" class="hidden fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 p-4 rounded-lg z-50 w-1/2 h-1/2" style="background-color: #333333">
  <video id="remote-screen-preview" autoplay class="max-w-lg max-h-[80vh]"></video>
  <button id="toggle-remote-fullscreen" class="text-white px-4 py-2 mt-2 rounded" style="background-color: #123b94"><i data-lucide="maximize" class="w-4 h-4 mr-2"></i> Tam Ekran</button>
</div>


<!-- Uncategorized Channels -->
<?php if (isset($channels_by_category['uncategorized'])): ?>
    <div class="mb-4">
        <div class="text-gray-400 font-bold text-sm mb-2"><?php echo $translations['uncategorized']; ?></div>
        <?php foreach ($channels_by_category['uncategorized'] as $channel): ?>
            <div class="flex items-center justify-between mb-2 group" data-channel-id="<?php echo $channel['id']; ?>" data-type="<?php echo $channel['type']; ?>">
                <?php if ($is_owner || $has_manage_channels_permission): ?>
                    <div class="channel-drag-handle cursor-move opacity-50 hover:opacity-100 mr-2">
                        <i data-lucide="grip-vertical" class="w-4 h-4 text-gray-400"></i>
                    </div>
                <?php endif; ?>
                <div class="flex items-center w-full relative">
                    <?php if ($channel['type'] === 'voice'): ?>
                        <div class="voice-channel-btn flex-1 rounded-lg p-2 flex items-center mr-2 cursor-pointer transition-all relative overflow-hidden hover:bg-gray-600 border border-gray-700 h-8" style="background-color: #333333;" data-channel-id="<?php echo $channel['id']; ?>" data-type="voice" data-max-users="<?php echo $channel['max_users'] ?? 10; ?>">
                            <i data-lucide="volume-2" class="w-4 h-4 text-gray-400 mr-2"></i>
                            <span class="text-white flex-1 truncate"><?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <div class="flex items-center ml-2">
                                <button class="join-button text-xs font-semibold px-3 py-1 rounded-full bg-green-600 hover:bg-green-700 text-white transition-colors flex items-center" data-joined="<?php echo isset($channel['is_joined']) && $channel['is_joined'] ? 'true' : 'false'; ?>">
                                    <?php if (isset($channel['is_joined']) && $channel['is_joined']): ?>
                                        <i data-lucide="phone-off" class="w-4 h-4 mr-1"></i>
                                        <span>Ayrıl</span>
                                    <?php else: ?>
                                        <i data-lucide="phone" class="w-4 h-4 mr-1"></i>
                                        <span>Katıl</span>
                                    <?php endif; ?>
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="#" onclick="changeChannel(<?php echo $channel['id']; ?>, '<?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?>'); return false;" class="block rounded-lg p-2 w-full flex items-center mr-2 cursor-pointer hover:bg-gray-600 border border-gray-700 h-8 hoverchannel">
                            <?php 
                            $icon = 'hash';
                            switch($channel['type']) {
                                case 'voice': $icon = 'volume-2'; break;
                                case 'announcement': $icon = 'megaphone'; break;
                                case 'important': $icon = 'star'; break;
                            }
                            ?>
                            <i data-lucide="<?php echo $icon; ?>" class="w-4 h-4 text-gray-400 mr-2"></i>
                            <span class="text-white"><?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($channel['unread_count'] > 0): ?>
                                <span class="unread-dot"></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="relative">
                    <?php if ($is_owner || $has_manage_channels_permission): ?>
                        <button class="settings-channel text-gray-400 hover:text-gray-300 p-1 rounded hover:bg-gray-700" data-channel-id="<?php echo $channel['id']; ?>">
                            <i data-lucide="settings" class="w-4 h-4"></i>
                        </button>
                        <div class="channel-settings-menu absolute right-0 mt-2 w-52 bg-[#1a1a1a] rounded-lg shadow-xl hidden z-50" data-channel-id="<?php echo $channel['id']; ?>">
                            <ul class="py-2">
                                <li class="flex items-center gap-2 px-4 py-2 text-sm text-[#dcddde] hover:bg-[#5865f2] hover:text-white transition-all duration-200 cursor-pointer">
                                    <i data-lucide="edit" class="w-4 h-4"></i>
                                    <a href="edit_channel?id=<?php echo $channel['id']; ?>" class="w-full">Kanalı Düzenle</a>
                                </li>
                                <li class="flex items-center gap-2 px-4 py-2 text-sm text-[#dcddde] hover:bg-[#5865f2] hover:text-white transition-all duration-200 cursor-pointer">
                                    <i data-lucide="trash" class="w-4 h-4"></i>
                                    <form method="POST" action="server?id=<?php echo $server_id; ?>" class="w-full">
                                        <input type="hidden" name="channel_id" value="<?php echo $channel['id']; ?>">
                                        <button type="submit" name="delete_channel" class="w-full text-left bg-transparent">Kanalı Sil</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($channel['type'] === 'voice'): ?>
                <div class="voice-participants ml-6 mt-1 hidden" data-channel-id="<?php echo $channel['id']; ?>">
                    <!-- Katılımcılar burada listelenecek -->
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<!-- User Profile Mobile -->
        <div id="mobile-profile" class="p-2 gradient-border" style="user-select: none;">
            <div class="flex items-center">
                <div class="flex items-center flex-1 nav-shadow">
                    
                <button class="items-center absolute rounded p-1 text-gradient nav-shadow bg-nav-card" style="z-index: 17; left: 3%; bottom: 5px;" onclick="gotoDM()"><i data-lucide="home" class="w-10 h-8 items-center flex"></i>
                 <p style="font-size: 9px;">Anasayfa</p>
                </button>
                <button class="items-center absolute rounded p-1 text-gradient nav-shadow bg-nav-card" style="z-index: 17; left: 23%; bottom: 5px;" onclick="gotoCOMM()"><i data-lucide="users" class="w-10 h-8 items-center flex"></i>
                 <p style="font-size: 9px;">Topluluklar</p>
                </button>
                <button class="items-center absolute rounded p-1 text-gradient nav-shadow bg-nav-card" style="z-index: 17; left: 44%; bottom: 5px;" onclick="gotoLAKE()"><i data-lucide="message-square-diff" class="w-10 h-8 items-center flex"></i>
                 <p style="font-size: 9px;">Lakealt</p>
                </button>
                <div class="flex space-x-1">
                    <a href="/settings"class="absolute p-1 rounded text-gradient nav-shadow bg-nav-card" style="bottom: 5px; right: 10.5vh;">
                        <i data-lucide="settings" class="w-10 h-8"></i>
                        <p style="font-size: 9px; padding-left: 6px;">Ayarlar</p>
                    </a>
                </div>
                
                    <div class="absolute right-3" style:"bottom: 6px;">
                        <div class=" w-12 h-12 flex items-center justify-center bg-nav-card"style="border-radius: 20px;" onclick="gotoProfile()" >
                            <?php
                            $avatar_query = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
                            $avatar_query->execute([$_SESSION['user_id']]);
                            $avatar_result = $avatar_query->fetch();
                            $avatar_url = $avatar_result['avatar_url'] ?? '';

                            if (!empty($avatar_url)): ?>
                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" 
                                     alt="Profile avatar"
                                     class="w-full h-full object-cover rounded-full">
                            <?php else: ?>
                                <span class="text-white text-sm font-medium">
                                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="status-dot1 status-<?php echo htmlspecialchars($_SESSION['status'] ?? 'online'); ?>" style="position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; border-radius: 50%; border: 2.5px solid #121212;"></div>
                    </div>
                </div>
            
            
            </div>
        </div>

<!-- User Profile -->
<div id="user-profile" style="background-color: #121212; padding: 8px; user-select: none; border-top: 1px solid #333333;">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; flex: 1;">
            <div style="position: relative;">
                <div style="width: 32px; height: 32px; background-color: #4f46e5; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <?php
                    // Ensure session username is set
                    $real_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown User';

                    // Fetch avatar_url and display_username from user_profiles
                    $profile_query = $db->prepare("SELECT avatar_url, display_username FROM user_profiles WHERE user_id = ?");
                    $profile_query->execute([$_SESSION['user_id']]);
                    $profile_result = $profile_query->fetch();
                    $avatar_url = $profile_result['avatar_url'] ?? '';
                    $display_username = $profile_result['display_username'] ?? $real_username; // Fallback to real username

                    if (!empty($avatar_url)): ?>
                        <img src="<?php echo htmlspecialchars($avatar_url); ?>" 
                             alt="Profile avatar"
                             style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <span style="color: #ffffff; font-size: 14px; font-weight: 500;">
                            <?php echo strtoupper(substr($real_username, 0, 1)); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="status-dot1 status-<?php echo htmlspecialchars($_SESSION['status'] ?? 'online'); ?>" style="position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; border-radius: 50%; border: 3px solid #121212;"></div>
            </div>
            <div style="margin-left: 8px;">
                <!-- Display display_username -->
                <div style="color: #ffffff; font-size: 14px; font-weight: 500;">
                    <?php echo htmlspecialchars($display_username); ?>
                </div>
                <!-- Status text with hover effect for real username -->
                <div class="status-text-container" style="position: relative; min-height: 16px;">
                    <div class="status-text" style="color: #9ca3af; font-size: 12px; transition: opacity 0.2s ease;">
                        <?php 
                        $status_text = [
                            'online' => $translations['Online'] ?? 'Çevrimiçi',
                            'idle' => $translations['Idle'] ?? 'Boşta',
                            'dnd' => $translations['DoNotDisturb'] ?? 'Rahatsız Etmeyin',
                            'offline' => $translations['Offline'] ?? 'Çevrimdışı'
                        ];
                        echo $status_text[$_SESSION['status'] ?? 'online'] ?? $translations['Online'];
                        ?>
                    </div>
                    <div class="real-username" style="color: #9ca3af; font-size: 12px; font-weight: 400; position: absolute; top: 0; left: 0; opacity: 0; transition: opacity 0.2s ease; z-index: 1;">
                        <?php echo '@' . htmlspecialchars($real_username); ?>
                    </div>
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 4px;">
            <a href="/settings" style="color: #9ca3af; padding: 4px; border-radius: 4px;" onmouseover="this.style.color='#ffffff'; this.style.backgroundColor='#4b5563';" onmouseout="this.style.color='#9ca3af'; this.style.backgroundColor='transparent';">
                <i data-lucide="settings" style="width: 16px; height: 16px;"></i>
            </a>
            <a href="logout" style="color: #9ca3af; padding: 4px; border-radius: 4px;" onmouseover="this.style.color='#ffffff'; this.style.backgroundColor='#4b5563';" onmouseout="this.style.color='#9ca3af'; this.style.backgroundColor='transparent';">
                <i data-lucide="log-out" style="width: 16px; height: 16px;"></i>
            </a>
        </div>
    </div>
</div>


        </div>
<div id="status-modal" style="display: none; position: absolute; background-color: #2f3136; border: 1px solid #202225; border-radius: 4px; z-index: 1000; transform: translateY(-160px);">
    <div class="status-option" data-status="online" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['Online'] ?? 'Çevrimiçi'; ?></div>
    <div class="status-option" data-status="idle" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['Idle'] ?? 'Boşta'; ?></div>
    <div class="status-option" data-status="dnd" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['DoNotDisturb'] ?? 'Rahatsız Etmeyin'; ?></div>
    <div class="status-option" data-status="offline" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['Offline'] ?? 'Çevrimdışı'; ?></div>
</div>



<!-- Main Content -->
<div id="main-content" class="absolute h-full flex-1 flex flex-col transition-all duration-300 ease-in-out" style="background-color: #333333; user-select: text;">
    <div class="h-16 border-b border-gray-900 flex items-center px-6 relative" style="background-color: #181818; border-bottom: 1px solid #515151;">
        <?php
        // Aktif kanalın ismini çek
        $stmt = $db->prepare("SELECT name FROM channels WHERE id = ?");
        $stmt->execute([$channel_id]);
        $channel_name = $stmt->fetchColumn();
        ?>
        <h3 class="text-white text-lg font-medium"><?php echo htmlspecialchars($channel_name, ENT_QUOTES, 'UTF-8'); ?></h3>
        
        <!-- Sabitlenen mesajlar butonu (Büyüteç ikonunun soluna eklendi) -->
        <button id="toggle-pinned-btn" class="text-gray-400 hover:text-white p-2 rounded-full hover:bg-gray-700 transition-colors mr-2">
            <i data-lucide="pin" class="w-5 h-5"></i>
        </button>
        
        <!-- Arama butonu -->
        <button id="open-search-btn" class="text-gray-400 hover:text-white p-2 rounded-full hover:bg-gray-700 transition-colors">
            <i data-lucide="search" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- Sabitlenen mesajlar paneli -->
 <div id="pinned-messages-panel" class="hidden fixed inset-0 bg-opacity-50 bg-black z-50 flex items-center justify-center modalentryanimation">
    <div class="w-full max-w-3xl rounded-lg shadow-lg flex flex-col max-h-[80vh]" style="background-color: #202225;">
        <div class="flex items-center justify-between p-4 border-b border-gray-800">
            <h2 class="text-white text-lg font-medium flex items-center">
                <i data-lucide="pin" class="w-5 h-5 mr-2"></i> <?php echo $translations['pinned_messages_dm']['pinned_messages']; ?>
                <span id="pinned-count" class="ml-2 bg-indigo-500 text-white text-xs rounded-full px-2 py-1">0</span>
            </h2>
            <button id="close-pinned-panel-btn" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div id="pinned-messages-container" class="p-4 overflow-y-auto custom-scrollbar max-h-[60vh]">
            <div class="text-center text-gray-500 mt-8"><?php echo $translations['pinned_messages_dm']['pinned_loading']; ?></div>
        </div>
    </div>
 </div>
<div id="create-poll-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-[#2f3136] rounded-xl p-6 w-full max-w-md shadow-lg modalentryanimation">
        <h2 class="text-xl font-semibold text-white mb-4"><?php echo $translations['create_poll'] ?? 'Anket Oluştur'; ?></h2>
        <form id="poll-form">
            <div class="mb-4">
                <label class="block text-gray-300 text-sm mb-2" for="poll-question"><?php echo $translations['poll_question'] ?? 'Anket Sorusu'; ?></label>
                <input id="poll-question" type="text" class="form-input w-full mb-2 text-white border-none rounded-lg p-3 focus:ring-2 focus:ring-[#5865f2]" placeholder="<?php echo $translations['poll_question_placeholder'] ?? 'Sorunuzu buraya yazın...'; ?>" required style="background-color: #40444b">
            </div>
            <div id="poll-options" class="mb-4">
                <label class="block text-gray-300 text-sm mb-2"><?php echo $translations['poll_options'] ?? 'Seçenekler'; ?></label>
                <div class="poll-option-container">
                    <input type="text" class="form-input poll-option w-full mb-2 text-white border-none rounded-lg p-3 focus:ring-2 focus:ring-[#5865f2]" placeholder="<?php echo $translations['option'] ?? 'Seçenek'; ?> 1" required style="background-color: #40444b">
                </div>
                <div class="poll-option-container">
                    <input type="text" class="form-input poll-option w-full mb-2 text-white border-none rounded-lg p-3 focus:ring-2 focus:ring-[#5865f2]" placeholder="<?php echo $translations['option'] ?? 'Seçenek'; ?> 2" required style="background-color: #40444b">
                </div>
            </div>
            <button type="button" id="add-poll-option" class="flex items-center text-[#5865f2] hover:text-[#4752c4] mb-4">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                <?php echo $translations['add_option'] ?? 'Seçenek Ekle'; ?>
            </button>
            <div class="flex justify-end space-x-2">
                <button type="button" id="cancel-poll" class="btn text-white rounded-lg px-4 py-2"><?php echo $translations['cancel'] ?? 'İptal'; ?></button>
                <button type="submit" id="sumbit-poll" class="btn text-white rounded-lg px-4 py-2"><?php echo $translations['create'] ?? 'Oluştur'; ?></button>
            </div>
        </form>
    </div>
</div>
    <!-- Arama paneli -->
<div id="search-panel" class="hidden absolute inset-0 z-20 flex flex-col" style="background-color: #1E1E1E;">
    
    <div class="flex-shrink-0 h-16 border-b border-gray-900 flex items-center px-4" style="background-color: #181818; border-bottom: 1px solid #515151;">
        <i data-lucide="search" class="w-5 h-5 text-gray-400 mr-3"></i>
        <input type="text" id="search-input" placeholder="<?php echo htmlspecialchars($translations['search_bar']['search_servers'] ?? 'Mesajlarda Ara...'); ?>" class="w-full bg-transparent text-white placeholder-gray-500 focus:outline-none">
        <button id="close-search-btn" class="text-gray-400 hover:text-white ml-3 p-2 rounded-full hover:bg-gray-700 transition-colors">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    <div id="search-filters" class="flex-shrink-0 p-4 border-b border-gray-700 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="text-xs text-gray-400 font-semibold uppercase">Kimden</label>
            <select id="filter-from-user-id" class="w-full mt-1 bg-gray-700 text-white p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">Tüm Kullanıcılar</option>
                <?php foreach ($server_members as $member): ?>
                    <option value="<?php echo $member['user_id']; ?>">
                        <?php echo htmlspecialchars($member['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-400 font-semibold uppercase">Nerede</label>
            <select id="filter-in-channel-id" class="w-full mt-1 bg-gray-700 text-white p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">Tüm Kanallar</option>
                <?php foreach ($channels as $channel): ?>
                     <?php if ($channel['type'] !== 'voice'): // Sadece metin kanallarını listele ?>
                        <option value="<?php echo $channel['id']; ?>">
                            #<?php echo htmlspecialchars($channel['name']); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-gray-400 font-semibold uppercase">Önce</label>
            <input type="date" id="filter-before-date" class="w-full mt-1 bg-gray-700 text-white p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="text-xs text-gray-400 font-semibold uppercase">Sonra</label>
            <input type="date" id="filter-after-date" class="w-full mt-1 bg-gray-700 text-white p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="md:col-span-2 flex items-center justify-end space-x-2">
            <button id="clear-filters-btn" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-md text-sm">Filtreleri Temizle</button>
            <button id="apply-filters-btn" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-md text-sm">Ara</button>
        </div>
    </div>

    <div id="search-results-container" class="flex-1 overflow-y-auto p-4 custom-scrollbar">
        <div class="text-center text-gray-500 mt-8"><?php echo htmlspecialchars($translations['search_bar']['type_for_search'] ?? 'Aramak için yukarıya yazın veya filtre uygulayın.'); ?></div>
    </div>
    
</div>

    <div id="message-container" class="flex-1 overflow-y-auto"></div>
    <div id="typing-indicator" class="hidden transition-opacity duration-300" style="opacity: 0;">
    <div class="flex items-center gap-2 text-gray-400 text-sm">
        <div class="typing-animation flex items-center">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <span class="typing-username"></span>
    </div>
</div>

    <!-- Dosya önizleme konteynırı -->
    <div id="file-preview-container" class="hidden absolute bottom-16 bg-gray-800 rounded-lg shadow-xl z-50" style="max-width: 300px; right: 0px;">
        <button id="cancel-file" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition-all shadow-lg">×</button>
        <div class="p-3">
            <div id="preview-content" class="flex flex-col items-end gap-2"></div>
        </div>
    </div>

    <!-- Mesaj formu -->
    <form id="message-form" class="message-form" enctype="multipart/form-data" <?php echo !$has_write_permission ? 'style="pointer-events: none; opacity: 0.5;"' : ''; ?>>
        <input type="hidden" name="server_id" value="<?php echo htmlspecialchars($server_id, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="channel_id" value="<?php echo htmlspecialchars($channel_id, ENT_QUOTES, 'UTF-8'); ?>">
        
        <button id="emoji-button" type="button" class="emoji-button" <?php echo !$has_write_permission ? 'disabled' : ''; ?>>
            <i data-lucide="smile" class="icon"></i>
        </button>
         <!-- GIF Button -->
        <button type="button" id="gif-button" class="text-gray-400 hover:text-white p-1 rounded hover:bg-gray-700 self-start" data-tooltip="GIF Seç">
            <i data-lucide="image" class="w-5 h-5"></i>
        </button>
        <button type="button" id="poll-button" class="emoji-button">
            <i data-lucide="bar-chart-2" class="w-5 h-5"></i>
        </button>
        <input type="text" name="message_text" id="message-input" class="message-input" placeholder="<?php echo $has_write_permission ? 'Mesaj yaz...' : 'Bu kanalda yazma izniniz yok.'; ?>" autocomplete="off" <?php echo !$has_write_permission ? 'disabled' : ''; ?>>
        
        <input type="file" id="file-input" name="file" class="file-input" <?php echo !$has_write_permission ? 'disabled' : ''; ?>>
        <label for="file-input" class="file-label">
            <i data-lucide="paperclip" class="icon"></i>
        </label>
        
        <button type="submit" name="send_message" id="send-button" class="send-button" <?php echo !$has_write_permission ? 'disabled' : ''; ?>>
            Gönder
        </button>
    </form>
</div>
<div id="gif-modal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 transition-opacity duration-300 hidden">
    <div class="bg-[#1a1a1a] rounded-xl p-6 w-full max-w-lg shadow-2xl border border-[#2d2f34] transform transition-all duration-300 scale-95" style="background: #1a1a1a;">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-semibold text-[#3CB371] tracking-tight" style="color: #3CB371">GIF Ara</h3>
            <button id="close-gif-modal" class="text-gray-400 hover:text-white transition-colors duration-200" aria-label="GIF Modalını Kapat">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="relative mb-4">
            <input id="gif-search" type="text" class="w-full bg-[#2a2a2a] text-white p-3 rounded-lg border border-[#3a3a3a] focus:border-[#3CB371] focus:ring-2 focus:ring-[#3CB371]/30 outline-none transition-all duration-200" placeholder="GIF ara..." aria-label="GIF Arama" style="background-color: #2a2a2a;">
            <i data-lucide="search" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
        </div>
        <div id="gif-results" class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3 max-h-80 overflow-y-auto p-2 rounded-lg bg-[#202225]/50 custom-scrollbar" style="background-color: #202225;"></div>
        <div id="gif-message" class="mt-3 text-center text-sm text-gray-400 hidden"></div>
    </div>
</div>
<!-- User Profile Sidebar -->
<div id="user-sidebar" class="absolute h-full w-64 flex flex-col transition-all duration-300 ease-in-out" style="background-color: #1E1E1E;">
    <div class="h-16 border-b border-gray-900 flex items-center px-4" style="background-color: #1E1E1E;">
        <h3 class="text-white text-lg font-medium"><?php echo $translations['Members']; ?></h3>
    </div>
    <div class="flex-1 overflow-y-auto custom-scrollbar p-4">
        <!-- Role Groups -->
        <?php foreach ($role_groups as $role => $members): ?>
            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-2"><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($members); ?>)</div>
                <?php foreach ($members as $member): ?>
                    <div class="flex items-center mb-2 <?php echo $member['status'] === 'offline' ? 'offline-user' : ''; ?>">
                        <div class="relative">
                            <div class="avatar-container">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img data-src="<?php echo htmlspecialchars($member['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" 
                                         src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
                                         alt="Profile Photo" 
                                         class="avatar-image lazy-load">
                                <?php else: ?>
                                    <div class="w-full h-full bg-indigo-500 flex items-center justify-center">
                                        <span class="text-white font-medium">
                                            <?php echo strtoupper(substr($member['display_username'] ?? $member['username'], 0, 1)); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <!-- Status Indicator -->
                            <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-900
                                <?php
                                switch ($member['status']) {
                                    case 'online':
                                        echo 'bg-green-500';
                                        break;
                                    case 'idle':
                                        echo 'bg-yellow-500';
                                        break;
                                    case 'dnd':
                                        echo 'bg-red-500';
                                        break;
                                    case 'offline':
                                        echo 'bg-gray-500';
                                        break;
                                    default:
                                        echo 'bg-gray-500';
                                }
                                ?>"></div>
                        </div>
                        <div class="ml-2">
                            <span class="text-white text-sm font-medium cursor-pointer" 
                                  style="color: <?php echo htmlspecialchars($member['highest_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8'); ?>;" 
                                  onclick="openUserProfilePopup('<?php echo $member['user_id']; ?>', '<?php echo htmlspecialchars($member['display_username'] ?? $member['username'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($member['avatar_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $member['status']; ?>')">
                                <?php echo htmlspecialchars($member['display_username'] ?? $member['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <div class="text-gray-400 text-xs">
                                <?php
                                switch ($member['status']) {
                                    case 'online':
                                        echo $translations['Online'];
                                        break;
                                    case 'idle':
                                        echo $translations['Idle'];
                                        break;
                                    case 'dnd':
                                        echo $translations['DoNotDisturb'];
                                        break;
                                    case 'offline':
                                        echo $translations['Offline'];
                                        break;
                                    default:
                                        echo $translations['Offline'];
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Online Members -->
        <?php if (!empty($online_members)): ?>
            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-2"><?php echo $translations['Online']; ?> (<?php echo count($online_members); ?>)</div>
                <?php foreach ($online_members as $member): ?>
                    <div class="flex items-center mb-2">
                        <div class="relative">
                            <div class="avatar-container">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img data-src="<?php echo htmlspecialchars($member['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" 
                                         src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
                                         alt="Profile Photo" 
                                         class="avatar-image lazy-load">
                                <?php else: ?>
                                    <div class="w-full h-full bg-indigo-500 flex items-center justify-center">
                                        <span class="text-white font-medium">
                                            <?php echo strtoupper(substr($member['display_username'] ?? $member['username'], 0, 1)); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-900
                                <?php
                                switch ($member['status']) {
                                    case 'online':
                                        echo 'bg-green-500';
                                        break;
                                    case 'idle':
                                        echo 'bg-yellow-500';
                                        break;
                                    case 'dnd':
                                        echo 'bg-red-500';
                                        break;
                                    case 'offline':
                                        echo 'bg-gray-500';
                                        break;
                                    default:
                                        echo 'bg-gray-500';
                                }
                                ?>"></div>
                        </div>
                        <div class="ml-2">
                            <span class="text-white text-sm font-medium cursor-pointer" 
                                  style="color: <?php echo htmlspecialchars($member['highest_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8'); ?>;" 
                                  onclick="openUserProfilePopup('<?php echo $member['user_id']; ?>', '<?php echo htmlspecialchars($member['display_username'] ?? $member['username'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($member['avatar_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $member['status']; ?>')">
                                <?php echo htmlspecialchars($member['display_username'] ?? $member['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <div class="text-gray-400 text-xs">
                                <?php
                                switch ($member['status']) {
                                    case 'online':
                                        echo $translations['Online'];
                                        break;
                                    case 'idle':
                                        echo $translations['Idle'];
                                        break;
                                    case 'dnd':
                                        echo $translations['DoNotDisturb'];
                                        break;
                                    case 'offline':
                                        echo $translations['Offline'];
                                        break;
                                    default:
                                        echo $translations['Offline'];
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Offline Members -->
        <?php if (!empty($offline_members)): ?>
            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-2"><?php echo $translations['Offline']; ?> (<?php echo count($offline_members); ?>)</div>
                <?php foreach ($offline_members as $member): ?>
                    <div class="flex items-center mb-2 offline-user">
                        <div class="relative">
                            <div class="avatar-container">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img data-src="<?php echo htmlspecialchars($member['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" 
                                         src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
                                         alt="Profile Photo" 
                                         class="avatar-image lazy-load">
                                <?php else: ?>
                                    <div class="w-full h-full bg-indigo-500 flex items-center justify-center">
                                        <span class="text-white font-medium">
                                            <?php echo strtoupper(substr($member['display_username'] ?? $member['username'], 0, 1)); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="absolute bottom-0 right-0 w-3 h-3 bg-gray-500 rounded-full border-2 border-gray-900"></div>
                        </div>
                        <div class="ml-2">
                            <span class="text-sm font-medium cursor-pointer text-gray-500" 
                                  onclick="openUserProfilePopup('<?php echo $member['user_id']; ?>', '<?php echo htmlspecialchars($member['display_username'] ?? $member['username'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($member['avatar_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $member['status']; ?>')">
                                <?php echo htmlspecialchars($member['display_username'] ?? $member['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <div class="text-gray-500 text-xs"><?php echo $translations['Offline']; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- User Profile Popup -->
<div id="user-profile-popup" class="hidden fixed inset-0 flex items-center justify-center z-50 bg-black/50">
    <div class="bg-[#2f3136] rounded-lg shadow-xl relative overflow-hidden" style="width: 560px; height: 830px;">
        <div id="popup-background" class="absolute top-0 left-0 w-full h-32 bg-[#5865f2] bg-cover bg-center" style="border-top-left-radius: 8px; border-top-right-radius: 8px; z-index: 0;"></div>
        <div class="relative z-10 p-4">
            <!-- Close Button -->
            <button id="close-popup-btn" class="absolute top-3 right-3 w-8 h-8 flex items-center justify-center bg-red-500 rounded-full text-white hover:bg-red-600 transition-all duration-200 hover:scale-110">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="x"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
            </button>
            <!-- Avatar and Username -->
            <div class="mt-32 flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <div class="w-16 h-16 rounded-full flex items-center justify-center bg-indigo-500 overflow-hidden ring-2 ring-[#2f3136] transition-transform duration-200 hover:scale-105 online-pulse">
                            <img id="popup-avatar" class="w-full h-full object-cover" alt="User avatar" src="avatars/default-avatar.png">
                            <span id="popup-avatar-initial" class="text-white text-xl font-medium hidden"></span>
                        </div>
                        <div id="popup-status-indicator" class="absolute bottom-0 right-0 w-4 h-4 rounded-full border-2 border-[#2f3136] online"></div>
                    </div>
                    <div>
                        <h3 id="popup-username" class="text-lg font-bold text-white" data-user-id="9">bongo</h3>
                        <p id="popup-status" class="text-xs text-gray-400"><?php echo $translations['Online']; ?></p>
                    </div>
                </div>
                <!-- Action Buttons -->
                <div id="popup-action-buttons" class="flex items-center gap-2">
                    <button id="go-to-profile-btn" class="px-3 py-1 text-white rounded text-sm transition-all duration-200 hover:scale-105 hover:shadow-md">Profile Git</button>
                    <button id="send-message-btn" class="hidden px-3 py-1 bg-[#5865f2] text-white rounded text-sm hover:bg-[#4752c4] transition-all duration-200 hover:scale-105 hover:shadow-md">Mesaj(yakında)</button>
                    <div id="popup-kick-button-container" class="hidden" style="display: block;">
                        <button id="kick-user-btn" class="px-3 py-1 bg-red-500 text-white rounded text-sm hover:bg-red-600 transition-all duration-200 hover:scale-105 hover:shadow-md">At</button>
                    </div>
                    <div id="popup-ban-button-container" class="hidden" style="display: block;">
                        <button id="ban-user-btn" class="px-3 py-1 bg-red-500 text-white rounded text-sm hover:bg-red-600 transition-all duration-200 hover:scale-105 hover:shadow-md">Banla</button>
                    </div>
                </div>
            </div>
                 <!-- Friend Request Button -->
                    <button id="add-friend-btn" class="px-3 py-1 text-white rounded text-sm transition-all duration-200 hover:scale-105 hover:shadow-md flex items-center">
                        <i data-lucide="user-plus" class="w-4 h-4 mr-1"></i>
                        <span>Arkadaş Ekle</span>
                    </button>
                    <!-- Friend Request Modal -->
<div id="friend-request-modal" class="hidden fixed inset-0 flex items-center justify-center z-50 bg-black/50 modalentryanimation">
    <div class="bg-[#2f3136] rounded-lg p-6 w-96 max-w-full">
        <h3 class="text-xl font-bold text-white mb-4">Arkadaşlık İsteği</h3>
        
        <div id="friend-request-status" class="mb-4">
            <p id="friend-request-message" class="text-gray-300"></p>
        </div>
        
        <div class="flex justify-end space-x-3">
            <button id="cancel-friend-request" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition-colors">İptal</button>
            <button id="confirm-friend-request" class="px-4 py-2 text-white rounded transition-colors hidden">Gönder</button>
        </div>
    </div>
</div>
            <!-- User Details -->
            <div class="mt-4 p-4 rounded overflow-y-auto" style="background-color: #232428; height: 55vh;">
                <div class="mb-2">
                    <h4 class="text-sm font-bold text-gray-300">Roller</h4>
                    <div id="popup-roles" class="flex flex-wrap gap-2 mt-1">
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                        <span class="role-tag" style="background-color: rgb(244, 11, 11);">sa</span>
                    </div>
                </div>
                <div class="mb-2">
                    <h4 class="text-sm font-bold text-gray-300">Son Aktif</h4>
                    <p id="popup-last-active" class="text-sm text-gray-400">7 saat önce</p>
                </div>
                <div class="mb-2">
                    <h4 class="text-sm font-bold text-gray-300">Hakkında</h4>
                    <p id="popup-bio" class="text-sm text-gray-400">Sayfama hoş geldiniz. Burası benim oyun alanım. Sen benim oyuncağımsın. Sen bir fahişesin, tadını çıkar.</p>
                </div>
                <div class="mb-2">
                    <h4 class="text-sm font-bold text-gray-300">Gönderi Sayısı</h4>
                    <p id="popup-post-count" class="text-sm text-gray-400">0</p>
                </div>
                <div class="mb-2">
                    <h4 class="text-sm font-bold text-gray-300">Katılma Tarihi</h4>
                    <p id="popup-join-date" class="text-sm text-gray-400">15.12.2024</p>
                </div>
                <div>
                    <h4 class="text-sm font-bold text-gray-300">Arkadaşlar</h4>
                    <div id="popup-friends-list" class="flex flex-col gap-2 mt-1">
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500">
                                <img src="avatars/default-avatar.png" alt="Offihito's avatar" class="w-full h-full object-cover rounded-full">
                            </div>
                            <span class="text-sm text-gray-300">Offihito</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500">
                                <img src="avatars/7_1734288886.png" alt="_Chakraa's avatar" class="w-full h-full object-cover rounded-full">
                            </div>
                            <span class="text-sm text-gray-300">_Chakraa</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500">
                                <img src="avatars/10_1745478720.jpg" alt="Testa's avatar" class="w-full h-full object-cover rounded-full">
                            </div>
                            <span class="text-sm text-gray-300">Testa</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center bg-gray-500">
                                <span class="text-white text-sm font-medium">+13</span>
                            </div>
                            <span class="text-sm text-gray-300">ve 13 diğer</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

   <!-- Leave Server Confirmation Modal -->
<div id="leave-server-modal" class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 hidden modalentryanimation">
    <div class="p-6 rounded-lg shadow-lg max-w-md w-full mx-4 border border-solid border-black" style="background-color: #202020;">
        <div class="text-left">
            <!-- Lucide warning ikonu -->
            <i data-lucide="alert-triangle" class="text-4xl text-red-500 mb-4"></i>
            <h3 class="text-xl font-bold mb-2 text-white">Sunucudan Ayrılmak İstediğinize Emin Misiniz?</h3>
            <p class="text-gray-400 mb-6">
                Bu işlem geri alınamaz ve sunucudan kalıcı olarak ayrılacaksınız.
            </p>
        </div>
        <div class="flex justify-end gap-4">
            <!-- İptal Butonu -->
            <button type="button" id="cancel-leave-btn" class="btn bg-gray-700 hover:bg-gray-800 text-white rounded-lg px-4 py-2 transition-all duration-500 ease-in-out">
                <i data-lucide="x"></i> <!-- Lucide ikonu -->
                İptal
            </button>
            <!-- Sunucudan Ayrıl Butonu -->
            <button type="button" id="confirm-leave-btn" class="btn bg-red-600 hover:bg-red-700 text-white rounded-lg px-4 py-2 transition-all duration-500 ease-in-out">
                <i data-lucide="log-out"></i> <!-- Lucide ikonu -->
                Sunucudan Ayrıl
            </button>
        </div>
    </div>
</div>
</div>
<script src="LazyLoading/lazyloadingserver.js"></script>
    <script>
    
       lucide.createIcons();
const leaveModal = document.getElementById('leave-server-modal');
const leaveBtn = document.getElementById('leave-server');
const cancelLeaveBtn = document.getElementById('cancel-leave-btn');
const confirmLeaveBtn = document.getElementById('confirm-leave-btn');


leaveBtn.addEventListener('click', (e) => {
    e.preventDefault(); // Prevent the default link behavior
    leaveModal.classList.remove('hidden');
    leaveModal.classList.add('flex');
});

cancelLeaveBtn.addEventListener('click', () => {
    leaveModal.classList.add('hidden');
    leaveModal.classList.remove('flex');
});

confirmLeaveBtn.addEventListener('click', () => {
    const serverId = leaveBtn.getAttribute('data-server-id');

    fetch('leave_server.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `server_id=${serverId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = 'directmessages'; // Redirect to the main page or server list
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to leave the server. Please try again.');
    });

    leaveModal.classList.add('hidden');
    leaveModal.classList.remove('flex');
});

// Close modal when clicking outside
leaveModal.addEventListener('click', (e) => {
    if (e.target === leaveModal) {
        leaveModal.classList.add('hidden');
        leaveModal.classList.remove('flex');
    }
});

        // Show/hide channel settings menu
        document.querySelectorAll('.settings-channel').forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent the default link behavior
                const menu = this.nextElementSibling;
                menu.classList.toggle('hidden');
            });
        });

        // Delete a channel
        document.querySelectorAll('.delete-channel').forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent the default link behavior
                const channelId = this.getAttribute('data-channel-id');
                if (confirm('Are you sure you want to delete this channel?')) {
                    fetch('delete_channel.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `channel_id=${channelId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Remove the channel from the UI
                            this.closest('.flex.items-center.justify-between').remove();
                        } else {
                            alert('Failed to delete channel.');
                        }
                    });
                }
            });
        });

     

        document.addEventListener('DOMContentLoaded', function() {
            const serverNames = document.querySelectorAll('.server-name');

            serverNames.forEach(serverName => {
                const fullText = serverName.getAttribute('data-title');
                if (fullText.length > 20) {
                    serverName.textContent = fullText.substring(0, 20) + '...';
                } else {
                    serverName.textContent = fullText;
                }
            });
        });



// Bildirim gösterme fonksiyonu (isteğe bağlı)
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 px-4 py-2 rounded-md shadow-lg ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    } text-white`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}


function showEmojiPicker(messageId, messageElement) {
    const picker = new EmojiMart.Picker({
        data: async () => {
            const response = await fetch('https://cdn.jsdelivr.net/npm/@emoji-mart/data');
            return response.json();
        },
        onEmojiSelect: (emoji) => {
            handleReactionClick(messageId, emoji.native);
            picker.remove(); // Emoji seçildiğinde kaldır
        }
    });

    // Emoji seçiciyi mesajın üstüne konumlandır
    const rect = messageElement.getBoundingClientRect();
    picker.style.position = 'absolute';
    picker.style.top = `300px`; // Mesajın üstüne yerleştir
    picker.style.left = `${rect.left}px`;
    picker.style.zIndex = '1000'; // Diğer öğelerin üstünde görünmesi için
    document.body.appendChild(picker);

    // Dışarıya tıklandığında emoji seçiciyi kaldır
    const handleClickOutside = (event) => {
        if (!picker.contains(event.target)) {
            picker.remove(); // Emoji seçiciyi kaldır
            document.removeEventListener('click', handleClickOutside); // Dinleyiciyi kaldır
        }
    };

    // Bir sonraki olay döngüsünde dinleyiciyi ekle (tıklama olayının tetiklenmesini önlemek için)
    setTimeout(() => {
        document.addEventListener('click', handleClickOutside);
    }, 0);
}


function handleReactionClick(messageId, emoji) {
    const currentUserId1 = <?php echo json_encode($_SESSION['user_id']); ?>;
    console.log('handleReactionClick çağrıldı:', { messageId, emoji, currentUserId1 });

    fetch('add_reaction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `message_id=${messageId}&emoji=${encodeURIComponent(emoji)}&user_id=${currentUserId1}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Reaksiyon işlemi başarılı:', data.message);
            const messageDiv = document.querySelector(`.message-bg[data-message-id="${messageId}"]`);
            if (!messageDiv) {
                console.error('Mesaj divi bulunamadı:', `messageId=${messageId}`);
                return;
            }
            const reactionContainer = messageDiv.querySelector('.flex.flex-wrap.gap-2.mt-2');
            if (!reactionContainer) {
                console.error('Reaksiyon container bulunamadı:', `messageId=${messageId}`);
                return;
            }

            fetch(`get_reactions.php?message_id=${messageId}`)
                .then(response => response.json())
                .then(reactions => {
                    console.log('get_reactions.php yanıtı:', reactions);
                    reactionContainer.innerHTML = '';
                    reactions.forEach(reaction => {
                        const reactionDiv = document.createElement('div');
                        reactionDiv.className = 'flex items-center bg-gray-700 rounded-full px-2 py-1 cursor-pointer hover:bg-gray-600';
                        reactionDiv.dataset.emoji = reaction.emoji;
                        reactionDiv.dataset.messageId = messageId;

                        const userIds = reaction.user_ids ? reaction.user_ids.split(',').map(id => parseInt(id)) : [];
                        if (userIds.includes(currentUserId1)) {
                            reactionDiv.classList.add('bg-blue-500');
                        }

                        reactionDiv.innerHTML = `
                            <span class="emoji text-lg">${reaction.emoji}</span>
                            <span class="count text-sm ml-1">${reaction.count}</span>
                        `;
                        reactionDiv.addEventListener('click', () => handleReactionClick(messageId, reaction.emoji));
                        reactionContainer.appendChild(reactionDiv);
                    });
                })
                .catch(error => {
                    console.error('Reaksiyonlar yüklenemedi:', error);
                });
        } else {
            console.error('Hata:', data.message);
        }
    })
    .catch(error => {
        console.error('Hata:', error);
    });
}

async function updateReactions(messageId) {
    const reactionContainer = document.querySelector(`.message-bg[data-message-id="${messageId}"] .reaction-container`);
    if (!reactionContainer) return;

    const currentUserId1 = <?php echo json_encode($_SESSION['user_id']); ?>;
    
    try {
        const response = await fetch(`get_reactions.php?message_id=${messageId}`);
        const reactions = await response.json();
        
        reactionContainer.innerHTML = '';
        
        reactions.forEach(reaction => {
            const reactionDiv = document.createElement('div');
            reactionDiv.className = 'flex items-center bg-gray-700 rounded-full px-2 py-1 cursor-pointer hover:bg-gray-600 transition ease-in-out duration-200';
            reactionDiv.dataset.emoji = reaction.emoji;
            reactionDiv.dataset.messageId = messageId;

            const userIds = reaction.user_ids ? reaction.user_ids.split(',').map(id => parseInt(id)) : [];
            if (userIds.includes(currentUserId1)) {
                reactionDiv.classList.add('bg-blue-500');
            }

            reactionDiv.innerHTML = `
                <span class="emoji text-lg">${reaction.emoji}</span>
                <span class="count text-sm ml-1">${reaction.count}</span>
            `;
            reactionDiv.addEventListener('click', () => handleReactionClick(messageId, reaction.emoji));
            reactionContainer.appendChild(reactionDiv);
        });
    } catch (error) {
        console.error('Reaksiyonlar yüklenirken hata:', error);
    }
}

document.getElementById('message-input').addEventListener('input', function() {
    const sendButton = document.getElementById('send-button');
    if (this.value.trim() !== '') {
        sendButton.disabled = false;
    } else {
        sendButton.disabled = true;
    }
});
document.addEventListener('DOMContentLoaded', function() {
    const messageContainer = document.getElementById('message-container');
    messageContainer.scrollTop = messageContainer.scrollHeight;
});


document.addEventListener('DOMContentLoaded', function () {
    const emojiButton = document.getElementById('emoji-button');
    const messageInput = document.getElementById('message-input');
    let picker = null; // Emoji picker'ı tutacak değişken
    let isPickerOpen = false; // Picker'ın açık olup olmadığını kontrol etmek için

    // PHP'den gelen sunucu emojilerini al (gerekirse)
    const serverEmojis = <?php echo json_encode(getServerEmojis($db, $server_id)); ?> || [];

    // Twemoji'nin yüklendiğini kontrol et
    if (typeof twemoji === 'undefined') {
        console.error('🚨 Hata: Twemoji kütüphanesi yüklenmedi!');
        return;
    }

    // Emoji butonuna tıklandığında picker'ı aç/kapat
    emojiButton.addEventListener('click', function (event) {
        event.stopPropagation(); // Tıklama olayının yayılmasını engelle
        console.log('🔍 Emoji butonuna tıklandı, isPickerOpen:', isPickerOpen);
        if (isPickerOpen) {
            // Picker açıksa kapat
            if (picker) {
                picker.remove();
                picker = null;
                isPickerOpen = false;
                console.log('✅ Picker kapatıldı');
            }
        } else {
            // Picker kapalıysa aç
            try {
                picker = new EmojiMart.Picker({
                    data: async () => {
                        const response = await fetch('https://cdn.jsdelivr.net/npm/@emoji-mart/data@latest/sets/14/twitter.json');
                        if (!response.ok) throw new Error('Emoji Mart veri yükleme başarısız');
                        const data = await response.json();
                        console.log('✅ Emoji Mart verileri yüklendi:', data);
                        // Sunucu emojilerini ekle (gerekirse)
                        if (serverEmojis.length > 0) {
                            data.categories.push({
                                id: 'server_emojis',
                                name: 'Sunucu Emojileri',
                                emojis: serverEmojis.map(emoji => ({
                                    id: emoji.emoji_name,
                                    name: emoji.emoji_name,
                                    skins: [{ src: emoji.emoji_url }]
                                }))
                            });
                        }
                        return data;
                    },
                    set: 'twitter', // Twemoji setini kullan
                    theme: 'dark', // Karanlık tema
                    custom: serverEmojis.map(emoji => ({
                        id: emoji.emoji_name,
                        name: emoji.emoji_name,
                        skins: [{ src: emoji.emoji_url }]
                    })),
                    // Twemoji stilini uygulamak için emoji render özelleştirmesi
                    emojiButton: (emoji, { category }) => {
                        console.log('🔍 Emoji render ediliyor:', { emoji, category });
                        if (category === 'server_emojis' || emoji.src) {
                            // Sunucu emojileri için doğrudan URL kullan
                            return `<img src="${emoji.src}" alt="${emoji.name}" class="emoji-img" style="width: 24px; height: 24px; vertical-align: middle;" />`;
                        } else {
                            // Standart emojiler için Twemoji ile render et
                            try {
                                const twemojiHtml = twemoji.parse(emoji.native, {
                                    folder: 'svg',
                                    ext: '.svg',
                                    base: 'https://twemoji.maxcdn.com/v/latest/',
                                    className: 'emoji-img',
                                    attributes: () => ({
                                        style: 'width: 24px; height: 24px; vertical-align: middle;'
                                    })
                                });
                                return twemojiHtml;
                            } catch (e) {
                                console.error('🚨 Twemoji parse hatası:', e, { emoji: emoji.native });
                                return `<span class="emoji-img" style="width: 24px; height: 24px; display: inline-block; vertical-align: middle;">${emoji.native}</span>`;
                            }
                        }
                    },
                    onEmojiSelect: (emoji) => {
                        console.log('✅ Emoji seçildi:', emoji);
                        // Seçilen emojiyi mesaj alanına ekle
                        messageInput.value += emoji.src ? `:${emoji.id}:` : emoji.native;
                        messageInput.focus(); // Mesaj girişine odaklan
                        // Picker'ı kapatMA: Emoji seçildiğinde picker açık kalsın
                    }
                });

                // Picker'ı mesaj giriş kutusunun sol üst köşesine yerleştir
                const messageInputRect = messageInput.getBoundingClientRect();
                picker.style.position = 'absolute';
                picker.style.bottom = `${window.innerHeight - messageInputRect.top + 15}px`;
                picker.style.left = `${messageInputRect.left - 60}px`;
                picker.style.zIndex = '1000';

                // Picker'ı body'ye ekle
                document.body.appendChild(picker);
                isPickerOpen = true;
                console.log('✅ Picker oluşturuldu ve eklendi:', picker);

                // Picker içindeki emojilere Twemoji stilini uygula
                setTimeout(() => {
                    try {
                        twemoji.parse(picker, {
                            folder: 'svg',
                            ext: '.svg',
                            base: 'https://twemoji.maxcdn.com/v/latest/',
                            className: 'emoji-img',
                            attributes: () => ({
                                style: 'width: 24px; height: 24px; vertical-align: middle;'
                            })
                        });
                        console.log('✅ Picker içindeki emojiler Twemoji ile parse edildi');
                    } catch (e) {
                        console.error('🚨 Picker parse hatası:', e);
                    }
                }, 100); // DOM'un güncellenmesi için gecikme
            } catch (error) {
                console.error('🚨 Emoji picker oluşturma hatası:', error);
            }
        }
    });

    // Picker dışında bir yere tıklandığında picker'ı kapat
    document.addEventListener('click', function (event) {
        if (picker && !picker.contains(event.target) && event.target !== emojiButton) {
            picker.remove();
            picker = null;
            isPickerOpen = false;
            console.log('✅ Picker dışarı tıklamayla kapatıldı');
        }
    });

    // Mesaj yazma alanına tıklandığında picker'ı kapat
    messageInput.addEventListener('click', function (event) {
        event.stopPropagation();
        if (picker) {
            picker.remove();
            picker = null;
            isPickerOpen = false;
            console.log('✅ Picker mesaj alanına tıklamayla kapatıldı');
        }
    });
});
document.querySelectorAll('.edit-message').forEach(button => {
    button.addEventListener('click', function(event) {
        event.preventDefault(); // Prevent the default link behavior
        const messageId = this.getAttribute('data-message-id');
        const editForm = document.querySelector(`.edit-message-form[data-message-id="${messageId}"]`);
        editForm.classList.toggle('hidden');
    });
});

document.querySelectorAll('.reply-message').forEach(button => {
    button.addEventListener('click', function(event) {
        event.preventDefault(); // Prevent the default link behavior
        const messageId = this.getAttribute('data-message-id');
        const replyForm = document.querySelector(`.reply-message-form[data-message-id="${messageId}"]`);
        if (replyForm) {
            replyForm.classList.toggle('hidden');
        }
    });
});

document.querySelectorAll('.cancel-reply').forEach(button => {
    button.addEventListener('click', function(event) {
        event.preventDefault(); // Prevent the default link behavior
        const replyForm = this.closest('.reply-message-form');
        replyForm.classList.add('hidden');
    });
});
document.querySelectorAll('.reply-message-container').forEach(container => {
    container.addEventListener('click', () => {
        const originalMessage = container.nextElementSibling;
        originalMessage.classList.toggle('hidden');
    });
});
function makeLinksClickable(text) {
    const urlRegex = /(https?:\/\/[^\s]+)|(\bwww\.[^\s]+)|(\b[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b)/gi;
    return text.replace(urlRegex, function(match, p1, p2, p3) {
        let url = match;
        if (p2 || p3) { // www. veya domain.com ise https:// ekle
            url = 'https://' + match;
        }
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-blue-500 hover:underline">${match}</a>`;
    });
}
function processMessageText(messageText) {
    // Regular expressions for link detection
   const youtubeRegex = /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})(?:[^\s]*)?/gi;
    const tenorRegex = /(https?:\/\/(?:media\.tenor\.com|tenor\.com)\/[^\s]+)/g;
    const imageRegex = /(https?:\/\/[^\s]+?\.(?:jpg|jpeg|png|gif|webp|bmp)(?:\?[^\s]*)?)/gi;
    const discordCdnRegex = /(https?:\/\/(?:cdn\.discordapp\.com|media\.discordapp\.net)\/attachments\/[^\s]+)/gi;
    const urlRegex = /(https?:\/\/[^\s<>"']+)/g;

    // First, preserve the original text for fallback
    let processedText = messageText;

    // Escape HTML
    processedText = processedText
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    // Replace newlines with <br> tags
    processedText = processedText.replace(/\n/g, '<br>');

    // Function to check if URL contains HTML tags (encoded or not)
    const containsHtmlTags = (url) => {
        return /%(3C|3E|3c|3e)|<|>|%(20)?(href|src)=/i.test(url) || 
               /<[a-z][\s\S]*>/i.test(decodeURIComponent(url));
    };

    // Process all URLs in the text
    const urlMatches = processedText.match(urlRegex) || [];
    const processedUrls = new Set();

    urlMatches.forEach(url => {
        if (processedUrls.has(url)) return;

        // Skip if URL is already processed
        if (processedText.includes(`href="${url}"`) || processedText.includes(`src="${url}"`)) {
            processedUrls.add(url);
            return;
        }

        try {
            const decodedUrl = decodeURIComponent(url);

            // Check for HTML tags in URL
            if (containsHtmlTags(url) || containsHtmlTags(decodedUrl)) {
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<a href="${url}" target="_blank" class="text-blue-400 hover:underline">${url}</a>`
                );
                processedUrls.add(url);
                return;
            }

            // Process special URL types
            // 1. YouTube
          if (youtubeRegex.test(processedText)) {
        processedText = processedText.replace(youtubeRegex, (match, videoId) => {
            return `
                <div class="youtube-preview">
                    <iframe 
                        width="100%" 
                        height="200" 
                        src="https://www.youtube.com/embed/${videoId}" 
                        frameborder="0" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                        allowfullscreen
                        loading="lazy">
                    </iframe>
                </div>
            `;
        });
    }

            // 2. Tenor GIFs
            if (tenorRegex.test(url)) {
                const cleanUrl = url.replace(/(\.gif).*$/, '$1');
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<img src="${cleanUrl}" class="max-w-xs rounded-lg mt-2" alt="GIF" loading="lazy" onerror="this.style.display='none'">`
                );
                processedUrls.add(url);
                return;
            }

            // 3. Discord CDN
            if (discordCdnRegex.test(url)) {
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<img src="${url}" class="max-w-xs rounded-lg mt-2" alt="Image" loading="lazy" onerror="this.style.display='none'">`
                );
                processedUrls.add(url);
                return;
            }

            // 4. Regular images
            if (imageRegex.test(url)) {
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<img src="${url}" class="max-w-xs rounded-lg mt-2" alt="Image" loading="lazy" onerror="this.style.display='none'">`
                );
                processedUrls.add(url);
                return;
            }

            // 5. All other URLs
            processedText = processedText.replace(
                new RegExp(escapeRegExp(url), 'g'),
                `<a href="${url}" target="_blank" class="text-blue-400 hover:underline">${url}</a>`
            );
            processedUrls.add(url);

        } catch (e) {
            // Fallback for invalid URLs
            processedText = processedText.replace(
                new RegExp(escapeRegExp(url), 'g'),
                `<a href="${url}" target="_blank" class="text-blue-400 hover:underline">${url}</a>`
            );
            processedUrls.add(url);
        }
    });

    return processedText;
}

// Helper function to escape regex special characters
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
// Mesaj elementi oluşturmak için bir fonksiyon
function createNewMessageElement(message, previousMessageData = null) {
    console.log('[DEBUG] Message object:', message); // Mesaj nesnesini logla
    const messageDiv = document.createElement('div');
    messageDiv.className = 'flex items-start space-x-3 message-bg p-8 rounded-lg relative message-container';
    messageDiv.dataset.messageId = message.id;
    messageDiv.dataset.senderId = message.sender_id;
    messageDiv.dataset.username = message.username || 'Bilinmeyen Kullanıcı'; // Kullanıcı adını ekle
    messageDiv.dataset.timestamp = message.created_at_unix;
    messageDiv.dataset.pinned = message.is_pinned ? 'true' : 'false';
    messageDiv.dataset.isProcessing = 'false';

    // Yığınlama kontrolü
    function areMessagesStackable(currentMsgData, prevMsgData) {
        if (!prevMsgData) return false;
        const timeDiffSeconds = currentMsgData.timestamp - prevMsgData.timestamp;
        if (timeDiffSeconds < 0 || timeDiffSeconds >= 300) return false;
        
        const currentDate = new Date(currentMsgData.timestamp * 1000);
        const previousDate = new Date(prevMsgData.timestamp * 1000);
        return currentMsgData.sender_id === prevMsgData.sender_id && 
               currentDate.getDate() === previousDate.getDate() &&
               currentDate.getMonth() === previousDate.getMonth() &&
               currentDate.getFullYear() === previousDate.getFullYear();
    }

    const shouldStack = areMessagesStackable(
        { sender_id: message.sender_id, timestamp: message.created_at_unix },
        previousMessageData
    );

    if (shouldStack) {
        messageDiv.classList.add('stacked-message');
    }

// Avatar
const avatarDiv = document.createElement('div');
avatarDiv.className = 'message-avatar w-10 h-10 relative flex items-center justify-center';
avatarDiv.style.overflow = 'visible'; // Prevent clipping

if (shouldStack) {
    avatarDiv.classList.add('invisible');
}

if (message.avatar_url) {
    // Avatar image
    const avatarImg = document.createElement('img');
    avatarImg.src = message.avatar_url;
    avatarImg.alt = `${message.username}'s avatar`;
    avatarImg.className = 'w-10 h-10 object-cover rounded-full z-1';
    avatarDiv.appendChild(avatarImg);

    // Avatar frame
    if (message.avatar_frame_url && !message.avatar_frame_url.includes('default-frame.png')) {
        const frameImg = document.createElement('img');
        frameImg.src = message.avatar_frame_url;
        frameImg.alt = `${message.username}'s avatar frame`;
        frameImg.className = 'absolute object-contain z-10 max-w-none overflow-visible';
        frameImg.style.width = '54px'; // Smaller size
        frameImg.style.height = '54px';
        frameImg.style.transform = 'translate(-50%, -50%)';
        frameImg.style.left = '50%';
        frameImg.style.top = '50%';
        avatarDiv.appendChild(frameImg);
    }
} else {
    // Fallback for no avatar
    const avatarFallback = document.createElement('span');
    avatarFallback.className = 'text-white text-sm font-medium z-1';
    avatarFallback.textContent = (message.username || 'U').charAt(0).toUpperCase();
    avatarDiv.className += ' bg-indigo-500 rounded-full'; // Add blue background and rounded shape
    avatarDiv.appendChild(avatarFallback);

    // Avatar frame for fallback
    if (message.avatar_frame_url && !message.avatar_frame_url.includes('default-frame.png')) {
        const frameImg = document.createElement('img');
        frameImg.src = message.avatar_frame_url;
        frameImg.alt = `${message.username}'s avatar frame`;
        frameImg.className = 'absolute object-contain z-10 max-w-none overflow-visible';
        frameImg.style.width = '54px'; // Smaller size
        frameImg.style.height = '54px';
        frameImg.style.transform = 'translate(-50%, -50%)';
        frameImg.style.left = '50%';
        frameImg.style.top = '50%';
        avatarDiv.appendChild(frameImg);
    }
}

messageDiv.style.overflow = 'visible'; // Prevent clipping by parent
messageDiv.appendChild(avatarDiv);

    // Mesaj İçeriği
    const contentDiv = document.createElement('div');
    contentDiv.className = 'flex-1 relative';

    // Header
    const headerDiv = document.createElement('div');
    headerDiv.className = 'flex items-center justify-between mb-1 message-header';
    
    // Kullanıcı bilgileri
    const userInfoDiv = document.createElement('div');
    userInfoDiv.className = 'flex items-center';
    
    const usernameSpan1 = document.createElement('span');
    usernameSpan1.className = 'text-white font-medium mr-2';
    usernameSpan1.style.color = message.role_color || '#ffffff';
   usernameSpan1.textContent = message.display_username || message.username || 'Bilinmeyen Kullanıcı';
    usernameSpan1.style.cursor = 'pointer'; // Tıklanabilir olduğunu belirt
    usernameSpan1.dataset.userId = message.sender_id; // Kullanıcı ID'sini sakla
    usernameSpan1.addEventListener('click', () => {
        openUserProfilePopup(message.sender_id);
    });

    const dateSpan = document.createElement('span');
    dateSpan.className = 'text-gray-400 text-xs timestamp';
    dateSpan.dataset.timestamp = message.created_at_unix;
    
    const timestamp = parseInt(message.created_at_unix);
    if (!isNaN(timestamp)) {
        const date = new Date(timestamp * 1000);
        dateSpan.textContent = date.toLocaleDateString('tr-TR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } else {
        dateSpan.textContent = 'Şimdi';
    }

    userInfoDiv.appendChild(usernameSpan1);
    userInfoDiv.appendChild(dateSpan);
    headerDiv.appendChild(userInfoDiv);
    contentDiv.appendChild(headerDiv);

    // Üç nokta butonu
    const moreOptionsButton = document.createElement('button');
    moreOptionsButton.className = 'more-options text-gray-400 hover:text-white p-1 rounded hover:bg-gray-700 ml-auto';
    moreOptionsButton.innerHTML = '<i data-lucide="more-vertical" class="w-5 h-5"></i>';

    // Options menü
    const optionsMenu = document.createElement('div');
    optionsMenu.className = 'options-menu absolute right-0 top-full mt-1 w-48 bg-gray-800 rounded-md shadow-lg hidden z-10';
    
    const optionsList = document.createElement('ul');
    const currentUserId1JS = <?php echo isset($_SESSION['user_id']) ? json_encode($_SESSION['user_id']) : 'null'; ?>;
    
    // Mesaj düzenleme seçeneği (sadece mesaj sahibi)
    if (message.sender_id == currentUserId1JS) {
        const editLi = document.createElement('li');
        editLi.className = 'flex items-center px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white cursor-pointer';
        editLi.dataset.action = 'edit-message';
        editLi.dataset.messageId = message.id;
        editLi.innerHTML = '<i data-lucide="edit" class="w-4 h-4 mr-2"></i> Düzenle';
        optionsList.appendChild(editLi);
    }

    // Mesaj silme seçeneği (mesaj sahibi veya sunucu sahibi/yönetici)
    if (message.sender_id == currentUserId1JS || isOwner || hasKickPermission) {
        const deleteLi = document.createElement('li');
        deleteLi.className = 'flex items-center px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white cursor-pointer';
        deleteLi.dataset.action = 'delete-message';
        deleteLi.dataset.messageId = message.id;
        deleteLi.innerHTML = '<i data-lucide="trash-2" class="w-4 h-4 mr-2"></i> Sil';
        optionsList.appendChild(deleteLi);
    }

    // Mesaj sabitleme seçeneği (sadece yetkililer)
    if (hasKickPermission || isOwner) {
        const pinLi = document.createElement('li');
        pinLi.className = 'flex items-center px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white cursor-pointer';
        pinLi.dataset.action = 'pin-message';
        pinLi.dataset.messageId = message.id;
        pinLi.innerHTML = message.is_pinned ? 
            '<i data-lucide="pin-off" class="w-4 h-4 mr-2"></i> Sabitlemeyi Kaldır' : 
            '<i data-lucide="pin" class="w-4 h-4 mr-2"></i> Sabitle';
        optionsList.appendChild(pinLi);
    }

    // Yanıtlama seçeneği (yazma izni olanlar için)
    const replyLi = document.createElement('li');
    replyLi.className = 'flex items-center px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white cursor-pointer';
    replyLi.dataset.action = 'reply-message';
    replyLi.dataset.messageId = message.id;
    replyLi.dataset.username = message.username || 'Bilinmeyen Kullanıcı'; // Kullanıcı adını ekle
    replyLi.innerHTML = '<i data-lucide="corner-down-left" class="w-4 h-4 mr-2"></i> Yanıtla';
    optionsList.appendChild(replyLi);

    // Reaksiyon ekleme seçeneği
    const reactionLi = document.createElement('li');
    reactionLi.className = 'flex items-center px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white cursor-pointer';
    reactionLi.dataset.action = 'react-message';
    reactionLi.dataset.messageId = message.id;
    reactionLi.innerHTML = '<i data-lucide="smile" class="w-4 h-4 mr-2"></i> Reaksiyon Ekle';
    optionsList.appendChild(reactionLi);

    optionsMenu.appendChild(optionsList);
    contentDiv.appendChild(moreOptionsButton);
    contentDiv.appendChild(optionsMenu);

    // Mesaj metni ve medya
    const messageTextAndMediaDiv = document.createElement('div');
    messageTextAndMediaDiv.className = 'message-text-media-container';

    // Yanıtlanan mesaj varsa
    if (message.reply_to_message_id) {
        const replyContainer = document.createElement('div');
        replyContainer.className = 'reply-message-container mb-2';

        fetch(`get_message.php?message_id=${message.reply_to_message_id}`)
            .then(response => response.json())
            .then(originalMessage => {
                if (originalMessage && !originalMessage.error) {
                    const flexContainer = document.createElement('div');
                    flexContainer.className = 'flex items-center space-x-2';

                    const originalAvatarDiv = document.createElement('div');
                    originalAvatarDiv.className = 'w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500';
                    
                    if (originalMessage.avatar_url) {
                        const originalAvatarImg = document.createElement('img');
                        originalAvatarImg.src = originalMessage.avatar_url;
                        originalAvatarImg.className = 'w-full h-full object-cover rounded-full';
                        originalAvatarDiv.appendChild(originalAvatarImg);
                    } else {
                        const originalAvatarText = document.createElement('span');
                        originalAvatarText.className = 'text-white text-sm font-medium';
                        originalAvatarText.textContent = originalMessage.username.charAt(0).toUpperCase();
                        originalAvatarDiv.appendChild(originalAvatarText);
                    }

                    const originalUserInfoDiv = document.createElement('div');
                    originalUserInfoDiv.className = 'text-gray-400 text-xs';
                    
                    const usernameSpan1 = document.createElement('span');
                    usernameSpan1.className = 'text-white font-medium';
                    usernameSpan1.textContent = originalMessage.username;
                    
                    const tarafindanSpan = document.createElement('span');
                    tarafindanSpan.className = 'ml-2';
                    tarafindanSpan.textContent = 'tarafından:';

                    originalUserInfoDiv.appendChild(usernameSpan1);
                    originalUserInfoDiv.appendChild(tarafindanSpan);
                    flexContainer.appendChild(originalAvatarDiv);
                    flexContainer.appendChild(originalUserInfoDiv);
                    replyContainer.appendChild(flexContainer);

                    const originalMessageText = document.createElement('div');
                    originalMessageText.className = 'text-gray-300 text-sm mt-1';
                    originalMessageText.textContent = originalMessage.message_text;
                    replyContainer.appendChild(originalMessageText);
                }
            })
            .catch(error => console.error('Orijinal mesaj yükleme hatası:', error));

        messageTextAndMediaDiv.appendChild(replyContainer);
    }

    // Anket kontrolü
    let isPoll = false;
    let pollData = {};

    // JSON geçerliliğini kontrol eden fonksiyon
    function isValidJSON(str) {
        if (typeof str !== 'string' || !str.trim()) return false; // Boş string veya string değilse false
        try {
            JSON.parse(str);
            return true;
        } catch (e) {
            return false;
        }
    }

    // Development ortamı kontrolü (tarayıcı uyumlu)
    const isDev = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

    // Mesaj içeriğini işle
    if ((message.message_type === 'poll' || isValidJSON(message.message_text)) && isValidJSON(message.message_text)) {
        try {
            pollData = JSON.parse(message.message_text);
            // Anket formatını doğrula
            if (
                pollData &&
                pollData.type === 'poll' &&
                typeof pollData.question === 'string' &&
                Array.isArray(pollData.options) &&
                Array.isArray(pollData.votes) &&
                pollData.options.length === pollData.votes.length
            ) {
                isPoll = true;
            } else {
                if (isDev) {
                    console.warn(`Mesaj JSON formatında ama geçerli anket değil: ${message.message_text}`);
                }
            }
        } catch (e) {
            if (isDev) {
                console.warn(`Mesaj JSON parse hatası: ${message.message_text}`, e);
            }
        }
    } else {
        if (isDev) {
            console.warn(`Mesaj geçerli JSON değil veya anket değil: ${message.message_text}`);
        }
    }

    // Anket veya düz metin gösterimi
    if (isPoll) {
        const pollContainer = document.createElement('div');
        pollContainer.className = 'poll-container bg-[#2f3136] p-4 rounded-lg mt-2';

        const pollQuestion = document.createElement('h3');
        pollQuestion.className = 'text-white font-semibold mb-2';
        pollQuestion.textContent = pollData.question || 'Anket Sorusu';
        pollContainer.appendChild(pollQuestion);

        pollData.options.forEach((option, index) => {
            const pollOption = document.createElement('div');
            pollOption.className = 'poll-option flex items-center justify-between p-2 rounded hover:bg-[#36393f] cursor-pointer';
            pollOption.dataset.messageId = message.id;
            pollOption.dataset.optionIndex = index;

            const optionText = document.createElement('span');
            optionText.textContent = option || `Seçenek ${index + 1}`;
            pollOption.appendChild(optionText);

            const voteCount = document.createElement('span');
            voteCount.className = 'text-gray-400';
            voteCount.textContent = `${pollData.votes[index] || 0} oy`;
            pollOption.appendChild(voteCount);

            pollContainer.appendChild(pollOption);
        });

        messageTextAndMediaDiv.appendChild(pollContainer);
    } else {
        // Düz metin mesajı
        const messageText = document.createElement('div');
        messageText.className = 'text-white text-sm mt-1 message-text';
        messageText.innerHTML = processMessageText(message.message_text);
        messageTextAndMediaDiv.appendChild(messageText);
    }

    // File attachment processing
    if (message.file_path) {
        let filePaths;
        try {
            filePaths = JSON.parse(message.file_path);
            if (!Array.isArray(filePaths)) {
                filePaths = [message.file_path]; // Backward compatibility for single files
            }
        } catch (e) {
            filePaths = [message.file_path]; // If parsing fails, treat as single file
        }

        const imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
        const videoTypes = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
        const audioTypes = ['mp3', 'wav', 'ogg', 'm4a'];

        // Handle files individually
        filePaths.forEach(filePath => {
            const fileExtension = filePath.split('.').pop().toLowerCase();

            if (imageTypes.includes(fileExtension)) {
                const mediaContainer = document.createElement('div');
                mediaContainer.className = 'mt-2';

                const img = document.createElement('img');
                img.src = filePath;
                img.alt = 'Yüklenen içerik';
                img.className = 'uploaded-media rounded-lg cursor-pointer max-w-[200px] h-auto';
                img.style.maxHeight = '200px';
                img.crossOrigin = 'anonymous';
                
                img.addEventListener('click', () => {
                });
                
                mediaContainer.appendChild(img);
                messageTextAndMediaDiv.appendChild(mediaContainer);
            } else if (videoTypes.includes(fileExtension)) {
                const videoContainer = document.createElement('div');
                videoContainer.className = 'video-player-container relative bg-[#161616] rounded-lg overflow-hidden shadow-lg';
                videoContainer.style.maxWidth = '320px';
                videoContainer.style.width = '100%';
                videoContainer.style.backgroundSize = 'cover';
                videoContainer.style.backgroundPosition = 'center';

                const thumbnail = document.createElement('div');
                thumbnail.className = 'thumbnail w-full h-auto bg-gray-800 rounded-lg relative';
                thumbnail.style.backgroundImage = 'url(/video_placeholder.png)';
                thumbnail.style.backgroundSize = 'cover';
                thumbnail.style.backgroundPosition = 'center';

                const loader = document.createElement('div');
                loader.className = 'absolute inset-0 flex items-center justify-center';
                loader.innerHTML = `<i data-lucide="loader-2" class="w-8 h-8 text-white animate-spin"></i>`;

                const video = document.createElement('video');
                video.className = 'w-full h-auto rounded-lg hidden';
                video.preload = 'metadata';
                video.innerHTML = `<source src="${filePath}" type="video/${fileExtension}">`;
                video.crossOrigin = 'anonymous';

                const controls = document.createElement('div');
                controls.className = 'video-controls absolute bottom-0 left-0 right-0 bg-black bg-opacity-70 p-2 flex items-center space-x-3 transition-opacity duration-200';
                controls.style.opacity = '0';
                controls.style.transform = 'translateY(100%)';

                videoContainer.addEventListener('mouseenter', () => {
                    if (!thumbnail.classList.contains('hidden')) return;
                    controls.style.opacity = '1';
                    controls.style.transform = 'translateY(0)';
                });
                videoContainer.addEventListener('mouseleave', () => {
                    if (!video.paused) {
                        controls.style.opacity = '0';
                        controls.style.transform = 'translateY(100%)';
                    }
                });

                const playPauseBtn = document.createElement('button');
                playPauseBtn.className = 'play-pause-btn bg-[#3CB371] hover:bg-[#2E8B57] w-8 h-8 rounded-full flex items-center justify-center transition-colors duration-200';
                playPauseBtn.innerHTML = `<i data-lucide="play" class="w-4 h-4 text-white"></i>`;
                playPauseBtn.setAttribute('data-playing', 'false');

                const progressContainer = document.createElement('div');
                progressContainer.className = 'flex-1 flex items-center';
                const progressBar = document.createElement('div');
                progressBar.className = 'bg-gray-600 h-1 rounded-full w-full relative cursor-pointer';
                const progress = document.createElement('div');
                progress.className = 'bg-[#3CB371] h-full rounded-full';
                progress.style.width = '0%';
                progressBar.appendChild(progress);

                const timeDisplay = document.createElement('div');
                timeDisplay.className = 'flex justify-between text-xs text-gray-300 w-24';
                const currentTime = document.createElement('span');
                currentTime.textContent = '0:00';
                const duration = document.createElement('span');
                duration.textContent = '0:00';
                timeDisplay.appendChild(currentTime);
                timeDisplay.appendChild(duration);

                const volumeControl = document.createElement('div');
                volumeControl.className = 'flex items-center space-x-1';
                const volumeIcon = document.createElement('i');
                volumeIcon.setAttribute('data-lucide', 'volume-2');
                volumeIcon.className = 'w-4 h-4 text-gray-300';
                const volumeSlider = document.createElement('input');
                volumeSlider.type = 'range';
                volumeSlider.min = '0';
                volumeSlider.max = '1';
                volumeSlider.step = '0.01';
                volumeSlider.value = '1';
                volumeSlider.className = 'w-12 h-1 bg-gray-600 rounded-full';
                volumeControl.appendChild(volumeIcon);
                volumeControl.appendChild(volumeSlider);

                const fullscreenBtn = document.createElement('button');
                fullscreenBtn.className = 'fullscreen-btn bg-gray-700 hover:bg-gray-600 w-8 h-8 rounded-full flex items-center justify-center transition-colors duration-200';
                fullscreenBtn.innerHTML = `<i data-lucide="maximize" class="w-4 h-4 text-white"></i>`;

                controls.appendChild(playPauseBtn);
                controls.appendChild(progressContainer);
                progressContainer.appendChild(progressBar);
                controls.appendChild(timeDisplay);
                controls.appendChild(volumeControl);
                controls.appendChild(fullscreenBtn);

                const playButton = document.createElement('div');
                playButton.className = 'play-button absolute inset-0 flex items-center justify-center opacity-90 hover:opacity-100 transition-opacity cursor-pointer';
                playButton.innerHTML = `
                    <div class="w-12 h-12 bg-black/50 rounded-full flex items-center justify-center">
                        <i data-lucide="play" class="w-6 h-6 text-white"></i>
                    </div>
                `;

                videoContainer.appendChild(thumbnail);
                videoContainer.appendChild(loader);
                videoContainer.appendChild(video);
                videoContainer.appendChild(controls);
                videoContainer.appendChild(playButton);
                messageTextAndMediaDiv.appendChild(videoContainer);

                video.addEventListener('loadeddata', async () => {
                    try {
                        video.currentTime = 0.1;
                        await new Promise(resolve => {
                            video.addEventListener('seeked', resolve, { once: true });
                            setTimeout(resolve, 1000);
                        });
                        const canvas = document.createElement('canvas');
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                        thumbnail.style.aspectRatio = `${video.videoWidth}/${video.videoHeight}`;
                        thumbnail.style.backgroundImage = `url(${canvas.toDataURL()})`;

                        const blurCanvas = document.createElement('canvas');
                        blurCanvas.width = video.videoWidth / 4;
                        blurCanvas.height = video.videoHeight / 4;
                        const blurCtx = blurCanvas.getContext('2d');
                        blurCtx.drawImage(video, 0, 0, blurCanvas.width, blurCanvas.height);
                        blurCtx.filter = 'blur(10px)';
                        blurCtx.drawImage(blurCanvas, 0, 0);
                        videoContainer.style.backgroundImage = `url(${blurCanvas.toDataURL()})`;

                        loader.remove();
                        if (window.lucide && window.lucide.createIcons) {
                            lucide.createIcons();
                        }
                    } catch (error) {
                        console.error('Thumbnail or blurred background generation failed:', error);
                        loader.remove();
                        thumbnail.style.backgroundImage = 'url(/video_placeholder.png)';
                        thumbnail.innerHTML = `<div class="text-red-400 p-2">Video could not be loaded.</div>`;
                    }
                });

                video.addEventListener('error', () => {
                    loader.remove();
                    thumbnail.style.backgroundImage = 'url(/video_placeholder.png)';
                    thumbnail.innerHTML = `<div class="text-red-400 p-2">Video could not be loaded.</div>`;
                });

                function togglePlayPause() {
                    if (video.paused) {
                        video.className = 'w-full h-auto rounded-lg';
                        thumbnail.className = 'thumbnail hidden';
                        playButton.className = 'hidden';
                        video.play();
                        playPauseBtn.setAttribute('data-playing', 'true');
                        playPauseBtn.innerHTML = `<i data-lucide="pause" class="w-4 h-4 text-white"></i>`;
                        videoContainer.classList.remove('paused');
                        videoContainer.classList.add('playing');
                        controls.style.opacity = '1';
                        controls.style.transform = 'translateY(0)';
                    } else {
                        video.pause();
                        playPauseBtn.setAttribute('data-playing', 'false');
                        playPauseBtn.innerHTML = `<i data-lucide="play" class="w-4 h-4 text-white"></i>`;
                        videoContainer.classList.remove('playing');
                        videoContainer.classList.add('paused');
                        controls.style.opacity = '1';
                        controls.style.transform = 'translateY(0)';
                    }
                    if (window.lucide && window.lucide.createIcons) {
                        lucide.createIcons();
                    }
                }

                playPauseBtn.addEventListener('click', togglePlayPause);
                playButton.addEventListener('click', togglePlayPause);

                video.addEventListener('timeupdate', () => {
                    const progressPercent = (video.currentTime / video.duration) * 100;
                    progress.style.width = `${progressPercent}%`;
                    currentTime.textContent = formatTime(video.currentTime);
                    duration.textContent = formatTime(video.duration);
                });

                progressBar.addEventListener('click', (e) => {
                    const rect = progressBar.getBoundingClientRect();
                    const pos = (e.clientX - rect.left) / rect.width;
                    video.currentTime = pos * video.duration;
                });

                volumeSlider.addEventListener('input', () => {
                    video.volume = volumeSlider.value;
                    volumeIcon.setAttribute('data-lucide', video.volume === 0 ? 'volume-x' : 'volume-2');
                    if (window.lucide && window.lucide.createIcons) {
                        lucide.createIcons();
                    }
                });

                fullscreenBtn.addEventListener('click', () => {
                    if (!document.fullscreenElement) {
                        videoContainer.requestFullscreen().then(() => {
                            fullscreenBtn.innerHTML = `<i data-lucide="minimize" class="w-4 h-4 text-white"></i>`;
                            video.style.maxWidth = '100%';
                            video.style.maxHeight = '100%';
                            if (window.lucide && window.lucide.createIcons) {
                                lucide.createIcons();
                            }
                        });
                    } else {
                        document.exitFullscreen().then(() => {
                            fullscreenBtn.innerHTML = `<i data-lucide="maximize" class="w-4 h-4 text-white"></i>`;
                            video.style.maxWidth = '320px';
                            video.style.maxHeight = 'none';
                            if (window.lucide && window.lucide.createIcons) {
                                lucide.createIcons();
                            }
                        });
                    }
                });

                document.addEventListener('fullscreenchange', () => {
                    if (!document.fullscreenElement) {
                        fullscreenBtn.innerHTML = `<i data-lucide="maximize" class="w-4 h-4 text-white"></i>`;
                        video.style.maxWidth = '320px';
                        video.style.maxHeight = 'none';
                        if (window.lucide && window.lucide.createIcons) {
                            lucide.createIcons();
                        }
                    }
                });

                video.addEventListener('ended', () => {
                    playPauseBtn.setAttribute('data-playing', 'false');
                    playPauseBtn.innerHTML = `<i data-lucide="play" class="w-4 h-4 text-white"></i>`;
                    progress.style.width = '0%';
                    currentTime.textContent = '0:00';
                    video.className = 'w-full h-auto rounded-lg hidden';
                    thumbnail.className = 'thumbnail w-full h-auto bg-gray-800 rounded-lg relative';
                    thumbnail.style.aspectRatio = `${video.videoWidth}/${video.videoHeight}`;
                    playButton.className = 'play-button absolute inset-0 flex items-center justify-center opacity-90 hover:opacity-100 transition-opacity cursor-pointer';
                    videoContainer.classList.remove('playing');
                    videoContainer.classList.add('paused');
                    controls.style.opacity = '1';
                    controls.style.transform = 'translateY(0)';
                    if (window.lucide && window.lucide.createIcons) {
                        lucide.createIcons();
                    }
                });
            } else if (audioTypes.includes(fileExtension)) {
                const audioContainer = document.createElement('div');
                audioContainer.className = 'audio-player-container relative p-3 bg-gray-800 rounded-lg flex items-center space-x-3 w-full max-w-md';
                audioContainer.innerHTML = `
                    <button class="audio-control play-pause-btn bg-[#3CB371] hover:bg-[#2E8B57] w-10 h-10 rounded-full flex items-center justify-center transition-colors duration-200" data-playing="false">
                        <i data-lucide="play" class="w-5 h-5 text-white"></i>
                    </button>
                    <div class="flex-1">
                        <div class="audio-progress-bar bg-gray-600 h-1.5 rounded-full overflow-hidden">
                            <div class="progress bg-[#3CB371] h-full" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-gray-400 mt-1">
                            <span class="current-time">0:00</span>
                            <span class="duration">0:00</span>
                        </div>
                    </div>
                    <div class="volume-control flex items-center space-x-2">
                        <i data-lucide="volume-2" class="w-5 h-5 text-gray-300"></i>
                        <input type="range" min="0" max="1" step="0.01" value="1" class="volume-slider w-16 h-1 bg-gray-600 rounded-full">
                    </div>
                `;
                const audio = document.createElement('audio');
                audio.src = filePath;
                audio.preload = 'metadata';
                audio.crossOrigin = 'anonymous';

                const playPauseBtn = audioContainer.querySelector('.play-pause-btn');
                const progressBar = audioContainer.querySelector('.progress');
                const currentTime = audioContainer.querySelector('.current-time');
                const duration = audioContainer.querySelector('.duration');
                const volumeSlider = audioContainer.querySelector('.volume-slider');

                audio.addEventListener('loadedmetadata', () => {
                    duration.textContent = formatTime(audio.duration);
                });

                playPauseBtn.addEventListener('click', () => {
                    if (audio.paused) {
                        audio.play();
                        playPauseBtn.setAttribute('data-playing', 'true');
                        playPauseBtn.innerHTML = `<i data-lucide="pause" class="w-5 h-5 text-white"></i>`;
                    } else {
                        audio.pause();
                        playPauseBtn.setAttribute('data-playing', 'false');
                        playPauseBtn.innerHTML = `<i data-lucide="play" class="w-5 h-5 text-white"></i>`;
                    }
                    if (window.lucide && window.lucide.createIcons) {
                        lucide.createIcons();
                    }
                });

                audio.addEventListener('timeupdate', () => {
                    const progressPercent = (audio.currentTime / audio.duration) * 100;
                    progressBar.style.width = `${progressPercent}%`;
                    currentTime.textContent = formatTime(audio.currentTime);
                });

                volumeSlider.addEventListener('input', () => {
                    audio.volume = volumeSlider.value;
                    const volumeIcon = audioContainer.querySelector('[data-lucide]');
                    volumeIcon.setAttribute('data-lucide', audio.volume === 0 ? 'volume-x' : 'volume-2');
                    if (window.lucide && window.lucide.createIcons) {
                        lucide.createIcons();
                    }
                });

                audio.addEventListener('error', () => {
                    audioContainer.innerHTML = `<div class="text-red-400 p-2">Audio file could not be loaded.</div>`;
                });

                audio.addEventListener('ended', () => {
                    playPauseBtn.setAttribute('data-playing', 'false');
                    playPauseBtn.innerHTML = `<i data-lucide="play" class="w-5 h-5 text-white"></i>`;
                    progressBar.style.width = '0%';
                    currentTime.textContent = '0:00';
                    if (window.lucide && window.lucide.createIcons) {
                        lucide.createIcons();
                    }
                });

                audioContainer.appendChild(audio);
                messageTextAndMediaDiv.appendChild(audioContainer);
            } else {
                const fileLink = document.createElement('a');
                fileLink.href = filePath;
                fileLink.className = 'flex items-center p-2 bg-gray-800 rounded hover:bg-gray-700 transition';
                fileLink.innerHTML = `
                    <i data-lucide="file" class="w-6 h-6 mr-2"></i>
                    <span>${message.file_name || filePath.split('/').pop() || 'Download File'}</span>
                `;
                fileLink.target = '_blank';
                fileLink.download = message.file_name || filePath.split('/').pop() || 'file';
                messageTextAndMediaDiv.appendChild(fileLink);
            }
        });

        if (window.lucide && window.lucide.createIcons) {
            lucide.createIcons();
        }
    }

    // Özel CSS ve Twemoji enjeksiyonu (sadece bir kez)
    if (!window.chatStylesAdded) {
        const style = document.createElement('style');
        style.innerHTML = `
            .uploaded-media {
                max-width: 400px;
                max-height: 300px;
                width: 100%;
                height: auto;
                object-fit: contain;
                border-radius: 8px;
                margin: 8px 0;
                background: #000;
            }
            video.uploaded-media {
                aspect-ratio: 16/9;
            }
            @media (max-width: 768px) {
                .uploaded-media {
                    max-width: 250px;
                    max-height: 200px;
                }
            }
            .preview-video {
                max-width: 280px;
                max-height: 200px;
                border-radius: 6px;
            }
            .media-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.9);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 10000;
                cursor: pointer;
            }
            .video-thumbnail-container {
                position: relative;
                cursor: pointer;
                max-width: 400px;
            }
            .play-button {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(0,0,0,0.6);
                border-radius: 50%;
                width: 60px;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .poll-container {
                background: #2f3136;
                padding: 16px;
                border-radius: 8px;
                margin-top: 8px;
            }
            .poll-option {
                display: flex;
                justify-content: space-between;
                padding: 8px;
                border-radius: 4px;
            }
            .poll-option:hover {
                background: #36393f;
                cursor: pointer;
            }
            .emoji {
                display: inline-block;
                vertical-align: middle;
                width: 18px;
                height: 18px;
                margin-bottom:5px;
            }
        `;
        document.head.appendChild(style);

        // Twemoji CDN
        const twemojiScript = document.createElement('script');
        twemojiScript.src = 'https://cdn.jsdelivr.net/npm/twemoji@14.0.2/dist/twemoji.min.js';
        twemojiScript.crossOrigin = 'anonymous';
        document.head.appendChild(twemojiScript);

        window.chatStylesAdded = true;
    }

    contentDiv.appendChild(messageTextAndMediaDiv);

    // Reaksiyonlar
    const reactionContainer = document.createElement('div');
    reactionContainer.className = 'flex flex-wrap gap-2 mt-2';
    contentDiv.appendChild(reactionContainer);

    // Formlar
    const replyFormDiv = document.createElement('div');
    replyFormDiv.className = 'reply-message-form hidden mt-2';
    replyFormDiv.dataset.messageId = message.id;
    replyFormDiv.innerHTML = `
        <form class="inline">
            <input type="hidden" name="server_id" value="${message.server_id || <?php echo json_encode($server_id); ?>}">
            <input type="hidden" name="channel_id" value="${message.channel_id || <?php echo json_encode($channel_id); ?>}">
            <input type="hidden" name="original_message_id" value="${message.id}">
            <textarea name="reply_text" class="w-full bg-gray-700 text-white px-3 py-2 rounded" placeholder="Yanıtınızı yazın..."></textarea>
            <div class="flex justify-end mt-2">
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Yanıtla</button>
                <button type="button" class="cancel-reply text-gray-400 hover:text-gray-300 p-2 rounded ml-2">İptal</button>
            </div>
        </form>`;
    contentDiv.appendChild(replyFormDiv);

    if (message.sender_id == currentUserId1JS) {
        const editFormDiv = document.createElement('div');
        editFormDiv.className = 'edit-message-form hidden mt-2';
        editFormDiv.dataset.messageId = message.id;
        editFormDiv.innerHTML = `
            <form class="edit-message-form-ajax">
                <textarea class="w-full bg-gray-700 text-white px-3 py-2 rounded">${message.message_text}</textarea>
                <div class="flex justify-end mt-2">
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Kaydet</button>
                    <button type="button" class="cancel-edit text-gray-400 hover:text-gray-300 p-2 rounded ml-2">İptal</button>
                </div>
            </form>`;
        contentDiv.appendChild(editFormDiv);
    }

    messageDiv.appendChild(contentDiv);

    // Etkileşimler
    moreOptionsButton.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.options-menu').forEach(menu => {
            if (menu !== optionsMenu) menu.classList.add('hidden');
        });
        optionsMenu.classList.toggle('hidden');
    });

    optionsList.addEventListener('click', (e) => {
        const action = e.target.closest('li')?.dataset.action;
        if (!action) return;

        optionsMenu.classList.add('hidden');
        
        switch(action) {
            case 'edit-message':
                messageDiv.querySelector('.edit-message-form').classList.toggle('hidden');
                break;
            case 'reply-message':
                break;
            case 'delete-message':
                // Silme işlemi buraya
                break;
        }
    });

    const cancelEdit = messageDiv.querySelector('.cancel-edit');
    if (cancelEdit) {
        cancelEdit.addEventListener('click', () => {
            messageDiv.querySelector('.edit-message-form').classList.add('hidden');
        });
    }

    const cancelReply = messageDiv.querySelector('.cancel-reply');
    if (cancelReply) {
        cancelReply.addEventListener('click', () => {
            messageDiv.querySelector('.reply-message-form').classList.add('hidden');
        });
    }

    const editForm = messageDiv.querySelector('.edit-message-form-ajax');
    if (editForm) {
        editForm.addEventListener('submit', (e) => handleEditSubmit(e, message.id));
    }

    // Reaksiyonları yükle
    fetch(`get_reactions.php?message_id=${message.id}`)
        .then(response => response.ok ? response.json() : Promise.reject(response.status))
        .then(reactions => {
            reactions.forEach(reaction => {
                const reactionDiv = document.createElement('div');
                reactionDiv.className = 'flex items-center bg-gray-700 rounded-full px-2 py-1 cursor-pointer hover:bg-gray-600';
                reactionDiv.dataset.emoji = reaction.emoji;
                reactionDiv.dataset.messageId = message.id;

                const userIds = reaction.user_ids?.split(',').map(Number) || [];
                if (userIds.includes(Number(currentUserId1JS))) {
                    reactionDiv.classList.add('bg-blue-500');
                }

                const emojiSpan = document.createElement('span');
                emojiSpan.className = 'emoji text-lg';
                emojiSpan.textContent = reaction.emoji;
                reactionDiv.appendChild(emojiSpan);

                const countSpan = document.createElement('span');
                countSpan.className = 'count text-sm ml-1';
                countSpan.textContent = reaction.count;
                reactionDiv.appendChild(countSpan);

                reactionDiv.addEventListener('click', () => handleReactionClick(message.id, reaction.emoji));

                reactionContainer.appendChild(reactionDiv);

                // Twemoji ile emojiyi işle
                if (window.twemoji) {
                    window.twemoji.parse(emojiSpan, {
                        folder: 'svg',
                        ext: '.svg',
                        className: 'emoji',
                        attributes: () => ({
                            style: 'width: 24px; height: 24px; vertical-align: middle;'
                        })
                    });
                }
            });

            // Mesaj metnindeki emojileri de işle
            if (window.twemoji && messageTextAndMediaDiv.querySelector('.message-text')) {
                window.twemoji.parse(messageTextAndMediaDiv.querySelector('.message-text'), {
                    folder: 'svg',
                    ext: '.svg',
                    className: 'emoji',
                    attributes: () => ({
                        style: 'width: 18px; height: 18px; vertical-align: middle;'
                    })
                });
            }
        })
        .catch(error => console.error('Reaksiyon yükleme hatası:', error));

    lucide.createIcons();
    return messageDiv;
}

// Helper function to format time
function formatTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`;
}
let replyToMessageId = null;


function setReplyContext(messageId, username) {
    replyToMessageId = messageId;
    const messageInput = document.getElementById('message-input');
    const messageForm = document.getElementById('message-form');

    if (!messageForm || !messageInput) {
        console.error('[DEBUG] Cannot set reply context: message-form or message-input not found', {
            messageForm: messageForm,
            messageInput: messageInput
        });
        showToast('error', '<?php echo $translations['form_not_found'] ?? 'Mesaj formu veya input alanı bulunamadı!'; ?>');
        return;
    }

    // XSS önlemi için kullanıcı adını kaçış yap
    const escapedUsername = username.replace(/</g, '<').replace(/>/g, '>');

 const replyIndicator = document.createElement('div');
replyIndicator.id = 'reply-indicator';
replyIndicator.className = 'text-base mb-2 px-3 py-2 rounded-md flex items-center';
replyIndicator.style.backgroundColor = 'rgba(65, 70, 100, 0.5)';
replyIndicator.innerHTML = `
    <i data-lucide="reply" class="w-4 h-4 mr-2 text-indigo-300 flex-shrink-0"></i>
    <span class="text-gray-200 font-medium flex-grow truncate pr-2">${escapedUsername}</span>
    <button id="cancel-reply" class="text-gray-400 hover:text-gray-100 transition-colors ml-2 flex-shrink-0">
        <i data-lucide="x" class="w-6 h-6"></i>
    </button>
`;

    const existingIndicator = document.getElementById('reply-indicator');
    if (existingIndicator) {
        existingIndicator.remove();
    }

    messageForm.parentElement.insertBefore(replyIndicator, messageForm);
    lucide.createIcons();
    messageInput.focus();
    console.log(`[DEBUG] Reply context set: messageId=${messageId}, username=${escapedUsername}`);
}
// Function to clear reply context
function clearReplyContext() {
    replyToMessageId = null;
    const replyIndicator = document.getElementById('reply-indicator');
    if (replyIndicator) {
        replyIndicator.remove();
    }
    console.log('[DEBUG] Reply context cleared');
}
function showToast(type, message) {
    alert(`${type === 'success' ? 'Başarılı' : 'Hata'}: ${message}`);
}

document.addEventListener('click', function(event) {
    const openMenus = document.querySelectorAll('.options-menu.absolute:not(.hidden)');
    openMenus.forEach(menu => {
        if (!menu.contains(event.target) && !event.target.closest('.more-options')) {
            menu.classList.add('hidden');
        }
    });
});

document.addEventListener('click', (e) => {
    const optionsMenu = e.target.closest('.options-menu');
    const moreOptionsButton = e.target.closest('.more-options');
    
    // Tüm açık menüleri kapat
    document.querySelectorAll('.options-menu').forEach(menu => {
        if (menu !== optionsMenu && !moreOptionsButton) {
            menu.classList.add('hidden');
        }
    });

    // Menü butonuna tıklama
    if (moreOptionsButton) {
        const menu = moreOptionsButton.nextElementSibling;
        if (menu && menu.classList.contains('options-menu')) {
            menu.classList.toggle('hidden');
        }
    }

    // Menü seçeneğine tıklama
    const actionButton = e.target.closest('[data-action]');
    if (actionButton) {
        const action = actionButton.dataset.action;
        const messageId = actionButton.dataset.messageId;
        const messageElement = actionButton.closest('.message-bg');

        // Menüyü kapat
        const menu = actionButton.closest('.options-menu');
        if (menu) {
            menu.classList.add('hidden');
        }

        switch (action) {
            case 'edit-message':
                const editForm = messageElement.querySelector('.edit-message-form');
                if (editForm) {
                    editForm.classList.toggle('hidden');
                }
                break;
            case 'delete-message':
                // Silme işlemi (mevcut kodunuz varsa burada)
                break;
           case 'reply-message':
    const usernameElement = messageElement.querySelector('.font-medium');
    const username = usernameElement ? usernameElement.textContent : `User_${messageId}`;
    console.log(`[DEBUG] Reply action: messageId=${messageId}, username=${username}`);
    setReplyContext(messageId, username);
    break;
            case 'react-message':
                showEmojiPicker(messageId, messageElement);
                break;
        }
    }
});
document.addEventListener('click', (e) => {
    if (e.target.closest('#cancel-reply')) {
        clearReplyContext();
    }
});
document.addEventListener('click', (e) => {
    const deleteBtn = e.target.closest('[data-action="delete-message"]');
    if (deleteBtn) {
        const messageId = deleteBtn.dataset.messageId;
        const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
        
        fetch('deleteserver_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `message_id=${messageId}`
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Mesajı animasyonla kaldır
                messageElement.style.opacity = '0';
                setTimeout(() => messageElement.remove(), 200);
            } else {
                // Sadece gerçek bir hata mesajı varsa log göster
                if (data.message) {
                    console.error('Silme hatası:', data.message);
                }
            }
        })
        .catch(error => {
            console.error('Hata:', error);
        });
    }
});
document.addEventListener('DOMContentLoaded', function() {
    // Üç nokta butonuna tıklandığında menüyü aç/kapat
    document.querySelectorAll('.more-options').forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation(); // Tıklamanın yayılmasını engelle
            const menu = this.nextElementSibling; // Menüyü al
            const isMenuOpen = !menu.classList.contains('hidden'); // Menü açık mı kontrol et

            // Tüm menüleri kapat
            document.querySelectorAll('.options-menu').forEach(m => {
                if (m !== menu) {
                    m.classList.add('hidden');
                }
            });

            // Seçili menüyü aç/kapat
            menu.classList.toggle('hidden', isMenuOpen);
        });
    });

    // Menü dışında tıklandığında menüyü kapat
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.more-options')) {
            document.querySelectorAll('.options-menu').forEach(menu => {
                menu.classList.add('hidden');
            });
        }
    });

  

    // Mesaj düzenleme işlemi
    document.addEventListener('click', function(event) {
        if (event.target.closest('[data-action="edit-message"]')) {
            const messageId = event.target.closest('[data-action="edit-message"]').dataset.messageId;
            const editForm = document.querySelector(`.edit-message-form[data-message-id="${messageId}"]`);
            if (editForm) {
                editForm.classList.toggle('hidden');
            }
        }
    });
});
document.getElementById('message-input').addEventListener('input', function() {
    var sendButton = document.getElementById('send-button');
    if (this.value.trim() !== '') {
        sendButton.disabled = false;
    } else {
        sendButton.disabled = true;
    }
});

const typingIndicator1 = document.getElementById('typing-indicator');


function loadAllReactions() {
    console.log('[DEBUG] loadAllReactions çağrıldı');
    const messageDivs = document.querySelectorAll('.message-bg');
    if (messageDivs.length === 0) {
        console.warn('[DEBUG] Mesaj divleri bulunamadı, 500ms sonra tekrar denenecek');
        setTimeout(loadAllReactions, 500); // 500ms sonra tekrar dene
        return;
    }

    // Tüm mesaj ID'lerini topla
    const messageIds = Array.from(messageDivs)
        .map(div => div.dataset.messageId)
        .filter(id => id); // Geçerli ID'leri al
    if (messageIds.length === 0) {
        console.warn('[DEBUG] Geçerli mesaj ID bulunamadı');
        return;
    }

    // Mesaj ID'lerini virgülle birleştir
    const messageIdsQuery = messageIds.join(',');

    // Reaksiyonları toplu olarak yükle
    fetch(`get_reactions.php?message_ids=${messageIdsQuery}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP hatası: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[DEBUG] Reaksiyon verileri alındı:', data);

            // Hata kontrolü
            if (data.error) {
                console.error('[DEBUG] get_reactions.php hatası:', data.error);
                showToast('error', 'Reaksiyonlar yüklenirken hata: ' + data.error);
                return;
            }

            // Her mesaj için reaksiyonları işle
            messageDivs.forEach(div => {
                const messageId = div.dataset.messageId;
                if (!messageId) {
                    console.error('[DEBUG] Mesaj ID bulunamadı:', div);
                    return;
                }

                // Reaksiyon container'ı bul veya oluştur
                let reactionContainer = div.querySelector('.flex-1 .flex.flex-wrap.gap-2.mt-2') || div.querySelector('.flex.flex-wrap.gap-2.mt-2');
                if (!reactionContainer) {
                    console.log(`[DEBUG] Reaksiyon container bulunamadı, oluşturuluyor: messageId=${messageId}`);
                    reactionContainer = document.createElement('div');
                    reactionContainer.className = 'flex flex-wrap gap-2 mt-2';
                    const contentDiv = div.querySelector('.flex-1');
                    if (contentDiv) {
                        contentDiv.appendChild(reactionContainer);
                    } else {
                        div.appendChild(reactionContainer);
                        console.warn('[DEBUG] contentDiv bulunamadı, reactionContainer doğrudan messageDiv’e eklendi:', `messageId=${messageId}`);
                    }
                }

                // Reaksiyonları temizle
                reactionContainer.innerHTML = '';

                // Mesaj için reaksiyonları al
                const reactions = data[messageId] || [];
                console.log(`[DEBUG] Mesaj ${messageId} için reaksiyonlar:`, reactions);

                // Reaksiyonların dizi olduğundan emin ol
                if (!Array.isArray(reactions)) {
                    console.warn(`[DEBUG] Mesaj ${messageId} için reaksiyonlar dizi değil:`, reactions);
                    return;
                }

                const currentUserId1 = <?php echo json_encode($_SESSION['user_id']); ?>;
                reactions.forEach(reaction => {
                    if (!reaction || !reaction.emoji || !reaction.count) {
                        console.warn(`[DEBUG] Geçersiz reaksiyon verisi: messageId=${messageId}`, reaction);
                        return;
                    }

                    const reactionDiv = document.createElement('div');
                    reactionDiv.className = 'flex items-center rounded-full px-2 py-1 cursor-pointer hover:bg-gray-600 transition ease-in-out duration-200';
                    reactionDiv.dataset.emoji = reaction.emoji;
                    reactionDiv.dataset.messageId = messageId;

                    const userIds = reaction.user_ids ? reaction.user_ids.split(',').map(id => parseInt(id)) : [];
                    if (userIds.includes(currentUserId1)) {
                        reactionDiv.classList.add('bg-blue-500');
                    }

                    reactionDiv.innerHTML = `
                        <span class="emoji text-lg">${reaction.emoji}</span>
                        <span class="count text-sm ml-1">${reaction.count}</span>
                    `;
                    reactionDiv.addEventListener('click', () => handleReactionClick(messageId, reaction.emoji));
                    reactionContainer.appendChild(reactionDiv);
                });
            });
        })
        .catch(error => {
            console.error('[DEBUG] Reaksiyonlar yüklenemedi:', error);
            showToast('error', 'Reaksiyonlar yüklenirken bir hata oluştu: ' + error.message);
        });
}


// Mesajlar dinamik olarak yüklendiğinde reaksiyonları yükle
window.addEventListener('messagesLoaded', () => {
    console.log('[DEBUG] messagesLoaded tetiklendi');
    loadAllReactions();
});


// Kullanıcı listesindeki her bir kullanıcı için tıklama olayı ekle
document.querySelectorAll('.user-profile-container .friend-username, .user-profile-container .friend-avatar').forEach(element => {
    element.addEventListener('click', function(event) {
        event.preventDefault(); // Profil sayfasına gitmeyi engelle

        const userId = this.closest('.friend').dataset.userId;
        const username = this.closest('.friend').querySelector('.friend-username').textContent;
        const avatarUrl = this.closest('.friend').querySelector('.friend-avatar img')?.src || '';
        const status = this.closest('.friend').querySelector('.friend-status').textContent;

        openUserProfilePopup(userId, username, avatarUrl, status);
    });
});
async function fetchUserProfile(userId) {
    try {
        const response = await fetch(`get_user_profile.php?user_id=${userId}`);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error fetching user profile:', error);
        return null;
    }
}

// Format last active time to handle MySQL datetime strings
function formatLastActive(timestamp) {
    if (!timestamp) return 'Bilinmiyor';
    const lastActive = new Date(timestamp);
    if (isNaN(lastActive.getTime())) return 'Bilinmiyor';
    const now = new Date();
    const diffMs = now - lastActive;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Şimdi aktif';
    if (diffMins < 60) return `${diffMins} dakika önce`;
    if (diffHours < 24) return `${diffHours} saat önce`;
    if (diffDays < 7) return `${diffDays} gün önce`;
    return lastActive.toLocaleDateString('tr-TR');
}

async function openUserProfilePopup(userId) {
    const userData = await fetchUserProfile(userId);

    if (userData && !userData.error) {
        const popup = document.getElementById('user-profile-popup');
        const popupBackground = document.getElementById('popup-background');
        const popupAvatar = document.getElementById('popup-avatar');
        const popupAvatarInitial = document.getElementById('popup-avatar-initial');
        const popupUsername = document.getElementById('popup-username');
        const popupStatus = document.getElementById('popup-status');
        const popupStatusIndicator = document.getElementById('popup-status-indicator');
        const popupRoles = document.getElementById('popup-roles');
        const popupLastActive = document.getElementById('popup-last-active');
        const popupBio = document.getElementById('popup-bio');
        const popupPostCount = document.getElementById('popup-post-count');
        const popupJoinDate = document.getElementById('popup-join-date');
        const popupFriendsList = document.getElementById('popup-friends-list');
        const popupKickButtonContainer = document.getElementById('popup-kick-button-container');
        const popupBanButtonContainer = document.getElementById('popup-ban-button-container');
        const goToProfileBtn = document.getElementById('go-to-profile-btn');
        const sendMessageBtn = document.getElementById('send-message-btn');

        // Store user ID
        popupUsername.dataset.userId = userId;

        // Avatar handling
        if (userData.avatar_url) {
            popupAvatar.src = userData.avatar_url;
            popupAvatar.classList.remove('hidden');
            popupAvatarInitial.classList.add('hidden');
        } else {
            popupAvatarInitial.textContent = userData.username.charAt(0).toUpperCase();
            popupAvatarInitial.classList.remove('hidden');
            popupAvatar.classList.add('hidden');
        }

        // User info
        popupUsername.textContent = userData.username;
        popupBio.textContent = userData.bio || 'Hakkında bilgi yok.';
        popupPostCount.textContent = userData.post_count || '0';
        popupJoinDate.textContent = new Date(userData.created_at).toLocaleDateString('tr-TR') || 'Bilinmiyor';

        // Fetch user data from server_members
        const serverMembers = <?php echo json_encode($server_members); ?>;
        const userMember = serverMembers.find(member => member.user_id == userId);

// Inside openUserProfilePopup, replace the roles rendering section with:
if (userMember) {
    // Status
    const userStatus = userMember.status;
    popupStatus.textContent = userStatus;
    popupStatusIndicator.classList.remove('online', 'offline');
    popupStatusIndicator.classList.add(userStatus);

    // Parse roles from role_names and role_colors
    const roles = [];
    if (userMember.role_names && userMember.role_colors) {
        const roleNames = userMember.role_names.split(',');
        const roleColors = userMember.role_colors.split(',');
        const roleIds = userMember.role_ids ? userMember.role_ids.split(',') : [];
        roleNames.forEach((name, index) => {
            roles.push({ 
                name: name, 
                color: roleColors[index] || '#5865f2',
                id: roleIds[index] || null 
            });
        });
    }

    // Check permissions for showing the remove button
    const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
    const hasManageRolesPermission = <?php
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = ? AND ur.server_id = ? AND r.permissions LIKE '%manage_roles%'
        ");
        $stmt->execute([$_SESSION['user_id'], $server_id]);
        echo $stmt->fetchColumn() > 0 ? 'true' : 'false';
    ?>;
    const canRemoveRoles = isOwner || hasManageRolesPermission;

    popupRoles.innerHTML = '';
    if (roles.length > 0) {
        roles.forEach(role => {
            const roleElement = document.createElement('span');
            roleElement.className = 'role-tag';
            roleElement.style.backgroundColor = role.color;
            roleElement.innerHTML = `
                ${role.name}
                ${canRemoveRoles ? `<span class="remove-role-btn" data-role-id="${role.id}" data-user-id="${userId}">×</span>` : ''}
            `;
            popupRoles.appendChild(roleElement);
        });
    } else {
        popupRoles.innerHTML = '<span class="text-gray-400 text-sm">Rol yok</span>';
    }

    // Last activity from server_members
    popupLastActive.textContent = formatLastActive(userMember.last_activity);
        } else {
            popupStatus.textContent = 'Bilinmiyor';
            popupStatusIndicator.classList.remove('online', 'offline');
            popupStatusIndicator.classList.add('offline');
            popupRoles.innerHTML = '<span class="text-gray-400 text-sm">Rol yok</span>';
            popupLastActive.textContent = 'Bilinmiyor';
        }

        // Background image with debugging
        if (userData.background_url) {
            console.log('Setting background URL:', userData.background_url);
            popupBackground.style.backgroundImage = `url('${userData.background_url}')`;
            popupBackground.style.backgroundColor = 'transparent';
        } else {
            console.log('No background URL for user:', userId);
            popupBackground.style.backgroundImage = '';
            popupBackground.style.backgroundColor = '#5865f2';
        }

        // Friends list
        popupFriendsList.innerHTML = '';
        if (userData.friends && userData.friends.length > 0) {
            userData.friends.slice(0, 3).forEach(friend => {
                const friendElement = document.createElement('div');
                friendElement.className = 'flex items-center space-x-2';
                friendElement.innerHTML = `
                    <div class="w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500">
                        ${friend.avatar_url ? 
                            `<img src="${friend.avatar_url}" alt="${friend.username}'s avatar" class="w-full h-full object-cover rounded-full">` : 
                            `<span class="text-white text-sm font-medium">${friend.username.charAt(0).toUpperCase()}</span>`
                        }
                    </div>
                    <span class="text-sm text-gray-300">${friend.username}</span>
                `;
                popupFriendsList.appendChild(friendElement);
            });

            if (userData.total_friends > 3) {
                const remainingFriends = userData.total_friends - 3;
                const remainingElement = document.createElement('div');
                remainingElement.className = 'flex items-center space-x-2';
                remainingElement.innerHTML = `
                    <div class="w-8 h-8 rounded-full flex items-center justify-center bg-gray-500">
                        <span class="text-white text-sm font-medium">+${remainingFriends}</span>
                    </div>
                    <span class="text-sm text-gray-300">ve ${remainingFriends} diğer</span>
                `;
                popupFriendsList.appendChild(remainingElement);
            }
        } else {
            popupFriendsList.innerHTML = '<div class="text-gray-400 text-sm">Arkadaş bulunamadı.</div>';
        }

        // Kick and Ban buttons
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        const hasKickPermission = <?php echo $hasKickPermission ? 'true' : 'false'; ?>;
        const hasBanPermission = <?php echo $hasBanPermission ? 'true' : 'false'; ?>;
        popupKickButtonContainer.style.display = (isOwner || hasKickPermission) ? 'block' : 'none';
        popupBanButtonContainer.style.display = (isOwner || hasBanPermission) ? 'block' : 'none';

        // Go to Profile button
        goToProfileBtn.onclick = () => {
            window.location.href = `https://lakeban.com/profile-page?username=${encodeURIComponent(userData.username)}`;
        };

        // Show popup
        popup.classList.remove('hidden');
    } else {
        alerting('Kullanıcı bilgileri alınamadı.', 'error');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const closePopupBtn = document.getElementById('close-popup-btn');
    if (closePopupBtn) {
        closePopupBtn.addEventListener('click', function() {
            const popup = document.getElementById('user-profile-popup');
            popup.classList.add('hidden');
        });
    } else {
        console.error('close-popup-btn element not found!');
    }
});
// Eğer kullanıcının kick izni yoksa veya sunucu kurucusu değilse, kick butonunu gizle
if (!hasKickPermission && !isOwner) {
    document.getElementById('kick-user-btn').style.display = 'none';
}
document.getElementById('ban-user-btn').addEventListener('click', function() {
    const userId = document.getElementById('popup-username').dataset.userId;
    const serverId = <?php echo json_encode($server_id); ?>;

    if (confirm('Bu kullanıcıyı banlamak istediğinizden emin misiniz?')) {
        fetch('ban_user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `user_id=${userId}&server_id=${serverId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Kullanıcı başarıyla banlandı.');
                // Pop-up'ı kapat
                document.getElementById('user-profile-popup').classList.add('hidden');
            } else {
                alert('Hata: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Hata:', error);
            alert('Banlama işlemi sırasında bir hata oluştu.');
        });
    }
});
if (hasBanPermission) {
    document.getElementById('popup-ban-button-container').style.display = 'block';
} else {
    document.getElementById('popup-ban-button-container').style.display = 'none';
}
function getChannelIdFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return params.get('channel_id');
}
document.addEventListener('DOMContentLoaded', function() {
    const messageForm = document.getElementById('message-form');
    const messageInput = document.getElementById('message-input');
    const fileInput = document.getElementById('file-input');
    const sendButton = document.getElementById('send-button');
    let isSubmitting = false;

    // Server ID'yi PHP'den al
    const serverId = <?php echo json_encode($server_id); ?>;
    // Varsayılan kanal ID'si
    let currentChannelId1 = <?php echo json_encode($channel_id); ?>;

    // showToast fonksiyonu yoksa alert kullan
    function showToast(type, message) {
        alert(message); // Basit bir alert ile hata bildirimi
    }

    // Clipboard'dan görsel yakalama
    messageInput.addEventListener('paste', async (e) => {
        const items = e.clipboardData.items;
        
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.startsWith('image/')) {
                const file = items[i].getAsFile();
                if (!file) return;

                // File input'unu güncelle
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                fileInput.files = dataTransfer.files;

                // Önizleme göster
                const reader = new FileReader();
                reader.onload = (e) => {
                    document.getElementById('preview-content').innerHTML = `
                        <img src="${e.target.result}" 
                             class="preview-image max-h-40 object-contain"
                             alt="Yapıştırılan görsel">
                    `;
                    document.getElementById('file-preview-container').classList.remove('hidden');
                };
                reader.readAsDataURL(file);

                // Buton durumunu güncelle
                updateSendButtonState();
                break;
            }
        }
    });

    // Gönderme butonu durumunu güncelle
    function updateSendButtonState() {
        sendButton.disabled = isSubmitting || (messageInput.value.trim() === '' && fileInput.files.length === 0);
    }

    // Mesajların yığılabilirliğini kontrol et
    function areMessagesStackable(currentMessage, previousMessage) {
        if (!previousMessage || !currentMessage) {
            console.log('[DEBUG] areMessagesStackable: previousMessage veya currentMessage eksik');
            return false;
        }
        const timeDiff = Math.abs(currentMessage.timestamp - previousMessage.timestamp);
        console.log('[DEBUG] areMessagesStackable: sender_id=', currentMessage.sender_id, 
                    'previous_sender_id=', previousMessage.sender_id, 
                    'timeDiff=', timeDiff);
        return (
            currentMessage.sender_id === previousMessage.sender_id &&
            timeDiff < 300 // 5 dakika (300 saniye)
        );
    }
// Tenor API Key (Kendi API anahtarınızı buraya ekleyin)
const TENOR_API_KEY = 'AIzaSyBXKEN0w8eMMdj-LAHmvYdoHVIADunht5c'; // Tenor'dan aldığınız API anahtarı
let selectedGifUrl = null;

// GIF Modalını Açma
document.getElementById('gif-button').addEventListener('click', () => {
    document.getElementById('gif-modal').classList.remove('hidden');
    document.getElementById('gif-search').focus();
});

// GIF Modalını Kapatma
document.getElementById('close-gif-modal').addEventListener('click', () => {
    document.getElementById('gif-modal').classList.add('hidden');
    document.getElementById('gif-search').value = '';
    document.getElementById('gif-results').innerHTML = '';
    document.getElementById('gif-message').classList.add('hidden');
});

// Modal dışına tıklama ile kapatma
document.getElementById('gif-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        document.getElementById('gif-modal').classList.add('hidden');
        document.getElementById('gif-search').value = '';
        document.getElementById('gif-results').innerHTML = '';
        document.getElementById('gif-message').classList.add('hidden');
    }
});

// GIF Arama (Debounce ile)
let gifSearchTimer;
document.getElementById('gif-search').addEventListener('input', () => {
    clearTimeout(gifSearchTimer);
    gifSearchTimer = setTimeout(() => {
        const query = document.getElementById('gif-search').value.trim();
        if (query.length < 2) {
            document.getElementById('gif-results').innerHTML = '<div class="text-center text-gray-500">En az 2 karakter girin</div>';
            return;
        }
        searchGifs(query);
    }, 300);
});

async function searchGifs(query) {
    const gifResults = document.getElementById('gif-results');
    const gifMessage = document.getElementById('gif-message');
    
    gifResults.innerHTML = '<div class="text-center text-gray-500"><i data-lucide="loader-2" class="animate-spin inline-block"></i> Yükleniyor...</div>';

    try {
        const response = await fetch(`tenor_proxy.php?query=${encodeURIComponent(query)}&limit=20`);
        const data = await response.json();

        if (data.results && data.results.length > 0) {
            gifResults.innerHTML = '';
            data.results.forEach(gif => {
                const img = document.createElement('img');
                img.src = gif.media_formats.gif.url; // GIF önizleme URL'si
                img.alt = gif.title || 'GIF';
                img.classList.add('gif-result');
                img.addEventListener('click', () => {
                    selectedGifUrl = gif.media_formats.gif.url;
                    sendGifMessage();
                });
                gifResults.appendChild(img);
            });
        } else {
            gifResults.innerHTML = '<div class="text-center text-gray-500">GIF bulunamadı</div>';
        }
    } catch (error) {
        console.error('GIF arama hatası:', error);
        gifResults.innerHTML = '';
        gifMessage.textContent = 'GIF yüklenirken hata oluştu';
        gifMessage.classList.remove('hidden');
        gifMessage.classList.add('bg-red-500/10', 'text-red-400');
    }
}

// GIF Mesaj Gönderme
// GIF Message Sending for Server Channels
async function sendGifMessage() {
    if (!selectedGifUrl) return;
    if (isSubmitting) return;

    const messageInput = document.getElementById('message-input');
    const sendButton = document.querySelector('.send-button');
    const fileInput = document.getElementById('file-input');

    isSubmitting = true;
    sendButton.disabled = true;
    sendButton.innerHTML = '<i data-lucide="loader" class="animate-spin"></i>';

    try {
        // Get channel ID from URL
        const channelIdFromUrl = getChannelIdFromUrl();
        if (!channelIdFromUrl) {
            console.error('Channel ID not found in URL');
            showToast('error', 'No channel selected!');
            return;
        }
        currentChannelId1 = parseInt(channelIdFromUrl, 10);
        if (isNaN(currentChannelId1)) {
            console.error('Invalid channel ID');
            showToast('error', 'Invalid channel ID!');
            return;
        }

        // Prepare form data
        const formData = new FormData();
        formData.append('message_text', selectedGifUrl); // Send GIF URL as message
        formData.append('is_gif', 'true'); // Indicate it's a GIF
        formData.append('server_id', serverId);
        formData.append('channel_id', currentChannelId1);

        // Add replied message ID if exists
        if (replyToMessageId) {
            formData.append('reply_to_message_id', replyToMessageId);
            console.log('[DEBUG] Sending GIF reply to message ID:', replyToMessageId);
        }

        console.log('[DEBUG] Sending GIF to channel:', currentChannelId1);

        const response = await fetch('send_server_message.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        console.log('[DEBUG] Server response for GIF:', data);

        if (data.success) {
            // Close GIF modal and reset
            document.getElementById('gif-modal').classList.add('hidden');
            document.getElementById('gif-search').value = '';
            document.getElementById('gif-results').innerHTML = '';
            selectedGifUrl = null;
            clearReplyContext();

            // Add new message to DOM
            const messageContainer = document.getElementById('message-container');
            let previousMessageData = null;
            if (messageContainer.lastElementChild) {
                const lastMessage = messageContainer.lastElementChild;
                previousMessageData = {
                    sender_id: lastMessage.dataset.senderId,
                    timestamp: parseInt(lastMessage.dataset.timestamp, 10) || 0
                };
            }

            if (!data.message.created_at_unix) {
                data.message.created_at_unix = Math.floor(Date.now() / 1000);
            }
            data.message.sender_id = String(data.message.sender_id);

            // Create message element (GIF will be handled in createNewMessageElement)
            const newMessage = createNewMessageElement(data.message, previousMessageData);
            messageContainer.appendChild(newMessage);
            messageContainer.scrollTop = messageContainer.scrollHeight;
            newMessage.scrollIntoView({ behavior: 'smooth' });

            if (!lastMessageId || data.message.id > lastMessageId) {
                lastMessageId = data.message.id;
            }
        } else {
            throw new Error(data.message || 'Failed to send GIF');
        }
    } catch (error) {
        console.error('GIF sending error:', error);
        showToast('error', error.message || 'Failed to send GIF');
    } finally {
        isSubmitting = false;
        sendButton.disabled = false;
        sendButton.innerHTML = '<i data-lucide="send" class="w-4 h-4"></i>';
        lucide.createIcons({ parent: sendButton });
    }
}

async function sendMessage() {
    if (isSubmitting || sendButton.disabled) {
        console.log('[DEBUG] Mesaj gönderimi engellendi: isSubmitting=', isSubmitting, 'sendButton.disabled=', sendButton.disabled);
        return;
    }

    const rawMessageText = messageInput.value.trim();
    const hasFile = fileInput.files.length > 0;

    // Check if message is empty and no file is attached
    if (!rawMessageText && !hasFile) {
        console.log('[DEBUG] Boş mesaj gönderimi engellendi');
        showToast('error', '<?php echo $translations['empty_message'] ?? 'Mesaj boş olamaz!'; ?>');
        return;
    }

    isSubmitting = true;
    sendButton.disabled = true;
    sendButton.innerHTML = '<i data-lucide="loader" class="animate-spin"></i>';

    try {
        const channelIdFromUrl = getChannelIdFromUrl();
        if (!channelIdFromUrl) {
            console.error('Channel ID URL\'de bulunamadı');
            showToast('error', 'Kanal seçili değil!');
            return;
        }
        currentChannelId1 = parseInt(channelIdFromUrl, 10);
        if (isNaN(currentChannelId1)) {
            console.error('Geçersiz kanal ID');
            showToast('error', 'Geçersiz kanal ID!');
            return;
        }

        const formData = new FormData();
        formData.append('message_text', rawMessageText);
        formData.append('server_id', serverId);
        formData.append('channel_id', currentChannelId1);

        if (hasFile) {
            formData.append('file', fileInput.files[0]);
        }

        if (replyToMessageId) {
            formData.append('reply_to_message_id', replyToMessageId);
            console.log('[DEBUG] Sending reply to message ID:', replyToMessageId);
        }

        console.log('[DEBUG] FormData: server_id=', serverId, 'channel_id=', currentChannelId1, 'reply_to_message_id=', replyToMessageId);

        const response = await fetch('send_server_message.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        console.log('[DEBUG] Sunucu cevabı:', data);

        if (data.success) {
            messageInput.value = '';
            fileInput.value = '';
            document.getElementById('preview-content').innerHTML = '';
            document.getElementById('file-preview-container').classList.add('hidden');
            clearReplyContext();

            const messageContainer = document.getElementById('message-container');
            let previousMessageData = null;
            if (messageContainer.lastElementChild) {
                const lastMessage = messageContainer.lastElementChild;
                previousMessageData = {
                    sender_id: lastMessage.dataset.senderId,
                    timestamp: parseInt(lastMessage.dataset.timestamp, 10) || 0
                };
            }

            if (!data.message.created_at_unix) {
                data.message.created_at_unix = Math.floor(Date.now() / 1000);
            }
            data.message.sender_id = String(data.message.sender_id);
            // Ensure reply_message is included if available
            data.message.reply_message = data.reply_message || null;

            const newMessage = createNewMessageElement(data.message, previousMessageData);
            messageContainer.appendChild(newMessage);

            // Force DOM reflow and image load
            messageContainer.offsetHeight;
            const emojiImages = newMessage.querySelectorAll('.emoji-img');
            emojiImages.forEach(img => {
                img.src = img.src;
            });

            messageContainer.scrollTop = messageContainer.scrollHeight;
            newMessage.scrollIntoView({ behavior: 'smooth' });

            if (!lastMessageId || data.message.id > lastMessageId) {
                lastMessageId = data.message.id;
            }

            // Update last read message in user_read_messages
            try {
                const updateReadResponse = await fetch('update_last_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `user_id=${currentUserId1}&channel_id=${currentChannelId1}&message_id=${data.message.id}`
                });
                const updateReadData = await updateReadResponse.json();
                if (!updateReadData.success) {
                    console.error('[DEBUG] Last read update failed:', updateReadData.message);
                    showToast('error', '<?php echo $translations['last_read_update_failed'] ?? 'Son okunan mesaj güncellenemedi.'; ?>');
                } else {
                    console.log('[DEBUG] Last read message updated:', data.message.id);
                }
            } catch (error) {
                console.error('[DEBUG] Last read update error:', error);
                showToast('error', '<?php echo $translations['last_read_update_failed'] ?? 'Son okunan mesaj güncellenemedi.'; ?>');
            }
        } else {
            console.error('[DEBUG] Mesaj gönderimi başarısız:', data.message);
            showToast('error', data.message || 'Mesaj gönderilemedi!');
        }
    } catch (error) {
        console.error('[DEBUG] Mesaj gönderim hatası:', error);
        showToast('error', 'Bir hata oluştu, lütfen tekrar deneyin.');
    } finally {
        isSubmitting = false;
        updateSendButtonState();
        sendButton.innerHTML = '<i data-lucide="send" class="w-4 h-4"></i>';
        lucide.createIcons({ parent: sendButton });
        console.log('[DEBUG] Mesaj gönderimi tamamlandı, isSubmitting sıfırlandı');
    }
}


    // Form submit olayını bağla
    messageForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        await sendMessage();
    });

    // Input değişikliklerini dinle
    messageInput.addEventListener('input', updateSendButtonState);
    fileInput.addEventListener('change', updateSendButtonState);
    
    // Enter tuşu ile gönderme kontrolü
    messageInput.addEventListener('keydown', async (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!isSubmitting && !sendButton.disabled) {
                await sendMessage();
            }
        }
    });
});
// Edit Form Submit Handler (AJAX)
function handleEditSubmit(e, messageId) {
    e.preventDefault();
    const form = e.target;
    const newText = form.querySelector('textarea').value.trim();
    const messageElement = document.querySelector(`.message-bg[data-message-id="${messageId}"]`);
    const originalTextElement = messageElement.querySelector('.text-white.text-sm.mt-1');

    if (!newText) {
        alert('Mesaj boş olamaz!');
        return;
    }

    fetch('editserver_message.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `message_id=${messageId}&edited_message_text=${encodeURIComponent(newText)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mesaj metnini güncelle
            originalTextElement.textContent = newText;
            
            // Düzenleme formunu gizle
            form.closest('.edit-message-form').classList.add('hidden');
            
        }
    })
    .catch(error => {
        console.error('Hata:', error);
        showToast('error', 'Bir hata oluştu!');
    });
}

 // Yığınlama kontrolü için fonksiyon
    function areMessagesStackable(currentMsgData, prevMsgData) {
        if (!prevMsgData) return false;

        const timeDiffSeconds = currentMsgData.timestamp - prevMsgData.timestamp;
        if (timeDiffSeconds < 0 || timeDiffSeconds >= 300) { // 5 dakikadan (300 saniye) büyükse stackleme
            return false;
        }
        
        // Aynı gün kontrolü
        const currentDate = new Date(currentMsgData.timestamp * 1000);
        const previousDate = new Date(prevMsgData.timestamp * 1000);

        const isSameDay = currentDate.getFullYear() === previousDate.getFullYear() &&
                          currentDate.getMonth() === previousDate.getMonth() &&
                          currentDate.getDate() === previousDate.getDate();

        return currentMsgData.sender_id == prevMsgData.sender_id && isSameDay;
    }

// Modal fonksiyonları
function openCreateServerModal() {
    document.getElementById('create-server-modal').classList.remove('hidden');
}

function closeCreateServerModal() {
    document.getElementById('create-server-modal').classList.add('hidden');
}

// Dosya yükleme için
document.getElementById('modal-pp-upload').addEventListener('click', () => {
    document.querySelector('#create-server-form input[type="file"]').click();
});

// Form gönderimi
document.getElementById('create-server-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    try {
        const response = await fetch('create_server.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeCreateServerModal();
            window.location.href = `server?id=${result.server_id}`;
        } else {
            alert(result.error || 'Sunucu oluşturulurken bir hata oluştu');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Bir hata oluştu, lütfen tekrar deneyin');
    }
});
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($is_owner || $has_manage_channels_permission): ?>
    // Her kategori için ayrı Sortable instance'ı oluştur
    document.querySelectorAll('.channel-sidebar > div').forEach(categoryContainer => {
        const channelList = categoryContainer.querySelector('.flex.items-center.justify-between.mb-2')?.parentElement;
        
        if (channelList) {
            new Sortable(channelList, {
                group: 'channels',
                animation: 150,
                handle: '.channel-drag-handle',
                ghostClass: 'bg-gray-700',
                onEnd: async function(evt) {
                    const categoryId = evt.to.closest('[data-category-id]')?.dataset.categoryId || 'uncategorized';
                    const channels = Array.from(evt.to.children);
                    
                    const newOrder = channels.map((channel, index) => ({
                        id: channel.dataset.channelId,
                        position: index,
                        category_id: categoryId
                    }));

                    try {
                        const response = await fetch('update_channel_order.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                serverId: <?php echo $server_id; ?>,
                                newOrder
                            })
                        });
                        
                        if (!response.ok) throw new Error('Güncelleme başarısız');
                    } catch (error) {
                        console.error('Hata:', error);
                        alert('Sıralama güncellenemedi!');
                    }
                }
            });
        }
    });
    <?php endif; ?>
});
// Global menü referansı
let currentMenu = null;

// Menüyü kapatma fonksiyonu
const closeCurrentMenu = () => {
    if (currentMenu) {
        currentMenu.remove();
        currentMenu = null;
    }
    document.removeEventListener('click', closeCurrentMenu);
    document.removeEventListener('keydown', handleEscape);
};

// ESC tuşu ile kapatma
const handleEscape = (e) => {
    if (e.key === 'Escape') closeCurrentMenu();
};

// Kanal bağlam menüsü
document.querySelectorAll('.channel-sidebar [data-channel-id]').forEach(channel => {
    channel.addEventListener('contextmenu', e => {
        e.preventDefault();
        closeCurrentMenu();
        
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        const hasManagePerm = <?php echo $has_manage_channels_permission ? 'true' : 'false'; ?>;
        if(!isOwner && !hasManagePerm) return;

        const menu = document.createElement('div');
        menu.className = 'absolute bg-gray-900 border border-gray-700 rounded-lg shadow-xl w-48 z-50 overflow-hidden';
        menu.innerHTML = `
            <div class="py-1">
                <a href="edit_channel?id=${e.currentTarget.dataset.channelId}" 
                   class="flex items-center px-4 py-2.5 space-x-3 text-gray-300 hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 114.95 0 2.5 2.5 0 01-4.95 0zM12 15v3m0 3h-3m3 0h3m-3 0v-3m-6.5-7.5h1"/>
                    </svg>
                    <span>Düzenle</span>
                </a>
                <hr class="border-gray-700">
                <button data-channel-id="${e.currentTarget.dataset.channelId}" 
                        class="w-full flex items-center px-4 py-2.5 space-x-3 text-red-500 hover:bg-gray-800 transition-colors delete-channel-btn">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span>Sil</span>
                </button>
            </div>
        `;

        // Pozisyon ayarı (ekran sınır kontrolü)
        const rect = e.currentTarget.getBoundingClientRect();
        const menuHeight = 104; // Toplam menü yüksekliği
        const yPos = rect.bottom + menuHeight > window.innerHeight ? 
            window.innerHeight - menuHeight : rect.bottom;
        
        menu.style.top = `${yPos}px`;
        menu.style.left = `${rect.left}px`;
        document.body.appendChild(menu);
        currentMenu = menu;

        // Silme işlemi
        menu.querySelector('.delete-channel-btn').addEventListener('click', () => {
            if(confirm('Kanalı silmek istediğinize emin misiniz?')) {
                window.location.href = `server?id=<?= $server_id ?>&delete_channel=${e.currentTarget.dataset.channelId}`;
            }
        });

        // Kapatma eventleri
        document.addEventListener('click', closeCurrentMenu);
        document.addEventListener('keydown', handleEscape);
    });
});

// Boş alan bağlam menüsü
document.querySelector('.channel-sidebar').addEventListener('contextmenu', e => {
    if(e.target.closest('[data-channel-id]')) return;
    
    e.preventDefault();
    closeCurrentMenu();
    
    const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
    const hasManagePerm = <?php echo $has_manage_channels_permission ? 'true' : 'false'; ?>;
    if(!isOwner && !hasManagePerm) return;

    const menu = document.createElement('div');
    menu.className = 'absolute bg-gray-900 border border-gray-700 rounded-lg shadow-xl w-48 z-50 overflow-hidden';
    menu.innerHTML = `
        <div class="py-1">
            <a href="create_channel?server_id=<?= $server_id ?>" 
               class="flex items-center px-4 py-2.5 space-x-3 text-gray-300 hover:bg-gray-800 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                <span>Kanal Oluştur</span>
            </a>
            <hr class="border-gray-700">
            <a href="create_category?server_id=<?= $server_id ?>" 
               class="flex items-center px-4 py-2.5 space-x-3 text-gray-300 hover:bg-gray-800 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Kategori Oluştur</span>
            </a>
        </div>
    `;

    // Pozisyon ayarı (ekran sınır kontrolü)
    const xPos = e.clientX + 240 > window.innerWidth ? 
        window.innerWidth - 250 : e.clientX;
    const yPos = e.clientY + 112 > window.innerHeight ? 
        window.innerHeight - 120 : e.clientY;

    menu.style.top = `${yPos}px`;
    menu.style.left = `${xPos}px`;
    document.body.appendChild(menu);
    currentMenu = menu;

    // Kapatma eventleri
    document.addEventListener('click', closeCurrentMenu);
    document.addEventListener('keydown', handleEscape);
});
// Dosya Önizleme İşlevselliği
document.getElementById('file-input').addEventListener('change', function() {
    const file = this.files[0];
    const previewContainer = document.getElementById('file-preview-container');
    const previewContent = document.getElementById('preview-content');
    
    previewContent.innerHTML = '';
    
    if(file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            let content = '';
            if(file.type.startsWith('image/')) {
                content = `
                    <img src="${e.target.result}" 
                         class="preview-image max-h-40 object-contain"
                         alt="Dosya önizleme">
                `;
            } else if(file.type.startsWith('video/')) {
                content = `
                    <video controls class="preview-video max-h-40">
                        <source src="${e.target.result}" type="${file.type}">
                    </video>
                `;
            } else {
                content = `
                    <div class="file-icon-wrapper flex flex-col items-center">
                        <i data-lucide="file" class="w-12 h-12 text-gray-400"></i>
                    </div>
                `;
            }
            previewContent.innerHTML = content;
            lucide.createIcons();
        };

        reader.readAsDataURL(file);
        previewContainer.classList.remove('hidden');
    } else {
        previewContainer.classList.add('hidden');
    }
});

// Dosya İptal Butonu
document.getElementById('cancel-file').addEventListener('click', function(e) {
    e.preventDefault();
    document.getElementById('file-input').value = '';
    document.getElementById('file-preview-container').classList.add('hidden');
    document.getElementById('preview-content').innerHTML = '';
});


if (window.innerWidth <= 768) {
  const main = document.getElementById("main-content");
  const user = document.getElementById("user-sidebar");

  // Başlangıç pozisyonlarını ayarla
  main.style.transform = "translateX(100vw)"; // sağda (channel-sidebar-x’in sağında)
  user.style.transform = "translateX(200vw)"; // daha da sağda

  let startX = 0;
  let isDragging = false;
  let dragOffset = 0;
  let stage = 0; // 0: channel-sidebar-x, 1: main-content, 2: user-sidebar

  function setTransform() {
    // Kanal 0 => main: 100vw, user: 200vw
    // Kanal 1 => main: 0vw, user: 100vw
    // Kanal 2 => main: -100vw, user: 0vw
    main.style.transform = `translateX(${100 - stage * 100 + dragOffset}vw)`;
    user.style.transform = `translateX(${200 - stage * 100 + dragOffset}vw)`;
  }

  function onTouchStart(e) {
    if (e.touches.length !== 1) return;
    startX = e.touches[0].clientX;
    isDragging = true;
    dragOffset = 0;

    main.style.transition = "none";
    user.style.transition = "none";
  }

  function onTouchMove(e) {
    if (!isDragging) return;
    const currentX = e.touches[0].clientX;
    const deltaX = currentX - startX;
    dragOffset = (deltaX / window.innerWidth) * 100;

    // sınırlar
    if (stage === 0 && dragOffset > 0) dragOffset = 0;
    if (stage === 0 && dragOffset < -100) dragOffset = -100;

    if (stage === 1 && dragOffset > 100) dragOffset = 100;
    if (stage === 1 && dragOffset < -100) dragOffset = -100;

    if (stage === 2 && dragOffset < 0) dragOffset = 0;
    if (stage === 2 && dragOffset > 100) dragOffset = 100;

    setTransform();
  }

  function onTouchEnd() {
    if (!isDragging) return;
    isDragging = false;

    const threshold = 50;

    main.style.transition = "transform 0.3s ease";
    user.style.transition = "transform 0.3s ease";

    if (dragOffset < -threshold && stage < 2) {
      stage += 1;
    } else if (dragOffset > threshold && stage > 0) {
      stage -= 1;
    }

    dragOffset = 0;
    updateView();
  }

  function updateView() {
    main.style.transform = `translateX(${100 - stage * 100}vw)`;
    user.style.transform = `translateX(${200 - stage * 100}vw)`;
  }

  // İlk görünümü ayarla
  updateView();

  // Event listener'lar
  document.addEventListener("touchstart", onTouchStart, { passive: false });
  document.addEventListener("touchmove", onTouchMove, { passive: false });
  document.addEventListener("touchend", onTouchEnd, { passive: false });
}


function updateSagOrtaDiv() {
    const solDiv = document.querySelector('#server-sidebar');
    const solOrtaDiv = document.querySelector('#channel-sidebar-x');
    const sagDiv = document.querySelector('#user-sidebar');
    const sagOrtaDiv = document.querySelector('#main-content');

    // Media query kontrolü: min-width: 769px
    const isLargeScreen = window.matchMedia('(min-width: 769px)').matches;

    if (isLargeScreen) {
        // Sol ve sol orta divlerin toplam genişliklerini al
        const totalLeftWidth = solDiv.offsetWidth + solOrtaDiv.offsetWidth;

        // Sağ divin genişliğini al
        const sagDivWidth = sagDiv.offsetWidth;

        // Sağ orta divin genişliğini ve konumunu ayarla
        const remainingWidth = window.innerWidth - totalLeftWidth - sagDivWidth;
        sagOrtaDiv.style.width = `${remainingWidth}px`;
        sagOrtaDiv.style.right = `${sagDivWidth}px`;
    } else {
    }
}

// Sayfa yüklendiğinde ve yeniden boyutlandırıldığında çalıştır
window.addEventListener('load', updateSagOrtaDiv);
window.addEventListener('resize', updateSagOrtaDiv);

// Click outside to close popup (targeting blurred background)
document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('user-profile-popup');
    popup.addEventListener('click', function(event) {
        if (event.target === popup) { // Only trigger if clicking the blurred background
            popup.classList.add('hidden');
        }
    });
});

// Close popup with Esc key
document.addEventListener('keydown', function(event) {
    const popup = document.getElementById('user-profile-popup');
    if (event.key === 'Escape' && !popup.classList.contains('hidden')) {
        popup.classList.add('hidden');
    }
});
const VOICE_EVENTS = {
    JOIN: 'voice-join',
    LEAVE: 'voice-leave',
    PARTICIPANTS: 'voice-participants'
};

const USER_ID = <?= isset($_SESSION['user_id']) ? json_encode($_SESSION['user_id']) : 'null' ?>;
const rtcConfig = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        {
            urls: 'turn:openrelay.metered.ca:80',
            username: 'openrelay.project',
            credential: 'openrelay'
        },
        {
            urls: 'turn:openrelay.metered.ca:443',
            username: 'openrelay.project',
            credential: 'openrelay'
        }
    ]
};

let localStream1 = null;
let peerConnections = {};
let currentVoiceChannel = null;
let ws1 = new WebSocket('wss://lakeban.com:8000');
let screenStream1 = null;
let screenPeerConnections = {};



function waitForWebSocket(ws1) {
    return new Promise((resolve, reject) => {
        if (ws1.readyState === WebSocket.OPEN) {
            resolve();
        } else if (ws1.readyState === WebSocket.CLOSED || ws1.readyState === WebSocket.CLOSING) {
            reject(new Error('WebSocket kapalı'));
        } else {
            ws1.onopen = () => resolve();
            ws1.onerror = () => reject(new Error('WebSocket bağlantı hatası'));
        }
    });
}

ws1.onopen = () => {
    console.log('WebSocket bağlantısı kuruldu');
    ws1.send(JSON.stringify({
        type: 'auth',
        userId: String(currentUserId1)
    }));
};

ws1.onerror = (error) => {
    console.error('WebSocket hatası:', error);
};

let reconnectAttempts = 0;
const maxAttempts = 5;

function reconnectWebSocket() {
    if (reconnectAttempts >= maxAttempts) {
        alert('WebSocket bağlantısı sağlanamadı. Lütfen daha sonra tekrar deneyin.');
        return;
    }
    setTimeout(() => {
        ws1 = new WebSocket('wss://lakeban.com:8000');
        ws1.onopen = () => {
            console.log('Yeniden bağlandı');
            ws1.send(JSON.stringify({ type: 'auth', userId: String(currentUserId1) }));
            reconnectAttempts = 0;
        };
        ws1.onerror = () => {
            reconnectAttempts++;
            reconnectWebSocket();
        };
    }, Math.pow(2, reconnectAttempts) * 1000); // 2, 4, 8, 16 saniye gecikme
}

// Heartbeat mekanizması: Her 30 saniyede bir mesaj gönder
  const heartbeatInterval1 = setInterval(() => {
    if (ws1.readyState === WebSocket.OPEN) {
      ws1.send(JSON.stringify({ type: 'heartbeat' }));
      console.log('Heartbeat mesajı gönderildi');
    }
  }, 30000); // server.js'deki pingInterval ile uyumlu (30 saniye)

  // Bağlantı kapandığında heartbeat'ı durdur
  ws1.addEventListener('close', () => {
    console.log('Bağlantı kesildi');
    clearInterval(heartbeatInterval1);
  });

  // Hata durumunda heartbeat'ı durdur (opsiyonel)
  ws1.addEventListener('error', (error) => {
    console.error('WebSocket hatası:', error);
    clearInterval(heartbeatInterval1);
  });
ws1.onclose = () => {
    console.log('WebSocket kapandı, yeniden bağlanılıyor...');
    reconnectWebSocket();
};

async function joinVoiceChannel(channelId) {
    try {
        await waitForWebSocket(ws1);
        localStream1 = await navigator.mediaDevices.getUserMedia({ 
            audio: { 
                echoCancellation: true, 
                noiseSuppression: true 
            } 
        });

        ws1.send(JSON.stringify({
            type: 'voice-join',
            channelId,
            userId: currentUserId1,
            sender: currentUserId1
        }));

        // API ile katılma işlemini gerçekleştir
        const joinResponse = await fetch('voice_channel_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `channel_id=${channelId}&action=join`
        });
        const joinData = await joinResponse.json();
        if (!joinData.success) {
            throw new Error(joinData.error);
        }

        // Katılımcı listesini güncelle
        await updateVoiceParticipants(channelId);

        // Mevcut katılımcılar için PeerConnection oluştur
        const participantsResponse = await fetch(`get_voice_participants.php?channel_id=${channelId}`);
        const participantsData = await participantsResponse.json();
        if (participantsData.success) {
            participantsData.participants.forEach(participant => {
                if (participant.id !== currentUserId1) {
                    createPeerConnection(channelId, participant.id);
                }
            });
        } else {
            console.warn('Katılımcı listesi alınamadı:', participantsData.error);
        }

        // Ses kontrollerini göster ve kanal durumunu güncelle
        showVoiceControls(channelId);
        currentVoiceChannel = channelId;

        const joinButton = document.querySelector(`.join-button[data-channel-id="${channelId}"]`);
        if (joinButton) {
            joinButton.dataset.joined = 'true';
            updateJoinButton(joinButton, true);
        }
        
         startCallTimeCounter(); // Sayacı burada başlatıyoruz.
        
    } catch (error) {
        console.error('Sesli kanala katılma hatası:', error);
        alert('Sesli kanala katılamadınız. Lütfen mikrofon izinlerini kontrol edin.');
    }
}

async function createPeerConnection(channelId, targetUserId) {
    if (!targetUserId) {
        console.error('Hedef kullanıcı ID eksik:', targetUserId);
        return null;
    }

    if (peerConnections[targetUserId]) {
        console.log(`Zaten mevcut bağlantı: ${targetUserId}`);
        return peerConnections[targetUserId];
    }

    const pc = new RTCPeerConnection(rtcConfig);
    peerConnections[targetUserId] = pc;

    pc.onconnectionstatechange = () => {
        console.log(`Bağlantı durumu (${targetUserId}): ${pc.connectionState}`);
        if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
            pc.close();
            delete peerConnections[targetUserId];
            ws1.send(JSON.stringify({
                type: 'voice-reconnect',
                channelId,
                target: targetUserId,
                sender: currentUserId1
            }));
        }
    };

    if (localStream1) {
        localStream1.getTracks().forEach(track => {
            pc.addTrack(track, localStream1);
        });
    } else {
        console.error('localStream mevcut değil');
    }

    pc.onicecandidate = ({ candidate }) => {
        if (candidate) {
            ws1.send(JSON.stringify({
                type: 'ice-candidate',
                channelId: channelId,
                target: targetUserId,
                candidate,
                sender: currentUserId1
            }));
        }
    };

    pc.ontrack = (event) => {
    console.log('Karşı tarafın akışı alındı:', targetUserId);
    const audio = document.createElement('audio');
    audio.id = `remote-audio-${targetUserId}`;
    audio.autoplay = true;
    audio.srcObject = event.streams[0];
    const container = document.getElementById('voice-audio-container') || document.createElement('div');
    if (!container.id) {
        container.id = 'voice-audio-container';
        container.style.display = 'none';
        document.body.appendChild(container);
    }
    container.appendChild(audio);

    const context = new AudioContext();
    const source = context.createMediaStreamSource(event.streams[0]);
    const analyser = context.createAnalyser();
    source.connect(analyser);
    analyser.fftSize = 512;
    const dataArray = new Uint8Array(analyser.frequencyBinCount);

    function detectSpeech() {
        analyser.getByteFrequencyData(dataArray);
        const volume = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;
        const participant = document.querySelector(`.voice-participant[data-user-id="${targetUserId}"] img`);
        if (participant && volume > 10) { // Ses eşiği
            participant.classList.add('online-pulse');
        } else if (participant) {
            participant.classList.remove('online-pulse');
        }
        requestAnimationFrame(detectSpeech);
    }
    detectSpeech();
  };

    try {
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        ws1.send(JSON.stringify({
            type: 'voice-offer',
            channelId: channelId,
            target: targetUserId,
            offer,
            sender: currentUserId1
        }));
    } catch (error) {
        console.error('Teklif oluşturma hatası:', error);
        delete peerConnections[targetUserId];
        pc.close();
    }

    return pc;
}



// Validate channel ID from URL
const urlParams = new URLSearchParams(window.location.search);
const urlChannelId = urlParams.get('channel_id');
if (urlChannelId !== currentVoiceChannel) {
    console.warn(`Channel ID mismatch: URL=${urlChannelId}, currentVoiceChannel=${currentVoiceChannel}`);
    currentVoiceChannel = urlChannelId || currentVoiceChannel; // Fallback to URL channel ID
}

// WebSocket message handler
ws1.onmessage = async (event) => {
    try {
        const data = JSON.parse(event.data);
        console.log('📥 Alınan WebSocket mesajı:', JSON.stringify(data, null, 2));

        if (data.type === 'voice-participants') {
            console.log('voice-participants mesajı alındı:', data.participants);
            updateParticipantList(data.participants);
            return;
        }

        if (data.type === 'ice-candidate') {
            const pc = peerConnections[data.sender];
            if (pc && pc.remoteDescription && data.candidate) {
                try {
                    await pc.addIceCandidate(new RTCIceCandidate(data.candidate));
                    console.log('Voice ICE candidate eklendi:', data.sender);
                } catch (err) {
                    console.error('Voice ICE candidate ekleme hatası:', err);
                }
            } else {
                console.warn('Voice ICE candidate ignored: No peer connection or remote description:', data.sender);
            }
            return;
        }

        if (data.type === 'voice-offer') {
            let pc = peerConnections[data.sender];
            if (!pc || pc.signalingState !== 'stable') {
                if (pc) {
                    console.log('Eski bağlantıyı temizle:', data.sender);
                    pc.close();
                    delete peerConnections[data.sender];
                }
                pc = await createNewConnection(data.channelId, data.sender);
            }

            try {
                await pc.setRemoteDescription(new RTCSessionDescription(data.offer));
                console.log('Voice remote description ayarlandı:', pc.signalingState);
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                console.log('Voice local description ayarlandı:', pc.signalingState);
                ws1.send(JSON.stringify({
                    type: 'voice-answer',
                    channelId: data.channelId,
                    target: data.sender,
                    answer: answer,
                    sender: currentUserId1
                }));
                console.log('Voice answer gönderildi:', data.sender);
            } catch (err) {
                console.error('Voice offer işleme hatası:', err);
                if (pc) {
                    pc.close();
                    delete peerConnections[data.sender];
                }
            }
            return;
        }

        if (data.type === 'voice-answer') {
            const pc = peerConnections[data.sender];
            if (pc && pc.signalingState === 'have-local-offer') {
                try {
                    await pc.setRemoteDescription(new RTCSessionDescription(data.answer));
                    console.log('Voice answer başarıyla ayarlandı:', data.sender);
                } catch (err) {
                    console.error('Voice answer ayarlama hatası:', err);
                    pc.close();
                    delete peerConnections[data.sender];
                }
            } else {
                console.warn('Voice answer ignored: Invalid signaling state or peer connection not found:', data.sender, pc ? pc.signalingState : 'no pc');
            }
            return;
        }

  if (data.type === 'screen-share-started') {
            if (data.userId !== currentUserId1.toString()) {
                console.log('Screen share başlatıldı:', data.userId, 'kanal:', data.channelId);
                let pc = screenPeerConnections[data.userId];
                if (pc) {
                    if (pc.signalingState !== 'stable') {
                        console.log('Cleaning up existing unstable screen peer connection:', data.userId);
                        pc.close();
                        delete screenPeerConnections[data.userId];
                    } else {
                        console.log('Stable screen peer connection already exists:', data.userId);
                        return;
                    }
                }

                pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
                screenPeerConnections[data.userId] = pc;

                pc.ontrack = (event) => {
                    console.log('Screen share ontrack tetiklendi:', data.userId, event.streams, 'track kind:', event.track.kind, 'track enabled:', event.track.enabled);
                    if (event.track.kind === 'video' && event.streams[0]) {
                        const remoteScreenPreview = document.getElementById('remote-screen-preview');
                        remoteScreenPreview.srcObject = event.streams[0];
                        remoteScreenPreview.play().catch(err => console.error('Video play error:', err));
                        document.getElementById('remote-screen-share-modal').classList.remove('hidden');
                        console.log('Remote screen stream ayarlandı:', data.userId, 'stream active:', event.streams[0].active, 'tracks:', event.streams[0].getTracks());
                    } else {
                        console.warn('No video stream or incorrect track in ontrack event:', data.userId, event.track.kind);
                    }
                };

                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        ws1.send(JSON.stringify({
                            type: 'screen-ice-candidate',
                            target: data.userId,
                            candidate: event.candidate,
                            channelId: data.channelId,
                            sender: currentUserId1
                        }));
                        console.log('Screen ICE candidate gönderildi:', data.userId);
                    }
                };

                pc.oniceconnectionstatechange = () => {
                    console.log('Screen ICE connection state:', data.userId, pc.iceConnectionState);
                    if (pc.iceConnectionState === 'failed') {
                        console.error('Screen ICE connection failed:', data.userId);
                        pc.close();
                        delete screenPeerConnections[data.userId];
                        document.getElementById('remote-screen-share-modal').classList.add('hidden');
                    }
                };
            } else {
                console.log('Screen share start ignored: Message from self:', data.userId);
            }
            return;
        }

        if (data.type === 'screen-share-ended') {
            console.log('Screen share bitti:', data.userId);
            if (screenPeerConnections[data.userId]) {
                screenPeerConnections[data.userId].close();
                delete screenPeerConnections[data.userId];
                document.getElementById('remote-screen-share-modal').classList.add('hidden');
                console.log('Screen peer connection kapatıldı:', data.userId);
            }
            return;
        }

        if (data.type === 'screen-offer') {
            console.log('Screen offer alındı:', data.target, 'from sender:', data.sender);
            let pc = screenPeerConnections[data.sender];
            if (!pc) {
                pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
                screenPeerConnections[data.sender] = pc;

                pc.ontrack = (event) => {
                    console.log('Screen share ontrack tetiklendi (offer):', data.sender, event.streams, 'track kind:', event.track.kind, 'track enabled:', event.track.enabled);
                    if (event.track.kind === 'video' && event.streams[0]) {
                        const remoteScreenPreview = document.getElementById('remote-screen-preview');
                        remoteScreenPreview.srcObject = event.streams[0];
                        remoteScreenPreview.play().catch(err => console.error('Video play error:', err));
                        document.getElementById('remote-screen-share-modal').classList.remove('hidden');
                        console.log('Remote screen stream ayarlandı (offer):', data.sender, 'stream active:', event.streams[0].active, 'tracks:', event.streams[0].getTracks());
                    } else {
                        console.warn('No video stream or incorrect track in ontrack event (offer):', data.sender, event.track.kind);
                    }
                };

                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        ws1.send(JSON.stringify({
                            type: 'screen-ice-candidate',
                            target: data.sender,
                            candidate: event.candidate,
                            channelId: data.channelId,
                            sender: currentUserId1
                        }));
                        console.log('Screen ICE candidate gönderildi (offer):', data.sender);
                    }
                };

                pc.oniceconnectionstatechange = () => {
                    console.log('Screen ICE connection state (offer):', data.sender, pc.iceConnectionState);
                    if (pc.iceConnectionState === 'failed') {
                        console.error('Screen ICE connection failed (offer):', data.sender);
                        pc.close();
                        delete screenPeerConnections[data.sender];
                        document.getElementById('remote-screen-share-modal').classList.add('hidden');
                    }
                };
            }

            if (pc.signalingState === 'stable') {
                try {
                    await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
                    console.log('Screen remote description ayarlandı:', data.sender, pc.signalingState);
                    const answer = await pc.createAnswer();
                    await pc.setLocalDescription(answer);
                    ws1.send(JSON.stringify({
                        type: 'screen-answer',
                        target: data.sender,
                        sdp: pc.localDescription,
                        channelId: data.channelId,
                        sender: currentUserId1
                    }));
                    console.log('Screen answer gönderildi:', data.sender);
                } catch (err) {
                    console.error('Screen offer işleme hatası:', err);
                    pc.close();
                    delete screenPeerConnections[data.sender];
                }
            } else {
                console.warn('Screen offer ignored: Invalid signaling state:', data.sender, pc.signalingState);
            }
            return;
        }

        if (data.type === 'screen-answer') {
            console.log('Screen answer alındı:', data.target, 'from sender:', data.sender);
            const pc = screenPeerConnections[data.sender];
            if (pc && pc.signalingState === 'have-local-offer') {
                try {
                    await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
                    console.log('Screen answer başarıyla ayarlandı:', data.sender);
                } catch (err) {
                    console.error('Screen answer ayarlama hatası:', err);
                    pc.close();
                    delete screenPeerConnections[data.sender];
                }
            } else {
                console.warn('Screen answer ignored: Invalid signaling state or peer connection not found:', data.sender, pc ? pc.signalingState : 'no pc');
            }
            return;
        }

        if (data.type === 'screen-ice-candidate') {
            console.log('Screen ICE candidate alındı:', data.target, 'from sender:', data.sender);
            const pc = screenPeerConnections[data.sender];
            if (pc && pc.remoteDescription && data.candidate) {
                try {
                    await pc.addIceCandidate(new RTCIceCandidate(data.candidate));
                    console.log('Screen ICE candidate eklendi:', data.sender);
                } catch (err) {
                    console.error('Screen ICE candidate ekleme hatası:', err);
                }
            } else {
                console.warn('Screen ICE candidate ignored: No peer connection or remote description:', data.sender);
            }
            return;
        }

    } catch (error) {
        console.error('WebSocket mesaj işleme hatası:', error);
    }
};

// Start screen sharing
async function startScreenShare(channelId) {
    try {
        console.log('Ekran paylaşımı başlatılıyor:', channelId);
        screenStream1 = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
        console.log('Ekran akışı alındı:', screenStream1, 'tracks:', screenStream1.getTracks());
        const screenPreview = document.getElementById('screen-preview');
        screenPreview.srcObject = screenStream1;
        document.getElementById('screen-preview-modal').classList.remove('hidden');
        document.getElementById('start-screen-share').classList.add('hidden');
        document.getElementById('stop-screen-share').classList.remove('hidden');

        ws1.send(JSON.stringify({
            type: 'screen-share-start',
            channelId,
            userId: currentUserId1
        }));
        console.log('Screen share start mesajı gönderildi:', channelId);

        const participants = document.querySelectorAll(`.voice-participants[data-channel-id="${channelId}"] .voice-participant`);
        console.log('Katılımcılar:', participants.length);
        participants.forEach(participant => {
            const targetId = participant.dataset.userId;
            if (targetId !== currentUserId1.toString()) {
                console.log('Ekran paylaşımı için bağlantı kuruluyor:', targetId);
                createScreenPeerConnection(targetId, channelId, screenStream1);
            } else {
                console.log('Kendisi için bağlantı atlanıyor:', targetId);
            }
        });

        screenStream1.getVideoTracks()[0].onended = () => {
            console.log('Ekran paylaşımı durduruldu (track ended):', channelId);
            stopScreenShare(channelId);
        };
    } catch (error) {
        console.error('Ekran paylaşımı başlatılamadı:', error);
        showToast('error', 'Ekran paylaşımı başlatılamadı.');
    }
}

// Create peer connection for screen sharing
function createScreenPeerConnection(targetId, channelId, stream) {
    console.log('Screen peer connection oluşturuluyor:', targetId);
    const pc = new RTCPeerConnection({
        iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
    });
    screenPeerConnections[targetId] = pc;

    stream.getVideoTracks().forEach(track => {
        pc.addTrack(track, stream);
        console.log('Video track eklendi:', track.kind, targetId, 'enabled:', track.enabled);
    });

    pc.onicecandidate = (event) => {
        if (event.candidate) {
            ws1.send(JSON.stringify({
                type: 'screen-ice-candidate',
                target: targetId,
                candidate: event.candidate,
                channelId,
                sender: currentUserId1
            }));
            console.log('Screen ICE candidate gönderildi (create):', targetId);
        }
    };

    pc.oniceconnectionstatechange = () => {
        console.log('Screen ICE connection state (create):', targetId, pc.iceConnectionState);
        if (pc.iceConnectionState === 'failed') {
            console.error('Screen ICE connection failed (create):', targetId);
            pc.close();
            delete screenPeerConnections[targetId];
        }
    };

    pc.onnegotiationneeded = async () => {
        try {
            if (pc.signalingState === 'stable') {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                ws1.send(JSON.stringify({
                    type: 'screen-offer',
                    target: targetId,
                    sdp: pc.localDescription,
                    channelId,
                    sender: currentUserId1
                }));
                console.log('Screen offer gönderildi (create):', targetId, 'signaling state:', pc.signalingState);
            } else {
                console.warn('Screen offer creation skipped: Invalid signaling state:', pc.signalingState);
            }
        } catch (err) {
            console.error('Screen offer oluşturma hatası (create):', err);
        }
    };
}

// Stop screen sharing
function stopScreenShare(channelId) {
    console.log('Ekran paylaşımı durduruluyor:', channelId);
    if (screenStream1) {
        screenStream1.getTracks().forEach(track => track.stop());
        screenStream1 = null;
        document.getElementById('screen-preview-modal').classList.add('hidden');
        document.getElementById('start-screen-share').classList.remove('hidden');
        document.getElementById('stop-screen-share').classList.add('hidden');

        ws1.send(JSON.stringify({
            type: 'screen-share-end',
            channelId,
            userId: currentUserId1
        }));
        console.log('Screen share end mesajı gönderildi:', channelId);

        Object.values(screenPeerConnections).forEach(pc => pc.close());
        screenPeerConnections = {};
        console.log('Screen peer connections kapatıldı');
    }
}

// Ensure event listeners are set up
document.getElementById('start-screen-share').addEventListener('click', () => {
    console.log('Start screen share tıklandı:', currentVoiceChannel);
    startScreenShare(currentVoiceChannel);
});
document.getElementById('stop-screen-share').addEventListener('click', () => {
    console.log('Stop screen share tıklandı:', currentVoiceChannel);
    stopScreenShare(currentVoiceChannel);
});
async function createNewConnection(channelId, targetUserId) {
    try {
        const pc = new RTCPeerConnection(rtcConfig);
        peerConnections[targetUserId] = pc;

        // ICE Candidate handler
        pc.onicecandidate = ({ candidate }) => {
            if (candidate) {
                ws1.send(JSON.stringify({
                    type: 'ice-candidate',
                    channelId: channelId,
                    target: targetUserId,
                    candidate: candidate.toJSON(),
                    sender: currentUserId1
                }));
            }
        };

        // Track handler
        pc.ontrack = (event) => {
            handleRemoteTrack(event, targetUserId);
        };

        // Bağlantı durumu takibi
        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
                console.log('Bağlantı koptu, temizlik yapılıyor:', targetUserId);
                pc.close();
                delete peerConnections[targetUserId];
            }
        };

        // Local stream varsa ekle
        if (localStream1) {
            localStream1.getTracks().forEach(track => {
                pc.addTrack(track, localStream1);
            });
        }

        return pc;

    } catch (err) {
        console.error('Bağlantı oluşturma hatası:', err);
        return null;
    }
}



async function leaveVoiceChannel(channelId) {
    
    stopCallTimeCounter(); // Sayacı burada durduruyoruz.

    
    ws1.send(JSON.stringify({
        type: 'voice-leave',
        channelId: channelId,
        userId: currentUserId1
    }));

    const response = await fetch('voice_channel_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `channel_id=${channelId}&action=leave`
    });
    const data = await response.json();
    if (!data.success) {
        console.error('API hatası:', data.error);
    }

    Object.values(peerConnections).forEach(pc => pc.close());
    peerConnections = {};

    if (localStream1) {
        localStream1.getTracks().forEach(track => track.stop());
        localStream1 = null;
    }

    const container = document.getElementById('voice-audio-container');
    if (container) {
        container.innerHTML = '';
    }

    const controls = document.getElementById('voice-controls');
    if (controls) {
        controls.classList.add('hidden');
    }

    await updateVoiceParticipants(channelId);
}

function showVoiceControls(channelId) {
    const controls = document.getElementById('voice-controls');
    if (!controls) {
        console.error('Ses kontrolleri elementi bulunamadı!');
        return;
    }
    const channelNameElement = document.querySelector(
        `.voice-channel-btn[data-channel-id="${channelId}"] .text-white`
    );
    const channelName = channelNameElement ? channelNameElement.textContent : 'Bilinmeyen Kanal';
    const channelNameSpan = controls.querySelector('#channel-name');
    if (channelNameSpan) {
        channelNameSpan.textContent = channelName;
    } else {
        console.error('channel-name elementi bulunamadı!');
        return;
    }
    controls.classList.remove('hidden');
}




async function updateVoiceParticipants(channelId) {
    try {
        const response = await fetch(`get_voice_participants.php?channel_id=${channelId}`);
        const data = await response.json();
        if (data.success) {
            const container = document.querySelector(`.voice-participants[data-channel-id="${channelId}"]`);
            if (!container) {
                console.warn(`voice-participants container bulunamadı: channel_id=${channelId}`);
                return;
            }
            container.innerHTML = data.participants
                .map(participant => `
                    <div class="voice-participant" data-user-id="${participant.id}">
                        <img src="${participant.avatar_url || '/images/default-avatar.png'}" alt="${participant.username}" class="w-6 h-6 rounded-full" onerror="this.src='/images/default-avatar.png'">
                        <span class="username">${participant.username}</span>
                        ${participant.id === currentUserId1 ? '<span class="text-xs text-green-500 ml-2">(Sen)</span>' : ''}
                        <div class="status-indicator"></div>
                    </div>
                `).join('');
            container.classList.toggle('hidden', data.participants.length === 0);
            const participantCount = document.querySelector(
                `.voice-channel-btn[data-channel-id="${channelId}"] .participant-count`
            );
            if (participantCount) {
                participantCount.textContent = `${data.participant_count}/${data.max_users}`;
            } else {
                console.warn(`participant-count öğesi bulunamadı: channel_id=${channelId}`);
            }
        } else {
            console.error('Katılımcılar alınamadı:', data.error);
        }
    } catch (error) {
        console.error('Katılımcı güncelleme hatası:', error);
    }
}

function updateParticipantList(participants) {
    const channelId = currentVoiceChannel;
    if (!channelId) {
        console.warn('currentVoiceChannel tanımlı değil, güncelleme atlanıyor.');
        return;
    }

    const container = document.querySelector(`.voice-participants[data-channel-id="${channelId}"]`);
    if (!container) {
        console.warn(`voice-participants container bulunamadı: channel_id=${channelId}`);
        return;
    }

    console.log('Katılımcı listesi güncelleniyor:', participants); // Hata ayıklama

    const currentIds = new Set([...container.children].map(el => el.dataset.userId));
    const newIds = new Set(participants.map(p => p.id));

    // Kaldırılan katılımcıları sil
    currentIds.forEach(id => {
        if (!newIds.has(id)) {
            const element = container.querySelector(`[data-user-id="${id}"]`);
            if (element) element.remove();
            console.log(`Katılımcı kaldırıldı: ${id}`);
        }
    });

    // Yeni veya güncellenmiş katılımcıları ekle
    participants.forEach(participant => {
        if (!currentIds.has(participant.id)) {
            const div = document.createElement('div');
            div.className = 'voice-participant';
            div.dataset.userId = participant.id;
            div.innerHTML = `
                <img src="${participant.avatar_url || '/images/default-avatar.png'}" alt="${participant.username}" class="w-6 h-6 rounded-full" onerror="this.src='/images/default-avatar.png'">
                <span class="username">${participant.username}</span>
                ${participant.id === currentUserId1 ? '<span class="text-xs text-green-500 ml-2">(Sen)</span>' : ''}
                <div class="status-indicator"></div>
            `;
            container.appendChild(div);
            console.log(`Katılımcı eklendi: ${participant.id}`);
        }
    });

    container.classList.toggle('hidden', participants.length === 0);

    // Katılımcı sayısını güncelle
    const participantCount = document.querySelector(
        `.voice-channel-btn[data-channel-id="${channelId}"] .participant-count`
    );
    if (participantCount) {
        participantCount.textContent = `${participants.length}/10`; // max_users dinamik olarak alınabilir
    } else {
        console.warn(`participant-count öğesi bulunamadı: channel_id=${channelId}`);
    }
}

document.querySelectorAll('.join-button').forEach(button => {
    button.addEventListener('click', async function() {
        const channelId = this.closest('[data-channel-id]').dataset.channelId;
        const isJoined = this.dataset.joined === 'true';

        try {
            if (isJoined) {
                await leaveVoiceChannel(channelId);
                this.dataset.joined = 'false';
                updateJoinButton(this, false);
            } else {
                await joinVoiceChannel(channelId);
                this.dataset.joined = 'true';
                updateJoinButton(this, true);
            }
        } catch (error) {
            console.error('Buton click hatası:', error);
        }
    });
});

const toggleMic = document.getElementById('toggle-mic');
const toggleDeafen = document.getElementById('toggle-deafen');
const disconnectVoice = document.getElementById('disconnect-voice');

if (toggleMic) {
    toggleMic.addEventListener('click', () => {
        if (localStream1) {
            const audioTrack = localStream1.getAudioTracks()[0];
            audioTrack.enabled = !audioTrack.enabled;
            toggleMic.classList.toggle('bg-gray-700', audioTrack.enabled);
            toggleMic.classList.toggle('bg-red-600', !audioTrack.enabled);
            toggleMic.querySelector('i').setAttribute('data-lucide', audioTrack.enabled ? 'mic' : 'mic-off');
            lucide.createIcons();
            ws1.send(JSON.stringify({
                type: 'voice-status',
                channelId: currentVoiceChannel,
                userId: currentUserId1,
                micEnabled: audioTrack.enabled
            }));
        }
    });
}

if (toggleDeafen) {
    toggleDeafen.addEventListener('click', () => {
        document.querySelectorAll('audio[id^="remote-audio-"]').forEach(audio => {
            audio.muted = !audio.muted;
        });
        toggleDeafen.classList.toggle('bg-gray-700', !document.querySelector('audio[id^="remote-audio-"]')?.muted);
        toggleDeafen.classList.toggle('bg-red-600', document.querySelector('audio[id^="remote-audio-"]')?.muted);
        toggleDeafen.querySelector('i').setAttribute('data-lucide', document.querySelector('audio[id^="remote-audio-"]')?.muted ? 'headphones-off' : 'headphones');
        lucide.createIcons();
    });
}

if (disconnectVoice) {
    disconnectVoice.addEventListener('click', async () => {
        if (currentVoiceChannel) {
            const button = document.querySelector(`.voice-channel-btn[data-channel-id="${currentVoiceChannel}"] .join-button`);
            await leaveVoiceChannel(currentVoiceChannel);
            if (button) {
                button.dataset.joined = 'false';
                updateJoinButton(button, false);
            }
            currentVoiceChannel = null;
        }
    });
}


function updateJoinButton(button, isJoined) {
    const icon = isJoined ? 'phone-off' : 'phone';
    const text = isJoined ? 'Ayrıl' : 'Katıl';
    const colorClass = isJoined ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700';
    
    button.innerHTML = `
        <i data-lucide="${icon}" class="w-4 h-4 mr-1"></i>
        <span>${text}</span>
    `;
    
    button.className = `join-button text-xs font-semibold px-3 py-1 rounded-full ${colorClass} text-white transition-colors flex items-center`;
    
    lucide.createIcons();
}
function handleRemoteTrack(event, userId) {

    const existingAudio = document.getElementById(`remote-audio-${userId}`);
    if (existingAudio) existingAudio.remove();


    const audio = document.createElement('audio');
    audio.id = `remote-audio-${userId}`;
    audio.autoplay = true;
    audio.srcObject = event.streams[0];
    
    // Konteynıra ekle
    const container = document.getElementById('voice-audio-container');
    if (container) {
        container.appendChild(audio);
    } else {
        console.error('Ses konteynırı bulunamadı!');
    }
}



// Mesaj düzenleme AJAX
document.querySelectorAll('.edit-message-form-ajax').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const messageId = this.closest('.edit-message-form').dataset.messageId;
        const newText = this.querySelector('textarea').value;
        
        fetch('editserver_message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `message_id=${messageId}&edited_message_text=${encodeURIComponent(newText)}`
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                // Mesajı görselde güncelle
                const messageElement = document.querySelector(`[data-message-id="${messageId}"] .text-white.text-sm.mt-1`);
                if(messageElement) {
                    messageElement.textContent = newText;
                }
                
                // Edit formunu kapat
                this.closest('.edit-message-form').classList.add('hidden');
                
         
            }
        })
        .catch(error => {
            console.error('Hata:', error);
            showToast('error', 'Bağlantı hatası!');
        });
    });
});

async function sendFriendRequest(targetUserId) {
    try {
        const formData = new FormData();
        formData.append('action', 'send_request');
        formData.append('receiver_id', targetUserId);

        const response = await fetch('find_user.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            // Buton durumunu güncelle
            const addFriendBtn = document.getElementById('add-friend-btn');
            addFriendBtn.innerHTML = '<i data-lucide="clock" class="w-4 h-4 mr-1"></i><span>İstek Gönderildi</span>';
            addFriendBtn.classList.remove('bg-[#3ba55d]', 'hover:bg-[#2d8c4d]');
            addFriendBtn.classList.add('bg-yellow-600', 'hover:bg-yellow-700');
            addFriendBtn.disabled = true;
            
            // WebSocket bildirimi
            ws1.send(JSON.stringify({
                type: 'friend-request-sent',
                receiverId: targetUserId
            }));
            
            return true;
        } else {
            showToast('error', result.message || 'Arkadaşlık isteği gönderilemedi');
            return false;
        }
    } catch (error) {
        console.error('Arkadaşlık isteği gönderme hatası:', error);
        showToast('error', 'Bir hata oluştu');
        return false;
    }
}

// Arkadaş ekleme butonu event listener
document.getElementById('add-friend-btn').addEventListener('click', function() {
    const userId = document.getElementById('popup-username').dataset.userId;
    const username = document.getElementById('popup-username').textContent;
    
    // Modal içeriğini ayarla
    document.getElementById('friend-request-message').textContent = 
        `${username} kullanıcısına arkadaşlık isteği göndermek istiyor musunuz?`;
    
    // Butonları ayarla
    document.getElementById('confirm-friend-request').classList.remove('hidden');
    document.getElementById('confirm-friend-request').onclick = async function() {
        const success = await sendFriendRequest(userId);
        if (success) {
            document.getElementById('friend-request-modal').classList.add('hidden');
        }
    };
    
    // Modalı göster
    document.getElementById('friend-request-modal').classList.remove('hidden');
});

// Modal kapatma butonları
document.getElementById('cancel-friend-request').addEventListener('click', function() {
    document.getElementById('friend-request-modal').classList.add('hidden');
});

document.getElementById('friend-request-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.add('hidden');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const messageContainer = document.getElementById('message-container');
    const serverId = <?php echo json_encode($server_id); ?>;
    const channelId = <?php echo json_encode($channel_id); ?>; // PHP'den gelen varsayılan kanal ID
    const currentUserId1 = <?php echo json_encode($_SESSION['user_id']); ?>; // Kullanıcı ID'sini oturumdan al

    // Mesajları yüklemek için AJAX çağrısı
    function loadInitialMessages() {
        const url = `get_channel_messages.php?server_id=${serverId}&channel_id=${channelId}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateMessageContainer(data.messages);
                    updateChannelName(data.channel_name);
                    lucide.createIcons();
                    // Lazy loading'i başlat

                    // Update last read message if there are messages
                    if (data.messages && data.messages.length > 0) {
                        const latestMessageId = Math.max(...data.messages.map(msg => parseInt(msg.id, 10)));
                        fetch('update_last_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `user_id=${currentUserId1}&channel_id=${channelId}&message_id=${latestMessageId}`
                        })
                        .then(response => response.json())
                        .then(updateData => {
                            if (!updateData.success) {
                                console.error('[DEBUG] Last read update failed:', updateData.message);
                                showToast('error', '<?php echo $translations['last_read_update_failed'] ?? 'Son okunan mesaj güncellenemedi.'; ?>');
                            } else {
                                console.log('[DEBUG] Last read message updated:', latestMessageId);
                            }
                        })
                        .catch(error => {
                            console.error('[DEBUG] Last read update error:', error);
                            showToast('error', '<?php echo $translations['last_read_update_failed'] ?? 'Son okunan mesaj güncellenemedi.'; ?>');
                        });
                    }
                } else {
                    console.error('Mesajlar alınamadı:', data.error);
                    messageContainer.innerHTML = '<div class="text-gray-400 text-center py-4">Henüz mesaj yok veya bir hata oluştu.</div>';
                }
            })
            .catch(error => {
                console.error('AJAX hatası:', error);
                messageContainer.innerHTML = '<div class="text-gray-400 text-center py-4">Mesajlar yüklenirken bir hata oluştu.</div>';
            });
    }

    lucide.createIcons();
    loadInitialMessages();
});
const serverId = <?php echo json_encode($server_id); ?>;
let currentChannelId1 = <?php echo json_encode($channel_id); ?>;
const pollInterval = 5000; // 5 saniye aralıklarla kontrol
let pollingInterval = null; // Polling interval'ini saklamak için

// Polling fonksiyonu
function pollNewMessages() {
    if (!currentChannelId1) {
        console.warn('[DEBUG] Kanal ID eksik, polling durduruldu');
        return;
    }

    let url = `check_new_messages.php?server_id=${serverId}&channel_id=${encodeURIComponent(currentChannelId1)}`;
    if (lastMessageId) {
        url += `&last_message_id=${lastMessageId}`;
    }

    fetch(url, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log(`[DEBUG] Fetch response status: ${response.status}`);
        if (!response.ok) {
            throw new Error(`HTTP hatası: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        const messageContainer = document.getElementById('message-container');
        if (!messageContainer) {
            console.warn('[DEBUG] Mesaj konteyneri bulunamadı');
            return;
        }

        if (data.success && data.messages && data.messages.length > 0) {
            let previousMessageData = null;
            if (messageContainer.lastElementChild) {
                const lastMessage = messageContainer.lastElementChild;
                previousMessageData = {
                    sender_id: lastMessage.dataset.senderId,
                    timestamp: parseInt(lastMessage.dataset.timestamp, 10) || 0
                };
            }

            const fragment = document.createDocumentFragment();
            data.messages.forEach(message => {
                console.log('[DEBUG] Yeni mesaj:', message); // Mesajı logla
                if (!message.created_at_unix) {
                    message.created_at_unix = Math.floor(Date.now() / 1000);
                }

                const existingMessage = messageContainer.querySelector(`[data-message-id="${message.id}"]`);
                if (!existingMessage) {
                    const messageElement = createNewMessageElement(message, previousMessageData);
                    fragment.appendChild(messageElement);

                    previousMessageData = {
                        sender_id: message.sender_id,
                        timestamp: parseInt(message.created_at_unix, 10)
                    };

                    if (!lastMessageId || message.id > lastMessageId) {
                        lastMessageId = message.id;
                    }

                    if (messageContainer.lastElementChild && previousMessageData) {
                        const isStackable = areMessagesStackable(
                            {
                                sender_id: message.sender_id,
                                timestamp: message.created_at_unix
                            },
                            {
                                sender_id: messageContainer.lastElementChild.dataset.senderId,
                                timestamp: parseInt(messageContainer.lastElementChild.dataset.timestamp, 10) || 0
                            }
                        );
                        if (isStackable) {
                            messageElement.classList.add('stacked-message');
                            const headerToHide = messageElement.querySelector('.message-header');
                            const avatarToHide = messageElement.querySelector('.message-avatar');
                            if (headerToHide) headerToHide.style.display = 'none';
                            if (avatarToHide) avatarToHide.style.display = 'none';
                        }
                    }
                }
            });

            messageContainer.appendChild(fragment);
            const messageElements = messageContainer.querySelectorAll('.message-text');
            messageElements.forEach(el => {
                el.innerHTML = replaceEmojiNamesWithImages(el.innerHTML);
            });
            lucide.createIcons();
        } else {
            console.log('[DEBUG] Yeni mesaj yok:', data.message);
        }
    })
    .catch(error => {
        console.error('[ERROR] Mesaj çekme hatası:', error);
        // Polling'i devam ettir
        pollingInterval = setTimeout(pollNewMessages, pollInterval);
    });
}

// Polling'i başlatma fonksiyonu
function startPolling() {
    console.log('[DEBUG] Starting polling');
    if (pollingInterval) {
        console.log('[DEBUG] Clearing existing polling interval:', pollingInterval);
        clearInterval(pollingInterval);
    }
    pollingInterval = setInterval(pollNewMessages, pollInterval);
    console.log('[DEBUG] New polling interval set:', pollingInterval);
    pollNewMessages(); // Hemen bir kez çalıştır
}
// Global variables for tracking message loading
let lastMessageId = null; // Tracks the oldest message ID for fetching older messages
let isLoadingMessages = false; // Prevents multiple simultaneous requests
let messageCache = new Map(); // Cache to store fetched messages and avoid duplicates


async function loadMessages(beforeId = null, isInitialLoad = false) {
    if (isLoadingMessages || !currentChannelId1) {
        console.log('[DEBUG] Skipping loadMessages: ', { isLoadingMessages, currentChannelId1 });
        return;
    }

    console.log('[DEBUG] Loading messages, beforeId:', beforeId, 'isInitialLoad:', isInitialLoad);
    isLoadingMessages = true;

    const messageContainer = document.getElementById('message-container');
    if (!messageContainer) {
        console.error('[ERROR] message-container not found');
        isLoadingMessages = false;
        return;
    }

    const scrollHeightBefore = messageContainer.scrollHeight;
    const scrollTopBefore = messageContainer.scrollTop;

    try {
        // Kanal ID'sinin geçerli olduğundan emin ol
        if (currentChannelId1 !== String(new URLSearchParams(window.location.search).get('channel_id'))) {
            console.log('[DEBUG] Channel ID mismatch, aborting loadMessages');
            isLoadingMessages = false;
            return;
        }

        // Eğer beforeId boşsa ve isInitialLoad değilse, mevcut mesajların en eskisini al
        let effectiveBeforeId = beforeId;
        if (!beforeId && !isInitialLoad) {
            const allMessages = messageContainer.querySelectorAll('.message-bg');
            if (allMessages.length > 0) {
                effectiveBeforeId = allMessages[0].dataset.messageId;
                console.log('[DEBUG] Using first message ID as beforeId:', effectiveBeforeId);
            }
        }

        const params = new URLSearchParams({
            server_id: serverId,
            channel_id: currentChannelId1,
            before_id: effectiveBeforeId || ''
        });

        const response = await fetch(`get_channel_messages.php?${params.toString()}`);
        if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

        const data = await response.json();
        console.log('[DEBUG] Fetched messages:', data);

        if (data.success && data.messages && data.messages.length > 0) {
            const fragment = document.createDocumentFragment();
            let previousMessageData = null;

            // Mevcut mesajların ilkini al (stackleme bağlantısı için)
            const allMessages = messageContainer.querySelectorAll('.message-bg');
            const firstExistingMessage = allMessages.length > 0 ? allMessages[0] : null;
            if (!isInitialLoad && firstExistingMessage) {
                previousMessageData = {
                    sender_id: firstExistingMessage.dataset.senderId,
                    timestamp: parseInt(firstExistingMessage.dataset.timestamp, 10)
                };
            }

            // Mesajları kronolojik sırayla sırala (eski -> yeni)
            const sortedMessages = data.messages.sort((a, b) => a.id - b.id);

            sortedMessages.forEach((msg, index) => {
                // Önbellekte zaten varsa atla
                if (messageCache.has(msg.id)) {
                    console.log('[DEBUG] Skipping duplicate message:', msg.id);
                    return;
                }

                messageCache.set(msg.id, msg);

                const messageElement = createNewMessageElement(msg, previousMessageData);
                fragment.appendChild(messageElement);

                previousMessageData = {
                    sender_id: msg.sender_id,
                    timestamp: parseInt(msg.created_at_unix, 10)
                };

                // İlk yüklemede veya yeni mesajlar eklendiğinde lastMessageId'yi güncelle
                if (index === 0 && !isInitialLoad) {
                    lastMessageId = msg.id;
                }
            });

            if (isInitialLoad) {
                messageContainer.innerHTML = ''; // İlk yüklemede konteyneri temizle
                messageContainer.appendChild(fragment);
                messageContainer.scrollTop = messageContainer.scrollHeight;

                // İlk yüklemede lastMessageId'yi mevcut mesajların en eskisiyle güncelle
                const allMessagesAfterLoad = messageContainer.querySelectorAll('.message-bg');
                if (allMessagesAfterLoad.length > 0) {
                    lastMessageId = allMessagesAfterLoad[0].dataset.messageId;
                    console.log('[DEBUG] Initial load set lastMessageId:', lastMessageId);
                }
            } else {
                messageContainer.prepend(fragment);
                const scrollHeightAfter = messageContainer.scrollHeight;
                messageContainer.scrollTop = scrollTopBefore + (scrollHeightAfter - scrollHeightBefore);

                // Batch ile mevcut mesajlar arasındaki stackleme bağlantısını düzelt
                const prependedMessages = fragment.querySelectorAll('.message-bg');
                if (prependedMessages.length > 0 && allMessages.length > 0) {
                    const lastPrependedMessage = prependedMessages[prependedMessages.length - 1];
                    const firstExistingMessage = messageContainer.querySelector('.message-bg');
                    const lastPrependedData = {
                        sender_id: lastPrependedMessage.dataset.senderId,
                        timestamp: parseInt(lastPrependedMessage.dataset.timestamp, 10)
                    };
                    const firstExistingData = {
                        sender_id: firstExistingMessage.dataset.senderId,
                        timestamp: parseInt(firstExistingMessage.dataset.timestamp, 10)
                    };

                    const shouldStack = areMessagesStackable(firstExistingData, lastPrependedData);
                    console.log('[DEBUG] Checking stacking between last prepended and first existing:', {
                        lastPrepended: lastPrependedData,
                        firstExisting: firstExistingData,
                        shouldStack
                    });

                    if (shouldStack) {
                        firstExistingMessage.classList.add('stacked-message');
                        firstExistingMessage.querySelector('.message-avatar').classList.add('invisible');
                        firstExistingMessage.querySelector('.message-header').classList.add('hidden');
                    } else {
                        firstExistingMessage.classList.remove('stacked-message');
                        firstExistingMessage.querySelector('.message-avatar').classList.remove('invisible');
                        firstExistingMessage.querySelector('.message-header').classList.remove('hidden');
                    }
                }
            }

            lucide.createIcons();
        } else {
            console.log('[DEBUG] No more messages to load or error:', data.error || 'No messages');
        }
    } catch (error) {
        console.error('[ERROR] Failed to load messages:', error);
        showTempNotification('Mesajlar yüklenemedi.', 'error');
    } finally {
        isLoadingMessages = false;
    }
}


// Scroll event listener
function setupScrollListener() {
    const messageContainer = document.getElementById('message-container');
    if (!messageContainer) {
        console.error('[ERROR] message-container not found');
        return;
    }

    messageContainer.addEventListener('scroll', () => {
        if (messageContainer.scrollTop < 100 && !isLoadingMessages) {
            console.log('[DEBUG] Scroll near top, triggering loadMessages');
            loadMessages(lastMessageId);
        }
    });
}

// DOM yüklendiğinde scroll listener'ı başlat
document.addEventListener('DOMContentLoaded', () => {
    setupScrollListener();
    console.log('[DEBUG] Scroll listener initialized');
});
let isChannelSwitching = false; // Kanal değiştirme işlemi devam ederken çakışmayı önlemek için

// Kanal değiştirme fonksiyonu
async function changeChannel(channelId, channelName) {
    if (isChannelSwitching) {
        console.log('[DEBUG] Kanal değiştirme işlemi zaten devam ediyor, yeni istek engellendi.');
        return;
    }

    isChannelSwitching = true;
    console.log(`[DEBUG] changeChannel called with channelId: ${channelId}, channelName: ${channelName}`);

    try {
        // 1. Önceki kanalın verilerini temizle
        await cleanupPreviousChannel();

        // 2. Yeni kanal ID'sini güncelle
        currentChannelId1 = channelId;
        console.log('[DEBUG] Updated currentChannelId1 to:', currentChannelId1);

        // 3. Kanal ID'sini formda güncelle
        const channelIdInput = document.querySelector('input[name="channel_id"]');
        if (channelIdInput) {
            channelIdInput.value = channelId;
            console.log('[DEBUG] Updated channel_id input value to:', channelId);
        } else {
            console.warn('[DEBUG] channel_id input not found');
        }

        // 4. Yükleme animasyonunu göster
        const messageContainer = document.getElementById('message-container');
        if (messageContainer) {
            messageContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><i data-lucide="loader-2" class="animate-spin inline-block"></i> Yükleniyor...</div>';
            lucide.createIcons();
        }

        // 5. Kanal mesajlarını, yazma izinlerini ve son okunan mesaj güncellemesini paralel olarak al
        const [messageResponse, permissionResponse, readResponse] = await Promise.all([
            fetch(`get_channel_messages.php?server_id=${serverId}&channel_id=${channelId}`),
            fetch(`check_write_permission.php?channel_id=${channelId}`),
            fetch('update_last_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `channel_id=${channelId}`
            })
        ]);

        // Mesaj yanıtını kontrol et
        if (!messageResponse.ok) {
            throw new Error(`Mesajları alma hatası! Status: ${messageResponse.status}`);
        }
        const messageData = await messageResponse.json();

        // İzin yanıtını kontrol et
        if (!permissionResponse.ok) {
            throw new Error(`Yazma izni alma hatası! Status: ${permissionResponse.status}`);
        }
        const permissionData = await permissionResponse.json();

        // Okunmamış mesaj güncelleme yanıtını kontrol et
        if (!readResponse.ok) {
            console.warn('[DEBUG] Son okunan mesaj güncelleme hatası! Status:', readResponse.status);
        } else {
            const readData = await readResponse.json();
            if (readData.success) {
                console.log('[DEBUG] Son okunan mesaj güncellendi:', channelId);
                // Beyaz noktayı kaldır
                const channelElement = document.querySelector(`[data-channel-id="${channelId}"] .unread-dot`);
                if (channelElement) {
                    channelElement.remove();
                    console.log('[DEBUG] Beyaz nokta kaldırıldı:', channelId);
                }
            } else {
                console.warn('[DEBUG] Son okunan mesaj güncelleme hatası:', readData.error);
            }
        }

        if (messageData.success && permissionData.success) {
            // 6. Mesajları ve kanal adını güncelle
            updateMessageContainer(messageData.messages);
            updateChannelName(messageData.channel_name);

            // 7. Yazma iznini güncelle
            hasWritePermission = permissionData.has_write_permission;
            updateMessageForm(hasWritePermission);

            // 8. URL'yi güncelle
            history.pushState({}, '', `server?id=${serverId}&channel_id=${channelId}`);
            console.log('[DEBUG] Updated browser history with new channel ID');

            // 9. Polling'i başlat
            startPolling();
        } else {
            throw new Error(messageData.error || permissionData.error || 'Veri alınamadı');
        }
    } catch (error) {
        console.error('[DEBUG] Channel change error:', error);
        showToast('error', 'Kanal değiştirilirken bir hata oluştu: ' + error.message);
        // Hata durumunda mesaj konteynırını sıfırla
        if (messageContainer) {
            messageContainer.innerHTML = '<div class="text-center text-red-400 mt-8">Kanal yüklenemedi. Lütfen tekrar deneyin.</div>';
        }
    } finally {
        isChannelSwitching = false;
        console.log('[DEBUG] Kanal değiştirme işlemi tamamlandı');
    }
}
// Mesaj formunu yazma iznine göre güncelle
function updateMessageForm(hasWritePermission) {
    const messageForm = document.getElementById('message-form');
    const messageInput = document.getElementById('message-input');
    const emojiButton = document.getElementById('emoji-button');
    const fileInput = document.getElementById('file-input');
    const sendButton = document.getElementById('send-button');
    const translations1 = <?php echo json_encode($translations); ?>;

    if (!messageForm || !messageInput || !emojiButton || !fileInput || !sendButton) {
        console.warn('[DEBUG] Mesaj formu öğeleri eksik');
        return;
    }

    if (hasWritePermission) {
        messageForm.style.pointerEvents = 'auto';
        messageForm.style.opacity = '1';
        messageInput.placeholder = translations1.TypeMessage || 'Mesaj yaz...';
        messageInput.disabled = false;
        emojiButton.disabled = false;
        fileInput.disabled = false;
        sendButton.disabled = false;
    } else {
        messageForm.style.pointerEvents = 'none';
        messageForm.style.opacity = '0.5';
        messageInput.placeholder = translations1.TypePermission || 'Bu kanalda yazma izniniz yok.';
        messageInput.disabled = true;
        emojiButton.disabled = true;
        fileInput.disabled = true;
        sendButton.disabled = true;
    }
    console.log('[DEBUG] Message form updated, hasWritePermission:', hasWritePermission);
}
function updateMessageContainer(messages) {
    const messageContainer = document.getElementById('message-container');
    messageContainer.innerHTML = ''; // Mevcut mesajları temizle
    let previousMessageData = null;
    messages.forEach(message => {
        const messageElement = createNewMessageElement(message, previousMessageData);
        messageContainer.appendChild(messageElement);
        previousMessageData = {
            sender_id: message.sender_id,
            timestamp: message.created_at_unix
        };
    });
    messageContainer.scrollTop = messageContainer.scrollHeight; // En alta kaydır
}

function updateChannelName(channelName) {
    const channelNameElement = document.querySelector('.text-white.text-lg.font-medium');
    if (channelNameElement) {
        channelNameElement.textContent = channelName;
    }
}
let previousUnreadCount = 0;
let hasInteracted = false;

// Kullanıcı etkileşimini algıla
function handleUserInteraction() {
    hasInteracted = true;
    // Etkileşim olaylarını kaldır (bir kez yeterli)
    document.removeEventListener('click', handleUserInteraction);
    document.removeEventListener('touchstart', handleUserInteraction);
    document.removeEventListener('keydown', handleUserInteraction);
}

// Etkileşim olaylarını dinle
document.addEventListener('click', handleUserInteraction);
document.addEventListener('touchstart', handleUserInteraction);
document.addEventListener('keydown', handleUserInteraction);

function playNotificationSound() {
    // Kullanıcı etkileşime girmediyse ses çalma
    if (!hasInteracted) {
        console.log('Ses çalma atlandı: Kullanıcı henüz etkileşime girmedi.');
        return;
    }

    // Kullanıcının özel bildirim sesini al
    const customSound = "<?php 
        $stmt = $db->prepare('SELECT notification_sound FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        echo $stmt->fetchColumn() ?? '/sounds/bildirim.mp3';
    ?>";
    
    try {
        const audio = new Audio(customSound);
        audio.play().catch(error => {
            console.error('Özel bildirim sesi çalma hatası:', error);
            // Varsayılan sese geri dön
            const fallbackAudio = document.getElementById('notification-sound');
            fallbackAudio.play().catch(fallbackError => {
                console.error('Varsayılan ses çalma hatası:', fallbackError);
            });
        });
    } catch (error) {
        console.error('Bildirim sesi çalma hatası:', error);
        // Varsayılan sese geri dön
        const fallbackAudio = document.getElementById('notification-sound');
        fallbackAudio.play().catch(fallbackError => {
            console.error('Varsayılan ses çalma hatası:', fallbackError);
        });
    }
}

async function updateUnreadMessageCount() {
    try {
        const response = await fetch('get_unread_counts.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();

        if (data.success) {
            const unreadCounter = document.getElementById('unread-message-counter');
            // Sum all unread message counts
            const totalUnread = Object.values(data.counts).reduce((sum, count) => sum + parseInt(count), 0);
            
            // Okunmamış mesaj sayısı artarsa bildirim sesi çal
            if (totalUnread > previousUnreadCount && totalUnread > 0) {
                playNotificationSound();
            }
            // Önceki sayıyı güncelle
            previousUnreadCount = totalUnread;

            if (totalUnread > 0) {
                unreadCounter.textContent = totalUnread;
                unreadCounter.classList.remove('hidden');
            } else {
                unreadCounter.classList.add('hidden');
            }
        } else {
            console.error('Okunmamış mesajlar alınamadı:', data.error);
        }
    } catch (error) {
        console.error('Okunmamış mesaj sayımı alma hatası:', error);
    }
}

// Sayfada yüklendiğinde ve periyodik olarak güncelleme
document.addEventListener('DOMContentLoaded', () => {
        console.log('DOMContentLoaded tetiklendi');
    setInterval(updateRtcStatusAndPing, 1000); // Her saniye güncelle
      document.querySelectorAll('.voice-channel-btn[data-type="voice"]').forEach(channel => {
        const channelId = channel.dataset.channelId;
        const container = document.querySelector(`.voice-participants[data-channel-id="${channelId}"]`);
        if (container) {
            updateVoiceParticipants(channelId);
        } else {
            console.warn(`voice-participants container bulunamadı, güncelleme atlanıyor: channel_id=${channelId}`);
        }
    });
    lucide.createIcons();
    updateUnreadMessageCount();
    // Her 30 saniyede bir güncelle
    setInterval(updateUnreadMessageCount, 5000);
});


// Fullscreen toggle functionality
const toggleFullscreenBtn = document.getElementById('toggle-fullscreen');
const screenPreview = document.getElementById('screen-preview');

if (toggleFullscreenBtn && screenPreview) {
    toggleFullscreenBtn.addEventListener('click', async () => {
        if (!document.fullscreenElement) {
            // Enter fullscreen
            try {
                await screenPreview.requestFullscreen();
                toggleFullscreenBtn.innerHTML = `
                    <i data-lucide="minimize" class="w-4 h-4 mr-2"></i>
                    Tam Ekrandan Çık
                `;
                lucide.createIcons();
            } catch (error) {
                console.error('Tam ekran başlatma hatası:', error);
                showToast('error', 'Tam ekran modu başlatılamadı.');
            }
        } else {
            // Exit fullscreen
            try {
                await document.exitFullscreen();
                toggleFullscreenBtn.innerHTML = `
                    <i data-lucide="maximize" class="w-4 h-4 mr-2"></i>
                    Tam Ekran
                `;
                lucide.createIcons();
            } catch (error) {
                console.error('Tam ekrandan çıkma hatası:', error);
                showToast('error', 'Tam ekrandan çıkılamadı.');
            }
        }
    });

    // Handle fullscreen change events to keep button state in sync
    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement) {
            toggleFullscreenBtn.innerHTML = `
                <i data-lucide="maximize" class="w-4 h-4 mr-2"></i>
                Tam Ekran
            `;
        } else {
            toggleFullscreenBtn.innerHTML = `
                <i data-lucide="minimize" class="w-4 h-4 mr-2"></i>
                Tam Ekrandan Çık
            `;
        }
        lucide.createIcons();
    });
}

const toggleRemoteFullscreenBtn = document.getElementById('toggle-remote-fullscreen');
const remoteScreenPreview = document.getElementById('remote-screen-preview');

if (toggleRemoteFullscreenBtn && remoteScreenPreview) {
    toggleRemoteFullscreenBtn.addEventListener('click', async () => {
        if (!document.fullscreenElement) {
            try {
                await remoteScreenPreview.requestFullscreen();
                toggleRemoteFullscreenBtn.innerHTML = `
                    <i data-lucide="minimize" class="w-4 h-4 mr-2"></i>
                    Tam Ekrandan Çık
                `;
                lucide.createIcons();
            } catch (error) {
                console.error('Tam ekran başlatma hatası:', error);
                showToast('error', 'Tam ekran modu başlatılamadı.');
            }
        } else {
            try {
                await document.exitFullscreen();
                toggleRemoteFullscreenBtn.innerHTML = `
                    <i data-lucide="maximize" class="w-4 h-4 mr-2"></i>
                    Tam Ekran
                `;
                lucide.createIcons();
            } catch (error) {
                console.error('Tam ekrandan çıkma hatası:', error);
                showToast('error', 'Tam ekrandan çıkılamadı.');
            }
        }
    });
}

// Tarayıcılar arası tam ekran isteği
function requestFullscreen(element) {
    if (element.requestFullscreen) {
        return element.requestFullscreen();
    } else if (element.webkitRequestFullscreen) {
        return element.webkitRequestFullscreen();
    } else if (element.mozRequestFullScreen) {
        return element.mozRequestFullScreen();
    } else if (element.msRequestFullscreen) {
        return element.msRequestFullscreen();
    }
}

// Tarayıcılar arası tam ekrandan çık
function exitFullscreen() {
    if (document.exitFullscreen) {
        return document.exitFullscreen();
    } else if (document.webkitExitFullscreen) {
        return document.webkitExitFullscreen();
    } else if (document.mozCancelFullScreen) {
        return document.mozCancelFullScreen();
    } else if (document.msExitFullscreen) {
        return document.msExitFullscreen();
    }
}

// Yerel tam ekran butonu için güncellenmiş kod
toggleFullscreenBtn.addEventListener('click', async () => {
    if (!document.fullscreenElement) {
        try {
            await requestFullscreen(screenPreview);
            toggleFullscreenBtn.innerHTML = `
                <i data-lucide="minimize" class="w-4 h-4 mr-2"></i>
                Tam Ekrandan Çık
            `;
            lucide.createIcons();
        } catch (error) {
            console.error('Tam ekran başlatma hatası:', error);
            showToast('error', 'Tam ekran modu başlatılamadı.');
        }
    } else {
        try {
            await exitFullscreen();
            toggleFullscreenBtn.innerHTML = `
                <i data-lucide="maximize" class="w-4 h-4 mr-2"></i>
                Tam Ekran
            `;
            lucide.createIcons();
        } catch (error) {
            console.error('Tam ekrandan çıkma hatası:', error);
            showToast('error', 'Tam ekrandan çıkılamadı.');
        }
    }
});

// Uzak tam ekran butonu için güncellenmiş kod
if (toggleRemoteFullscreenBtn && remoteScreenPreview) {
    toggleRemoteFullscreenBtn.addEventListener('click', async () => {
        if (!document.fullscreenElement) {
            try {
                await requestFullscreen(remoteScreenPreview);
                toggleRemoteFullscreenBtn.innerHTML = `
                    <i data-lucide="minimize" class="w-4 h-4 mr-2"></i>
                    Tam Ekrandan Çık
                `;
                lucide.createIcons();
            } catch (error) {
                console.error('Tam ekran başlatma hatası:', error);
                showToast('error', 'Tam ekran modu başlatılamadı.');
            }
        } else {
            try {
                await exitFullscreen();
                toggleRemoteFullscreenBtn.innerHTML = `
                    <i data-lucide="maximize" class="w-4 h-4 mr-2"></i>
                    Tam Ekran
                `;
                lucide.createIcons();
            } catch (error) {
                console.error('Tam ekrandan çıkma hatası:', error);
                showToast('error', 'Tam ekrandan çıkılamadı.');
            }
        }
    });
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'f' && !e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey) {
        if (screenPreview.contains(document.activeElement) || remoteScreenPreview?.contains(document.activeElement)) {
            toggleFullscreenBtn.click();
        }
    }
});

//======================================================================
// SESLİ KANAL GÜNCELLEME SİSTEMİ (POLLING)
//======================================================================

/**
 * Sayfadaki tüm sesli kanalların katılımcı bilgilerini periyodik olarak günceller.
 * Bu fonksiyon, diğer kullanıcıların kanala katılma veya ayrılma durumlarını
 * tüm istemcilere yansıtmak için kullanılır.
 */
async function pollAllVoiceChannels() {
    // Sayfadaki tüm sesli kanal butonlarını seçelim.
    const voiceChannelElements = document.querySelectorAll('.voice-channel-btn[data-channel-id]');

    // Eğer sayfada hiç sesli kanal yoksa işlemi sonlandır.
    if (voiceChannelElements.length === 0) {
        return;
    }

    // Her bir sesli kanal için güncelleme işlemini gerçekleştir.
    for (const channelElement of voiceChannelElements) {
        const channelId = channelElement.dataset.channelId;
        if (!channelId) continue; // Kanal ID'si yoksa atla.

        try {
            // Sunucudan bu kanala ait güncel katılımcı bilgilerini çek.
            const response = await fetch(`get_voice_participants.php?channel_id=${channelId}`);
            const data = await response.json();

            if (data.success) {
                // Arayüzü yeni verilerle güncelle.
                updateVoiceChannelUI(channelId, data);
            }
        } catch (error) {
            // Bir hata oluşursa konsola yazdır, ancak döngüyü kırma.
            console.error(`Kanal ${channelId} için veri çekilemedi:`, error);
        }
    }
}

function updateVoiceChannelUI(channelId, data) {
    // Katılımcı sayısını gösteren (Örn: 5/10) etiketi bul ve güncelle.
    const participantCountElement = document.querySelector(`.voice-channel-btn[data-channel-id="${channelId}"] .participant-count`);
    if (participantCountElement) {
        participantCountElement.textContent = `${data.participant_count}/${data.max_users}`;
    }

    // Katılımcı listesinin gösterildiği alanı (div) bul.
    const participantsContainer = document.querySelector(`.voice-participants[data-channel-id="${channelId}"]`);
    if (participantsContainer) {
        // Mevcut listeyi temizle.
        participantsContainer.innerHTML = '';

        // Yeni katılımcı listesini HTML olarak oluştur.
        if (data.participants && data.participants.length > 0) {
            data.participants.forEach(participant => {
                const participantHTML = `
                    <div class="voice-participant" data-user-id="${participant.id}">
                        <img src="${participant.avatar_url || '/images/default-avatar.png'}" 
                             alt="${participant.username}" class="w-6 h-6 rounded-full object-cover">
                        <span class="username text-white">${participant.username}</span>
                    </div>
                `;
                participantsContainer.innerHTML += participantHTML;
            });
            // Eğer en az bir katılımcı varsa listeyi görünür yap.
            participantsContainer.classList.remove('hidden');
        } else {
            // Hiç katılımcı yoksa listeyi gizle.
            participantsContainer.classList.add('hidden');
        }
    }
}

// Sayfa yüklendiğinde güncellemeyi bir kere çalıştır.
document.addEventListener('DOMContentLoaded', () => {
    pollAllVoiceChannels();
});


setInterval(pollAllVoiceChannels, 5000); 

    const websocket = new WebSocket('wss://lakeban.com:8000');
    
document.addEventListener('DOMContentLoaded', () => {


    lucide.createIcons(); // Eklenen ikonları render etmek için



    websocket.onopen = (event) => {
        console.log('WebSocket bağlantısı kuruldu.');
    };

    websocket.onmessage = (event) => {
        const data = JSON.parse(event.data);
        // Sunucudan gelen mesajın yapısına göre güncellemeyi tetikleyin
        if (data.type === 'voice_channel_update' && data.channel_id) {
            updateVoiceChannelUI(data.channel_id, data);
            updateRtcStatusAndPing(); // RTC durumunu güncelle
        }
    };

    websocket.onclose = (event) => {
        console.log('WebSocket bağlantısı kapatıldı.');
        // Bağlantı koptuğunda yeniden bağlanma mantığı ekleyebilirsiniz.
    };

    websocket.onerror = (error) => {
        console.error('WebSocket hatası:', error);
    };
});

// Helper function to map RTC states to Turkish and assign colors
function mapRtcStateToTurkish(state) {
    switch (state) {
        case 'new':
            return { text: 'Yeni', color: '#5865F2' }; // Discord mavi
        case 'checking':
            return { text: 'Kontrol Ediliyor', color: '#F1C40F' }; // Sarı
        case 'connected':
            return { text: 'Bağlandı', color: '#3BA55C' }; // Yeşil
        case 'completed':
            return { text: 'Tamamlandı', color: '#3BA55C' }; // Yeşil
        case 'disconnected':
            return { text: 'Bağlantı Kesildi', color: '#ED4245' }; // Kırmızı
        case 'failed':
            return { text: 'Başarısız', color: '#ED4245' }; // Kırmızı
        case 'closed':
            return { text: 'Kapalı', color: '#747F8D' }; // Gri
        default:
            return { text: state, color: '#747F8D' }; // Varsayılan gri
    }
}

// RTC durumunu ve ping'i güncelleyen fonksiyon
function updateRtcStatusAndPing() {
    const connectionStatus = document.getElementById('connection-status');
    const pingLatency = document.getElementById('ping-latency');

    if (!currentVoiceChannel || Object.keys(peerConnections).length === 0) {
        if (connectionStatus) {
            connectionStatus.textContent = 'Bağlantı yok';
            connectionStatus.style.color = '#747F8D'; // Gri
        }
        if (pingLatency) {
            pingLatency.textContent = '';
            pingLatency.style.color = '#747F8D'; // Gri
        }
        return;
    }

    const firstParticipantId = Object.keys(peerConnections)[0];
    const pc = peerConnections[firstParticipantId];

    if (pc && pc.connectionState !== 'closed') {
        let rtcState = pc.iceConnectionState || pc.connectionState;
        let ping = 'Yok';

        pc.getStats(null).then(stats => {
            stats.forEach(report => {
                if (report.type === 'candidate-pair' && report.state === 'succeeded' && report.roundTripTime) {
                    ping = Math.round(report.roundTripTime * 1000) + 'ms';
                } else if (report.type === 'remote-inbound-rtp' && report.roundTripTime) {
                    ping = Math.round(report.roundTripTime * 1000) + 'ms';
                }
            });

            const stateInfo = mapRtcStateToTurkish(rtcState);
            if (connectionStatus) {
                connectionStatus.textContent = `Durum: ${stateInfo.text}`;
                connectionStatus.style.color = stateInfo.color;
            }
            if (pingLatency) {
                pingLatency.textContent = `Ping: ${ping}`;
                pingLatency.style.color = ping === 'Hata' ? '#ED4245' : '#dcddde'; // Hata kırmızı, normalde açık gri
            }
        }).catch(e => {
            console.error('RTC istatistikleri alınamadı:', e);
            const stateInfo = mapRtcStateToTurkish(rtcState);
            if (connectionStatus) {
                connectionStatus.textContent = `Durum: ${stateInfo.text}`;
                connectionStatus.style.color = stateInfo.color;
            }
            if (pingLatency) {
                pingLatency.textContent = `Ping: Hata`;
                pingLatency.style.color = '#ED4245'; // Kırmızı
            }
        });
    } else {
        if (connectionStatus) {
            connectionStatus.textContent = 'Bağlantı yok';
            connectionStatus.style.color = '#747F8D'; // Gri
        }
        if (pingLatency) {
            pingLatency.textContent = '';
            pingLatency.style.color = '#747F8D'; // Gri
        }
    }
}


//Fotoğraf büyütme fonksiyonu
// Fotoğraf tıklanınca büyütme fonksiyonu
// Tüm fotoğraflara tıklama olayı ekle
document.addEventListener("click", (event) => {
    if (event.target.classList.contains("uploaded-media")) {
        openFullscreen(event.target.src);
    }
});

// Fullscreen modal oluşturma
function openFullscreen(imageSrc) {
    // Eğer modal yoksa yeni modal oluştur
    let modal = document.getElementById("fullscreen-modal");
    if (!modal) {
        modal = document.createElement("div");
        modal.id = "fullscreen-modal";
        modal.style.position = "fixed";
        modal.style.top = "0";
        modal.style.left = "0";
        modal.style.width = "100%";
        modal.style.height = "100%";
        modal.style.background = "rgba(0, 0, 0, 0.9)";
        modal.style.display = "flex";
        modal.style.justifyContent = "center";
        modal.style.alignItems = "center";
        modal.style.zIndex = "9999";
        modal.style.cursor = "pointer";

        modal.addEventListener("click", () => {
            modal.remove();
        });

        document.body.appendChild(modal);
    }

    // İçerik temizle ve yeni görüntü ekle
    modal.innerHTML = "";
    const img = document.createElement("img");
    img.src = imageSrc;
    img.alt = "Fullscreen image";
    img.style.maxWidth = "100%";
    img.style.maxHeight = "100%";
    modal.appendChild(img);

    // Modalı göster
    modal.style.display = "fixed";
}
const serverEmojis = <?php echo json_encode(getServerEmojis($db, $server_id)); ?>;

function replaceEmojiNamesWithImages(text) {
    serverEmojis.forEach(emoji => {
        // Boşlukları tolere eden bir regex
        const regex = new RegExp(`\\s*:${emoji.emoji_name}:\\s*`, 'g');
        text = text.replace(regex, `<img src="${emoji.emoji_url}" alt="${emoji.emoji_name}" class="inline-block w-6 h-6">`);
    });
    return text;
}



document.addEventListener('DOMContentLoaded', () => {
    // Önce Emojileri işle
    console.log('Server Emojis:', serverEmojis);
    if (serverEmojis && Array.isArray(serverEmojis) && serverEmojis.length > 0) {
        const messageElements = document.querySelectorAll('.message-text');
        messageElements.forEach(el => {
            el.innerHTML = replaceEmojiNamesWithImages(el.innerHTML);
        });
    } else {
        console.warn('serverEmojis verisi eksik veya hatalı:', serverEmojis);
    }


    // Diğer fonksiyonları çalıştır
    lucide.createIcons();
    startPolling();
});


// Sabitlenen mesajlar panelini aç/kapat
document.getElementById('toggle-pinned-btn').addEventListener('click', function() {
    const pinnedPanel = document.getElementById('pinned-messages-panel');
    pinnedPanel.classList.toggle('hidden');
    
    if (!pinnedPanel.classList.contains('hidden')) {
        loadPinnedMessages();
    }
});

// Sabitlenen mesajlar panelini kapat
document.getElementById('close-pinned-panel-btn').addEventListener('click', function() {
    document.getElementById('pinned-messages-panel').classList.add('hidden');
});

// Sabitlenen mesajları yükleme fonksiyonu
function loadPinnedMessages() {
    const serverId = <?php echo json_encode($server_id); ?>;
    const channelId = <?php echo json_encode($channel_id); ?>;
    
    const container = document.getElementById('pinned-messages-container');
    const countElement = document.getElementById('pinned-count');
    
    container.innerHTML = '<div class="text-center text-gray-500 mt-8"><i data-lucide="loader-2" class="animate-spin inline-block"></i> Yükleniyor...</div>';
    lucide.createIcons();
    
    fetch(`get_pinned_messages.php?server_id=${serverId}&channel_id=${channelId}`)
        .then(response => response.json())
        .then(data => {
            container.innerHTML = '';
            
            if (!data.success || data.messages.length === 0) {
                container.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['pinned_messages_dm']['no_pinned']; ?></div>';
                countElement.textContent = '0';
                return;
            }
            
            countElement.textContent = data.messages.length;
            const fragment = document.createDocumentFragment();
            
            data.messages.forEach(msg => {
                const messageDiv = document.createElement('div');
                messageDiv.className = 'pinned-message-item flex items-start space-x-3';
                messageDiv.dataset.messageId = msg.id;
                
                const avatarUrl = msg.avatar_url || 'https://via.placeholder.com/40';
                const formattedDate = new Date(msg.created_at_unix * 1000).toLocaleDateString('tr-TR', {
                    day: '2-digit',
                    month: 'long',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                messageDiv.innerHTML = `
                    <img src="${avatarUrl}" alt="${msg.username}" class="w-10 h-10 rounded-full object-cover">
                    <div class="flex-1">
                        <div class="flex items-baseline space-x-2">
                            <span class="text-white font-semibold" style="color: ${msg.role_color || '#ffffff'}">${msg.username}</span>
                            <span class="text-gray-500 text-xs">${formattedDate}</span>
                        </div>
                        <p class="text-gray-300 text-sm mt-1">${msg.message_text.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>
                        <div class="pinned-message-actions">
                            <button class="jump-to-message text-indigo-400 hover:text-indigo-300" data-message-id="${msg.id}">
                                <i data-lucide="corner-down-right" class="w-4 h-4 mr-1"></i> Mesaja Git
                            </button>
                            ${(hasKickPermission || isOwner) ? `
                            <button class="unpin-message text-red-400 hover:text-red-300" data-message-id="${msg.id}">
                                <i data-lucide="pin-off" class="w-4 h-4 mr-1"></i> Sabitlemeyi Kaldır
                            </button>` : ''}
                        </div>
                    </div>
                `;
                
                fragment.appendChild(messageDiv);
            });
            
            container.appendChild(fragment);
            lucide.createIcons();
            
            // Mesaja git butonları
            document.querySelectorAll('.jump-to-message').forEach(btn => {
                btn.addEventListener('click', function() {
                    const messageId = this.dataset.messageId;
                    const targetMsg = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (targetMsg) {
                        document.getElementById('pinned-messages-panel').classList.add('hidden');
                        targetMsg.scrollIntoView({ behavior: 'smooth' });
                        targetMsg.classList.add('bg-yellow-500/10');
                        setTimeout(() => targetMsg.classList.remove('bg-yellow-500/10'), 2000);
                    }
                });
            });
            
            // Sabitlemeyi kaldır butonları
            document.querySelectorAll('.unpin-message').forEach(btn => {
                btn.addEventListener('click', function() {
                    const messageId = this.dataset.messageId;
                    unpinMessage(messageId);
                });
            });
        })
        .catch(error => {
            console.error('Sabitlenen mesajlar yüklenemedi:', error);
            container.innerHTML = '<div class="text-center text-red-400 mt-8">Mesajlar yüklenirken hata oluştu.</div>';
        });
}

// Sabitlemeyi kaldırma işlemi
function unpinMessage(messageId) {
    const serverId = <?php echo json_encode($server_id); ?>;
    const channelId = <?php echo json_encode($channel_id); ?>;
    
    fetch('unpin_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `message_id=${messageId}&server_id=${serverId}&channel_id=${channelId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadPinnedMessages();
            const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
            if (messageElement) {
                messageElement.dataset.pinned = 'false';
            }
        } else {
            alert(data.message || 'Sabitleme kaldırılırken hata oluştu');
        }
    })
    .catch(error => {
        console.error('Sabitleme kaldırma hatası:', error);
        alert('Sabitleme kaldırılırken bir hata oluştu');
    });
}

// Mesaj seçeneklerinde sabitleme işlemi
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-action="pin-message"]')) {
        const messageId = e.target.closest('[data-action="pin-message"]').dataset.messageId;
        console.log(`[DEBUG] Sabitleme işlemi başlatıldı, mesaj ID: ${messageId}`); // Hata ayıklama
        pinMessage(messageId);
    }
});

// Mesaj sabitleme fonksiyonu
// Debounce fonksiyonu
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function pinMessage(messageId) {
    const serverId = <?php echo json_encode($server_id); ?>;
    const channelId = <?php echo json_encode($channel_id); ?>;
    
    console.log(`[DEBUG] pinMessage çağrıldı: messageId=${messageId}, serverId=${serverId}, channelId=${channelId}`);
    
    // İşlem kilit kontrolü
    if (document.querySelector(`[data-message-id="${messageId}"]`).dataset.isProcessing === 'true') {
        console.log(`[DEBUG] Mesaj ${messageId} zaten işleniyor, istek engellendi`);
        return;
    }
    
    const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
    messageElement.dataset.isProcessing = 'true';
    
    fetch('pin_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `message_id=${messageId}&server_id=${serverId}&channel_id=${channelId}&action=${messageElement.dataset.pinned === 'true' ? 'unpin' : 'pin'}`
    })
    .then(response => {
        console.log(`[DEBUG] pin_message.php yanıtı: status=${response.status}`);
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('[DEBUG] pin_message.php veri:', data);
        messageElement.dataset.isProcessing = 'false';
        
        if (data.success) {
            showToast('success', data.message);
            messageElement.dataset.pinned = data.is_pinned ? 'true' : 'false';
            const pinLi = messageElement.querySelector('[data-action="pin-message"]');
            if (pinLi) {
                pinLi.innerHTML = data.is_pinned ? 
                    '<i data-lucide="pin-off" class="w-4 h-4 mr-2"></i> Sabitlemeyi Kaldır' : 
                    '<i data-lucide="pin" class="w-4 h-4 mr-2"></i> Sabitle';
                lucide.createIcons();
            }
            loadPinnedMessages(); // Sabitlenen mesajlar panelini güncelle
        } else {
            showToast('error', data.message || 'Mesaj sabitlenirken hata oluştu');
        }
    })
    .catch(error => {
        console.error('[DEBUG] Sabitleme hatası:', error);
        messageElement.dataset.isProcessing = 'false';
        showToast('error', 'Sabitleme sırasında bir hata oluştu: ' + error.message);
    });
}
document.addEventListener('DOMContentLoaded', () => {
    const serverList = document.getElementById('server-list');
    let draggedItem = null;

    // Add drag event listeners to each server item
    document.querySelectorAll('.server-item').forEach(item => {
        item.addEventListener('dragstart', (e) => {
            draggedItem = item;
            setTimeout(() => {
                item.classList.add('dragging');
            }, 0);
        });

        item.addEventListener('dragend', () => {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
            updateServerOrder();
        });

        item.addEventListener('dragover', (e) => {
            e.preventDefault();
        });

        item.addEventListener('dragenter', (e) => {
            e.preventDefault();
            item.classList.add('drag-over');
        });

        item.addEventListener('dragleave', () => {
            item.classList.remove('drag-over');
        });

        item.addEventListener('drop', (e) => {
            e.preventDefault();
            item.classList.remove('drag-over');
            if (draggedItem !== item) {
                // Reorder the DOM
                const allItems = Array.from(serverList.querySelectorAll('.server-item'));
                const draggedIndex = allItems.indexOf(draggedItem);
                const targetIndex = allItems.indexOf(item);

                if (draggedIndex < targetIndex) {
                    item.after(draggedItem);
                } else {
                    item.before(draggedItem);
                }
            }
        });
    });

    // Update server order in the database
    function updateServerOrder() {
        const serverItems = Array.from(serverList.querySelectorAll('.server-item'));
        const order = serverItems.map((item, index) => ({
            server_id: item.dataset.serverId,
            position: index
        }));

        // Send the new order to the server
        fetch('update_server_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to update server order:', data.error);
                showTempNotification('Sunucu sıralaması güncellenemedi.', 'error');
            }
        })
        .catch(error => {
            console.error('Error updating server order:', error);
            showTempNotification('Bir hata oluştu.', 'error');
        });
    }
});

// Modified showTempNotification to support error type
function showTempNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed bottom-4 right-4 bg-gray-800 text-white px-4 py-3 rounded-lg shadow-lg border-l-4 transform transition-all duration-300 opacity-0 translate-y-10`;
    notification.style.borderColor = type === 'error' ? '#ef4444' : '#3CB371';
    notification.innerHTML = `
        <div class="flex items-center">
            <i data-lucide="${type === 'error' ? 'alert-circle' : 'info'}" class="w-5 h-5 mr-2" style="color: ${type === 'error' ? '#ef4444' : '#3CB371'}"></i>
            <span>${message}</span>
        </div>
    `;
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateY(0)';
    }, 10);

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(10px)';
        setTimeout(() => notification.remove(), 300);
    }, 4000);

    lucide.createIcons();
}

// Global variables for call time counter
let callStartTime = null;
let callTimeInterval = null;

// Function to start the call time counter
function startCallTimeCounter() {
    console.log('[DEBUG] startCallTimeCounter called');
    if (callTimeInterval) {
        clearInterval(callTimeInterval);
        console.log('[DEBUG] Cleared existing call time interval');
    }
    callStartTime = Date.now();
    const callTimeCounter = document.getElementById('call-time-counter');

    if (!callTimeCounter) {
        console.error('[ERROR] call-time-counter element not found');
        return;
    }

    callTimeCounter.textContent = '00:00:00';
    callTimeInterval = setInterval(() => {
        const elapsedTime = Date.now() - callStartTime;
        const hours = Math.floor(elapsedTime / (1000 * 60 * 60)).toString().padStart(2, '0');
        const minutes = Math.floor((elapsedTime % (1000 * 60 * 60)) / (1000 * 60)).toString().padStart(2, '0');
        const seconds = Math.floor((elapsedTime % (1000 * 60)) / 1000).toString().padStart(2, '0');
        callTimeCounter.textContent = `${hours}:${minutes}:${seconds}`;
        console.log('[DEBUG] Call time updated:', callTimeCounter.textContent);
    }, 1000);
}

// Function to stop the call time counter
function stopCallTimeCounter() {
    console.log('[DEBUG] stopCallTimeCounter called');
    if (callTimeInterval) {
        clearInterval(callTimeInterval);
        callTimeInterval = null;
        console.log('[DEBUG] Call time interval cleared');
    }
    const callTimeCounter = document.getElementById('call-time-counter');
    if (callTimeCounter) {
        callTimeCounter.textContent = '00:00:00';
        console.log('[DEBUG] Call time counter reset to 00:00:00');
    }
}

// WebSocket for voice channel updates
document.addEventListener('DOMContentLoaded', () => {
    console.log('[DEBUG] DOMContentLoaded fired');
    lucide.createIcons();
    startPolling(); // Mevcut mesaj polling fonksiyonunu başlat



    websocket.onopen = () => {
        console.log('[DEBUG] WebSocket connection established');
    };

    websocket.onmessage = (event) => {
        console.log('[DEBUG] WebSocket message received:', event.data);
        const data = JSON.parse(event.data);

        if (data.type === 'voice_channel_update' && data.channel_id) {
            console.log('[DEBUG] Processing voice_channel_update for channel:', data.channel_id);
            updateVoiceChannelUI(data.channel_id, data);

            const currentUserId1 = <?php echo json_encode($_SESSION['user_id']); ?>;
            const isUserInParticipants = data.participants && data.participants.some(participant => participant.id == currentUserId1);

            console.log('[DEBUG] Current user ID:', currentUserId1, 'Is in participants:', isUserInParticipants);

            if (isUserInParticipants && data.channel_id !== currentVoiceChannel) {
                console.log('[DEBUG] User joined voice channel:', data.channel_id);
                currentVoiceChannel = data.channel_id;
                startCallTimeCounter();
                const voiceControls = document.getElementById('voice-controls');
                if (voiceControls) {
                    voiceControls.classList.remove('hidden');
                    console.log('[DEBUG] Voice controls shown');
                }
            } else if (!isUserInParticipants && currentVoiceChannel === data.channel_id) {
                console.log('[DEBUG] User left voice channel:', data.channel_id);
                currentVoiceChannel = null;
                stopCallTimeCounter();
                const voiceControls = document.getElementById('voice-controls');
                if (voiceControls) {
                    voiceControls.classList.add('hidden');
                    console.log('[DEBUG] Voice controls hidden');
                }
            }
        }
    };

    websocket.onclose = () => {
        console.log('[DEBUG] WebSocket connection closed');
        stopCallTimeCounter();
        currentVoiceChannel = null;
    };

    websocket.onerror = (error) => {
        console.error('[ERROR] WebSocket error:', error);
        stopCallTimeCounter();
        currentVoiceChannel = null;
    };
});

// Cleanup when changing channels
async function cleanupPreviousChannel() {
    console.log('[DEBUG] Cleaning up previous channel');

    // Kanal değiştirme işlemini kilitle
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
        console.log('[DEBUG] Cleared polling interval');
    }

    // Mesaj konteynırını sıfırla
    const messageContainer = document.getElementById('message-container');
    if (messageContainer) {
        messageContainer.innerHTML = '';
        console.log('[DEBUG] Cleared message container');
    } else {
        console.warn('[DEBUG] message-container not found');
    }

    // Mesaj yükleme ve cache değişkenlerini sıfırla
    isLoadingMessages = false;
    messageCache.clear();
    lastMessageId = null;
    console.log('[DEBUG] Reset message variables and cache');



    console.log('[DEBUG] Previous channel cleanup completed');
}
let typingDebounce1;
const currentUsername1 = <?php echo json_encode($current_user_username); ?>;
const usernameSpan1 = typingIndicator1 ? typingIndicator1.querySelector('.typing-username') : null;
const translations1 = <?php echo json_encode($translations); ?>;

// Debug: Initial state
console.log('🛠️ Initial Debugging Info:', {
    currentUserId1,
    currentUsername1,
    serverId,
    currentChannelId1,
    hasTypingIndicator1: !!typingIndicator1,
    hasUsernameSpan1: !!usernameSpan1,
    translations1Typing: translations1.typing || 'yazıyor...',
    hasWritePermission: typeof hasWritePermission !== 'undefined' ? hasWritePermission : 'undefined'
});

// Check if typingIndicator exists
if (!typingIndicator1) {
    console.error('🚨 Hata: #typing-indicator elementi bulunamadı!');
}
if (!usernameSpan1) {
    console.error('🚨 Hata: .typing-username elementi bulunamadı!');
}

// WebSocket authentication
ws1.addEventListener('open', () => {
    const authMessage = {
        type: 'auth',
        userId: String(currentUserId1),
        username: currentUsername1,
        avatarUrl: <?php echo json_encode($avatar_url ?? 'avatars/default-avatar.png'); ?>
    };
    console.log('📤 Gönderilen auth mesajı:', JSON.stringify(authMessage, null, 2));
    ws1.send(JSON.stringify(authMessage));
});

// Typing handler function
function handleTyping(isTyping) {
    if (!currentChannelId1 || !serverId) {
        console.log('🛑 Kanal veya sunucu seçili değil, typing gönderilmedi', { serverId, currentChannelId1 });
        return;
    }

    const typingMessage = {
        type: 'typing',
        username: currentUsername1,
        senderId: String(currentUserId1),
        isTyping: isTyping,
        isServer: true,
        serverId: String(serverId),
        channelId: String(currentChannelId1)
    };

    console.log('📤 Gönderilen typing mesajı:', typingMessage);
    ws1.send(JSON.stringify(typingMessage));
}

// Typing indicator listener
document.getElementById('message-input').addEventListener('input', () => {
    console.log('⌨️ Mesaj girişi algılandı, hasWritePermission:', hasWritePermission);
    if (!hasWritePermission) {
        console.log('🛑 Yazma izni yok, typing gönderilmedi');
        return;
    }

    clearTimeout(typingDebounce1);
    handleTyping(true);

    typingDebounce1 = setTimeout(() => {
        handleTyping(false);
    }, 1000); // 1 saniye sonra typing durur
});

// Stop typing when message is sent
document.getElementById('message-form').addEventListener('submit', (e) => {
    console.log('📤 Mesaj gönderildi, typing durduruluyor');
    handleTyping(false);
});

// Handle incoming typing events
ws1.addEventListener('message', (event) => {
    try {
        const data = JSON.parse(event.data);
        console.log('📩 Gelen WebSocket mesajı:', JSON.stringify(data, null, 2));

        // Check if message is a typing event
        if (data.type === 'typing') {
            console.log('🔍 Typing mesajı işleniyor:', {
                senderId: data.senderId,
                isTyping: data.isTyping,
                serverId: data.serverId,
                channelId: data.channelId,
                username: data.username,
                isServer: data.isServer,
                currentUserId1: String(currentUserId1),
                currentServerId: String(serverId),
                currentChannelId1: String(currentChannelId1)
            });

            // Ignore own typing messages
            if (String(data.senderId) === String(currentUserId1)) {
                console.log('🛑 Kendi typing mesajı yoksayıldı:', data.senderId);
                return;
            }

            // Treat as server typing if serverId and channelId are present
            const isServerTyping = data.isServer !== false && data.serverId && data.channelId;
            console.log('🔎 Server typing kontrolü:', { isServerTyping, isServer: data.isServer });

            if (isServerTyping) {
                // Validate server and channel IDs
                const isMatchingServer = String(data.serverId) === String(serverId);
                const isMatchingChannel = String(data.channelId) === String(currentChannelId1);
                console.log('🔎 ID eşleşme kontrolü:', {
                    isMatchingServer,
                    isMatchingChannel,
                    receivedServerId: data.serverId,
                    receivedChannelId: data.channelId,
                    currentServerId: serverId,
                    currentChannelId1: currentChannelId1
                });

                if (isMatchingServer && isMatchingChannel) {
                    console.log(`✍️ Typing göstergesi güncelleniyor: ${data.username}, isTyping: ${data.isTyping}`);
                    if (data.isTyping) {
                        console.log('✅ Typing göstergesi gösteriliyor');
                        if (typingIndicator1 && usernameSpan1) {
                            typingIndicator1.classList.remove('hidden');
                            typingIndicator1.style.opacity = '1';
                            usernameSpan1.textContent = `${data.username} ${translations1.typing || 'yazıyor...'}`;
                            console.log('✅ DOM güncellendi:', {
                                display: window.getComputedStyle(typingIndicator1).display,
                                opacity: window.getComputedStyle(typingIndicator1).opacity,
                                classList: typingIndicator1.classList.toString(),
                                textContent: usernameSpan1.textContent
                            });
                        } else {
                            console.error('🚨 Hata: typingIndicator veya usernameSpan1 mevcut değil', {
                                typingIndicator1: !!typingIndicator1,
                                usernameSpan1: !!usernameSpan1
                            });
                        }
                    } else {
                        console.log('✅ Typing göstergesi gizleniyor');
                        if (typingIndicator1) {
                            typingIndicator1.style.opacity = '0';
                            setTimeout(() => {
                                typingIndicator1.classList.add('hidden');
                                console.log('✅ Typing göstergesi gizlendi:', {
                                    display: window.getComputedStyle(typingIndicator1).display,
                                    opacity: window.getComputedStyle(typingIndicator1).opacity,
                                    classList: typingIndicator1.classList.toString()
                                });
                            }, 300); // Match CSS transition duration
                        } else {
                            console.error('🚨 Hata: typingIndicator mevcut değil');
                        }
                    }
                } else {
                    console.log('🛑 Mesaj farklı bir sunucu veya kanal için:', {
                        receivedServerId: data.serverId,
                        receivedChannelId: data.channelId,
                        currentServerId: serverId,
                        currentChannelId1: currentChannelId1
                    });
                }
            } else {
                console.log('🛑 Mesaj server typing için uygun değil:', { isServer: data.isServer });
            }
        }
    } catch (error) {
        console.error('🚨 Mesaj işleme hatası:', error, { rawData: event.data });
    }
});

// Update currentChannelId1 when channel changes
function updateChannelTyping(channelId) {
    currentChannelId1 = String(channelId); // Ensure string comparison
    console.log('🔄 Güncellenen kanal ID:', currentChannelId1);
}

// Hook into channel change function (if exists)
const originalChangeChannel = changeChannel || function() {};
changeChannel = async function(channelId, channelName) {
    console.log('🔄 Kanal değiştiriliyor:', { channelId, channelName });
    await originalChangeChannel(channelId, channelName);
    updateChannelTyping(channelId);
};
// Anket modalını açma
document.getElementById('poll-button').addEventListener('click', () => {
    document.getElementById('create-poll-modal').classList.remove('hidden');
});

// Anket modalını kapatma ve formu sıfırlama
document.getElementById('cancel-poll').addEventListener('click', () => {
    const modal = document.getElementById('create-poll-modal');
    const form = document.getElementById('poll-form');
    const optionsContainer = document.getElementById('poll-options');
    
    modal.classList.add('hidden');
    form.reset();
    // Sadece input olan poll-option'ları sil
    optionsContainer.querySelectorAll('input.poll-option').forEach((input, index) => {
        if (index > 1) input.remove();
    });
});

// Seçenek ekleme
document.getElementById('add-poll-option').addEventListener('click', () => {
    const optionsContainer = document.getElementById('poll-options');
    if (optionsContainer.querySelectorAll('input.poll-option').length < 10) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-input poll-option w-full mb-2';
        input.required = true;
        input.placeholder = `Seçenek ${optionsContainer.querySelectorAll('input.poll-option').length + 1}`;
        optionsContainer.appendChild(input);
    } else {
        showToast('error', '<?php echo $translations['max_options'] ?? 'En fazla 10 seçenek eklenebilir'; ?>');
    }
});

// Anket oluşturma
document.getElementById('poll-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const question = document.getElementById('poll-question').value.trim();
    const options = Array.from(document.querySelectorAll('input.poll-option'))
        .map(input => input.value ? input.value.trim() : '')
        .filter(val => val);

    if (!question) {
        showToast('error', '<?php echo $translations['question_required'] ?? 'Anket sorusu gereklidir'; ?>');
        return;
    }

    if (options.length < 2) {
        showToast('error', '<?php echo $translations['min_options'] ?? 'En az 2 seçenek gereklidir'; ?>');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'create_poll');
    formData.append('server_id', '<?php echo $server_id; ?>');
    formData.append('channel_id', currentChannelId1); // Güncel kanal ID'sini kullan
    formData.append('question', question);
    formData.append('options', JSON.stringify(options));

    try {
        const response = await fetch('vote_poll.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) {
            document.getElementById('create-poll-modal').classList.add('hidden');
            document.getElementById('poll-form').reset();
            document.querySelectorAll('input.poll-option').forEach((input, index) => {
                if (index > 1) input.remove();
            });
            showToast('success', '<?php echo $translations['poll_created'] ?? 'Anket oluşturuldu'; ?>');
        } else {
            showToast('error', data.message);
        }
    } catch (error) {
        console.error('Anket oluşturma hatası:', error);
        showToast('error', '<?php echo $translations['poll_error'] ?? 'Anket oluşturulurken hata oluştu'; ?>');
    }
});

document.addEventListener('click', async (e) => {
    const pollOption = e.target.closest('.poll-option');
    if (pollOption) {
        const messageId = pollOption.dataset.messageId;
        const optionIndex = pollOption.dataset.optionIndex;

        const formData = new FormData();
        formData.append('action', 'vote_poll');
        formData.append('message_id', messageId);
        formData.append('option_index', optionIndex);

        try {
            const response = await fetch('vote_poll.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                const options = pollOption.parentElement.querySelectorAll('.poll-option');
                options.forEach((opt, index) => {
                    const voteCount = opt.querySelector('.text-gray-400');
                    voteCount.textContent = `${data.poll_data.votes[index]} oy`;
                });
            }
        } catch (error) {
            // Hata işleme kaldırıldı
        }
    }
});
// Kategori düzenleme modali açma
function openEditCategoryModal(categoryId, categoryName) {
    document.getElementById('edit-category-modal').classList.remove('hidden');
    document.getElementById('edit-category-id').value = categoryId;
    document.getElementById('edit-category-name').value = categoryName;
}

// Kategori düzenleme formu gönderimi
document.getElementById('edit-category-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const categoryId = document.getElementById('edit-category-id').value;
    const serverId = document.getElementById('edit-server-id').value;
    const newName = document.getElementById('edit-category-name').value.trim();

    if (!newName) {
        showToast('error', '<?php echo $translations['category_name_required'] ?? 'Kategori adı gereklidir'; ?>');
        return;
    }

    try {
        const response = await fetch('edit_category.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=edit&server_id=${serverId}&category_id=${categoryId}&category_name=${encodeURIComponent(newName)}`
        });
        const data = await response.json();
        if (data.success) {
            showToast('success', data.message);
            document.getElementById('edit-category-modal').classList.add('hidden');
            location.reload(); // Sayfayı yenile
        } else {
            showToast('error', data.message);
        }
    } catch (error) {
        console.error('Kategori düzenleme hatası:', error);
        showToast('error', 'Kategori düzenlenirken bir hata oluştu');
    }
});

// Kategori silme
document.getElementById('delete-category-btn').addEventListener('click', async () => {
    const categoryId = document.getElementById('edit-category-id').value;
    const serverId = document.getElementById('edit-server-id').value;

    if (confirm('Bu kategoriyi silmek istediğinizden emin misiniz?')) {
        try {
            const response = await fetch('edit_category.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete&server_id=${serverId}&category_id=${categoryId}`
            });
            const data = await response.json();
            if (data.success) {
                showToast('success', data.message);
                document.getElementById('edit-category-modal').classList.add('hidden');
                location.reload(); // Sayfayı yenile
            } else {
                showToast('error', data.message);
            }
        } catch (error) {
            console.error('Kategori silme hatası:', error);
            showToast('error', 'Kategori silinirken bir hata oluştu');
        }
    }
});


// Mobil navbar linkleri
function gotoProfile() {
    window.location.href = '/profile-page?username=<?php echo htmlspecialchars($_SESSION['username']); ?>';
}
function gotoDM() {
    window.location.href = '/directmessages';
}
function gotoCOMM() {
    window.location.href = '/topluluklar';
}
function gotoLAKE() {
    window.location.href = '/posts';
}

// Modal kapatma
document.getElementById('cancel-category-btn').addEventListener('click', () => {
    document.getElementById('edit-category-modal').classList.add('hidden');
    document.getElementById('edit-category-form').reset();
});
    function updateLastActivity() {
                fetch('update_last_activity.php')
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            console.error('Failed to update last activity');
                        }
                    })
                    .catch(error => console.error('Error:', error));
            }

            setInterval(updateLastActivity, 5000); // Update every 5 seconds
            
            


document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notification-bell');
    const panel = document.getElementById('notification-panel');
    const counter = document.getElementById('notification-counter');
    const list = document.getElementById('notification-list');
    const markAllReadBtn = document.getElementById('mark-all-read');
    let isOpen1 = false;

    // Bildirim panelini aç/kapat
    bell.addEventListener('click', function(event) {
    event.stopPropagation();

    // Eğer panel açık değilse, animasyonla aç
    if (!isOpen1) {
        notificationPanel1.classList.remove('notific-anim-out');
        notificationPanel1.classList.add('notific-anim-in');
        notificationPanel1.classList.remove('hidden');

        // Bildirimleri almak için fetch fonksiyonunu çağır
        fetchNotifications();

    } else {
        // Eğer panel açık ise, animasyonla kapat
        notificationPanel1.classList.remove('notific-anim-in');
        notificationPanel1.classList.add('notific-anim-out');
    }
    isOpen1 = !isOpen1;
    });
    // Dışarı tıklayınca paneli kapat
    document.addEventListener('click', function(event) {
        if (!panel.contains(event.target) && !bell.contains(event.target)) {
            panel.classList.add('notific-anim-out');
        }
    });

    // Tümünü okundu say
    markAllReadBtn.addEventListener('click', function() {
        markNotificationAsRead(null); // ID null ise hepsi okunur
        list.innerHTML = '<div class="p-4 text-center text-gray-500">Okunmamış bildirim yok.</div>';
        counter.classList.add('hidden');
        counter.textContent = '0';
    });

    // Bildirimleri sunucudan çek
    function fetchNotifications() {
        fetch('get_notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationUI(data.notifications);
                }
            })
            .catch(error => console.error('Bildirimler alınamadı:', error));
    }

    // Bildirim arayüzünü güncelle
    function updateNotificationUI(notifications) {
        list.innerHTML = '';
        if (notifications.length > 0) {
            counter.textContent = notifications.length;
            counter.classList.remove('hidden');

            notifications.forEach(notif => {
                const notifElement = document.createElement('div');
                notifElement.className = 'p-3 hover:bg-gray-700 cursor-pointer border-b border-gray-700';
                notifElement.dataset.id = notif.id;
                notifElement.dataset.serverId = notif.server_id;
                notifElement.dataset.channelId = notif.channel_id;

                let message = `<strong>${notif.sender_username}</strong> sizi etiketledi.`;
                if (notif.server_name) {
                    message += ` - <strong>${notif.server_name}</strong> sunucusunda.`;
                }

                notifElement.innerHTML = `<p class="text-sm text-gray-300">${message}</p>`;
                list.appendChild(notifElement);
            });
        } else {
            list.innerHTML = '<div class="p-4 text-center text-gray-500">Okunmamış bildirim yok.</div>';
            counter.classList.add('hidden');
            counter.textContent = '0';
        }
    }

    // Bildirimi okundu olarak işaretle
  function markNotificationAsRead(notificationId) {
    const formData = new FormData();
    if (notificationId) {
        formData.append('notification_id', notificationId);
    } else {
        formData.append('action', 'mark_all_read'); // Tümünü okundu saymak için özel bir aksiyon
    }

    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (!notificationId) {
                // Tüm bildirimler okunduysa arayüzü sıfırla
                list.innerHTML = '<div class="p-4 text-center text-gray-500">Okunmamış bildirim yok.</div>';
                counter.classList.add('hidden');
                counter.textContent = '0';
            }
            fetchNotifications(); // Bildirimleri yenile
            showTempNotification('Bildirimler okundu olarak işaretlendi.', 'success');
        } else {
            showTempNotification(data.error || 'Bildirimler okundu olarak işaretlenemedi.', 'error');
        }
    })
    .catch(error => {
        console.error('Bildirimler okundu olarak işaretlenemedi:', error);
        showTempNotification('Bildirimler okundu olarak işaretlenemedi: ' + error.message, 'error');
    });
}

// Tümünü okundu say butonu
markAllReadBtn.addEventListener('click', function() {
    markNotificationAsRead(null); // null göndererek tüm bildirimleri okundu say
});

    // Bildirime tıklama olayı
    list.addEventListener('click', function(event) {
        const target = event.target.closest('.p-3');
        if (target) {
            const notifId = target.dataset.id;
            const serverId = target.dataset.serverId;
            const channelId = target.dataset.channelId;

            markNotificationAsRead(notifId);

            // İlgili sunucu ve kanala yönlendir
            if (serverId && channelId) {
                window.location.href = `server?id=${serverId}&channel_id=${channelId}`;
            }
            // Gerekirse direct message için de yönlendirme eklenebilir
        }
    });

    // Sayfa yüklendiğinde ve periyodik olarak bildirimleri kontrol et
    fetchNotifications();
    setInterval(fetchNotifications, 5000); // 15 saniyede bir kontrol et
});

// Bildirim paneli animasyon giriş çıkış kodları
let notificationPanel1 = document.getElementById('notification-panel');
let toggleButton1 = document.getElementById('notification-bell');
let isOpen1 = false; // Durum, panel açık mı kapalı mı?

toggleButton1.addEventListener('click', function() {
  if (isOpen1) {
    // Paneli kapat
    notificationPanel1.classList.remove('notific-anim-in');
    notificationPanel1.classList.add('notific-anim-out');
  } else {
    // Paneli aç
    notificationPanel1.classList.remove('notific-anim-out');
    notificationPanel1.classList.add('notific-anim-in');
  }

  // Durumu güncelle
  isOpen1 = !isOpen1;
});

 // CSS injection
    (function() {
        const style = document.createElement('style');
        style.id = 'status-styles';
        style.textContent = `
            #user-profile .status-dot {
                width: 10px !important;
                height: 10px !important;
                border-radius: 50% !important;
                border: 2px solid #121212 !important;
                background-color: transparent !important;
                position: absolute !important;
                bottom: 0 !important;
                right: 0 !important;
            }
            #user-profile .status-dot.status-online {
                background-color: #43b581 !important;
            }
            #user-profile .status-dot.status-idle {
                background-color: #faa61a !important;
            }
            #user-profile .status-dot.status-dnd {
                background-color: #f04747 !important;
            }
            #user-profile .status-dot.status-offline {
                background-color: #747f8d !important;
            }
             #user-profile .status-dot1 {
                width: 10px !important;
                height: 10px !important;
                border-radius: 50% !important;
                border: 2px solid #121212 !important;
                background-color: transparent !important;
                position: absolute !important;
                bottom: 0 !important;
                right: 0 !important;
            }
            #user-profile .status-dot1.status-online {
                background-color: #43b581 !important;
            }
            #user-profile .status-dot1.status-idle {
                background-color: #faa61a !important;
            }
            #user-profile .status-dot1.status-dnd {
                background-color: #f04747 !important;
            }
            #user-profile .status-dot1.status-offline {
                background-color: #747f8d !important;
            }
            #status-modal .status-option {
                padding: 8px !important;
                cursor: pointer !important;
                color: #dcddde !important;
            }
            #status-modal .status-option:hover {
                background-color: #36393f !important;
            }
        `;
        document.head.appendChild(style);
    })();
    const translation1 = {
        Online: '<?php echo addslashes($translations['Online'] ?? 'Online'); ?>',
        Idle: '<?php echo addslashes($translations['Idle'] ?? 'Idle'); ?>',
        DoNotDisturb: '<?php echo addslashes($translations['DoNotDisturb'] ?? 'Do Not Disturb'); ?>',
        Offline: '<?php echo addslashes($translations['Offline'] ?? 'Offline'); ?>',
        StatusUpdateError: '<?php echo addslashes($translations['StatusUpdateError'] ?? 'Status update failed'); ?>'
    };

$(document).ready(function() {
    // Kullanıcı profiline sağ tıklama
    $('#user-profile').on('contextmenu', function(e) {
        e.preventDefault();
        const modal = $('#status-modal');
        modal.css({
            top: e.pageY,
            left: e.pageX
        }).show();
    });

    // Durum seçimi
    $('.status-option').click(function() {
    const status = $(this).data('status');
    $.ajax({
        url: 'set_status.php',
        method: 'POST',
        data: { action: 'update_status', status: status },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('.status-dot1').removeClass('status-online status-idle status-dnd status-offline')
                    .addClass(`status-${response.status}`);
                $('#status-modal').hide();
                // Durum metnini güncelle
                const statusText = {
                    'online': translation1.Online,
                    'idle': translation1.Idle,
                    'dnd': translation1.DoNotDisturb,
                    'offline': translation1.Offline
                };
                $('#user-profile .text-gray-400.text-xs').text(statusText[response.status]);
                showTempNotification('Durumunuz güncellendi: ' + statusText[response.status], 'success');
                // original_status kontrolü
                if (response.original_status !== response.status) {
                    console.warn('original_status eşleşmiyor:', response);
                    showTempNotification('Hata: original_status güncellenemedi (' + response.original_status + ')', 'error');
                }
            } else {
                console.error('Status update failed:', response.message);
                showTempNotification(response.message, 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error:', error);
            showTempNotification(translation1.StatusUpdateError + ': ' + error, 'error');
        }
    });
});

    // Modal dışına tıklayınca kapat
    $(document).click(function(e) {
        if (!$(e.target).closest('#status-modal').length && !$(e.target).closest('#user-profile').length) {
            $('#status-modal').hide();
        }
    });
});
(function() {
    // Mevcut status-styles ID'sine sahip stil varsa kaldır
    const existingStyle = document.getElementById('status-styles');
    if (existingStyle) {
        existingStyle.remove();
    }

    // Yeni stil elementi oluştur
    const style = document.createElement('style');
    style.id = 'status-styles';
    style.textContent = `
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #121212;
            background-color: transparent;
            display: inline-block;
            margin-left: auto;
        }
        .status-dot.status-online {
            background-color: #43b581;
        }
        .status-dot.status-idle {
            background-color: #faa61a;
        }
        .status-dot.status-dnd {
            background-color: #f04747;
        }
        .status-dot.status-offline {
            background-color: #747f8d;
        }
        .status-dot1 {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #121212;
            background-color: transparent;
            display: inline-block;
            margin-left: auto;
        }
        .status-dot1.status-online {
            background-color: #43b581;
        }
        .status-dot1.status-idle {
            background-color: #faa61a;
        }
        .status-dot1.status-dnd {
            background-color: #f04747;
        }
        .status-dot1.status-offline {
            background-color: #747f8d;
        }
        #status-modal .status-option {
            padding: 0.5rem;
            cursor: pointer;
            color: #d1d5db;
        }
        #status-modal .status-option:hover {
            background-color: #374151;
        }
    `;
    document.head.appendChild(style);
})();
document.getElementById('popup-roles').addEventListener('click', async (e) => {
    if (e.target.classList.contains('remove-role-btn')) {
        const roleId = e.target.dataset.roleId;
        const userId = e.target.dataset.userId;
        const serverId = <?php echo json_encode($server_id); ?>;
        
        if (!roleId || !userId) {
            showToast('error', 'Rol veya kullanıcı bilgisi eksik.');
            return;
        }

        // Check permissions (only owner or users with manage_roles permission can remove roles)
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        const hasManageRolesPermission = <?php
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM user_roles ur 
                JOIN roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ? AND ur.server_id = ? AND r.permissions LIKE '%manage_roles%'
            ");
            $stmt->execute([$_SESSION['user_id'], $server_id]);
            echo $stmt->fetchColumn() > 0 ? 'true' : 'false';
        ?>;

        if (!isOwner && !hasManageRolesPermission) {
            showToast('error', 'Rolleri yönetme izniniz yok.');
            return;
        }

        try {
            const response = await fetch('remove_role.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `user_id=${userId}&role_id=${roleId}&server_id=${serverId}`
            });
            const data = await response.json();
            if (data.success) {
                showToast('success', 'Rol başarıyla kaldırıldı.');
                // Refresh the popup to update roles
                openUserProfilePopup(userId);
            } else {
                showToast('error', data.message || 'Rol kaldırılırken hata oluştu.');
            }
        } catch (error) {
            console.error('Rol kaldırma hatası:', error);
            showToast('error', 'Rol kaldırılırken bir hata oluştu.');
        }
    }
});


// ARAMA PANELİ İŞLEVSELLİĞİ - YENİ VE DÜZGÜN KOD
document.addEventListener('DOMContentLoaded', () => {

    // --- Element Referansları ---
    const openSearchBtn = document.getElementById('open-search-btn');
    const closeSearchBtn = document.getElementById('close-search-btn');
    const searchPanel = document.getElementById('search-panel');
    const searchInput = document.getElementById('search-input');
    const searchResultsContainer = document.getElementById('search-results-container');
    const messageContainer = document.getElementById('message-container');
    const messageForm = document.getElementById('message-form');
    const applyFiltersBtn = document.getElementById('apply-filters-btn');
    const clearFiltersBtn = document.getElementById('clear-filters-btn');

    // Debounce için zamanlayıcı
    let debounceTimer;


    function openSearchPanel() {
        searchPanel.classList.remove('hidden');
        messageContainer.classList.add('hidden');
        messageForm.classList.add('hidden');
        searchInput.focus();
    }


    function closeSearchPanel() {
        searchPanel.classList.add('hidden');
        messageContainer.classList.remove('hidden');
        messageForm.classList.remove('hidden');
    }


    function renderSearchResults(messages, query) {
        searchResultsContainer.innerHTML = '';

        const translations1 = <?php echo json_encode($translations['search_bar'] ?? []); ?>;

        if (!messages || messages.length === 0) {
            searchResultsContainer.innerHTML = `<div class="text-center text-gray-500 mt-8">${translations1.no_found || 'Arama sonucu bulunamadı.'}</div>`;
            return;
        }

        const fragment = document.createDocumentFragment();
        const highlightRegex = query ? new RegExp(query.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&'), 'gi') : null;

        messages.forEach(msg => {
            const resultDiv = document.createElement('div');
            resultDiv.className = 'search-result-item p-3 rounded-lg flex items-start space-x-3 transition-colors duration-200 cursor-pointer hover:bg-gray-700';
            resultDiv.dataset.messageId = msg.id;
            resultDiv.dataset.channelId = msg.channel_id;

            const avatarUrl = msg.avatar_url || 'https://via.placeholder.com/40';
            const formattedDate = new Date(msg.created_at).toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric' });
            
            const escapedText = msg.message_text.replace(/</g, "&lt;").replace(/>/g, "&gt;");
            const highlightedText = highlightRegex ? escapedText.replace(highlightRegex, (match) => `<span class="highlight">${match}</span>`) : escapedText;

            resultDiv.innerHTML = `
                <img src="${avatarUrl}" alt="${msg.username}" class="w-10 h-10 rounded-full object-cover">
                <div class="flex-1">
                    <div class="flex items-baseline space-x-2">
                        <span class="text-white font-semibold">${msg.username}</span>
                        <span class="text-gray-500 text-xs">#${msg.channel_name}</span>
                        <span class="text-gray-500 text-xs">${formattedDate}</span>
                    </div>
                    <p class="text-gray-300 text-sm mt-1">${highlightedText}</p>
                </div>
            `;
            fragment.appendChild(resultDiv);
        });

        searchResultsContainer.appendChild(fragment);
    }


    async function performSearch() {
        const serverId = <?php echo json_encode($server_id); ?>;
        const query = searchInput.value.trim();
        const fromUserId = document.getElementById('filter-from-user-id').value;
        const inChannelId = document.getElementById('filter-in-channel-id').value;
        const beforeDate = document.getElementById('filter-before-date').value;
        const afterDate = document.getElementById('filter-after-date').value;

        // Eğer hiçbir arama kriteri yoksa arama yapma
        if (!query && !fromUserId && !inChannelId && !beforeDate && !afterDate) {
            searchResultsContainer.innerHTML = `<div class="text-center text-gray-500 mt-8">${<?php echo json_encode($translations['search_bar']['type_for_search']); ?>}</div>`;
            return;
        }

        const params = new URLSearchParams({ server_id: serverId });
        if (query) params.append('query', query);
        if (fromUserId) params.append('from_user_id', fromUserId);
        if (inChannelId) params.append('in_channel_id', inChannelId);
        if (beforeDate) params.append('before_date', beforeDate);
        if (afterDate) params.append('after_date', afterDate);

        searchResultsContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><i data-lucide="loader-2" class="animate-spin inline-block"></i> Aranıyor...</div>';
        lucide.createIcons();

        try {
            const response = await fetch(`search_messages.php?${params.toString()}`);
            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
            const data = await response.json();

            if (data.success) {
                renderSearchResults(data.messages, query);
            } else {
                searchResultsContainer.innerHTML = `<div class="text-center text-red-400 mt-8">Hata: ${data.error}</div>`;
            }
        } catch (error) {
            console.error('Arama hatası:', error);
            searchResultsContainer.innerHTML = '<div class="text-center text-red-400 mt-8">Arama servisine ulaşılamadı.</div>';
        }
    }
    
    // --- Olay Dinleyicileri (Event Listeners) ---

    openSearchBtn.addEventListener('click', openSearchPanel);
    closeSearchBtn.addEventListener('click', closeSearchPanel);
    applyFiltersBtn.addEventListener('click', performSearch);

    clearFiltersBtn.addEventListener('click', () => {
        document.getElementById('search-input').value = '';
        document.getElementById('filter-from-user-id').value = '';
        document.getElementById('filter-in-channel-id').value = '';
        document.getElementById('filter-before-date').value = '';
        document.getElementById('filter-after-date').value = '';
        searchResultsContainer.innerHTML = `<div class="text-center text-gray-500 mt-8">${<?php echo json_encode($translations['search_bar']['type_for_search']); ?>}</div>`;
    });

    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(performSearch, 300);
    });

    // Arama panelindeki tüm filtreler değiştiğinde aramayı tetikle
    ['filter-from-user-id', 'filter-in-channel-id', 'filter-before-date', 'filter-after-date'].forEach(id => {
        document.getElementById(id).addEventListener('change', performSearch);
    });

    // ESC tuşu ile paneli kapat
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !searchPanel.classList.contains('hidden')) {
            closeSearchPanel();
        }
    });

    // Arama sonucuna tıklayınca ilgili mesaja gitme
    searchResultsContainer.addEventListener('click', function(e) {
        const resultItem = e.target.closest('.search-result-item');
        if (!resultItem) return;

        const messageId = resultItem.dataset.messageId;
        const channelId = resultItem.dataset.channelId;
        const serverId = <?php echo json_encode($server_id); ?>;

        // Sayfayı yeniden yükleyerek ilgili kanala git ve mesajı vurgula
        window.location.href = `server?id=${serverId}&channel_id=${channelId}&highlight=${messageId}`;
    });
});
// JavaScript for hover effect on status-text-container
document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('#user-profile .status-text-container');
    containers.forEach(container => {
        const statusText = container.querySelector('.status-text');
        const realUsername = container.querySelector('.real-username');

        // Check if elements exist to avoid errors
        if (statusText && realUsername) {
            container.addEventListener('mouseover', function() {
                statusText.style.opacity = '0';
                realUsername.style.opacity = '1';
            });
            container.addEventListener('mouseout', function() {
                statusText.style.opacity = '1';
                realUsername.style.opacity = '0';
            });
        } else {
            console.error('Status text or real username element not found in container');
        }
    });
});
document.addEventListener('DOMContentLoaded', function() {
    const userSidebar = document.querySelector('#user-sidebar .flex-1.overflow-y-auto');
    let pollingInterval = null;

    // Mevcut çevirileri kullan
    const translations1 = <?php echo json_encode($translations); ?>;

    // Durum çevirileri için yardımcı fonksiyon
    function getStatusText(status) {
        switch (status) {
            case 'online':
                return translations1['Online'] || 'Online';
            case 'idle':
                return translations1['Idle'] || 'Idle';
            case 'dnd':
                return translations1['DoNotDisturb'] || 'Do Not Disturb';
            case 'offline':
                return translations1['Offline'] || 'Offline';
            default:
                return translations1['Offline'] || 'Offline';
        }
    }

    // Durum rengi için yardımcı fonksiyon
    function getStatusClass(status) {
        switch (status) {
            case 'online':
                return 'bg-green-500';
            case 'idle':
                return 'bg-yellow-500';
            case 'dnd':
                return 'bg-red-500';
            case 'offline':
                return 'bg-gray-500';
            default:
                return 'bg-gray-500';
        }
    }

    // Kullanıcı sidebar'ını güncelleme fonksiyonu
    function updateUserSidebar() {
        const serverId = <?php echo json_encode($server_id); ?>;
        
        fetch(`get_user_status.php?server_id=${serverId}`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Sidebar içeriğini temizle
                    userSidebar.innerHTML = '';

                    // Role Groups
                    Object.keys(data.role_groups).forEach(role => {
                        const members = data.role_groups[role];
                        const roleDiv = document.createElement('div');
                        roleDiv.className = 'mb-4';
                        roleDiv.innerHTML = `
                            <div class="text-gray-400 text-sm mb-2">${role} (${members.length})</div>
                        `;

                        members.forEach(member => {
                            const memberDiv = document.createElement('div');
                            memberDiv.className = `flex items-center mb-2 ${member.status === 'offline' ? 'offline-user' : ''}`;
                            const avatarUrl = member.avatar_url || '';
                            const displayName = member.display_username || member.username;
                            const statusText = getStatusText(member.status);
                            const statusClass = getStatusClass(member.status);
                            const highestColor = member.highest_color || '#ffffff';

                            memberDiv.innerHTML = `
                                <div class="relative">
                                    <div class="avatar-container">
                                        ${avatarUrl ? 
                                            `<img src="${avatarUrl}" 
                                                  alt="Profile Photo" 
                                                  class="avatar-image">` : 
                                            `<div class="w-full h-full bg-indigo-500 flex items-center justify-center">
                                                <span class="text-white font-medium">${displayName.charAt(0).toUpperCase()}</span>
                                            </div>`
                                        }
                                    </div>
                                    <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-900 ${statusClass}"></div>
                                </div>
                                <div class="ml-2">
                                    <span class="text-white text-sm font-medium cursor-pointer" 
                                          style="color: ${highestColor};"
                                          onclick="openUserProfilePopup('${member.user_id}', '${displayName}', '${avatarUrl}', '${member.status}')">
                                        ${displayName}
                                    </span>
                                    <div class="text-gray-400 text-xs">${statusText}</div>
                                </div>
                            `;
                            roleDiv.appendChild(memberDiv);
                        });
                        userSidebar.appendChild(roleDiv);
                    });

                    // Online Members
                    if (data.online_members.length > 0) {
                        const onlineDiv = document.createElement('div');
                        onlineDiv.className = 'mb-4';
                        onlineDiv.innerHTML = `
                            <div class="text-gray-400 text-sm mb-2">${translations1['Online'] || 'Online'} (${data.online_members.length})</div>
                        `;
                        data.online_members.forEach(member => {
                            const memberDiv = document.createElement('div');
                            memberDiv.className = 'flex items-center mb-2';
                            const avatarUrl = member.avatar_url || '';
                            const displayName = member.display_username || member.username;
                            const statusText = getStatusText(member.status);
                            const statusClass = getStatusClass(member.status);
                            const highestColor = member.highest_color || '#ffffff';

                            memberDiv.innerHTML = `
                                <div class="relative">
                                    <div class="avatar-container">
                                        ${avatarUrl ? 
                                            `<img src="${avatarUrl}" 
                                                  alt="Profile Photo" 
                                                  class="avatar-image">` : 
                                            `<div class="w-full h-full bg-indigo-500 flex items-center justify-center">
                                                <span class="text-white font-medium">${displayName.charAt(0).toUpperCase()}</span>
                                            </div>`
                                        }
                                    </div>
                                    <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-900 ${statusClass}"></div>
                                </div>
                                <div class="ml-2">
                                    <span class="text-white text-sm font-medium cursor-pointer" 
                                          style="color: ${highestColor};"
                                          onclick="openUserProfilePopup('${member.user_id}', '${displayName}', '${avatarUrl}', '${member.status}')">
                                        ${displayName}
                                    </span>
                                    <div class="text-gray-400 text-xs">${statusText}</div>
                                </div>
                            `;
                            onlineDiv.appendChild(memberDiv);
                        });
                        userSidebar.appendChild(onlineDiv);
                    }

                    // Offline Members
                    if (data.offline_members.length > 0) {
                        const offlineDiv = document.createElement('div');
                        offlineDiv.className = 'mb-4';
                        offlineDiv.innerHTML = `
                            <div class="text-gray-400 text-sm mb-2">${translations1['Offline'] || 'Offline'} (${data.offline_members.length})</div>
                        `;
                        data.offline_members.forEach(member => {
                            const memberDiv = document.createElement('div');
                            memberDiv.className = 'flex items-center mb-2 offline-user';
                            const avatarUrl = member.avatar_url || '';
                            const displayName = member.display_username || member.username;
                            const statusText = getStatusText(member.status);
                            const statusClass = getStatusClass(member.status);

                            memberDiv.innerHTML = `
                                <div class="relative">
                                    <div class="avatar-container">
                                        ${avatarUrl ? 
                                            `<img src="${avatarUrl}" 
                                                  alt="Profile Photo" 
                                                  class="avatar-image">` : 
                                            `<div class="w-full h-full bg-indigo-500 flex items-center justify-center">
                                                <span class="text-white font-medium">${displayName.charAt(0).toUpperCase()}</span>
                                            </div>`
                                        }
                                    </div>
                                    <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-900 ${statusClass}"></div>
                                </div>
                                <div class="ml-2">
                                    <span class="text-sm font-medium cursor-pointer text-gray-500"
                                          onclick="openUserProfilePopup('${member.user_id}', '${displayName}', '${avatarUrl}', '${member.status}')">
                                        ${displayName}
                                    </span>
                                    <div class="text-gray-500 text-xs">${statusText}</div>
                                </div>
                            `;
                            offlineDiv.appendChild(memberDiv);
                        });
                        userSidebar.appendChild(offlineDiv);
                    }
                } else {
                    console.error('Kullanıcı durumu güncelleme hatası:', data.error);
                    showTempNotification(translations1['status_update_error'] || 'Kullanıcı durumu güncellenemedi.', 'error');
                }
            })
            .catch(error => {
                console.error('Kullanıcı durumu çekme hatası:', error);
                showTempNotification(translations1['status_update_error'] || 'Kullanıcı durumu güncellenemedi.', 'error');
            });
    }

    // Polling'i başlat
    function startStatusPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
        updateUserSidebar(); // İlk güncellemeyi hemen yap
        pollingInterval = setInterval(updateUserSidebar, 5000); // Her 5 saniyede bir güncelle
    }

    // Polling'i durdur
    function stopStatusPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
    }

    // Sayfaya yüklendiğinde polling'i başlat
    startStatusPolling();

    // Sayfadan çıkıldığında polling'i durdur
    window.addEventListener('beforeunload', stopStatusPolling);
});


// Function to execute scripts in dynamically loaded content
async function executeScripts(container) {
    const scripts = Array.from(container.querySelectorAll('script'));
    for (const script of scripts) {
        // Remove the script element from DOM to prevent double execution
        script.remove();
        
        if (script.src) {
            // For external scripts, check if already loaded
            const existingScript = document.querySelector(`script[src="${script.src}"]`);
            if (!existingScript) {
                const newScript = document.createElement('script');
                newScript.src = script.src;
                // Add async false to maintain execution order for dependent scripts
                newScript.async = false;
                document.head.appendChild(newScript);
                await new Promise((resolve, reject) => {
                    newScript.onload = resolve;
                    newScript.onerror = () => {
                        console.error(`Error loading script: ${script.src}`);
                        reject();
                    };
                });
            }
        } else {
            // For inline scripts, wrap in IIFE to avoid variable conflicts
            try {
                // Use Function constructor to execute in a clean context
                const executeInlineScript = new Function(script.textContent);
                executeInlineScript();
            } catch (error) {
                console.error('Error executing inline script:', error);
                // Fallback: use eval with try-catch (less secure)
                try {
                    eval(script.textContent);
                } catch (e) {
                    console.error('Fallback execution also failed:', e);
                }
            }
        }
    }
}

// Store resize listeners to clean them up later
let resizeListeners = [];

document.addEventListener('DOMContentLoaded', () => {
    if (window.spaInitialized) return;
    window.spaInitialized = true;
    const mainContent = document.getElementById('main-content');
    const dmLink = document.querySelector('.dm-link');
    const serverLinks = document.querySelectorAll('.server-link');
    let activeLink = null;

  

    // Function to update active link styling
    const setActiveLink = (link) => {
        if (activeLink) {
            activeLink.parentElement.classList.remove('bg-indigo-500', 'rounded-2xl');
            activeLink.parentElement.classList.add('bg-gray-700', 'rounded-3xl');
        }
        link.parentElement.classList.remove('bg-gray-700', 'rounded-3xl');
        link.parentElement.classList.add('bg-indigo-500', 'rounded-2xl');
        activeLink = link;
    };

    // Function to clean up resize listeners
    const cleanupResizeListeners = () => {
        resizeListeners.forEach(({ element, handler }) => {
            element.removeEventListener('resize', handler);
        });
        resizeListeners = []; // Reset the listeners array
    };

    // Function to initialize resize-dependent logic in loaded content
    const initResizeDependentLogic = (container) => {
        // Example: Find elements that depend on window.innerWidth
        const responsiveElements = container.querySelectorAll('[data-responsive]');
        responsiveElements.forEach(element => {
            const handler = () => {
                const width = window.innerWidth;
                // Example logic: Adjust based on window.innerWidth
                if (width < 768) {
                    element.classList.add('mobile');
                    element.classList.remove('desktop');
                } else {
                    element.classList.add('desktop');
                    element.classList.remove('mobile');
                }
            };
            // Run initially
            handler();
            // Add resize listener
            window.addEventListener('resize', handler);
            // Store for cleanup
            resizeListeners.push({ element: window, handler });
        });
    };

    // Function to load content
const loadContent = async (url, isDm = false) => {
    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.text();

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = data;

        const newTitle = tempDiv.querySelector('title')?.textContent || document.title;

        document.dispatchEvent(new CustomEvent('beforeContentUnload'));
        cleanupResizeListeners();

        // Find the content container, fall back to body if not found
        let contentContainer = document.getElementById('content-container');
        if (!contentContainer) {
            console.warn('Uyarı: #content-container bulunamadı, body kullanılıyor.');
            contentContainer = document.body;
        }

        // Inject content into the container
        contentContainer.innerHTML = tempDiv.innerHTML;

        // Debug: Verify critical elements
        const requiredElements = ['#server-sidebar', '#channel-sidebar-x', '#user-sidebar', '#content-container'];
        requiredElements.forEach(selector => {
            if (!document.querySelector(selector)) {
                console.warn(`Uyarı: ${selector} bulunamadı.`);
            }
        });

        document.title = newTitle;

        await executeScripts(contentContainer);
        
        initResizeDependentLogic(contentContainer);

        // Call updateSagOrtaDiv after a short delay
        setTimeout(() => {
            if (typeof window.updateSagOrtaDiv === 'function') {
                window.updateSagOrtaDiv();
            }
        }, 0);

        document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true, cancelable: true }));

        const state = { page: isDm ? 'dm' : 'server' };
        const title = isDm ? 'Direct Messages' : `Server ${url.split('id=')[1] || ''}`;
        history.pushState(state, title, url);
    } catch (error) {
        console.error('Error loading content:', error);
        const contentContainer = document.getElementById('content-container') || document.body;
        contentContainer.innerHTML = '<p>Error loading content. Please try again.</p>';
    }
};

    // Handle DM link click
    dmLink.addEventListener('click', (e) => {
        e.preventDefault();
        loadContent('directmessages.php', true);
        setActiveLink(dmLink);
    });

    // Handle server link clicks
    serverLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const url = link.getAttribute('href');
            loadContent(url);
            setActiveLink(link);
        });
    });

    // Handle browser back/forward navigation
    window.addEventListener('popstate', (e) => {
        const url = window.location.pathname + window.location.search;
        loadContent(url, url.includes('directmessages'));
        const isDm = url.includes('directmessages');
        const link = isDm ? dmLink : [...serverLinks].find(l => l.getAttribute('href') === url);
        if (link) setActiveLink(link);
    });

    // Load initial content based on current URL
    const currentUrl = window.location.pathname + window.location.search;
    if (currentUrl.includes('directmessages')) {
        loadContent('directmessages.php', true);
        setActiveLink(dmLink);
    } else if (currentUrl.includes('server?id=')) {
        loadContent(currentUrl);
        const activeServerLink = [...serverLinks].find(link => link.getAttribute('href') === currentUrl);
        if (activeServerLink) setActiveLink(activeServerLink);
    }
});
    </script>
</body>
</html>