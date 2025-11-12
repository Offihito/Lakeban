<?php
require_once 'db_connection.php';


// Default language
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
    return [];
}

$translations = loadLanguage($lang);

// Default theme settings
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

// Fetch theme settings from database
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

$isLakebiumUser = false;
if (isset($_SESSION['user_id']) && isset($conn)) {
    try {
        // Check Lakebium subscription status
        $lakebiumStmt = $conn->prepare("SELECT status FROM lakebium WHERE user_id = ? AND status = 'active'");
        $lakebiumStmt->bind_param("i", $_SESSION['user_id']);
        $lakebiumStmt->execute();
        $result = $lakebiumStmt->get_result();
        $isLakebiumUser = $result->fetch_assoc() !== null;
        $lakebiumStmt->close();

        // Fetch user theme settings
        $userStmt = $conn->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
        $userStmt->bind_param("i", $_SESSION['user_id']);
        $userStmt->execute();
        $result = $userStmt->get_result();
        $userData = $result->fetch_assoc();
        if ($userData) {
            // If user is not a Lakebium subscriber and theme is custom, revert to default
            $currentTheme = ($userData['theme'] === 'custom' && !$isLakebiumUser) ? $defaultTheme : ($userData['theme'] ?? $defaultTheme);
            $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
            $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
        }
        $userStmt->close();
    } catch (Exception $e) {
        error_log('Theme settings fetch error: ' . $e->getMessage());
    }
}

// Fetch user profile picture from database
$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";
$profilePicture = $defaultProfilePicture;

if (isset($_SESSION['user_id']) && isset($conn)) {
    $stmt = $conn->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $profilePicture = !empty($row['avatar_url']) ? "../" . htmlspecialchars($row['avatar_url']) : $defaultProfilePicture;
    }
    $stmt->close();
}

