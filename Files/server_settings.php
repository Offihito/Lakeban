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

// Kullanıcının tema ayarlarını al
$currentTheme = 'dark';
$currentCustomColor = '#663399';
$currentSecondaryColor = '#3CB371';

try {
    $theme_stmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $theme_stmt->execute([$_SESSION['user_id']]);
    $userTheme = $theme_stmt->fetch(PDO::FETCH_ASSOC);
    
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
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid server ID.");
}

$server_id = (int)$_GET['id'];

// Check if the user is the owner or has manage_roles permission
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

if ($stmt->rowCount() === 0) {
    header("Location: sayfabulunamadı");
    exit();
}

// Check if the user is the owner
$is_owner = false;
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND owner_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);
if ($stmt->rowCount() > 0) {
    $is_owner = true;
}

// Fetch the server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Handle form submission (only for owners)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_owner) {
    if (isset($_POST['delete_server'])) {
        // Delete server
        $stmt = $db->prepare("DELETE FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        header("Location: directmessages");
        exit;
    } else {
        $new_server_name = trim($_POST['server_name']);
        $new_server_description = trim($_POST['server_description']);
        $new_server_avatar = $_FILES['server_pp'] ?? ['name' => '', 'error' => UPLOAD_ERR_NO_FILE];
        $new_server_banner = $_FILES['server_banner'] ?? ['name' => '', 'error' => UPLOAD_ERR_NO_FILE];
        $new_invite_background = $_FILES['invite_background'] ?? ['name' => '', 'error' => UPLOAD_ERR_NO_FILE];

        if (empty($new_server_name)) {
            $error = "Server name cannot be empty";
        } elseif (strlen($new_server_name) > 100) {
            $error = "Server name must be less than 100 characters";
        } else {
            $stmt = $db->prepare("UPDATE servers SET name = ? WHERE id = ?");
            $stmt->execute([$new_server_name, $server_id]);
        }

        if (isset($new_server_description)) {
            $stmt = $db->prepare("UPDATE servers SET description = ? WHERE id = ?");
            $stmt->execute([$new_server_description, $server_id]);
        }

        function handleFileUpload($file, $fieldName, $maxSize = 500000, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif']) {
            global $db, $server_id, $server, $error;
            if ($file['error'] === UPLOAD_ERR_NO_FILE) return true;

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Dosya yükleme hatası: " . $file['error'];
                return false;
            }

            $check = getimagesize($file['tmp_name']);
            if ($check === false) {
                $error = "Dosya bir resim değil.";
                return false;
            }

            if ($file['size'] > $maxSize) {
                $error = "Dosya çok büyük. Maksimum boyut " . ($maxSize/1000) . "KB.";
                return false;
            }

            $imageFileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($imageFileType, $allowedTypes)) {
                $error = "Üzgünüz, sadece " . implode(", ", $allowedTypes) . " dosyalarına izin verilir.";
                return false;
            }

            $target_dir = "uploads/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
            $target_file = $target_dir . uniqid() . '_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                $stmt = $db->prepare("UPDATE servers SET $fieldName = ? WHERE id = ?");
                $stmt->execute([$target_file, $server_id]);
                if (!empty($server[$fieldName]) && file_exists($server[$fieldName])) unlink($server[$fieldName]);
                return true;
            } else {
                $error = "Üzgünüz, dosyanızı yüklerken bir hata oluştu.";
                return false;
            }
        }

        if (isset($_POST['delete_pp'])) {
            if (!empty($server['profile_picture']) && file_exists($server['profile_picture'])) unlink($server['profile_picture']);
            $stmt = $db->prepare("UPDATE servers SET profile_picture = NULL WHERE id = ?");
            $stmt->execute([$server_id]);
            $server['profile_picture'] = NULL;
        }
        if (isset($_POST['delete_banner'])) {
            if (!empty($server['banner']) && file_exists($server['banner'])) unlink($server['banner']);
            $stmt = $db->prepare("UPDATE servers SET banner = NULL WHERE id = ?");
            $stmt->execute([$server_id]);
            $server['banner'] = NULL;
        }
        if (isset($_POST['delete_invite_background'])) {
            if (!empty($server['invite_background']) && file_exists($server['invite_background'])) unlink($server['invite_background']);
            $stmt = $db->prepare("UPDATE servers SET invite_background = NULL WHERE id = ?");
            $stmt->execute([$server_id]);
            $server['invite_background'] = NULL;
        }

        handleFileUpload($new_server_avatar, 'profile_picture');
        handleFileUpload($new_server_banner, 'banner', 1000000);
        handleFileUpload($new_invite_background, 'invite_background', 2000000);

        $show_in_community = isset($_POST['show_in_community']) && $_POST['show_in_community'] === 'on' ? 1 : 0;
        $stmt = $db->prepare("UPDATE servers SET show_in_community = ? WHERE id = ?");
        $stmt->execute([$show_in_community, $server_id]);

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        $server = $stmt->fetch();

        if (!isset($error)) {
            header("Location: server_settings.php?id=" . $server_id . "&success=1");
            exit;
        }
    }
}

