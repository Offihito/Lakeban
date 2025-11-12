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

// Varsayılan dil
$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
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
    header("Location: login");
    exit;
}

if (!isset($_GET['server_id'])) {
    die("Server ID is missing.");
}

$server_id = $_GET['server_id'];

$stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {
    die("You are not a member of this server.");
}

$stmt = $db->prepare("SELECT * FROM roles WHERE id IN (SELECT role_id FROM user_roles WHERE server_id = ?)");
$stmt->execute([$server_id]);
$roles = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM categories WHERE server_id = ? ORDER BY name ASC");
$stmt->execute([$server_id]);
$categories = $stmt->fetchAll();

$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_owner_id = $stmt->fetchColumn();

$is_owner = ($server_owner_id == $_SESSION['user_id']);

if (!$is_owner) {
    $stmt = $db->prepare("SELECT role_id FROM user_roles WHERE server_id = ? AND user_id = ?");
    $stmt->execute([$server_id, $_SESSION['user_id']]);
    $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $has_manage_channels_permission = false;
    foreach ($user_roles as $role_id) {
        $stmt = $db->prepare("SELECT permissions FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        $permissions = json_decode($stmt->fetchColumn(), true);

        if (is_array($permissions) && in_array('manage_channels', $permissions)) {
            $has_manage_channels_permission = true;
            break;
        }
    }

    if (!$has_manage_channels_permission) {
        die("You do not have permission to create channels.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['channel_name'], $_POST['channel_type'])) {
    $channel_name = trim($_POST['channel_name']);
    $channel_type = $_POST['channel_type'];
    $restricted_to_role_ids = $_POST['restricted_to_role_id'] ?? [];
    $category_id = $_POST['category_id'] ?? null;
    $write_allowed_role_ids = $_POST['write_allowed_role_id'] ?? [];

    if ($category_id === "") {
        $category_id = null;
    }

    $restricted_to_role_id = null;
    if (!empty($restricted_to_role_ids)) {
        $restricted_to_role_id = json_encode($restricted_to_role_ids);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = "Invalid role selection format.";
            header("Location: create_channel?server_id=" . $server_id . "&error=" . urlencode($error_message));
            exit;
        }
    }

    $permissions = [
        'write_allowed_roles' => $write_allowed_role_ids,
        'write_denied_roles' => [],
        'write_allowed_users' => [],
        'write_denied_users' => []
    ];
    $permissions_json = json_encode($permissions);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error_message = "Invalid permissions format.";
        header("Location: create_channel?server_id=" . $server_id . "&error=" . urlencode($error_message));
        exit;
    }

    $valid_channel_types = ['text', 'voice', 'announcement'];
    if (empty($channel_name)) {
        $error_message = "Channel name cannot be empty.";
    } elseif (!in_array($channel_type, $valid_channel_types)) {
        $error_message = "Invalid channel type selected.";
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO channels (server_id, name, restricted_to_role_id, category_id, type, permissions) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$server_id, $channel_name, $restricted_to_role_id, $category_id, $channel_type, $permissions_json]);
            header("Location: server?id=" . $server_id);
            exit;
        } catch (PDOException $e) {
            error_log("Channel creation error: " . $e->getMessage());
            $error_message = "An error occurred while creating the channel.";
        }
    }

    if (isset($error_message)) {
        header("Location: create_channel?server_id=" . $server_id . "&error=" . urlencode($error_message));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['CreateChannel'] ?? 'Create Channel'; ?></title>
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
        <h2 class="text-lg font-semibold mb-3"><?php echo $translations['NewChannel'] ?? 'New Channel'; ?></h2>
        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-500 text-white p-2 rounded mb-3 text-sm"><?php echo htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="POST" action="create_channel?server_id=<?php echo $server_id; ?>">
            <div class="mb-3">
                <label for="channel_name" class="block text-sm mb-1"><?php echo $translations['ChannelName'] ?? 'Channel Name'; ?></label>
                <input type="text" name="channel_name" id="channel_name" class="form-input" required>
            </div>
            <div class="mb-3">
                <label for="channel_type" class="block text-sm mb-1"><?php echo $translations['ChannelType'] ?? 'Channel Type'; ?></label>
                <select name="channel_type" id="channel_type" class="form-select" required>
                    <option value="text"><?php echo $translations['TextChannel'] ?? 'Text Channel'; ?></option>
                    <option value="voice"><?php echo $translations['VoiceChannel'] ?? 'Voice Channel'; ?></option>
                    <option value="announcement"><?php echo $translations['AnnouncementChannel'] ?? 'Announcement Channel'; ?></option>
                </select>
            </div>
            <div class="mb-3">
                <label for="category_id" class="block text-sm mb-1"><?php echo $translations['Category'] ?? 'Category'; ?></label>
                <select name="category_id" id="category_id" class="form-select">
                    <option value=""><?php echo $translations['NoCategory'] ?? 'No Category'; ?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="restricted_to_role_id" class="block text-sm mb-1"><?php echo $translations['RestrictToRoles'] ?? 'Restrict to Roles'; ?></label>
                <select name="restricted_to_role_id[]" id="restricted_to_role_id" class="form-select" multiple>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1"><?php echo $translations['SelectMultipleRoles'] ?? 'Select multiple roles with Ctrl/Cmd'; ?></p>
            </div>
            <div class="mb-3">
                <label for="write_allowed_role_id" class="block text-sm mb-1"><?php echo $translations['WriteAllowedRoles'] ?? 'Write Allowed Roles'; ?></label>
                <select name="write_allowed_role_id[]" id="write_allowed_role_id" class="form-select" multiple>
                    <option value=""><?php echo $translations['Everyone'] ?? 'Everyone'; ?></option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1"><?php echo $translations['WritePermissionHint'] ?? 'Select roles that can write'; ?></p>
            </div>
            <div class="flex justify-between">
                <a href="server?id=<?php echo $server_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> <?php echo $translations['BackToServer'] ?? 'Back'; ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $translations['CreateChannel'] ?? 'Create'; ?>
                </button>
            </div>
        </form>
    </div>
</body>
</html>