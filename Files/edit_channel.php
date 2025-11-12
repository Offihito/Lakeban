<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

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
    error_log("Database connection successful");
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Unable to connect to the database. Please try again later.");
}

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
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    die($translations['ChannelIDMissing'] ?? "Channel ID is missing.");
}

$channel_id = $_GET['id'];

try {
    $stmt = $db->prepare("SELECT * FROM channels WHERE id = ?");
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch();
    error_log("Channel fetch result: " . var_export($channel, true));
} catch (PDOException $e) {
    error_log("Channel fetch error: " . $e->getMessage());
    die($translations['ErrorFetchingChannel'] ?? "Error fetching channel data.");
}

if (!$channel) {
    die($translations['ChannelNotFound'] ?? "Channel not found.");
}

$permissions = json_decode($channel['permissions'] ?? '{}', true);
if (!is_array($permissions)) {
    error_log("Invalid permissions JSON for channel ID $channel_id: " . var_export($channel['permissions'], true));
    $permissions = [];
}
$write_allowed_roles = (isset($permissions['write_allowed_roles']) && is_array($permissions['write_allowed_roles'])) 
    ? $permissions['write_allowed_roles'] 
    : [];
$restricted_to_role_ids = json_decode($channel['restricted_to_role_id'] ?? '[]', true);
if (!is_array($restricted_to_role_ids)) {
    error_log("Invalid restricted_to_role_id JSON for channel ID $channel_id: " . var_export($channel['restricted_to_role_id'], true));
    $restricted_to_role_ids = [];
}

