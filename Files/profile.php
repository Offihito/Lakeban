<?php
session_start();
require 'db_connection.php';

// Session check: Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Function to load language files from JSON
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

// Determine default language based on browser or session
$default_lang = 'en'; // Changed to English as default for translation
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fi', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}
$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : $default_lang;
$_SESSION['lang'] = $lang;
$translations = loadLanguage($lang);

// Fetch user profile data from database
$profile_query = $db->prepare("
    SELECT up.avatar_url, up.bio, up.background_url, up.profile_music, up.video_url, 
           up.profile_header_color, up.profile_text_color, up.profile_button_color, 
           up.country, up.youtube_url, up.instagram_url, up.spotify_url, up.steam_url, 
           up.github_url, up.display_username
    FROM user_profiles up
    WHERE up.user_id = ?
");
$profile_query->execute([$_SESSION['user_id']]);
$profile_data = $profile_query->fetch(PDO::FETCH_ASSOC);

// Set default values for profile fields
$avatar_url = $profile_data['avatar_url'] ?? 'avatars/9_1734619702.jpg';
$bio = $profile_data['bio'] ?? 'Write something about yourself...';
$background_url = $profile_data['background_url'] ?? 'Background';
$profile_music = $profile_data['profile_music'] ?? 'Music';
$video_url = $profile_data['video_url'] ?? 'Video';
$profile_header_color = $profile_data['profile_header_color'] ?? '#bb00ff';
$profile_text_color = $profile_data['profile_text_color'] ?? '#e100ff';
$profile_button_color = $profile_data['profile_button_color'] ?? '#2bff00';
$country = $profile_data['country'] ?? 'gb'; // Default to UK for English
$youtube_url = $profile_data['youtube_url'] ?? '';
$instagram_url = $profile_data['instagram_url'] ?? '';
$spotify_url = $profile_data['spotify_url'] ?? '';
$steam_url = $profile_data['steam_url'] ?? '';
$github_url = $profile_data['github_url'] ?? '';
$display_username = $profile_data['display_username'] ?? $_SESSION['username'];

// Varsayılan tema değerleri
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
    // Handle error, perhaps set session error message
}
$isLakebiumUser = false;
try {
    $lakebiumStmt = $db->prepare("SELECT status FROM lakebium WHERE user_id = ? AND status = 'active'");
    $lakebiumStmt->execute([$_SESSION['user_id']]);
    $isLakebiumUser = $lakebiumStmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Lakebium abonelik durumu alınırken bir hata oluştu: ' . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--font: 'Poppins', 'Arial', sans-serif; --app-height: 100vh; --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>; --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;">
<head>
    <meta charset="UTF-8">
    <title><?php echo $translations['profile_page_title'] ?? 'Profile Settings'; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <!-- App Icons -->
    <link rel="apple-touch-icon" href="/assets/apple-touch.png">
    <link rel="icon" type="image/png" href="/assets/logo_round.png">
    <!-- Lucide Icons -->
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide@latest/css/lucide.css">
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Flag Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.5.0/css/flag-icon.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- CSS -->
    <style>
        :root, .root {
            --hover: #3CB371;
            --gradient: #423d3c;
            --scrollback: #0d3b22;
            --error: #ed5151;
            --profile-header-color: <?php echo $profile_header_color; ?>;
            --profile-text-color: <?php echo $profile_text_color; ?>;
            --profile-button-color: <?php echo $profile_button_color; ?>;
            --profile-font: 'Poppins', 'Arial', sans-serif;
            --card-bg: rgba(23, 26, 33, 1);
            --card-border: rgba(255, 255, 255, 0.1);
            --avatar-shape: circle;
            --accent-color: #3CB371;
            --font-size: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            transition: background-color 0.3s ease, color 0.3s ease;
            background-color: #1E1E1E;
            color: #ffffff;
            font-family: var(--profile-font);
            margin: 0;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
            font-size: var(--font-size);
        }

        .sidebar, .content-container, .right-sidebar {
            transition: background-color 0.3s ease;
            background-color: #242424;
        }
        .sidebar-item { color: #b9bbbe; }
        .sidebar-item:hover, .sidebar-item.active { background-color: #2f3136; color: #ffffff; }
        .content-container h1, .content-container h3, .theme-option h4, .select-box { color: #ffffff; }
        .content-container h5, .category, .keybind_c2b141, .user-id, .setting-content .description { color: #b9bbbe; }
        hr { border-top: 1px solid #2f3136; }
        .tip {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .tip svg { width: 20px; height: 20px; margin-right: 8px; }

        /* === AYDINLIK TEMA === */
        .light-theme body { background-color: #F2F3F5; color: #2E3338; }
        .light-theme .sidebar, .light-theme .content-container, .light-theme .right-sidebar { background-color: #FFFFFF; }
        .light-theme .sidebar-item { color: #4F5660; }
        .light-theme .sidebar-item:hover, .light-theme .sidebar-item.active { background-color: #e3e5e8; color: #060607; }
        .light-theme .content-container h1, .light-theme .content-container h3, .light-theme .theme-card h4 { color: #060607; }
        .light-theme .content-container h5, .light-theme .category, .light-theme .setting-content .description { color: #4F5660; }
        .light-theme hr { border-top: 1px solid #e3e5e8; }
        .light-theme .theme-card { background-color: #F8F9FA; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        .light-theme .theme-card.active { border-color: #007bff; }
        .light-theme .edit-profile-btn { background-color: var(--accent-color); }
        .light-theme .edit-profile-btn:hover { background-color: #2e9b5e; }
        .light-theme .reset-btn { background-color: #dc3545; }
        .light-theme .reset-btn:hover { background-color: #c82333; }
        .light-theme .editor-form { background-color: #F8F9FA; }
        .light-theme .editor-form textarea, .light-theme .editor-form select, .light-theme .editor-form input[type="text"] { background-color: #e3e5e8; color: #060607; border-color: #d1d3d6; }
        .light-theme .editor-form textarea:focus, .light-theme .editor-form select:focus, .light-theme .editor-form input[type="text"]:focus { background-color: #ffffff; border-color: #007bff; }
        .light-theme .tip { background-color: #e3e5e8; color: #4F5660; }
        .light-theme .tip svg { fill: #4F5660; }
        .light-theme .tip a { color: #007bff; }
        .light-theme .file-preview { background-color: #e3e5e8; }
        .light-theme .file-preview .placeholder { color: #4F5660; }
        .light-theme .char-counter, .light-theme .file-info { color: #4F5660; }

        /* === KOYU TEMA === */
        .dark-theme body { background-color: #1E1E1E; color: #ffffff; }
        .dark-theme .sidebar, .dark-theme .content-container, .dark-theme .right-sidebar { background-color: #242424; }
        .dark-theme .sidebar-item { color: #b9bbbe; }
        .dark-theme .sidebar-item:hover, .dark-theme .sidebar-item.active { background-color: #2f3136; color: #ffffff; }
        .dark-theme .theme-card { background-color: #2f3136; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); }
        .dark-theme .theme-card.active { border-color: #3CB371; }
        .dark-theme .custom-color-input { border-color: #b9bbbe; }
        .dark-theme .edit-profile-btn { background-color: var(--accent-color); }
        .dark-theme .edit-profile-btn:hover { background-color: #2e9b5e; }
        .dark-theme .reset-btn { background-color: #dc3545; }
        .dark-theme .reset-btn:hover { background-color: #c82333; }
        .dark-theme .editor-form { background-color: #2f3136; }
        .dark-theme .editor-form textarea, .dark-theme .editor-form select, .dark-theme .editor-form input[type="text"] { background-color: #1c1e22; color: #ffffff; border-color: #2f3136; }
        .dark-theme .editor-form textarea:focus, .dark-theme .editor-form select:focus, .dark-theme .editor-form input[type="text"]:focus { background-color: #202225; border-color: #3CB371; }
        .dark-theme .tip { background-color: #2f3136; color: #b9bbbe; }
        .dark-theme .tip svg { fill: #b9bbbe; }
        .dark-theme .tip a { color: #3CB371; }
        .dark-theme .file-preview { background-color: #202225; }
        .dark-theme .file-preview .placeholder { color: #b9bbbe; }
        .dark-theme .char-counter, .dark-theme .file-info { color: #b9bbbe; }

        /* === ÖZEL TEMA === */
        .custom-theme body { 
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%); 
            color: #ffffff; 
        }
        .custom-theme .sidebar, .custom-theme .content-container, .custom-theme .right-sidebar { 
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%); 
        }
        .custom-theme .sidebar-item { color: color-mix(in srgb, var(--custom-background-color) 40%, white); }
        .custom-theme .sidebar-item:hover, .custom-theme .sidebar-item.active { 
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%); 
            color: #ffffff; 
        }
        .custom-theme .content-container h1, .custom-theme .content-container h3, .custom-theme .theme-card h4 { color: #ffffff; }
        .custom-theme .content-container h5, .custom-theme .category, .custom-theme .setting-content .description { 
            color: color-mix(in srgb, var(--custom-background-color) 40%, white); 
        }
        .custom-theme hr { 
            border-top: 1px solid color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
        }
        .custom-theme .theme-card { 
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); 
        }
        .custom-theme .theme-card.active { border-color: var(--custom-secondary-color); }
        .custom-theme .edit-profile-btn { 
            background-color: var(--custom-secondary-color); 
        }
        .custom-theme .edit-profile-btn:hover { 
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%); 
        }
        .custom-theme .reset-btn { 
            background-color: color-mix(in srgb, var(--custom-background-color) 50%, #dc3545 50%); 
        }
        .custom-theme .reset-btn:hover { 
            background-color: color-mix(in srgb, var(--custom-background-color) 40%, #c82333 60%); 
        }
        .custom-theme .editor-form { 
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
        }
        .custom-theme .editor-form textarea, .custom-theme .editor-form select, .custom-theme .editor-form input[type="text"] { 
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, black 20%); 
            color: #ffffff; 
            border-color: color-mix(in srgb, var(--custom-background-color) 50%, var(--custom-secondary-color) 50%); 
        }
        .custom-theme .editor-form textarea:focus, .custom-theme .editor-form select:focus, .custom-theme .editor-form input[type="text"]:focus { 
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, black 30%); 
            border-color: var(--custom-secondary-color); 
        }
        .custom-theme .tip { 
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%); 
            color: #ffffff; 
        }
        .custom-theme .tip svg { fill: #ffffff; }
        .custom-theme .tip a { color: var(--custom-secondary-color); }
        .custom-theme .file-preview { 
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, black 30%); 
        }
        .custom-theme .file-preview .placeholder { color: #ffffff; }
        .custom-theme .char-counter, .custom-theme .file-info { color: #ffffff; }

        .app-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            height: var(--app-height);
            padding: 24px;
            box-sizing: border-box;
        }

        .sidebar {
            width: 260px;
            padding: 16px 8px;
            overflow-y: auto;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: #1E1E1E;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: #3CB371;
            border-radius: 2px;
        }

        .category {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 8px 16px;
            margin: 8px 0;
        }

        .sidebar-item {
            display: flex;
            align-items: center;
            padding: 8px 16px;
            margin: 2px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .sidebar-item i {
            margin-right: 8px;
        }

        .content-container {
            flex-grow: 1;
            padding: 24px;
            overflow-y: auto;
            margin-left: 16px;
            margin-right: 16px;
            border-radius: 8px;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            align-items: start;
        }

        .content-container::-webkit-scrollbar {
            width: 8px;
        }

        .content-container::-webkit-scrollbar-track {
            background: #1E1E1E;
        }

        .content-container::-webkit-scrollbar-thumb {
            background: #2f3136;
            border-radius: 4px;
        }

        .content-container h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 24px;
            grid-column: 1 / -1;
        }

        .content-container h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 16px 0 12px;
            position: relative;
            padding-bottom: 8px;
        }

        .content-container h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 2px;
            background: linear-gradient(90deg, var(--profile-button-color), var(--profile-header-color));
        }

        .right-sidebar {
            width: 72px;
            padding: 16px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .tools__23e6b {
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .container_c2b141 {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .closeButton_c2b141 {
            background-color: #2f3136;
            border-radius: 4px;
            padding: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .closeButton_c2b141:hover {
            background-color: #3CB371;
        }

        .closeButton_c2b141 svg {
            width: 18px;
            height: 18px;
            fill: #b9bbbe;
        }

        .keybind_c2b141 {
            color: #b9bbbe;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        /* Profile Preview Styles */
        .profile-preview {
            background: #2f3136;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .preview-container {
            position: relative;
            width: 100%;
            font-size: 0.8rem;
            background-color: #0d1117;
            border-radius: 12px;
            overflow: hidden;
            transform: scale(1);
            transform-origin: top left;
        }

        .full-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: <?php echo $background_url ? "url('" . htmlspecialchars($background_url) . "')" : 'url("../images/default_background.jpg")'; ?>;
            background-size: cover;
            background-position: center;
            z-index: -2;
            filter: brightness(0.4);
        }

        .gradient-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(13, 17, 23, 0.8) 0%, rgba(27, 40, 56, 0.7) 100%);
            z-index: -1;
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            padding: 10px;
        }

        /* Profile Header */
        .profile-header {
            background: linear-gradient(135deg, rgba(23, 26, 33, 0.95) 0%, rgba(27, 40, 56, 0.95) 100%);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--profile-button-color), var(--profile-header-color));
        }

        .profile-header-content {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .profile-avatar-section {
            flex-shrink: 0;
        }

        .profile-avatar-wrapper {
            width: 80px;
            height: 80px;
            border-radius: var(--avatar-shape);
            padding: 3px;
            background: linear-gradient(135deg, var(--profile-button-color), var(--profile-header-color));
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .profile-avatar-wrapper::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            border-radius: var(--avatar-shape);
        }

        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: var(--avatar-shape);
            overflow: hidden;
            background: var(--profile-header-color);
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: white;
            background: linear-gradient(135deg, var(--profile-header-color), var(--profile-button-color));
            font-weight: bold;
        }

        .profile-info-section {
            flex-grow: 1;
            min-width: 180px;
        }

        .profile-name {
            font-size: 1.6rem;
            color: var(--profile-text-color);
            margin: 0 0 6px 0;
            font-weight: 600;
            background: linear-gradient(90deg, var(--profile-text-color), var(--profile-button-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .country-info {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .bio {
            color: rgba(255, 255, 255, 0.8);
            margin: 8px 0;
            font-size: 1rem;
            line-height: 1.4;
            padding-left: 10px;
            position: relative;
        }

        .bio::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 2px;
            background: linear-gradient(to bottom, var(--profile-button-color), var(--profile-header-color));
            border-radius: 2px;
        }

        .preview-social-links {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .preview-social-link {
            color: white;
            text-decoration: none;
            font-size: 0.75rem;
            padding: 6px 10px;
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .preview-social-link i {
            font-size: 1.1em;
        }

        .profile-details {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .profile-stats {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 6px;
            padding: 8px 12px;
            min-width: 70px;
            text-align: center;
            border: 1px solid var(--card-border);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .profile-stats::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: all 0.5s ease;
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .stat-value {
            color: var(--profile-text-color);
            font-size: 1rem;
            font-weight: 600;
        }

        .profile-content {
            display: grid;
            grid-template-columns: 180px 1fr 180px;
            gap: 15px;
        }

        .left-sidebar, .right-sidebar, .profile-main {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 15px;
            border: 1px solid var(--card-border);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .left-sidebar, .right-sidebar {
            height: fit-content;
            max-height: 250px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--profile-button-color) transparent;
        }

        .left-sidebar::-webkit-scrollbar,
        .right-sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .left-sidebar::-webkit-scrollbar-thumb,
        .right-sidebar::-webkit-scrollbar-thumb {
            background-color: var(--profile-button-color);
            border-radius: 5px;
        }

        .profile-music h3, .friends-list h3, .stats-sidebar h3, .profile-comments h2 {
            color: var(--profile-text-color);
            margin-bottom: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            position: relative;
            padding-bottom: 6px;
        }

        .profile-music h3::after, .friends-list h3::after, .stats-sidebar h3::after, .profile-comments h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 30px;
            height: 2px;
            background: linear-gradient(90deg, var(--profile-button-color), var(--profile-header-color));
            border-radius: 2px;
        }

        .music-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .music-controls button {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: var(--profile-text-color);
            cursor: pointer;
            font-size: 16px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .music-controls input[type="range"] {
            flex-grow: 1;
            height: 4px;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.1);
            outline: none;
            -webkit-appearance: none;
            cursor: pointer;
        }

        .music-controls input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--profile-button-color);
            cursor: pointer;
        }

        .friend {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .friend-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--profile-header-color);
            border: 2px solid var(--profile-button-color);
        }

        .friend-avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            background: linear-gradient(135deg, var(--profile-header-color), var(--profile-button-color));
            font-weight: bold;
        }

        .friend-username {
            color: var(--profile-text-color);
            font-size: 0.75rem;
            font-weight: 500;
            background: linear-gradient(90deg, var(--profile-text-color), var(--profile-button-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .video-container {
            margin-bottom: 15px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            position: relative;
            display: none;
        }

        .video-container.active {
            display: block;
        }

        .video-placeholder {
            width: 100%;
            height: 80px;
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.7);
            font-size: 1rem;
        }

        .post {
            background: rgba(0, 0, 0, 0.2);
            padding: 12px;
            margin-bottom: 12px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
        }

        .post h3 {
            color: var(--profile-text-color);
            margin: 0 0 8px 0;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .post p {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.4;
            font-size: 0.75rem;
        }

        .comment {
            display: flex;
            gap: 8px;
            padding: 12px;
            margin-bottom: 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            border: 1px solid var(--card-border);
        }

        .comment-avatar {
            flex-shrink: 0;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--profile-header-color);
            border: 2px solid var(--profile-button-color);
        }

        .comment-avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            background: linear-gradient(135deg, var(--profile-header-color), var(--profile-button-color));
            font-weight: bold;
        }

        .comment-content {
            flex-grow: 1;
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .comment-username {
            color: var(--profile-text-color);
            font-weight: 500;
            font-size: 0.75rem;
            background: linear-gradient(90deg, var(--profile-text-color), var(--profile-button-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .comment-date {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.65rem;
        }

        .comment-text {
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.4;
            font-size: 0.75rem;
        }

        .action-button {
            background: linear-gradient(135deg, var(--profile-button-color), var(--profile-header-color));
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 0.75rem;
            margin: 4px;
            transition: all 0.3s ease;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .action-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .editor-form {
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            position: sticky;
            top: 24px;
            max-height: calc(var(--app-height) - 48px);
            overflow-y: auto;
            transition: background-color 0.3s ease;
        }

        .editor-form::-webkit-scrollbar {
            width: 5px;
        }

        .editor-form::-webkit-scrollbar-thumb {
            background-color: var(--profile-button-color);
            border-radius: 5px;
        }

        .form-section {
            margin-bottom: 16px;
        }

        .editor-form textarea {
            width: 100%;
            padding: 10px;
            margin: 6px 0;
            border: 1px solid #2f3136;
            border-radius: 5px;
            font-family: inherit;
            font-size: 13px;
            min-height: 80px;
            resize: vertical;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .editor-form textarea:focus {
            outline: none;
            border-color: var(--profile-button-color);
        }

        .editor-form select {
            width: 100%;
            padding: 10px;
            margin: 6px 0;
            border: 1px solid #2f3136;
            border-radius: 5px;
            font-family: inherit;
            font-size: 13px;
            transition: border-color 0.2s ease, background 0.2s ease;
            cursor: pointer;
        }

        .editor-form select:focus {
            outline: none;
            border-color: var(--profile-button-color);
        }

        .editor-form input[type="file"] {
            display: none;
        }

        .file-upload {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 6px 0;
        }

        .file-preview {
            width: 60px;
            height: 60px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .file-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .file-preview.banner {
            width: 100%;
            height: 50px;
        }

        .file-preview .placeholder {
            font-size: 11px;
            text-align: center;
            word-break: break-all;
            padding: 4px;
        }

        .file-upload-label {
            background: linear-gradient(135deg, var(--profile-button-color), var(--profile-header-color));
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .file-upload-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .file-info {
            font-size: 11px;
            margin-top: 3px;
        }

        .editor-form .form-group {
            margin-bottom: 12px;
        }

        .editor-form .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
            color: #dcddde;
        }

        .editor-form .form-group input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #2f3136;
            border-radius: 5px;
            font-family: inherit;
            font-size: 13px;
        }

        .editor-form .form-group input[type="text"]:focus {
            outline: none;
            border-color: var(--profile-button-color);
        }

        .color-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
        }

        .color-picker {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .color-picker label {
            font-size: 13px;
            color: #dcddde;
        }

        .color-picker input[type="color"] {
            -webkit-appearance: none;
            border: none;
            width: 100%;
            height: 40px;
            cursor: pointer;
            background: transparent;
            padding: 0;
            border-radius: 5px;
            overflow: hidden;
        }

        .color-picker input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 0;
        }

        .color-picker input[type="color"]::-webkit-color-swatch {
            border: none;
            border-radius: 5px;
        }

        .char-counter {
            text-align: right;
            font-size: 11px;
            margin-top: -4px;
            margin-bottom: 8px;
        }

        #save-button {
            background: linear-gradient(135deg, var(--profile-button-color), var(--profile-header-color));
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.2s ease;
            width: 100%;
            margin-top: 20px;
        }

        #save-button:hover {
            transform: translateY(-2px);
        }

        #save-button:disabled {
            background-color: #5c6b73;
            cursor: not-allowed;
            transform: none;
        }

        .tip {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background-color: #2f3136;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.toast.show {
    opacity: 1;
}
        .tip svg {
            width: 20px;
            height: 20px;
            margin-right: 8px;
        }

        .tip a {
            text-decoration: none;
            font-weight: 500;
        }

        .tip a:hover {
            text-decoration: underline;
        }

        .file-error {
            color: var(--error);
            font-size: 11px;
            margin-top: 3px;
        }

        @media (max-width: 1024px) {
            .app-container {
                flex-direction: column;
                padding: 16px;
            }
            .sidebar {
                width: 100%;
                margin-bottom: 16px;
            }
            .content-container {
                width: 100%;
                margin-left: 0;
                margin-right: 0;
                grid-template-columns: 1fr;
            }
            .right-sidebar {
                display: none;
            }
            .profile-preview {
                grid-column: 1;
            }
            .editor-form {
                grid-column: 1;
                position: relative;
                top: auto;
            }
            .preview-container {
                transform: scale(1);
            }
        }
        @media (max-width: 768px) {
            .app-container { padding: 16px; }
            .sidebar { position: absolute; width: 100%; height: 100vh; left: 0%; margin-bottom: 16px; border-radius: 8px; }
            #back { display: flex; }
            .content-container { padding-left: 6px !important; padding: 0; position: absolute; width: 100%; height: 100vh; left: 0%; margin-left: 0; margin-right: 0; border-radius: 8px; z-index: 5; }
            .right-sidebar { display: none; }
            .user-row { flex-direction: column; align-items: flex-start; }
            .edit-profile-btn { width: 100%; }
            .modal-content { width: 90%; }
            
            #preview {
                display: none;
            }
        }
    </style>
</head>
<body style="background-color: <?= $currentTheme === 'custom' ? htmlspecialchars($currentCustomColor) : '' ?>;">
     <div class="app-container">
    <div id="movesidebar" class="sidebar">
        <div class="category"><?php echo $translations['settings']['categories']['user'] ?? 'Kullanıcı Ayarları'; ?></div>
          <a href="/settings" style="text-decoration: none; color: inherit;">
        <div class="sidebar-item" data-page="settings" onclick="('settings', null)"><i data-lucide="user"></i> <?php echo $translations['settings']['sidebar']['account'] ?? 'Hesabım'; ?></div>
        </a>
            <div class="sidebar-item active" data-page="profile"><i data-lucide="user-pen"></i> <?php echo $translations['settings']['sidebar']['profile'] ?? 'Profilim'; ?></div>
        <div class="sidebar-item" data-page="content-control" onclick="loadPage('content-control', 'content_control.php')"><i data-lucide="shield-check"></i> <?php echo $translations['settings']['sidebar']['content_control'] ?? 'İçerik Kontrolü'; ?></div>
        <div class="sidebar-item" data-page="connections" onclick="loadPage('connections', 'connections.php')"><i data-lucide="link-2"></i> <?php echo $translations['settings']['sidebar']['connections'] ?? 'Bağlantılar'; ?></div>
         <a href="/language_settings" style="text-decoration: none; color: inherit;">
        <div class="sidebar-item" data-page="language" onclick="('language', 'language_settings_content.php')"><i data-lucide="languages"></i> <?php echo $translations['settings']['sidebar']['language'] ?? 'Dil'; ?></div>
         </a>
        <div class="category"><?php echo $translations['settings']['categories']['customization'] ?? 'Özelleştirme'; ?></div>
          <a href="/themes" style="text-decoration: none; color: inherit;">
            <div class="sidebar-item" data-page="themes"><i data-lucide="palette"></i> <?php echo $translations['settings']['sidebar']['themes'] ?? 'Temalar'; ?></div>
             </a>
        <?php if ($isLakebiumUser): ?>
            <a href="/frame_settings" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item" data-page="avatar-frame" onclick="('avatar-frame', 'avatar_frame_content.php')">
                    <i data-lucide="frame"></i> <?php echo $translations['settings']['sidebar']['avatar_frame'] ?? 'Avatar Çerçevesi'; ?>
                </div>
            </a>
        <?php else: ?>
            <!-- Hata ayıklama için geçici olarak görünür -->
            <div class="sidebar-item" style="color: #ed5151; font-size: 12px; padding: 8px 16px;">
                <i data-lucide="alert-triangle"></i> Avatar Çerçevesi (Lakebium gerekli): <?php echo htmlspecialchars($lakebiumError); ?>
            </div>
        <?php endif; ?>
      <a href="/bildirimses" style="text-decoration: none; color: inherit;">
        <div class="sidebar-item" data-page="notifications" onclick="('notifications', 'bildirimses_content.php')"><i data-lucide="bell"></i> <?php echo $translations['settings']['sidebar']['notifications'] ?? 'Bildirimler'; ?></div>
        </a>
        <div class="sidebar-item" data-page="keybinds" onclick="loadPage('keybinds', 'keybinds.php')"><i data-lucide="keyboard"></i> <?php echo $translations['settings']['sidebar']['keybinds'] ?? 'Tuş Atamaları'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['accessibility'] ?? 'Erişebilirlik'; ?></div>
        <div class="sidebar-item" data-page="voice" onclick="loadPage('voice', 'voice.php')"><i data-lucide="mic"></i> <?php echo $translations['settings']['sidebar']['voice'] ?? 'Ses'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['advanced'] ?? 'Gelişmiş'; ?></div>
        <div class="sidebar-item" data-page="extra" onclick="loadPage('extra', 'extra.php')"><i data-lucide="circle-ellipsis"></i> <?php echo $translations['settings']['sidebar']['extra'] ?? 'Ekstra'; ?></div>
    </div>

        <!-- Content Container -->
        <div id="main-content" class="content-container">
            <h1>Profil Ayarları</h1>
            <!-- Profile Preview -->
            <div id="preview" class="profile-preview" data-aos="fade-right">
                <div class="preview-container">
                    <div class="full-background" id="preview-background-img"></div>
                    <div class="gradient-overlay"></div>
                    <div class="content-wrapper">
                        <!-- Profile Header -->
                        <div class="profile-header">
                            <div class="profile-header-content">
                                <div class="profile-avatar-section">
                                    <div class="profile-avatar-wrapper">
                                        <div class="profile-avatar">
                                            <?php if ($avatar_url): ?>
                                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar">
                                            <?php else: ?>
                                                <div class="avatar-letter"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="profile-info-section">
                                    <h2 class="profile-name" id="preview-display-username"><?php echo htmlspecialchars($display_username); ?></h2>
                                    <div class="country-info">
                                        <span class="flag-icon flag-icon-<?php echo $country; ?>"></span>
                                        <span><?php echo $translations['country_' . $country] ?? 'Unknown'; ?></span>
                                    </div>
                                    <p class="bio" id="preview-bio"><?php echo htmlspecialchars($bio); ?></p>
                                    <div class="preview-social-links">
                                        <?php if ($youtube_url): ?>
                                            <a href="<?php echo htmlspecialchars($youtube_url); ?>" class="preview-social-link" target="_blank"><i class="fab fa-youtube"></i> YouTube</a>
                                        <?php endif; ?>
                                        <?php if ($instagram_url): ?>
                                            <a href="<?php echo htmlspecialchars($instagram_url); ?>" class="preview-social-link" target="_blank"><i class="fab fa-instagram"></i> Instagram</a>
                                        <?php endif; ?>
                                        <?php if ($spotify_url): ?>
                                            <a href="<?php echo htmlspecialchars($spotify_url); ?>" class="preview-social-link" target="_blank"><i class="fab fa-spotify"></i> Spotify</a>
                                        <?php endif; ?>
                                        <?php if ($steam_url): ?>
                                            <a href="<?php echo htmlspecialchars($steam_url); ?>" class="preview-social-link" target="_blank"><i class="fab fa-steam"></i> Steam</a>
                                        <?php endif; ?>
                                        <?php if ($github_url): ?>
                                            <a href="<?php echo htmlspecialchars($github_url); ?>" class="preview-social-link" target="_blank"><i class="fab fa-github"></i> GitHub</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="profile-details">
                                <div class="profile-stats">
                                    <div class="stat-label">Takipçiler</div>
                                    <div class="stat-value">0</div>
                                </div>
                                <div class="profile-stats">
                                    <div class="stat-label">Takip Edilen</div>
                                    <div class="stat-value">0</div>
                                </div>
                            </div>
                        </div>
                        <!-- Profile Content -->
                        <div class="profile-content">
                            <!-- Left Sidebar -->
                            <div class="left-sidebar">
                                <div class="profile-music" id="preview-music" style="display: <?php echo $profile_music ? 'block' : 'none'; ?>;">
                                    <h3>Profil Müziği</h3>
                                    <div class="music-controls">
                                        <button><i class="fas fa-play"></i></button>
                                        <input type="range" min="0" max="100" value="0">
                                        <button><i class="fas fa-pause"></i></button>
                                    </div>
                                </div>
                                <div class="friends-list">
                                    <h3>Arkadaşlar</h3>
                                    <div class="friend">
                                        <div class="friend-avatar">
                                            <div class="friend-avatar-letter">A</div>
                                        </div>
                                        <span class="friend-username">Friend1</span>
                                    </div>
                                    <div class="friend">
                                        <div class="friend-avatar">
                                            <div class="friend-avatar-letter">B</div>
                                        </div>
                                        <span class="friend-username">Friend2</span>
                                    </div>
                                    <div class="friend">
                                        <div class="friend-avatar">
                                            <div class="friend-avatar-letter">C</div>
                                        </div>
                                        <span class="friend-username">Friend3</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Main Content -->
                            <div class="profile-main">
                                <div class="video-container" id="preview-video" style="display: <?php echo $video_url ? 'block' : 'none'; ?>;">
                                    <div class="video-placeholder">Örnek Video</div>
                                </div>
                                <h2>Gönderiler</h2>
                                <div class="post">
                                    <h3>Örnek Gönderi</h3>
                                    <p>Bu bir örnek gönderi! 😊</p>
                                </div>
                                <div class="profile-comments">
                                    <h2>Profil Yorumları</h2>
                                    <div class="comment">
                                        <div class="comment-avatar">
                                            <div class="comment-avatar-letter">T</div>
                                        </div>
                                        <div class="comment-content">
                                            <div class="comment-header">
                                                <span class="comment-username">TestUser</span>
                                                <span class="comment-date">01.01.2025</span>
                                            </div>
                                            <div class="comment-text">Harika profil!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Right Sidebar -->
                            <div class="right-sidebar" style="width: 182px;">
                                <div class="stats-sidebar">
                                    <h3>İstatistikler</h3>
                                    <div class="profile-stats">
                                        <div class="stat-label">Toplam Gönderi</div>
                                        <div class="stat-value">5</div>
                                    </div>
                                    <div class="profile-stats">
                                        <div class="stat-label">Toplam Yorum</div>
                                        <div class="stat-value">8</div>
                                    </div>
                                    <div class="profile-stats">
                                        <div class="stat-label">Arkadaş Sayısı</div>
                                        <div class="stat-value">12</div>
                                    </div>
                                    <div class="profile-stats">
                                        <div class="stat-label">Katılım Tarihi</div>
                                        <div class="stat-value">01.01.2025</div>
                                    </div>
                                    <div class="profile-stats">
                                        <div class="stat-label">Son Aktivite</div>
                                        <div class="stat-value">01.01.2025 12:00</div>
                                    </div>
                                    <div class="profile-stats">
                                        <div class="stat-label">Profil Görüntüleme</div>
                                        <div class="stat-value">100</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Editor Form -->
            <div id="editor" class="editor-form" data-aos="fade-left">
                <form id="profile-settings-form" action="update_profile.php" method="post" enctype="multipart/form-data">
                    <div class="form-section">
                        <h3>Hakkımda</h3>
                        <textarea name="bio" rows="5" placeholder="Kendin hakkında bir şeyler yaz..." maxlength="200" oninput="previewBio(this)"><?php echo htmlspecialchars($bio); ?></textarea>
                        <div class="char-counter" id="bio-counter">0 / 200</div>
                        <select name="country" id="country-select" onchange="updateCountry(this)">
                            <option value="tr" <?php echo $country === 'tr' ? 'selected' : ''; ?>>Türkiye</option>
                            <option value="fi" <?php echo $country === 'fi' ? 'selected' : ''; ?>>Finlandiya</option>
                            <option value="de" <?php echo $country === 'de' ? 'selected' : ''; ?>>Almanya</option>
                            <option value="fr" <?php echo $country === 'fr' ? 'selected' : ''; ?>>Fransa</option>
                            <option value="gb" <?php echo $country === 'gb' ? 'selected' : ''; ?>>İngiltere</option>
                            <option value="ru" <?php echo $country === 'ru' ? 'selected' : ''; ?>>Rusya</option>
                        </select>
                    </div>
                    <div class="form-section">
                        <h3>Görünen Ad</h3>
                        <div class="form-group">
                            <label for="display_username">Görünen Ad</label>
                            <input type="text" name="display_username" id="display_username" value="<?php echo htmlspecialchars($display_username); ?>" placeholder="Görünen adınızı girin..." maxlength="50" oninput="previewDisplayUsername(this)">
                            <div class="char-counter" id="display-username-counter">0 / 50</div>
                        </div>
                    </div>
                    <div class="form-section">
                        <h3>Profil Fotoğrafı</h3>
                        <div class="file-upload">
                            <div class="file-preview" id="preview-avatar-upload">
                                <?php if ($avatar_url): ?>
                                    <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar">
                                <?php else: ?>
                                    <span class="placeholder">Resim Yok</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="file-upload-label" for="avatar-upload">Dosya Seç</label>
                                <input type="file" id="avatar-upload" name="avatar" accept="image/webp,image/jpeg,image/png,image/gif" onchange="previewAvatar(this)">
                                <div class="file-info">(max 4.00 MB)</div>
                                <div class="file-error" id="avatar-error" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <h3>Özel Arka Plan</h3>
                        <div class="file-upload">
                            <div class="file-preview banner" id="preview-background">
                                <?php if ($background_url): ?>
                                    <img src="<?php echo htmlspecialchars($background_url); ?>" alt="Background">
                                <?php else: ?>
                                    <span class="placeholder">Resim Yok</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="file-upload-label" for="background-upload">Dosya Seç</label>
                                <input type="file" id="background-upload" name="background" accept="image/webp,image/jpeg,image/png,image/gif" onchange="previewBackground(this)">
                                <div class="file-info">(max 6.00 MB)</div>
                                <div class="file-error" id="background-error" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <h3>Müzik Ekle</h3>
                        <div class="file-upload">
                            <div class="file-preview" id="preview-music-upload">
                                <span class="placeholder"><?php echo $profile_music ? basename($profile_music) : 'Müzik Yok'; ?></span>
                            </div>
                            <div>
                                <label class="file-upload-label" for="music-upload">Dosya Seç</label>
                                <input type="file" id="music-upload" name="music" accept="audio/mpeg,audio/ogg,audio/wav" onchange="previewMusic(this)">
                                <div class="file-info">(max 10.00 MB)</div>
                                <div class="file-error" id="music-error" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <h3>Medya Ekle</h3>
                        <div class="file-upload">
                            <div class="file-preview" id="preview-video-upload">
                                <span class="placeholder"><?php echo $video_url ? basename($video_url) : 'Video Yok'; ?></span>
                            </div>
                            <div>
                                <label class="file-upload-label" for="video-upload">Dosya Seç</label>
                                <input type="file" id="video-upload" name="video" accept="video/mp4,video/webm,video/ogg,video/mov" onchange="previewVideo(this)">
                                <div class="file-info">(max 20.00 MB)</div>
                                <div class="file-error" id="video-error" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <h3>Tema Renkleri</h3>
                        <div class="color-picker-grid">
                            <div class="color-picker">
                                <label>Başlık Rengi</label>
                                <input type="color" name="profile_header_color" value="<?php echo $profile_header_color; ?>" oninput="updateThemeColors()">
                            </div>
                            <div class="color-picker">
                                <label>Metin Rengi</label>
                                <input type="color" name="profile_text_color" value="<?php echo $profile_text_color; ?>" oninput="updateThemeColors()">
                            </div>
                            <div class="color-picker">
                                <label>Düğme Rengi</label>
                                <input type="color" name="profile_button_color" value="<?php echo $profile_button_color; ?>" oninput="updateThemeColors()">
                            </div>
                        </div>
                    </div>
                    <button type="submit" id="save-button" disabled>Değişiklikleri Kaydet</button>
                </form>
                <div class="tip">
                    <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                    </svg>
                    <span>
                        Kullanıcı adını değiştirmek mi istiyorsun? 
                        <a href="/settings">Hesap ayarlarına git.</a>
                    </span>
                </div>
            </div>
        </div>

        <!-- Right Sidebar -->
        <div class="right-sidebar">
            <div class="tools__23e6b">
                <div class="container_c2b141">
                    <div class="closeButton_c2b141" aria-label="Close" role="button" tabindex="0" onclick="closeSettings()">
                        <svg aria-hidden="true" role="img" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M17.3 18.7a1 1 0 0 0 1.4-1.4L13.42 12l5.3-5.3a1 0 0 0-1.42-1.4L12 10.58l-5.3-5.3a1 0 0 0-1.4 1.42L10.58 12l-5.3 5.3a1 0 1 0 1.42 1.4L12 13.42l5.3 5.3Z" class=""></path>
                        </svg>
                    </div>
                    <div class="keybind_c2b141" aria-hidden="true">ESC</div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
   <script>
    AOS.init({
        duration: 800,
        easing: 'ease-in-out',
        once: true
    });

    lucide.createIcons();

    const form = document.getElementById('profile-settings-form');
    const saveButton = document.getElementById('save-button');

    // Enable save button on input/change
    form.addEventListener('input', () => {
        saveButton.disabled = false;
    });
    form.addEventListener('change', () => {
        saveButton.disabled = false;
    });

    // Form submission with AJAX
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(form);
        saveButton.disabled = true;
        saveButton.textContent = 'Kaydediliyor...';

        try {
            const response = await fetch('update_profile.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

          if (result.success) {
    showToast(result.message);
    document.getElementById('preview-display-username').textContent = formData.get('display_username') || '<?php echo htmlspecialchars($_SESSION['username']); ?>';
} else {
    showToast(result.message, true);
}
        } catch (error) {
            alert('Bir hata oluştu: ' + error.message);
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'Değişiklikleri Kaydet';
        }
    });

    function previewAvatar(input) {
        if (input.files && input.files[0]) {
            if (input.files[0].size > 4 * 1024 * 1024) {
                document.getElementById('avatar-error').textContent = 'Dosya boyutu 4MB\'ı aşamaz.';
                document.getElementById('avatar-error').style.display = 'block';
                input.value = '';
                return;
            }
            document.getElementById('avatar-error').style.display = 'none';
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewAvatar = document.querySelector('.preview-container .profile-avatar');
                const previewAvatarUpload = document.getElementById('preview-avatar-upload');
                previewAvatar.innerHTML = `<img src="${e.target.result}" alt="Avatar">`;
                previewAvatarUpload.innerHTML = `<img src="${e.target.result}" alt="Avatar">`;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
function showToast(message, isError = false) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    if (isError) {
        toast.style.backgroundColor = '#ed5151';
    }
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}
    function previewBackground(input) {
        if (input.files && input.files[0]) {
            if (input.files[0].size > 6 * 1024 * 1024) {
                document.getElementById('background-error').textContent = 'Dosya boyutu 6MB\'ı aşamaz.';
                document.getElementById('background-error').style.display = 'block';
                input.value = '';
                return;
            }
            document.getElementById('background-error').style.display = 'none';
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewBackground = document.getElementById('preview-background');
                const previewBackgroundImg = document.getElementById('preview-background-img');
                previewBackground.innerHTML = `<img src="${e.target.result}" alt="Background">`;
                previewBackgroundImg.style.background = `url('${e.target.result}')`;
                previewBackgroundImg.style.backgroundSize = 'cover';
                previewBackgroundImg.style.backgroundPosition = 'center';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function previewMusic(input) {
        if (input.files && input.files[0]) {
            if (input.files[0].size > 10 * 1024 * 1024) {
                document.getElementById('music-error').textContent = 'Dosya boyutu 10MB\'ı aşamaz.';
                document.getElementById('music-error').style.display = 'block';
                input.value = '';
                return;
            }
            document.getElementById('music-error').style.display = 'none';
            const previewMusic = document.getElementById('preview-music');
            const previewMusicUpload = document.getElementById('preview-music-upload');
            previewMusic.style.display = 'block';
            previewMusicUpload.innerHTML = `<span class="placeholder">${input.files[0].name}</span>`;
        }
    }

    function previewVideo(input) {
        if (input.files && input.files[0]) {
            if (input.files[0].size > 20 * 1024 * 1024) {
                document.getElementById('video-error').textContent = 'Dosya boyutu 20MB\'ı aşamaz.';
                document.getElementById('video-error').style.display = 'block';
                input.value = '';
                return;
            }
            document.getElementById('video-error').style.display = 'none';
            const previewVideo = document.getElementById('preview-video');
            const previewVideoUpload = document.getElementById('preview-video-upload');
            previewVideo.style.display = 'block';
            previewVideoUpload.innerHTML = `<span class="placeholder">${input.files[0].name}</span>`;
        }
    }

    function previewBio(textarea) {
        const bio = textarea.value;
        const previewBio = document.getElementById('preview-bio');
        const bioCounter = document.getElementById('bio-counter');
        if (bio) {
            previewBio.textContent = bio;
        } else {
            previewBio.textContent = 'Biyografi yok...';
        }
        bioCounter.textContent = `${bio.length} / 200`;
    }

    function updateCountry(select) {
        const countryCode = select.value;
        const countryNames = {
            'tr': 'Türkiye',
            'fi': 'Finlandiya',
            'de': 'Almanya',
            'fr': 'Fransa',
            'gb': 'İngiltere',
            'ru': 'Rusya'
        };
        const countryInfo = document.querySelector('.country-info');
        countryInfo.innerHTML = `
            <span class="flag-icon flag-icon-${countryCode}"></span>
            <span>${countryNames[countryCode]}</span>
        `;
    }

    function updateThemeColors() {
        const root = document.documentElement;
        const headerColor = document.querySelector('input[name="profile_header_color"]').value;
        const textColor = document.querySelector('input[name="profile_text_color"]').value;
        const buttonColor = document.querySelector('input[name="profile_button_color"]').value;

        root.style.setProperty('--profile-header-color', headerColor);
        root.style.setProperty('--profile-text-color', textColor);
        root.style.setProperty('--profile-button-color', buttonColor);
    }

    function closeSettings() {
        window.location.href = '/directmessages';
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeSettings();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const sidebarItems = document.querySelectorAll('.sidebar-item');
        sidebarItems.forEach(item => {
            item.addEventListener('click', () => {
                sidebarItems.forEach(i => i.classList.remove('active'));
                item.classList.add('active');
            });
        });

        // Initialize bio counter
        const bioTextarea = document.querySelector('textarea[name="bio"]');
        previewBio(bioTextarea);

        // Initialize country selection
        const countrySelect = document.getElementById('country-select');
        updateCountry(countrySelect);

        // Initialize display username counter
        const displayUsernameInput = document.querySelector('input[name="display_username"]');
        if (displayUsernameInput) {
            previewDisplayUsername(displayUsernameInput);
        }
    });

    // Mobile swipe functionality
    const sidebar = document.getElementById("main-content");
    const leftPanel = document.getElementById("movesidebar");

    function enableSwipeSidebar() {
        const sidebarWidth = sidebar.offsetWidth;

        let isDragging = false;
        let startX = 0;
        let currentTranslate = sidebarWidth;
        let previousTranslate = sidebarWidth;

        sidebar.style.width = `${sidebarWidth}px`;
        sidebar.style.transform = `translateX(${sidebarWidth}px)`;
        sidebar.style.transition = 'transform 0.1s ease-out';

        function handleTouchStart(e) {
            startX = e.touches[0].clientX;
            isDragging = true;
            previousTranslate = currentTranslate;
            sidebar.style.transition = 'none';
        }

        function handleTouchMove(e) {
            if (!isDragging) return;

            const currentX = e.touches[0].clientX;
            const diff = currentX - startX;
            currentTranslate = previousTranslate + diff;

            if (currentTranslate < 0) currentTranslate = 0;
            if (currentTranslate > sidebarWidth) currentTranslate = sidebarWidth;

            sidebar.style.transform = `translateX(${currentTranslate}px)`;
        }

        function handleTouchEnd() {
            isDragging = false;
            sidebar.style.transition = 'transform 0.2s ease-out';

            const threshold = sidebarWidth * 0.5;

            if (currentTranslate < threshold) {
                openSidebar();
            } else {
                closeSidebar();
            }
        }

        function openSidebar() {
            currentTranslate = 0;
            sidebar.style.transform = 'translateX(0)';
        }

        function closeSidebar() {
            currentTranslate = sidebarWidth;
            sidebar.style.transform = `translateX(${sidebarWidth}px)`;
        }

        const listeners = [
            { el: leftPanel, type: "touchstart", fn: handleTouchStart },
            { el: leftPanel, type: "touchmove", fn: handleTouchMove },
            { el: leftPanel, type: "touchend", fn: handleTouchEnd },
            { el: sidebar, type: "touchstart", fn: handleTouchStart },
            { el: sidebar, type: "touchmove", fn: handleTouchMove },
            { el: sidebar, type: "touchend", fn: handleTouchEnd },
        ];

        listeners.forEach(({ el, type, fn }) => {
            el.addEventListener(type, fn, { passive: false });
        });
    }

    if (window.innerWidth <= 768) {
        enableSwipeSidebar();
    }

    function previewDisplayUsername(input) {
        const displayUsername = input.value;
        const previewDisplayUsername = document.getElementById('preview-display-username');
        const displayUsernameCounter = document.getElementById('display-username-counter');
        if (displayUsername) {
            previewDisplayUsername.textContent = displayUsername;
        } else {
            previewDisplayUsername.textContent = '<?php echo htmlspecialchars($_SESSION['username']); ?>';
        }
        displayUsernameCounter.textContent = `${displayUsername.length} / 50`;
    }
</script>

    <noscript>
        <div>
            <h1>JavaScript Devre Dışı</h1>
            <p>Bu uygulamayı kullanmak için JavaScript'i etkinleştirmeniz gerekiyor.</p>
            <a href="profile" target="_blank">Yeniden Yükle</a>
        </div>
    </noscript>
</body>
</html>