// Get username from session, fallback to 'Guest'
$username = $_SESSION['username'] ?? ($translations['header']['guest'] ?? 'Misafir');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>" class="<?php echo htmlspecialchars($currentTheme); ?>-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['header']['title'] ?? 'Lakeban - Modern Navigation'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #121212;
            --secondary-bg: #1e1e1e;
            --accent-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
            --text-primary: #f0f0f0;
            --text-secondary: #a0a0a0;
            --border-color: rgba(255, 255, 255, 0.08);
            --dropdown-bg: #282828;
            --dropdown-item-hover: rgba(0, 230, 118, 0.15);
            --shadow-light: rgba(0, 0, 0, 0.3);
            --shadow-heavy: rgba(0, 0, 0, 0.6);
            --red-alert: #ff6b6b;
            --transition-speed: 0.3s ease-out;
            --custom-background-color: <?php echo htmlspecialchars($currentCustomColor); ?>;
            --custom-secondary-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
        }

        /* Light Theme */
        .light-theme body {
            background-color: #F2F3F5;
            color: #2E3338;
        }
        .light-theme .header {
            background-color: #FFFFFF;
            border-bottom-color: #e3e5e8;
        }
        .light-theme .header-logo,
        .light-theme .header-main-nav a,
        .light-theme .profile-btn span {
            color: #4F5660;
        }
        .light-theme .header-main-nav a:hover,
        .light-theme .profile-btn:hover span,
        .light-theme .profile-btn.active span {
            color: var(--accent-color);
        }
        .light-theme .header-main-nav a::before {
            background-color: rgba(0, 230, 118, 0.1);
        }
        .light-theme .search-form {
            background-color: #F8F9FA;
            border-color: #e3e5e8;
        }
        .light-theme .search-form:focus-within {
            background-color: #FFFFFF;
            border-color: var(--accent-color);
        }
        .light-theme .search-input,
        .light-theme .search-input::placeholder {
            color: #4F5660;
        }
        .light-theme .header-btn,
        .light-theme .profile-btn i,
        .light-theme .sidebar-left .sidebar-nav-links li a,
        .light-theme .sidebar-left .sidebar-nav-links li a i {
            color: #4F5660;
        }
        .light-theme .header-btn:hover,
        .light-theme .profile-btn:hover i,
        .light-theme .profile-btn.active i,
        .light-theme .sidebar-left .sidebar-nav-links li a:hover,
        .light-theme .sidebar-left .sidebar-nav-links li a:hover i {
            color: var(--accent-color);
        }
        .light-theme .profile-btn {
            background-color: #F8F9FA;
            border-color: #e3e5e8;
        }
        .light-theme .profile-btn:hover,
        .light-theme .profile-btn.active {
            background-color: rgba(0, 230, 118, 0.1);
        }
        .light-theme .profile-dropdown {
            background-color: #FFFFFF;
            border-color: #e3e5e8;
        }
        .light-theme .profile-dropdown ul li a {
            color: #4F5660;
        }
        .light-theme .profile-dropdown ul li a:hover {
            background-color: rgba(0, 230, 118, 0.1);
            color: var(--accent-color);
        }
        .light-theme .sidebar-left {
            background-color: #FFFFFF;
            border-right-color: #e3e5e8;
        }
        .light-theme .sidebar-left .sidebar-nav-links li a:hover {
            background-color: rgba(0, 230, 118, 0.1);
        }
        .light-theme .divider {
            background-color: #e3e5e8;
        }

        /* Dark Theme */
        .dark-theme body {
            background-color: #1E1E1E;
            color: #ffffff;
        }
        .dark-theme .header {
            background-color: #242424;
            border-bottom-color: #2f3136;
        }
        .dark-theme .header-logo,
        .dark-theme .header-main-nav a,
        .dark-theme .profile-btn span {
            color: #b9bbbe;
        }
        .dark-theme .header-main-nav a:hover,
        .dark-theme .profile-btn:hover span,
        .dark-theme .profile-btn.active span {
            color: var(--accent-color);
        }
        .dark-theme .header-main-nav a::before {
            background-color: rgba(0, 230, 118, 0.15);
        }
        .dark-theme .search-form {
            background-color: #1E1E1E;
            border-color: #2f3136;
        }
        .dark-theme .search-form:focus-within {
            background-color: #2f3136;
            border-color: var(--accent-color);
        }
        .dark-theme .search-input,
        .dark-theme .search-input::placeholder {
            color: #b9bbbe;
        }
        .dark-theme .header-btn,
        .dark-theme .profile-btn i,
        .dark-theme .sidebar-left .sidebar-nav-links li a,
        .dark-theme .sidebar-left .sidebar-nav-links li a i {
            color: #b9bbbe;
        }
        .dark-theme .header-btn:hover,
        .dark-theme .profile-btn:hover i,
        .dark-theme .profile-btn.active i,
        .dark-theme .sidebar-left .sidebar-nav-links li a:hover,
        .dark-theme .sidebar-left .sidebar-nav-links li a:hover i {
            color: var(--accent-color);
        }
        .dark-theme .profile-btn {
            background-color: #1E1E1E;
            border-color: #2f3136;
        }
        .dark-theme .profile-btn:hover,
        .dark-theme .profile-btn.active {
            background-color: rgba(0, 230, 118, 0.15);
        }
        .dark-theme .profile-dropdown {
            background-color: #2f3136;
            border-color: #2f3136;
        }
        .dark-theme .profile-dropdown ul li a {
            color: #b9bbbe;
        }
        .dark-theme .profile-dropdown ul li a:hover {
            background-color: rgba(0, 230, 118, 0.15);
            color: var(--accent-color);
        }
        .dark-theme .sidebar-left {
            background-color: #242424;
            border-right-color: #2f3136;
        }
        .dark-theme .sidebar-left .sidebar-nav-links li a:hover {
            background-color: rgba(0, 230, 118, 0.15);
        }
        .dark-theme .divider {
            background-color: #2f3136;
        }

        /* Custom Theme */
        .custom-theme body {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            color: #ffffff;
        }
        .custom-theme .header {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            border-bottom-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .header-logo,
        .custom-theme .header-main-nav a,
        .custom-theme .profile-btn span {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .header-main-nav a:hover,
        .custom-theme .profile-btn:hover span,
        .custom-theme .profile-btn.active span {
            color: var(--custom-secondary-color);
        }
        .custom-theme .header-main-nav a::before {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 20%, transparent);
        }
        .custom-theme .search-form {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .search-form:focus-within {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            border-color: var(--custom-secondary-color);
        }
        .custom-theme .search-input,
        .custom-theme .search-input::placeholder {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .header-btn,
        .custom-theme .profile-btn i,
        .custom-theme .sidebar-left .sidebar-nav-links li a,
        .custom-theme .sidebar-left .sidebar-nav-links li a i {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .header-btn:hover,
        .custom-theme .profile-btn:hover i,
        .custom-theme .profile-btn.active i,
        .custom-theme .sidebar-left .sidebar-nav-links li a:hover,
        .custom-theme .sidebar-left .sidebar-nav-links li a:hover i {
            color: var(--custom-secondary-color);
        }
        .custom-theme .profile-btn {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .profile-btn:hover,
        .custom-theme .profile-btn.active {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 20%, transparent);
        }
        .custom-theme .profile-dropdown {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .profile-dropdown ul li a {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .profile-dropdown ul li a:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 20%, transparent);
            color: var(--custom-secondary-color);
        }
        .custom-theme .sidebar-left {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            border-right-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .sidebar-left .sidebar-nav-links li a:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 20%, transparent);
        }
        .custom-theme .divider {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding-top: 60px;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        .header {
            background-color: var(--secondary-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0.4rem 1.5rem;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 4px 15px var(--shadow-heavy);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: background-color var(--transition-speed);
        }

        .header-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1500px;
            margin: 0 auto;
            height: 50px;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-primary);
            margin-right: 2rem;
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: -0.05em;
            transition: color var(--transition-speed), transform var(--transition-speed);
        }

        .header-logo:hover {
            color: var(--accent-color);
            transform: translateY(-1px);
        }

        .header-logo img {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            transition: transform var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .header-logo:hover img {
            transform: scale(1.08) rotate(5deg);
            box-shadow: 0 0 12px rgba(0, 230, 118, 0.4);
        }

        .header-main-nav {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .header-main-nav a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            transition: all var(--transition-speed);
            position: relative;
            overflow: hidden;
        }

        .header-main-nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 230, 118, 0.08);
            transition: transform var(--transition-speed) ease-in-out;
            z-index: 0;
            border-radius: 8px;
        }

        .header-main-nav a:hover::before {
            transform: translateX(100%);
        }

        .header-main-nav a span {
            position: relative;
            z-index: 1;
        }

        .header-main-nav a:hover {
            color: var(--accent-color);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px var(--shadow-light);
        }

        .header-main-nav a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -6px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--accent-color);
            transition: width var(--transition-speed) ease-in-out, background-color var(--transition-speed);
        }

        .header-main-nav a:hover::after {
            width: calc(100% - 20px);
            background-color: var(--accent-color);
        }

        .header-search {
            flex: 1;
            margin: 0 2rem;
            max-width: 400px;
        }

        .search-form {
            display: flex;
            align-items: center;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 0.5rem 1rem;
            box-shadow: inset 0 1px 4px var(--shadow-light);
            transition: all var(--transition-speed);
        }

        .search-form:focus-within {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(0, 230, 118, 0.3), inset 0 1px 4px var(--shadow-heavy);
            background-color: #1a1a1a;
        }

        .search-input {
            background: none;
            border: none;
            color: var(--text-primary);
            width: 100%;
            outline: none;
            font-size: 1rem;
            padding-left: 0.6rem;
        }

        .search-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.7;
            font-style: italic;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-right: 0.5rem;
        }

        .header-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            padding: 0.6rem;
            cursor: pointer;
            font-size: 1.4rem;
            position: relative;
            border-radius: 50%;
            transition: all var(--transition-speed) cubic-bezier(0.25, 0.8, 0.25, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 6px rgba(0,0,0,0.3);
        }

        .header-btn:hover {
            color: var(--accent-color);
            background-color: rgba(0, 230, 118, 0.1);
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 4px 12px rgba(0, 230, 118, 0.2);
        }

        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background-color: var(--red-alert);
            color: white;
            border-radius: 50%;
            padding: 4px 7px;
            font-size: 0.7em;
            line-height: 1;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            border: 1.5px solid var(--secondary-bg);
            animation: pulse 1.2s infinite ease-out;
            box-shadow: 0 0 0 1px rgba(255, 107, 107, 0.2);
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.08); opacity: 0.9; }
            100% { transform: scale(1); opacity: 1; }
        }

        .profile-menu-container {
            position: relative;
            margin-right: 0.8rem;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            background: var(--primary-bg);
            border: 1px solid var(--border-color);
            cursor: pointer;
            padding: 0.4rem 1rem;
            border-radius: 25px;
            transition: all var(--transition-speed);
            box-shadow: 0 2px 8px var(--shadow-light);
        }

        .profile-btn:hover,
        .profile-btn.active {
            background-color: rgba(0, 230, 118, 0.15);
            border-color: var(--accent-color);
            box-shadow: 0 4px 12px rgba(0, 230, 118, 0.2), 0 0 0 1.5px rgba(0, 230, 118, 0.15);
            transform: translateY(-1px);
        }

        .profile-btn img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.75rem;
            border: 2px solid var(--accent-color);
            box-shadow: 0 0 0 1.5px var(--secondary-bg);
            transition: transform var(--transition-speed);
        }

        .profile-btn:hover img {
            transform: scale(1.02);
        }

        .profile-btn span {
            color: var(--text-primary);
            font-weight: 500;
            margin-right: 0.75rem;
            font-size: 1rem;
            white-space: nowrap;
        }

        .profile-btn i {
            color: var(--text-secondary);
            font-size: 0.9rem;
            transition: transform var(--transition-speed);
        }

        .profile-btn.active i {
            transform: rotate(180deg);
            color: var(--accent-color);
        }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 15px);
            right: 0;
            background-color: var(--dropdown-bg);
            border-radius: 12px;
            box-shadow: 0 8px 25px var(--shadow-heavy);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-20px) scale(0.95);
            transition: opacity 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), visibility 0.3s;
            z-index: 1001;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .profile-dropdown ul {
            list-style: none;
            padding: 0.6rem 0;
            margin: 0;
        }

        .profile-dropdown ul li a {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1.2rem;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.95rem;
            transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
        }

        .profile-dropdown ul li a:hover {
            background-color: var(--dropdown-item-hover);
            color: var(--accent-color);
        }

        .profile-dropdown ul li a i {
            font-size: 1.1rem;
            color: var(--text-secondary);
            transition: color 0.2s ease;
        }

        .profile-dropdown ul li a:hover i {
            color: var(--accent-color);
        }

        .profile-dropdown .divider {
            height: 1px;
            background-color: var(--border-color);
            margin: 0.6rem 1.2rem;
        }

        .hamburger-btn {
            display: none;
            font-size: 1.8rem;
            margin-right: 0.5rem;
        }

        .sidebar-left {
            position: fixed;
            top: 0;
            left: -300px;
            width: 280px;
            height: 100vh;
            background-color: var(--secondary-bg);
            border-right: 1px solid var(--border-color);
            z-index: 999;
            transition: left 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);
            box-shadow: 6px 0 20px var(--shadow-heavy);
            padding-top: 70px;
            overflow-y: auto;
        }

        .sidebar-left.active {
            left: 0;
        }

        .sidebar-left .sidebar-nav-links {
            list-style: none;
            padding: 1.2rem 0.8rem;
            margin: 0;
        }

        .sidebar-left .sidebar-nav-links li a {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 1.2rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 1.05rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all var(--transition-speed);
        }

        .sidebar-left .sidebar-nav-links li a:hover {
            background-color: rgba(0, 230, 118, 0.15);
            color: var(--accent-color);
            transform: translateX(6px);
            box-shadow: 0 3px 8px var(--shadow-light);
        }

        .sidebar-left .sidebar-nav-links li a i {
            font-size: 1.2rem;
            color: var(--text-secondary);
            transition: color var(--transition-speed);
        }

        .sidebar-left .sidebar-nav-links li a:hover i {
            color: var(--accent-color);
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            z-index: 998;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-out;
        }

        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        @media (max-width: 1024px) {
            .header-search {
                margin: 0 1rem;
                max-width: 280px;
            }
            .header-nav {
                height: 52px;
            }
            .header-logo {
                margin-right: 1.5rem;
                font-size: 1.4rem;
            }
            .header-logo img {
                width: 38px;
                height: 38px;
            }
            .header-main-nav {
                gap: 1rem;
            }
            .header-main-nav a {
                padding: 0.5rem 0.8rem;
                font-size: 0.95rem;
            }
            .header-actions {
                gap: 0.8rem;
                padding-right: 0.4rem;
            }
            .header-btn {
                padding: 0.5rem;
                font-size: 1.3rem;
            }
            .profile-btn {
                padding: 0.3rem 0.6rem;
            }
            .profile-btn img {
                width: 36px;
                height: 36px;
                margin-right: 0.6rem;
            }
            .profile-btn span {
                font-size: 0.95rem;
                margin-right: 0.6rem;
            }
            .profile-menu-container {
                margin-right: 0.6rem;
            }
        }

        @media (max-width: 768px) {
            .hamburger-btn {
                display: block;
            }
            .header-main-nav,
            .header-search,
            .profile-menu-container {
                display: none;
            }
            .header-actions .header-btn:not(.hamburger-btn) {
                display: none;
            }
            .header-nav {
                justify-content: space-between;
                height: 55px;
                padding: 0.6rem 1rem;
            }
            .header-logo {
                margin-right: 0;
                flex-grow: 1;
                justify-content: flex-start;
                font-size: 1.6rem;
            }
            .header-logo img {
                width: 40px;
                height: 40px;
            }
            .header-btn {
                font-size: 1.6rem;
                padding: 0.6rem;
            }
            .header-actions .header-btn:not(.hamburger-btn).notification-btn {
                display: flex;
            }
            .profile-menu-container {
                margin-right: 0;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 0.5rem 0.8rem;
            }
            .header-logo {
                font-size: 1.5rem;
            }
            .header-logo img {
                width: 38px;
                height: 38px;
            }
            .hamburger-btn {
                font-size: 1.5rem;
                padding: 0.5rem;
            }
            .sidebar-left {
                width: 250px;
                left: -270px;
            }
            .sidebar-left .sidebar-nav-links li a {
                font-size: 1rem;
                padding: 0.7rem 1rem;
            }
            .sidebar-left .sidebar-nav-links li a i {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="overlay" id="sidebarOverlay"></div>

    <header class="header">
        <nav class="header-nav">
            <a href="/posts" class="header-logo">
                <img src="LakebanAssets/icon.png" alt="<?php echo $translations['header']['logo_alt'] ?? 'Lakeban Logo'; ?>">
                <span><?php echo $translations['header']['logo_text'] ?? 'Lakeban'; ?></span>
            </a>

            <div class="header-main-nav">
                <a href="/posts"><span><?php echo $translations['header']['posts'] ?? 'Gönderiler'; ?></span></a>
                <a href="/topluluklar"><span><?php echo $translations['header']['communities'] ?? 'Topluluklar'; ?></span></a>
                <a href="/directmessages"><span><?php echo $translations['header']['messages'] ?? 'Mesajlar'; ?></span></a>
                <a href="/explore"><span><?php echo $translations['header']['lakealts'] ?? 'Lakealt\'lar'; ?></span></a>
            </div>

            <div class="header-search">
                <form action="/search" method="GET" class="search-form">
                    <i class="fas fa-search" style="color: var(--text-secondary);"></i>
                    <input type="text" name="q" placeholder="<?php echo $translations['header']['search_placeholder'] ?? 'Ara...'; ?>" class="search-input">
                </form>
            </div>

            <div class="header-actions">
                <div class="profile-menu-container">
                    <button class="profile-btn" id="profileMenuBtn">
                        <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="<?php echo $translations['header']['profile_alt'] ?? 'Profil Resmi'; ?>">
                        <span><?php echo htmlspecialchars($username); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="profile-dropdown" id="profileDropdown">
                        <ul>
                            <li><a href="/profile-page?username=<?php echo htmlspecialchars($username); ?>"><i class="fas fa-user"></i> <?php echo $translations['header']['profile'] ?? 'Profilim'; ?></a></li>
                            <li><a href="/profile"><i class="fas fa-cog"></i> <?php echo $translations['header']['account_settings'] ?? 'Hesap Detayları'; ?></a></li>
                            <li class="divider"></li>
                            <li><a href="/logout"><i class="fas fa-sign-out-alt"></i> <?php echo $translations['header']['logout'] ?? 'Çıkış Yap'; ?></a></li>
                        </ul>
                    </div>
                </div>

                <button class="header-btn hamburger-btn" title="<?php echo $translations['header']['menu'] ?? 'Menü'; ?>" id="hamburgerBtn">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </nav>
    </header>

    <div class="sidebar-left" id="mobileSidebar">
        <ul class="sidebar-nav-links">
            <li><a href="/posts"><i class="fas fa-newspaper"></i> <?php echo $translations['header']['posts'] ?? 'Gönderiler'; ?></a></li>
            <li><a href="/topluluklar"><i class="fas fa-users"></i> <?php echo $translations['header']['communities'] ?? 'Topluluklar'; ?></a></li>
            <li><a href="/directmessages"><i class="fas fa-comments"></i> <?php echo $translations['header']['messages'] ?? 'Mesajlar'; ?></a></li>
            <li><a href="/explore"><i class="fas fa-compass"></i> <?php echo $translations['header']['lakealts'] ?? 'Lakealt\'lar'; ?></a></li>
            <li class="divider" style="margin: 1rem 0.8rem;"></li>
            <li><a href="/profile-page?username=<?php echo htmlspecialchars($username); ?>"><i class="fas fa-user-circle"></i> <?php echo $translations['header']['profile'] ?? 'Profilim'; ?></a></li>
            <li><a href="/profile"><i class="fas fa-user-cog"></i> <?php echo $translations['header']['account_settings'] ?? 'Hesap Ayarları'; ?></a></li>
            <li><a href="/logout"><i class="fas fa-door-open"></i> <?php echo $translations['header']['logout'] ?? 'Çıkış Yap'; ?></a></li>
        </ul>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const profileMenuBtn = document.getElementById("profileMenuBtn");
            const profileDropdown = document.getElementById("profileDropdown");
            const hamburgerBtn = document.getElementById("hamburgerBtn");
            const mobileSidebar = document.getElementById("mobileSidebar");
            const sidebarOverlay = document.getElementById("sidebarOverlay");

            if (profileMenuBtn && profileDropdown) {
                profileMenuBtn.addEventListener("click", (event) => {
                    event.stopPropagation();
                    profileDropdown.classList.toggle("show");
                    profileMenuBtn.classList.toggle("active");
                });

                document.addEventListener("click", (event) => {
                    if (!profileMenuBtn.contains(event.target) && !profileDropdown.contains(event.target)) {
                        profileDropdown.classList.remove("show");
                        profileMenuBtn.classList.remove("active");
                    }
                });
            }

            if (hamburgerBtn && mobileSidebar && sidebarOverlay) {
                hamburgerBtn.addEventListener("click", () => {
                    mobileSidebar.classList.toggle("active");
                    sidebarOverlay.classList.toggle("active");
                });

                sidebarOverlay.addEventListener("click", () => {
                    mobileSidebar.classList.remove("active");
                    sidebarOverlay.classList.remove("active");
                });

                mobileSidebar.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', () => {
                        mobileSidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                    });
                });
            }
        });
    </script>
</body>
</html>