try {
    $stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND (owner_id = ? OR id IN (SELECT server_id FROM user_roles WHERE user_id = ? AND role_id IN (SELECT id FROM roles WHERE permissions LIKE '%manage_channels%')))");
    $stmt->execute([$channel['server_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $server = $stmt->fetch();
    error_log("Server permission check result: " . var_export($server, true));
} catch (PDOException $e) {
    error_log("Server permission check error: " . $e->getMessage());
    die($translations['ErrorCheckingPermissions'] ?? "Error checking server permissions.");
}

if (!$server) {
    die($translations['NoPermission'] ?? "You do not have permission to edit this channel.");
}

try {
    $stmt = $db->prepare("SELECT * FROM categories WHERE server_id = ? ORDER BY name ASC");
    $stmt->execute([$channel['server_id']]);
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
    die($translations['ErrorFetchingCategories'] ?? "Error fetching categories.");
}

try {
    $stmt = $db->prepare("SELECT * FROM roles WHERE id IN (SELECT role_id FROM user_roles WHERE server_id = ?)");
    $stmt->execute([$channel['server_id']]);
    $roles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Roles fetch error: " . $e->getMessage());
    die($translations['ErrorFetchingRoles'] ?? "Error fetching roles.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_channel'])) {
    $new_name = $_POST['channel_name'];
    $new_category_id = $_POST['category_id'] ?? null;
    $new_type = $_POST['channel_type'];
    $write_allowed_role_ids = $_POST['write_allowed_role_id'] ?? [];
    $restricted_to_role_ids = $_POST['restricted_to_role_id'] ?? [];

    if ($new_category_id === "") {
        $new_category_id = null;
    }

    $permissions = [
        'write_allowed_roles' => !empty($write_allowed_role_ids) && !in_array("", $write_allowed_role_ids) ? $write_allowed_role_ids : [],
        'write_denied_roles' => [],
        'write_allowed_users' => [],
        'write_denied_users' => []
    ];
    $permissions_json = json_encode($permissions);

    $restricted_to_role_id = null;
    if (!empty($restricted_to_role_ids) && !in_array("", $restricted_to_role_ids)) {
        $restricted_to_role_id = json_encode($restricted_to_role_ids);
    }

    try {
        $stmt = $db->prepare("UPDATE channels SET name = ?, category_id = ?, type = ?, permissions = ?, restricted_to_role_id = ? WHERE id = ?");
        $stmt->execute([$new_name, $new_category_id, $new_type, $permissions_json, $restricted_to_role_id, $channel_id]);
        header("Location: edit_channel.php?id=" . $channel_id);
        exit;
    } catch (PDOException $e) {
        error_log("Channel update error: " . $e->getMessage());
        $error_message = $translations['ErrorUpdatingChannel'] ?? "An error occurred while updating the channel.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_channel'])) {
    try {
        $stmt = $db->prepare("DELETE FROM channels WHERE id = ?");
        $stmt->execute([$channel_id]);
        header("Location: server.php?id=" . $channel['server_id']);
        exit;
    } catch (PDOException $e) {
        error_log("Channel deletion error: " . $e->getMessage());
        $error_message = $translations['ErrorDeletingChannel'] ?? "An error occurred while deleting the channel.";
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['EditChannel'] ?? 'Edit Channel'; ?></title>
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
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }

        .form-container {
            background-color: var(--secondary-bg);
            border-radius: 0.75rem;
            padding: 1.25rem;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .form-input, .form-select {
            background-color: #1f2024;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.375rem;
            padding: 0.5rem;
            color: var(--text-primary);
            width: 100%;
            transition: border-color 0.2s ease;
        }

        .form-input:focus, .form-select:focus {
            border-color: var(--accent-color);
            outline: none;
        }

        .form-select[multiple] {
            height: 80px;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2E8B57;
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-secondary);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c03537;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #1f2024;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #2E8B57;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2 class="text-lg font-semibold mb-3"><?php echo $translations['EditChannel'] ?? 'Edit Channel'; ?></h2>
        <?php if (isset($error_message)): ?>
            <div class="bg-red-500 text-white p-2 rounded mb-3 text-sm"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="POST" action="edit_channel.php?id=<?php echo $channel_id; ?>">
            <div class="mb-3">
                <label for="channel_name" class="block text-sm mb-1"><?php echo $translations['ChannelName'] ?? 'Channel Name'; ?></label>
                <input type="text" name="channel_name" id="channel_name" value="<?php echo htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8'); ?>" class="form-input" required>
            </div>
            <div class="mb-3">
                <label for="category_id" class="block text-sm mb-1"><?php echo $translations['Category'] ?? 'Category'; ?></label>
                <select name="category_id" id="category_id" class="form-select">
                    <option value=""><?php echo $translations['NoCategory'] ?? 'No Category'; ?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" <?php echo $channel['category_id'] == $category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="channel_type" class="block text-sm mb-1"><?php echo $translations['ChannelType'] ?? 'Channel Type'; ?></label>
                <select name="channel_type" id="channel_type" class="form-select" required>
                    <option value="text" <?php echo ($channel['type'] ?? 'text') === 'text' ? 'selected' : ''; ?>><?php echo $translations['TextChannel'] ?? 'Text Channel'; ?></option>
                    <option value="voice" <?php echo ($channel['type'] ?? 'text') === 'voice' ? 'selected' : ''; ?>><?php echo $translations['VoiceChannel'] ?? 'Voice Channel'; ?></option>
                    <option value="announcement" <?php echo ($channel['type'] ?? 'text') === 'announcement' ? 'selected' : ''; ?>><?php echo $translations['AnnouncementChannel'] ?? 'Announcement Channel'; ?></option>
                </select>
            </div>
            <div class="mb-3">
                <label for="restricted_to_role_id" class="block text-sm mb-1"><?php echo $translations['RestrictToRoles'] ?? 'Restrict to Roles'; ?></label>
                <select name="restricted_to_role_id[]" id="restricted_to_role_id" class="form-select" multiple>
                    <option value="" <?php echo empty($restricted_to_role_ids) ? 'selected' : ''; ?>><?php echo $translations['Everyone'] ?? 'Everyone'; ?></option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>" <?php echo is_array($restricted_to_role_ids) && in_array($role['id'], $restricted_to_role_ids) ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1"><?php echo $translations['SelectMultipleRoles'] ?? 'Select multiple roles with Ctrl/Cmd'; ?></p>
            </div>
            <div class="mb-3">
                <label for="write_allowed_role_id" class="block text-sm mb-1"><?php echo $translations['WriteAllowedRoles'] ?? 'Write Allowed Roles'; ?></label>
                <select name="write_allowed_role_id[]" id="write_allowed_role_id" class="form-select" multiple>
                    <option value="" <?php echo empty($write_allowed_roles) ? 'selected' : ''; ?>><?php echo $translations['Everyone'] ?? 'Everyone'; ?></option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>" <?php echo is_array($write_allowed_roles) && in_array($role['id'], $write_allowed_roles) ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1"><?php echo $translations['WritePermissionHint'] ?? 'Select roles that can write'; ?></p>
            </div>
            <div class="flex justify-between">
                <a href="server.php?id=<?php echo $channel['server_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> <?php echo $translations['BackToServer'] ?? 'Back'; ?>
                </a>
                <button type="submit" name="update_channel" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $translations['UpdateChannel'] ?? 'Update'; ?>
                </button>
            </div>
        </form>
        <form method="POST" action="edit_channel.php?id=<?php echo $channel_id; ?>" onsubmit="return confirm('<?php echo $translations['ConfirmDeleteChannel'] ?? 'Are you sure you want to delete this channel?'; ?>');" class="mt-3">
            <button type="submit" name="delete_channel" class="btn btn-danger w-full">
                <i class="fas fa-trash-alt"></i> <?php echo $translations['DeleteChannel'] ?? 'Delete Channel'; ?>
            </button>
        </form>
    </div>
</body>
</html>