// Fetch roles for the server
$stmt = $db->prepare("SELECT * FROM roles WHERE id IN (SELECT role_id FROM user_roles WHERE server_id = ?)");
$stmt->execute([$server_id]);
$roles = $stmt->fetchAll();

// Varsayılan dil
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
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);
?>

<!DOCTYPE html>
<html lang="tr" class="<?php echo $currentTheme; ?>-theme" style="--custom-background-color: <?php echo $currentCustomColor; ?>; --custom-secondary-color: <?php echo $currentSecondaryColor; ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['server_settings']['title_one']; ?> - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
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

        /* Light Theme */
        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
            --scrollbar-thumb: #c1c3c7;
            --scrollbar-track: #F2F3F5;
        }

        /* Custom Theme */
        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 40%, white);
            --scrollbar-thumb: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            --scrollbar-track: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            --accent-color: var(--custom-secondary-color);
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
            left: 0;
        }
        #main-content {
            position: absolute;
            height: 100vh;
            width: 80%;
            margin-left: 20%;
            left: 0;
            background-color: var(--primary-bg);
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

        .form-section.compact {
            margin-bottom: 0;
        }
        .form-section.compact + .form-section {
            margin-top: 16px;
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

        .form-input:disabled, .form-textarea:disabled {
            background-color: rgba(0, 0, 0, 0.3);
            cursor: not-allowed;
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

        .btn:disabled {
            background-color: rgba(79, 84, 92, 0.3);
            cursor: not-allowed;
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

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch input:disabled + .slider {
            background-color: rgba(79, 84, 92, 0.3);
            cursor: not-allowed;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(79, 84, 92, 0.6);
            transition: .15s;
            border-radius: 12px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .15s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent-color);
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

        .container__68f37 {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(79, 84, 92, 0.3);
        }

        .container__68f37:last-of-type {
            border-bottom: none;
        }

        .column__68f37 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .column__68f37.left-column {
            border-right: 1px solid rgba(79, 84, 92, 0.3);
            padding-right: 20px;
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

        .anchor_edefb8 {
            color: var(--accent-color);
            text-decoration: none;
        }

        .anchor_edefb8:hover {
            text-decoration: underline;
        }

        .profile-picture-upload-area {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: var(--secondary-bg);
            border: 1px dashed rgba(79, 84, 92, 0.6);
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .profile-picture-upload-area:hover {
            border-color: var(--accent-color);
        }

        .profile-picture-upload-area .imageUploaderInner_e4d0bf {
            border-radius: 50%;
        }

        .upsell__0969c {
            position: relative;
            width: 100%;
            height: 180px;
            background-color: var(--secondary-bg);
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px dashed rgba(79, 84, 92, 0.6);
            transition: all 0.2s ease;
        }

        .upsell__0969c:hover {
            border-color: var(--accent-color);
        }

        .imageUploader_e4d0bf {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .imageUploaderInner_e4d0bf {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            border-radius: 8px;
        }

        .upload-area-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .profile-picture-upload-area .imageUploaderInner_e4d0bf:hover .upload-area-overlay,
        .upsell__0969c .imageUploaderInner_e4d0bf:hover .upload-area-overlay {
            opacity: 1;
        }

        .imageUploaderAcronym_e4d0bf {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
            color: white;
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .imageUploaderIcon_e4d0bf {
            z-index: 2;
            background-color: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .profile-picture-upload-area .imageUploaderAcronym_e4d0bf {
            font-size: 1.25rem;
        }

        .profile-picture-upload-area .imageUploaderIcon_e4d0bf {
            width: 32px;
            height: 32px;
            padding: 6px;
        }

        .imageUploaderInner_e4d0bf.has-image .imageUploaderAcronym_e4d0bf,
        .imageUploaderInner_e4d0bf.has-image .imageUploaderIcon_e4d0bf {
            display: none;
        }

        .image-preview-full-size {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
            z-index: 0;
        }

        .profile-picture-upload-area .image-preview-full-size {
            border-radius: 50%;
        }

        .buttons-group {
            display: flex;
            gap: 10px;
        }

        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.7);
        }

        .modal-content {
            background-color: var(--secondary-bg);
            border-radius: 5px;
            box-shadow: 0 0 0 1px rgba(32, 34, 37, 0.6), 0 2px 10px 0 rgba(0, 0, 0, 0.2);
        }

        .danger-zone {
            border-left: 4px solid var(--danger-color);
            background-color: rgba(237, 66, 69, 0.1);
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            #movesidebar {
                width: 100%;
                left: -100%;
                transition: left 0.3s ease-in-out;
                height: 100vh;
                z-index: 10;
            }
            #main-content {
                position: relative;
                height: auto;
                width: 100%;
                margin-left: 0;
                padding: 1rem;
                transition: margin-left 0.3s ease-in-out;
            }
            .container__68f37 {
                flex-direction: column;
                gap: 0;
                padding-bottom: 10px;
            }
            .column__68f37.left-column {
                border-right: none;
                border-bottom: 1px solid rgba(79, 84, 92, 0.3);
                padding-right: 0;
                padding-bottom: 20px;
                margin-bottom: 20px;
            }
            .form-section.flex {
                flex-direction: column;
                align-items: center;
            }
            .form-section.flex > div {
                width: 100%;
                margin-bottom: 1rem;
            }
            .form-section.flex > div:last-child {
                margin-bottom: 0;
            }
            .form-section.flex .profile-picture-upload-area {
                margin: 0 auto 1rem auto;
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
                <a href="server_settings?id=<?php echo $server_id; ?>" class="nav-item active">
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
            </div>
        </nav>

        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item">
                <i class="fas fa-arrow-left w-5 text-center"></i>
                <span><?php echo $translations['server_settings']['back_server']; ?></span>
            </a>
        </div>
    </div>
    
    <div id="main-content" class="p-6 overflow-y-auto">
        <div class="mb-6">
            <h2 class="text-2xl font-semibold"><?php echo $translations['server_settings']['general']; ?></h2>
            <p class="text-sm text-gray-400"><?php echo $translations['server_settings']['general_desc']; ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-500 text-white p-3 rounded-lg mb-4 shadow-md"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif (isset($_GET['success'])): ?>
            <div class="bg-green-500 text-white p-3 rounded-lg mb-4 shadow-md"><?php echo $translations['server_settings']['changes_saved']; ?></div>
        <?php endif; ?>

        <form method="POST" action="server_settings.php?id=<?php echo $server_id; ?>" enctype="multipart/form-data">
            <div class="container__68f37">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37"><?php echo $translations['server_settings']['server_pp']; ?></h2>
                    <div class="text-sm/medium_cf4812 text__68f37" data-text-variant="text-sm/medium" style="color: var(--text-secondary);"><?php echo $translations['server_settings']['pp_desc']; ?></div>
                </div>
                <div class="column__68f37">
                    <div class="profile-picture-upload-area" role="button" tabindex="0" id="pp-upload-area">
                        <input type="file" name="server_pp" id="server_pp" class="hidden" accept="image/*" <?php echo !$is_owner ? 'disabled' : ''; ?>>
                        <div class="imageUploader_e4d0bf">
                            <div class="imageUploaderInner_e4d0bf <?php echo !empty($server['profile_picture']) ? 'has-image' : ''; ?>" 
                                 style="<?php echo !empty($server['profile_picture']) ? 'background-image: url(\'' . htmlspecialchars($server['profile_picture'], ENT_QUOTES, 'UTF-8') . '\');' : ''; ?>">
                                
                                <?php if (!empty($server['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($server['profile_picture'], ENT_QUOTES, 'UTF-8'); ?>"
                                         alt="Mevcut Profil Resmi"
                                         class="image-preview-full-size" id="current_pp_preview">
                                <?php endif; ?>

                                <h2 class="imageUploaderAcronym_e4d0bf" data-text-variant="heading-xxl/normal"><?php echo strtoupper(substr($server['name'], 0, 1)); ?></h2>
                                <div class="imageUploaderIcon_e4d0bf">
                                    <svg aria-hidden="true" role="img" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24">
                                        <path fill="var(--text-primary)" fill-rule="evenodd" d="M2 5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v8.67c0 .12-.34.17-.39.06A2.87 2.87 0 0 0 19 12a3 3 0 0 0-2.7 1.7c-.1.18-.36.22-.48.06l-.47-.63a2 2 0 0 0-3.2 0L9.93 16.1l-.5-.64a1.5 1.5 0 0 0-2.35 0l-1.86 2.32A.75.75 0 0 0 5.81 19h5.69c.28 0 .5.23.54.5.17.95.81 1.68 1.69 2.11.11.06.06.39-.06.39H5a3 3 0 0 1-3-3V5Zm8.2.98c.23-.91-.88-1.55-1.55-.90a.93.93 0 0 1-1.3 0c-.67-.65-1.78-.01-1.55.9a.93.93 0 0 1-.65 1.12c-.9.26-.9 1.54 0 1.8.48.14.77.63.65 1.12-.23.91.88 1.55 1.55.90a.93.93 0 0 1 1.3 0c.67.65 1.78.01 1.55-.9a.93.93 0 0 1 .65-1.12c.9-.26.9-1.54 0-1.8a.93.93 0 0 1-.65-1.12Z" clip-rule="evenodd" class=""></path>
                                        <path fill="var(--text-primary)" d="M19 14a1 1 0 0 1 1 1v3h3a1 1 0 0 1 0 2h-3v3a1 1 0 0 1-2 0v-3h-3a1 1 0 1 1 0-2h3v-3a1 1 0 0 1 1-1Z" class=""></path>
                                    </svg>
                                </div>
                                
                                <div class="upload-area-overlay" style="border-radius: 50%;">
                                    <div class="buttons-group">
                                        <?php if ($is_owner): ?>
                                            <button type="button" class="btn btn-secondary text-xs upload-button">
                                                <i class="fas fa-upload text-xs"></i>
                                                <span><?php echo !empty($server['profile_picture']) ? 'Değiştir' : 'Yükle'; ?></span>
                                            </button>
                                            <?php if (!empty($server['profile_picture'])): ?>
                                                <button type="submit" name="delete_pp" class="btn btn-danger text-xs">
                                                    <i class="fas fa-trash-alt text-xs"></i>
                                                    <span><?php echo $translations['server_settings']['remove']; ?></span>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">Yetki gerekli</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-xs text-gray-400 mt-2"><?php echo $translations['server_settings']['req_pp']; ?></div>
                </div>

                <div class="flex-grow">
                    <div class="form-section compact">
                        <label for="server_name" class="form-label"><?php echo $translations['server_settings']['server_name']; ?></label>
                        <input type="text" name="server_name" id="server_name" class="form-input" 
                               value="<?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?>" 
                               <?php echo $is_owner ? 'required' : 'disabled'; ?>>
                    </div>
                    <div class="form-section compact mt-4">
                        <label for="server_description" class="form-label"><?php echo $translations['server_settings']['server_desc']; ?></label>
                        <textarea name="server_description" id="server_description" class="form-input form-textarea"
                                  <?php echo !$is_owner ? 'disabled' : ''; ?>><?php echo htmlspecialchars($server['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="container__68f37">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37"><?php echo $translations['server_settings']['banner']; ?></h2>
                    <div class="text-sm/medium_cf4812 text__68f37" data-text-variant="text-sm/medium" style="color: var(--text-secondary);"><?php echo $translations['server_settings']['banner_desc']; ?></div>
                </div>
                <div class="column__68f37">
                    <div class="upsell__0969c" role="button" tabindex="0" id="banner-upload-area">
                        <input type="file" name="server_banner" id="server_banner" class="hidden" accept="image/*" <?php echo !$is_owner ? 'disabled' : ''; ?>>
                        <div class="imageUploader_e4d0bf">
                            <div class="imageUploaderInner_e4d0bf <?php echo !empty($server['banner']) ? 'has-image' : ''; ?>" 
                                 style="<?php echo !empty($server['banner']) ? 'background-image: url(\'' . htmlspecialchars($server['banner'], ENT_QUOTES, 'UTF-8') . '\');' : ''; ?>">
                                
                                <?php if (!empty($server['banner'])): ?>
                                    <img src="<?php echo htmlspecialchars($server['banner'], ENT_QUOTES, 'UTF-8'); ?>"
                                         alt="Mevcut Banner"
                                         class="image-preview-full-size" id="current_banner_preview">
                                <?php endif; ?>

                                <h2 class="imageUploaderAcronym_e4d0bf" data-text-variant="heading-xxl/normal">BANNER</h2>
                                <div class="imageUploaderIcon_e4d0bf">
                                    <svg aria-hidden="true" role="img" xmlns="http://www.w3.org/2000/svg" width="18" height-18" fill="none" viewBox="0 0 24 24">
                                        <path fill="var(--text-primary)" fill-rule="evenodd" d="M2 5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v8.67c0 .12-.34.17-.39.06A2.87 2.87 0 0 0 19 12a3 3 0 0 0-2.7 1.7c-.1.18-.36.22-.48.06l-.47-.63a2 2 0 0 0-3.2 0L9.93 16.1l-.5-.64a1.5 1.5 0 0 0-2.35 0l-1.86 2.32A.75.75 0 0 0 5.81 19h5.69c.28 0 .5.23.54.5.17.95.81 1.68 1.69 2.11.11.06.06.39-.06.39H5a3 3 0 0 1-3-3V5Zm8.2.98c.23-.91-.88-1.55-1.55-.90a.93.93 0 0 1-1.3 0c-.67-.65-1.78-.01-1.55.9a.93.93 0 0 1-.65 1.12c-.9.26-.9 1.54 0 1.8.48.14.77.63.65 1.12-.23.91.88 1.55 1.55.90a.93.93 0 0 1 1.3 0c.67.65 1.78.01 1.55-.9a.93.93 0 0 1 .65-1.12c.9-.26.9-1.54 0-1.8a.93.93 0 0 1-.65-1.12Z" clip-rule="evenodd" class=""></path>
                                        <path fill="var(--text-primary)" d="M19 14a1 1 0 0 1 1 1v3h3a1 1 0 0 1 0 2h-3v3a1 1 0 0 1-2 0v-3h-3a1 1 0 1 1 0-2h3v-3a1 1 0 0 1 1-1Z" class=""></path>
                                    </svg>
                                </div>
                                
                                <div class="upload-area-overlay">
                                    <div class="buttons-group">
                                        <?php if ($is_owner): ?>
                                            <button type="button" class="btn btn-secondary text-xs upload-button">
                                                <i class="fas fa-upload text-xs"></i>
                                                <span><?php echo $translations['server_settings']['upload']; ?></span>
                                            </button>
                                            <?php if (!empty($server['banner'])): ?>
                                                <button type="submit" name="delete_banner" class="btn btn-danger text-xs">
                                                    <i class="fas fa-trash-alt text-xs"></i>
                                                    <span><?php echo $translations['server_settings']['remove']; ?></span>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">Yetki gerekli</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container__68f37">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37"><?php echo $translations['server_settings']['invite_bg']; ?></h2>
                    <div class="text-sm/medium_cf4812 text__68f37" data-text-variant="text-sm/medium" style="color: var(--text-secondary);"><?php echo $translations['server_settings']['invite_desc']; ?></div>
                </div>
                <div class="column__68f37">
                    <div class="upsell__0969c" role="button" tabindex="0" id="invite-background-upload-area">
                        <input type="file" name="invite_background" id="invite_background" class="hidden" accept="image/*" <?php echo !$is_owner ? 'disabled' : ''; ?>>
                        <div class="imageUploader_e4d0bf">
                            <div class="imageUploaderInner_e4d0bf <?php echo !empty($server['invite_background']) ? 'has-image' : ''; ?>" 
                                 style="<?php echo !empty($server['invite_background']) ? 'background-image: url(\'' . htmlspecialchars($server['invite_background'], ENT_QUOTES, 'UTF-8') . '\');' : ''; ?>">
                                
                                <?php if (!empty($server['invite_background'])): ?>
                                    <img src="<?php echo htmlspecialchars($server['invite_background'], ENT_QUOTES, 'UTF-8'); ?>"
                                         alt="Mevcut Davet Arka Planı"
                                         class="image-preview-full-size" id="current_invite_background_preview">
                                <?php endif; ?>

                                <h2 class="imageUploaderAcronym_e4d0bf" data-text-variant="heading-xxl/normal">DAVET</h2>
                                <div class="imageUploaderIcon_e4d0bf">
                                    <svg aria-hidden="true" role="img" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24">
                                        <path fill="var(--text-primary)" fill-rule="evenodd" d="M2 5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v8.67c0 .12-.34.17-.39.06A2.87 2.87 0 0 0 19 12a3 3 0 0 0-2.7 1.7c-.1.18-.36.22-.48.06l-.47-.63a2 2 0 0 0-3.2 0L9.93 16.1l-.5-.64a1.5 1.5 0 0 0-2.35 0l-1.86 2.32A.75.75 0 0 0 5.81 19h5.69c.28 0 .5.23.54.5.17.95.81 1.68 1.69 2.11.11.06.06.39-.06.39H5a3 3 0 0 1-3-3V5Zm8.2.98c.23-.91-.88-1.55-1.55-.90a.93.93 极 0 0 1-1.3 0c-.67-.65-1.78-.01-1.55.9a.93.93 0 0 1-.65 1.12c-.9.26-.9 1.54 0 1.8.48.14.77.63.65 1.12-.23.91.88 1.55 1.55.90a.93.93 0 0 极 1.3 0c.67.65 1.78.01 1.55-.9a.93.93 0 0 1 .65-1.12c.9-.26.9-1.54 0-1.8a.93.93 0 0 1-.65-1.12Z" clip-rule="evenodd" class=""></path>
                                        <path fill="var(--text-primary)" d="M19 极 14a1 1 0 0 1 1 1v3h3a1 1 0 0 1 0 2h-3v3a1 1 0 0 1-2 0v-3h-3a1 1 0 1 1 0-2h3v-3a1 1 0 0 1 1-1Z" class=""></path>
                                    </svg>
                                </div>
                                
                                <div class="upload-area-overlay">
                                    <div class="buttons-group">
                                        <?php if ($is_owner): ?>
                                            <button type="button" class="btn btn-secondary text-xs upload-button">
                                                <i class="fas fa-upload text-xs"></i>
                                                <span><?php echo $translations['server_settings']['upload']; ?></span>
                                            </button>
                                            <?php if (!empty($server['invite_background'])): ?>
                                                <button type极="submit" name="delete_invite_background" class="btn btn-danger text-xs">
                                                    <i class="fas fa-trash-alt text-xs"></i>
                                                    <span><?php echo $translations['server_settings']['remove']; ?></span>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">Yetki gerekli</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <label class="flex items-center justify-between p-3 bg-gray-800/30 rounded">
                    <div>
                        <div class="font-medium text-sm"><?php echo $translations['server_settings']['show_on_comm_page']; ?></div>
                        <div class="text-xs text-gray-400"><?php echo $translations['server_settings']['show_on_comm_page_desc']; ?></div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="show_in_community" <?php echo $server['show_in_community'] ? 'checked' : ''; ?> <?php echo !$is_owner ? 'disabled' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </label>
            </div>

            <?php if ($is_owner): ?>
                <div class="form-section">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <span><?php echo $translations['server_settings']['apply_changes']; ?></span>
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($is_owner): ?>
            <div class="mt-10 border-t border-gray-800 pt-6">
                <h3 class="text-lg font-semibold text-red-400 mb-4"><?php echo $translations['server_settings']['danger_zone']; ?></h3>
                
                <div class="danger-zone p-4 rounded">
                    <div class="flex justify-between items-center">
                        <div>
                            <h4 class="font-medium"><?php echo $translations['server_settings']['delete_server']; ?></h4>
                            <p class="text-xs text-gray-400"><?php echo $translations['server_settings']['delete_conf']; ?></p>
                        </div>
                        <button type="button" id="delete-server-btn" class="btn btn-danger text-sm">
                            <i class="fas fa-trash-alt"></i>
                            <span><?php echo $translations['server_settings']['delete_server']; ?></span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div id="confirmation-modal" class="fixed inset-0 flex items-center justify-center hidden z-50">
        <div class="modal-overlay absolute inset-0"></div>
        <div class="modal-content relative p-6 max-w-md w-full mx-4">
            <div class="text-center mb-6">
                <div class="w-12 h-12 bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-exclamation-triangle text-red-400"></i>
                </div>
                <h极3 class="font-semibold mb-1"><?php echo $translations['server_settings']['delete_conf_sure']; ?></h3>
                <p class="text-sm text-gray-400">
                    <?php echo $translations['server_settings']['delete_p']; ?>
                </p>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button"极 id="cancel-delete-btn" class="btn btn-secondary">
                    <span><?php echo $translations['server_settings']['cancel']; ?></span>
                </button>
                <?php if ($is_owner): ?>
                    <form id="delete-server-form" method="POST" action="server_settings.php?id=<?php echo $server_id; ?>">
                        <input type="hidden" name="delete_server" value="1">
                        <button type="submit" class="btn btn-danger">
                            <span><?php echo $translations['server_settings']['delete']; ?></span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function updateFilePreview(inputElement, previewContainerId, isProfilePicture = false) {
                const file = inputElement.files[0];
                const uploadContainer = document.getElementById(previewContainerId);
                const imageUploaderInner = uploadContainer.querySelector('.imageUploaderInner_e4d0bf');
                let previewImg = imageUploaderInner.querySelector('.image-preview-full-size');
                const defaultAcronym = imageUploaderInner.querySelector('.imageUploaderAcronym_e4d0bf');
                const defaultIcon = imageUploaderInner.querySelector('.imageUploaderIcon_e4d0bf');
                const uploadButtonText = imageUploaderInner.querySelector('.upload-button span');

                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        if (!previewImg) {
                            previewImg = document.createElement('img');
                            previewImg.className = 'image-preview-full-size';
                            previewImg.id = inputElement.id.replace('server_', 'current_') + '_preview';
                            imageUploaderInner.prepend(previewImg);
                        }
                        previewImg.src = e.target.result;
                        imageUploaderInner.classList.add('has-image');
                        if (defaultAcronym) defaultAcronym.style.display = 'none';
                        if (defaultIcon) defaultIcon.style.display = 'none';
                        if (uploadButtonText) uploadButtonText.textContent = 'Değiştir';
                    };
                    reader.readAsDataURL(file);
                } else {
                    if (previewImg) {
                        previewImg.remove();
                    }
                    imageUploaderInner.classList.remove('has-image');
                    if (defaultAcronym) defaultAcronym.style.display = 'flex';
                    if (defaultIcon) defaultIcon.style.display = 'flex';
                    if (uploadButtonText) uploadButtonText.textContent = 'Yükle';
                }

                if (isProfilePicture) {
                    if (preview极) previewImg.style.borderRadius = '50%';
                    imageUploaderInner.style.borderRadius = '50%';
                }
            }

            // Initialize upload areas for owners only
            <?php if ($is_owner): ?>
                document.querySelectorAll('.imageUploaderInner_e4d0bf').forEach(el => {
                    const isProfilePicture = el.closest('.profile-picture-upload-area') !== null;
                    const uploadButtonText = el.querySelector('.upload-button span');
                    if (el.style.backgroundImage || el.querySelector('.image-preview-full-size')) {
                        el.classList.add('has-image');
                        if (uploadButtonText) uploadButtonText.textContent = 'Değiştir';
                    } else {
                        if (uploadButtonText) uploadButtonText.textContent = 'Yükle';
                    }
                    
                    if (isProfilePicture) {
                        el.style.borderRadius = '50%';
                        const previewImg = el.querySelector('.image-preview-full-size');
                        if (previewImg) previewImg.style.borderRadius = '50%';
                    }
                });

                document.getElementById('server_pp').addEventListener('change', function() {
                    updateFilePreview(this, 'pp-upload-area', true);
                });
                document.getElementById('server_banner').addEventListener('change', function() {
                    updateFilePreview(this, 'banner-upload-area');
                });
                document.getElementById('invite_background').addEventListener('change', function() {
                    updateFilePreview(this, 'invite-background-upload-area');
                });

                document.querySelectorAll('.upload-button').forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const uploadArea = this.closest('.profile-picture-upload-area, .upsell__0969c');
                        const fileInput = uploadArea.querySelector('input[type="file"]');
                        if (fileInput) fileInput.click();
                    });
                });

                document.querySelectorAll('.profile-picture-upload-area, .upsell__0969c').forEach(element => {
                    element.addEventListener('click', function(e) {
                        if (e.target.closest('button') || e.target.closest('a')) return;
                        const fileInput = this.querySelector('input[type="file"]');
                        if (fileInput) fileInput.click();
                    });

                    const fileInput = element.querySelector('input[type="file"]');
                    if (fileInput) {
                        element.addEventListener('dragover', (e) => {
                            e.preventDefault();
                            element.classList.add('border-accent-color');
                        });
                        element.addEventListener('dragleave', () => {
                            element.classList.remove('border-accent-color');
                        });
                        element.addEventListener('drop', (e) => {
                            e.preventDefault();
                            element.classList.remove('border-accent-color');
                            if (e.dataTransfer.files.length) {
                                fileInput.files = e.dataTransfer.files;
                                fileInput.dispatchEvent(new Event('change'));
                            }
                        });
                    }
                });
            <?php endif; ?>

            const modal = document.getElementById('confirmation-modal');
            const deleteBtn = document.getElementById('delete-server-btn');
            const cancelBtn = document.getElementById('cancel-delete-btn');

            if (deleteBtn) {
                deleteBtn.addEventListener('click', () => {
                    modal.classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                });
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                });
            }

            modal.addEventListener('click', (e) => {
                if (e.target.classList.contains('modal-overlay')) {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                }
            });

            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const serverName = document.getElementById('server_name').value.trim();
                if (!e.submitter || e.submitter.name !== 'delete_server') {
                    if (serverName.length < 3) {
                        e.preventDefault();
                        alert('Sunucu ismi en az 3 karakter olmalıdır.');
                        return;
                    }
                }

                const submitBtn = e.submitter;
                if (submitBtn && submitBtn.name !== 'delete_pp' && submitBtn.name !== 'delete_banner' && submitBtn.name !== 'delete_invite_background') {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner spinner"></i> Kaydediliyor...';
                }
            });

            // Mobil Kaydırma hareketi
            const movesidebar = document.getElementById("movesidebar");
            const mainContent = document.getElementById("main-content");

            let startX, endX;

            if (window.innerWidth <= 768) {
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
                        movesidebar.style.left = "-100%";
                    } else if (deltaX < -100) {
                        movesidebar.style.left = "0";
                    }
                }
            }
        });
    </script>
</body>
</html>