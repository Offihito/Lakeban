<?php
// Hata raporlamayı açalım
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';

// URL'den kullanıcı adını al
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri_parts = explode('/', $request_uri);

// URL'den kullanıcı adını al
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri_parts = explode('/', $request_uri);

if (count($uri_parts) >= 3 && $uri_parts[1] === 'user') {
    $profile_username = $uri_parts[2];
} else {
    $profile_username = isset($_GET['username']) ? $_GET['username'] : null;
}

// Kullanıcı adı kontrolü: null veya boş string ise yönlendir
if ($profile_username === null || $profile_username === '') {
    header("Location: index.php");
    exit;
}

// Veritabanından kullanıcı bilgilerini çek (showcase_image_url eklendi)
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.created_at, u.post_count, up.avatar_url, up.avatar_frame_url, up.avatar_frame_color, up.bio, up.background_url, up.profile_music, up.profile_theme, u.last_activity,
           up.profile_header_color, up.profile_text_color, up.profile_button_color, up.video_url, up.profile_views, up.country,
           up.youtube_url, up.instagram_url, up.spotify_url, up.steam_url, up.github_url, up.showcase_image_url, u.badges, up.display_username
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.username = ?
");
$stmt->bind_param("s", $profile_username);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

if (!$user_data) {
    header("Location: index.php");
    exit;
}

// Giriş yapmış kullanıcı bilgisi
$isLoggedIn = isset($_SESSION['user_id']);
$current_username = $isLoggedIn ? $_SESSION['username'] : null;
$current_user_id = $isLoggedIn ? $_SESSION['user_id'] : null;

// Varsayılan dil ve dil yükleme
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

// Biyografi kontrolü
$bio = !empty($user_data['bio']) ? htmlspecialchars(substr($user_data['bio'], 0, 200)) : ($translations['profile_no_bio'] ?? 'No bio available.');

// Profil görüntüleme sayacını artır
if ($profile_username && $user_data) {
    if (!isset($_SESSION['viewed_profiles'])) {
        $_SESSION['viewed_profiles'] = [];
    }
    if (!in_array($user_data['id'], $_SESSION['viewed_profiles']) && (!$isLoggedIn || $current_user_id != $user_data['id'])) {
        $conn->query("UPDATE user_profiles SET profile_views = profile_views + 1 WHERE user_id = " . $user_data['id']);
        $_SESSION['viewed_profiles'][] = $user_data['id'];
    }
}

// Profil yorumlarını çeken sorgu
$comments_stmt = $conn->prepare("
    SELECT pc.*, u.username, up.avatar_url
    FROM profile_comments pc
    JOIN users u ON pc.commenter_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE pc.profile_user_id = ?
    ORDER BY pc.created_at DESC
");
$comments_stmt->bind_param("i", $user_data['id']);
$comments_stmt->execute();
$comments_result = $comments_stmt->get_result();
$comment_count = $comments_result->num_rows;

// İstatistikler için sorgu
$stats_stmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM friends WHERE user_id = ? OR friend_id = ?) as friend_count
");
$stats_stmt->bind_param("ii", $user_data['id'], $user_data['id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats_data = $stats_result->fetch_assoc();

// Kullanıcının üye olduğu Lakealt'ları çeken sorgu
$lakealts_stmt = $conn->prepare("
    SELECT l.name, l.avatar_url, l.id
    FROM lakealts l
    JOIN lakealt_members lm ON l.id = lm.lakealt_id
    WHERE lm.user_id = ?
    LIMIT 3
");
$lakealts_stmt->bind_param("i", $user_data['id']);
$lakealts_stmt->execute();
$lakealts_result = $lakealts_stmt->get_result();

// Arkadaşlar listesini çek
$friends_list_stmt = $conn->prepare("
    SELECT
        u.id AS friend_id,
        u.username AS friend_username,
        up.avatar_url AS friend_avatar_url,
        u.last_activity AS friend_last_activity
    FROM
        friends f
    JOIN
        users u ON (f.user_id = u.id AND f.friend_id = ?) OR (f.friend_id = u.id AND f.user_id = ?)
    LEFT JOIN
        user_profiles up ON u.id = up.user_id
    WHERE
        u.id != ?
    LIMIT 6
");
$friends_list_stmt->bind_param("iii", $user_data['id'], $user_data['id'], $user_data['id']);
$friends_list_stmt->execute();
$friends_list_result = $friends_list_stmt->get_result();
$friends_count_for_preview = $friends_list_result->num_rows;

// Rozetler için sorgu
$badges_stmt = $conn->prepare("
    SELECT b.id, b.name, b.description, b.icon_url
    FROM user_badges ub
    JOIN badges b ON ub.badge_id = b.id
    WHERE ub.user_id = ?
");
$badges_stmt->bind_param("i", $user_data['id']);
$badges_stmt->execute();
$badges_result = $badges_stmt->get_result();
$user_badges = [];
while ($badge = $badges_result->fetch_assoc()) {
    $user_badges[] = $badge;
}

// Gönderiler için sorgu
$post_stmt = $conn->prepare("SELECT title, content FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$post_stmt->bind_param("i", $user_data['id']);
$post_stmt->execute();
$post_result = $post_stmt->get_result();

// Kullanıcı renklerini ayarlama
$primary_color = !empty($user_data['profile_header_color']) ? $user_data['profile_header_color'] : '#00aaff';
$secondary_color = !empty($user_data['profile_button_color']) ? $user_data['profile_button_color'] : '#00ff7f';
$text_color = !empty($user_data['profile_text_color']) ? $user_data['profile_text_color'] : '#e1e1e1';

// Renk kodunu RGB'ye çevirme
list($r, $g, $b) = sscanf($primary_color, "#%02x%02x%02x");
$container_bg = "rgba($r, $g, $b, 0.1)"; // %10 opaklık

?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profile_username ?? 'Profile'); ?> - Profil</title>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
      <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.5.0/css/flag-icon.min.css">
    <style>
        /* HEADER STYLES FROM HOMEPAGE */
        :root {
            --dark-bg: #0f1114;
            --darker-bg: #0a0c0f;
            --card-bg: #1e2025;
            --card-border: #2c2e33;
            --text-light: #e0e0e0;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --primary-light: #81C784;
            --success: #4CAF50;
            --danger: #F44336;
            --warning: #FFC107;
            --transition: all 0.3s ease;
            --shadow-sm: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-md: 0 8px 24px rgba(0,0,0,0.2);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
        }
        
        /* HEADER STYLES */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 5%;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(10, 12, 15, 0.95);
            backdrop-filter: blur(10px);
            z-index: 1000;
            border-bottom: 1px solid var(--card-border);
            transition: var(--transition);
        }

        .header.scrolled {
            background: rgba(10, 12, 15, 0.98);
            box-shadow: var(--shadow-sm);
            padding: 0.7rem 5%;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            transition: var(--transition);
        }

        .header.scrolled .logo-container img {
            height: 35px;
        }

        .logo-container img {
            height: 45px;
            width: auto;
            transition: var(--transition);
        }

        .logo-container .brand {
            font-weight: 700;
            font-size: 1.4rem;
            background: linear-gradient(to right, var(--primary), var(--primary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .header nav {
            display: flex;
            gap: 1.5rem;
        }

        .header nav a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            position: relative;
            padding: 0.5rem 0;
            transition: var(--transition);
        }

        .header nav a:hover {
            color: white;
        }

        .header nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary);
            transition: var(--transition);
        }

        .header nav a:hover::after {
            width: 100%;
        }

        .utils {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .language-selector select {
            background: var(--card-bg);
            color: white;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            padding: 0.5rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .language-selector select:hover {
            border-color: var(--primary);
        }

        .auth-buttons {
            display: flex;
            gap: 1rem;
        }

        .login-btn, .register-btn {
            padding: 0.6rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
        }

        .login-btn {
            color: white;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .login-btn:hover {
            background: rgba(255,255,255,0.05);
        }

        .register-btn {
            background: var(--primary);
            color: white;
            border: none;
        }

        .register-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            transition: var(--transition);
        }

        .user-profile:hover {
            background: var(--darker-bg);
        }

        .user-profile a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: white;
            text-decoration: none;
        }

        .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            color: white;
            overflow: hidden;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* PROFILE PAGE STYLES */
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --text-color: <?php echo $text_color; ?>;
            --bg-color: #1b2838;
            --container-bg: <?php echo $container_bg; ?>;
            --container-width: 920px;
            --spacing-unit: 8px;
            --border-radius-main: 16px;
            --border-radius-module: 6px;
            --danger-color: #ff4d4d;
            --danger-glow: rgba(255, 77, 77, 0.5);
            --accent-gradient: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            --text-gradient: linear-gradient(90deg, var(--secondary-color), var(--text-color));
        }

        body {
            font-family: 'Arial', sans-serif;
            color: var(--text-color);
            background-color: var(--bg-color);
            background-image: url('<?php echo !empty($user_data['background_url']) ? "../" . htmlspecialchars($user_data['background_url']) : ''; ?>');
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            padding: calc(var(--spacing-unit) * 3) 0;
            margin: 0;
            padding-top: 0px; /* Added for header spacing */
        }

        /* Profile page container */
        .profile_page_container {
            width: 100%;
            max-width: var(--container-width);
            margin: 100px auto 40px auto; 
            background: var(--container-bg);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.125);
            border-radius: var(--border-radius-main);
            padding: calc(var(--spacing-unit) * 3);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }
        
        /* İçerideki modüller için daha sade bir arkaplan */
        .profile_module {
            background: rgba(0, 0, 0, 0.2);
            border-radius: var(--border-radius-module);
            padding: calc(var(--spacing-unit) * 2);
        }
        
        .profile_header_bg {
            background: none;
            padding: 0;
            border: none;
            margin-bottom: calc(var(--spacing-unit) * 3);
        }

        .profile_header {
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 3);
        }

        .playerAvatar {
            width: 128px; 
            height: 128px;
            flex-shrink: 0; 
            position: relative;
        }
        .playerAvatar .avatar_image {
            width: 100%; 
            height: 100%; 
            object-fit: cover;
            border: 3px solid #5a5a5a;
            border-radius: 121px; /* Kare avatar */
        }
        .profile_avatar_frame {
            position: absolute; 
            top: -10px; 
            left: -10px; 
            right: -10px; 
            bottom: -10px;
            pointer-events: none;
        }
        .profile_avatar_frame img {
            width: 105%; 
            height: 110%; 
            border: none; 
            object-fit: cover;
            border-radius: 4px; /* Kare frame */
        }
        
        .profile_header_centered_col { 
            flex-grow: 1; 
        }
        .persona_name { 
            font-size: 2rem; 
            font-weight: bold;
            background: var(--text-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .header_location { 
            color: #b0b3b8; 
            margin-top: var(--spacing-unit);
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 1);
        }
        .profile_summary { 
            margin-top: calc(var(--spacing-unit) * 2); 
            color: #dcdedf; 
            line-height: 1.6; 
            padding-left: calc(var(--spacing-unit) * 1.5);
            border-left: 3px solid;
            border-image: var(--accent-gradient) 1;
        }
        
        /* İki Sütunlu Yapı */
        .profile_content {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
            gap: calc(var(--spacing-unit) * 3);
        }
        
        .profile_leftcol, .profile_rightcol {
            display: flex; flex-direction: column;
            gap: calc(var(--spacing-unit) * 3);
        }
        
        @media (max-width: 960px) {
            .profile_content { grid-template-columns: 1fr; }
        }
        
        .module_header {
            font-size: 1.1rem; 
            color: var(--text-color);
            padding-bottom: calc(var(--spacing-unit) * 1.5);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: calc(var(--spacing-unit) * 2);
            font-weight: 500;
        }

        /* Sağ Sütun Öğeleri */
        .profile_count_link {
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            padding: var(--spacing-unit) 0; 
            text-decoration: none; 
            color: #dcdedf;
            font-size: 0.9rem;
        }
        .profile_count_link:not(:last-child) { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .profile_count_link_total { font-weight: bold; color: var(--secondary-color); }
        
        /* Yorumlar */
        .commentthread_comment {
            display: flex; 
            gap: calc(var(--spacing-unit) * 2);
            padding: calc(var(--spacing-unit) * 2) 0;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .commentthread_comment:first-of-type { border-top: none; padding-top: 0; }
        .commentthread_comment:last-of-type { padding-bottom: 0; }
        .commentthread_comment_avatar img { 
            width: 40px; 
            height: 40px; 
            border-radius: 4px;
            border: 2px solid var(--secondary-color);
        }
        .commentthread_author_link { 
            color: var(--text-color); 
            font-weight: bold; 
            text-decoration: none; 
        }
        .commentthread_author_link:hover { text-decoration: underline; }
        .commentthread_comment_timestamp { 
            font-size: 0.8rem; 
            color: #8f98a0; 
            margin-left: var(--spacing-unit); 
        }
        .commentthread_comment_text { 
            margin-top: calc(var(--spacing-unit) / 2); 
            color: #dcdedf; 
            word-wrap: break-word; 
            line-height: 1.5; 
        }
        
        /* Eklenen Özellikler için Stiller */
        .profile_header_action_area {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: calc(var(--spacing-unit) * 1.5);
        }
        
        .profile_header_badges_container {
            display: flex;
            flex-wrap: wrap;
            gap: var(--spacing-unit);
            align-self: flex-end;
        }
        
        .profile-badge {
            width: 50px; 
            height: 50px;
            background-color: rgba(0,0,0,0.2);
            border: 1px solid var(--border-color);
            border-radius: 4px; /* Kare rozet */
            padding: var(--spacing-unit);
            transition: transform 0.2s ease;
        }
        .profile-badge img { 
            width: 100%; 
            height: 100%; 
            object-fit: contain; 
        }
        .profile-badge:hover { 
            transform: scale(1.1); 
        }
        
        .report-button {
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) / 2);
            background: none;
            border: none;
            color: var(--danger-color);
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            padding: calc(var(--spacing-unit) * 1);
            border-radius: var(--border-radius-module);
            transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
        }
        .report-button:hover {
            background-color: rgba(255, 77, 77, 0.1);
            color: var(--danger-color);
            transform: translateY(-2px);
        }
        .report-button .lucide {
            width: 18px;
            height: 18px;
            color: var(--danger-color);
        }
        
        .action-button {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-unit);
            padding: 12px 24px;
            font-weight: 600;
            border-radius: var(--border-radius-module);
            text-decoration: none;
            cursor: pointer;
            border: none;
            background: var(--accent-gradient);
            color: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-top: calc(var(--spacing-unit) * 2);
        }
        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.3);
        }
        
        .video-player {
            position: relative;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            border-radius: var(--border-radius-module);
            overflow: hidden;
        }
        .video-player video {
            width: 100%;
            height: auto;
            display: block;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: var(--border-radius-module);
        }
        .controls {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            padding: 10px;
            gap: 10px;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        .video-player:hover .controls {
            transform: translateY(0);
        }
        .control-btn {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 5px;
            transition: color 0.2s ease;
        }
        .control-btn:hover {
            color: var(--secondary-color);
        }
        .progress-container {
            flex-grow: 1;
            height: 5px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            overflow: hidden;
            cursor: pointer;
        }
        .progress-bar {
            width: 0;
            height: 100%;
            background: var(--secondary-color);
            transition: width 0.1s linear;
        }
        .time-display {
            color: var(--text-color);
            font-size: 0.9rem;
            white-space: nowrap;
        }
        .volume-slider {
            width: 80px;
            -webkit-appearance: none;
            appearance: none;
            height: 5px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
        }
        .volume-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 12px;
            height: 12px;
            background: var(--text-color);
            border-radius: 50%;
            cursor: pointer;
        }
        .volume-slider::-moz-range-thumb {
            width: 12px;
            height: 12px;
            background: var(--text-color);
            border-radius: 50%;
            cursor: pointer;
        }
        
        .showcase-container {
            border-radius: var(--border-radius-module);
            overflow: hidden;
            cursor: pointer;
        }
        .showcase-image {
            display: block; 
            width: 100%; 
            height: auto;
            transition: transform 0.5s ease;
        }
        .showcase-container:hover .showcase-image { 
            transform: scale(1.05); 
        }
        
        .post {
            padding: calc(var(--spacing-unit) * 2) 0;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .post:first-of-type { border-top: none; padding-top: 0; }
        .post:last-of-type { padding-bottom: 0; }
        .post h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: calc(var(--spacing-unit) * 0.5);
        }
        .post p {
            color: var(--text-color);
            line-height: 1.6;
        }
        
        .music-controls {
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 2);
        }
        .music-controls button {
            display: flex; 
            align-items: center; 
            justify-content: center;
            width: 44px; 
            height: 44px;
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-radius: 50%;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .music-controls button:hover {
            background-color: rgba(255,255,255,0.1);
            transform: scale(1.1);
        }
        .music-controls button i { 
            width: 20px; 
            height: 20px; 
        }
        
        .lakealt-item {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: calc(var(--spacing-unit) * 1.5);
            padding: calc(var(--spacing-unit) * 1.5);
            text-decoration: none;
            color: inherit;
            transition: background-color 0.2s ease, transform 0.2s ease;
            border-radius: var(--border-radius-module);
        }
        .lakealt-item:hover {
            background-color: rgba(255,255,255,0.05);
            transform: translateX(5px);
        }
        .lakealt-avatar img, .lakealt-avatar-letter {
            width: 40px; 
            height: 40px;
            object-fit: cover;
            border-radius: 4px; /* Kare avatar */
        }
        .lakealt-avatar-letter {
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-gradient);
            font-weight: 700;
            color: white;
        }
        .lakealt-name { 
            font-weight: 500; 
        }
        
        .comment-form { 
            margin: calc(var(--spacing-unit) * 2) 0; 
        }
        .comment-form textarea {
            width: 100%;
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-color);
            padding: calc(var(--spacing-unit) * 1.5);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            border-radius: var(--border-radius-module);
            resize: vertical;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .comment-form textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 15px 0px rgba(43, 255, 0, 0.3);
        }
        
        .delete-comment-btn {
            background: transparent;
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
            cursor: pointer;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 5px;
            transition: all 0.2s ease;
            margin-top: 10px;
        }
        .delete-comment-btn:hover { 
            background: var(--danger-color); 
            color: white; 
            box-shadow: 0 0 8px var(--danger-glow); 
        }
        
        .lightbox-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: zoom-out;
            animation: fadeIn 0.3s ease;
        }
        .lightbox-image {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
.playerAvatar {
        position: relative;
        width: 184px;
        height: 184px;
    }

.avatar_image {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #5a5a5a;
    }

.avatar_initial {
        width: 100%;
        height: 100%;
        background-color: #2a475e;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 72px;
        font-weight: bold;
        text-transform: uppercase;
        border-radius: 50%;
        border: 3px solid #5a5a5a;
    }
.profile_avatar_frame {
    position: absolute;
    width: 115%;
    height: 105%;
    z-index: 1;
    pointer-events: none;
      left: -8%;
}
    </style>
</head>
<body class="has_profile_background">

<!-- NEW HEADER FROM HOMEPAGE -->
<header class="header">
    <div class="logo-container">
        <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
        <span class="brand">LakeBan</span>
    </div>
    <nav>
        <a href="/index"><?php echo $translations['header']['nav']['home'] ?? 'Anasayfa'; ?></a>
        <a href="/topluluklar"><?php echo $translations['header']['nav']['communities'] ?? 'Topluluklar'; ?></a>
        <a href="/changelog"><?php echo $translations['header']['nav']['updates'] ?? 'Güncellemeler'; ?></a>
        <a href="/stats"><?php echo $translations['header']['nav']['statistics'] ?? 'İstatistikler'; ?></a>
    </nav>
    <div class="utils">
        <div class="language-selector">
            <select onchange="changeLanguage(this.value)">
                <option value="tr" <?php if ($lang == 'tr') echo 'selected'; ?>>Türkçe</option>
                <option value="en" <?php if ($lang == 'en') echo 'selected'; ?>>English</option>
                <option value="fı" <?php if ($lang == 'fı') echo 'selected'; ?>>Suomi</option>
                <option value="ru" <?php if ($lang == 'ru') echo 'selected'; ?>>Русский</option>
                <option value="fr" <?php if ($lang == 'fr') echo 'selected'; ?>>Français</option>
                <option value="de" <?php if ($lang == 'de') echo 'selected'; ?>>Deutsch</option>
            </select>
        </div>

        <?php if ($isLoggedIn): ?>
            <div class="user-profile">
                <a href="/profile-page?username=<?php echo htmlspecialchars($_SESSION['username']); ?>">
                    <div class="avatar">
                        <?php if (!empty($_SESSION['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="<?php echo htmlspecialchars($_SESSION['username']); ?>'s avatar">
                        <?php else: ?>
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                </a>
            </div>
        <?php else: ?>
            <div class="auth-buttons">
                <a href="login" class="login-btn"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></a>
                <a href="register" class="register-btn"><?php echo $translations['header']['register'] ?? 'Kayıt Ol'; ?></a>
            </div>
        <?php endif; ?>
    </div>
</header>

<div class="profile_page_container">
        <div class="profile_header_bg">
            <div class="profile_header">
                <div class="playerAvatar">
                    <?php if (!empty($user_data['avatar_frame_url'])): ?>
                        <div class="profile_avatar_frame">
                            <img src="../<?php echo htmlspecialchars($user_data['avatar_frame_url']); ?>" alt="Avatar Çerçevesi">
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($user_data['avatar_url'])): ?>
                        <img class="avatar_image" src="../<?php echo htmlspecialchars($user_data['avatar_url']); ?>" alt="Avatar">
                    <?php else: ?>
                        <div class="avatar_initial"><?php echo strtoupper(substr($profile_username, 0, 1)); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="profile_header_centered_col">
                    <div class="persona_name"><?php echo htmlspecialchars($user_data['display_username'] ?? $profile_username); ?></div>
                    <div class="username" style="font-size: 1.2rem; color: var(--text-secondary); margin-top: calc(var(--spacing-unit) * 0.5);">@<?php echo htmlspecialchars($profile_username); ?></div>
                    <?php if (!empty($user_data['country'])): ?>
                        <div class="header_location">
                            <span class="flag-icon flag-icon-<?php echo strtolower(htmlspecialchars($user_data['country'])); ?>"></span>
                            <?php
                            $countries = ['tr' => 'Türkiye', 'us' => 'United States', 'gb' => 'United Kingdom', 
                                         'de' => 'Germany', 'fr' => 'France', 'ru' => 'Russia', 'jp' => 'Japan'];
                            echo $countries[$user_data['country']] ?? $user_data['country'];
                            ?>
                        </div>
                    <?php endif; ?>
                    <div class="profile_summary">
                        <?php echo $bio; ?>
                    </div>
                    <button class="action-button" onclick="copyProfileLink()">
                        <i class="fas fa-share"></i> <?php echo $translations['profile_share'] ?? 'Profil Paylaş'; ?>
                    </button>
                </div>
                
                <div class="profile_header_action_area">
                    <?php if (!empty($user_badges)): ?>
                        <div class="profile_header_badges_container">
                            <?php foreach ($user_badges as $badge): ?>
                                <div class="profile-badge" title="<?php echo htmlspecialchars($badge['name']).' - '.htmlspecialchars($badge['description']); ?>">
                                    <img src="../<?php echo htmlspecialchars($badge['icon_url']); ?>" alt="<?php echo htmlspecialchars($badge['name']); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isLoggedIn && $current_user_id !== $user_data['id']): ?>
                        <button class="report-button" onclick="reportUser('<?php echo htmlspecialchars($profile_username); ?>')">
                            <i data-lucide="flag"></i>
                            <?php echo $translations['profile_report_user'] ?? 'Kullanıcıyı Şikayet Et'; ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <div class="profile_content">
        <div class="profile_leftcol">
            <?php if (!empty($user_data['video_url'])): ?>
            <div class="profile_module">
                <div class="module_header"><?php echo $translations['profile_featured_media'] ?? 'Öne Çıkan Medya'; ?></div>
                <div class="video-container">
                    <div class="video-player">
                        <video id="customVideo" muted loop playsinline>
                            <source src="../<?php echo htmlspecialchars($user_data['video_url']); ?>" type="video/mp4">
                            Tarayıcınız video etiketini desteklemiyor.
                        </video>
                        <div class="controls">
                            <button class="control-btn play-pause"><i class="fas fa-play"></i></button>
                            <div class="progress-container">
                                <div class="progress-bar" id="progressBar"></div>
                            </div>
                            <div class="time-display">
                                <span id="currentTime">00:00</span> / <span id="duration">00:00</span>
                            </div>
                            <button class="control-btn mute-btn"><i class="fas fa-volume-up"></i></button>
                            <input type="range" class="volume-slider" min="0" max="1" step="0.1" value="0.5">
                            <button class="control-btn fullscreen-btn"><i class="fas fa-expand"></i></button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($user_data['showcase_image_url'])): ?>
            <div class="profile_module">
                <div class="module_header"><?php echo $translations['profile_featured_showcase'] ?? 'Özel Vitrin'; ?></div>
                <div class="showcase-container">
                    <img src="../<?php echo htmlspecialchars($user_data['showcase_image_url']); ?>"
                         alt="Özel vitrin resmi"
                         class="showcase-image"
                         onclick="openLightbox(this)">
                </div>
            </div>
            <?php endif; ?>

            <div class="profile_module">
                <div class="module_header"><?php echo $translations['profile_posts'] ?? 'Gönderiler'; ?></div>
                <?php
                if ($post_result->num_rows > 0) {
                    while ($post = $post_result->fetch_assoc()) {
                        echo "<div class='post'>";
                        echo "<h3>" . htmlspecialchars($post['title'], ENT_QUOTES) . "</h3>";
                        echo "<p>" . nl2br(htmlspecialchars($post['content'], ENT_QUOTES)) . "</p>";
                        echo "</div>";
                    }
                } else {
                    echo "<p>" . ($translations['profile_no_posts'] ?? 'Henüz gönderi yok.') . "</p>";
                }
                ?>
            </div>

            <div class="profile_module">
                 <div class="module_header"><?php echo $translations['profile_comments'] ?? 'Yorumlar'; ?> (<?php echo $comment_count; ?>)</div>
                 
                 <?php if ($isLoggedIn && $current_username !== $profile_username): ?>
                    <div class="comment-form">
                        <form action="../add_profile_comment.php" method="post">
                            <input type="hidden" name="profile_user_id" value="<?php echo $user_data['id']; ?>">
                            <textarea name="comment" rows="3" placeholder="<?php echo $translations['profile_comment_placeholder'] ?? 'Bu profile bir yorum bırak...'; ?>" required></textarea>
                            <button type="submit" class="action-button"><?php echo $translations['profile_comment_submit'] ?? 'Yorumu Gönder'; ?></button>
                        </form>
                    </div>
                 <?php endif; ?>
                 
                 <div class="commentthread_comments">
                    <?php if ($comments_result->num_rows > 0): ?>
                        <?php while ($comment = $comments_result->fetch_assoc()): ?>
                            <div class="commentthread_comment">
                                <div class="commentthread_comment_avatar">
                                    <a href="/profile-page?username=<?php echo urlencode($comment['username']); ?>">
                                        <?php if (!empty($comment['avatar_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($comment['avatar_url']); ?>" alt="Avatar">
                                        <?php else: ?>
                                            <div class="comment-avatar-letter"><?php echo strtoupper(substr($comment['username'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <div class="commentthread_comment_content">
                                    <div class="commentthread_comment_author">
                                        <a class="commentthread_author_link" href="/profile-page?username=<?php echo urlencode($comment['username']); ?>"><?php echo htmlspecialchars($comment['username']); ?></a>
                                        <span class="commentthread_comment_timestamp"><?php echo date('d M Y @ H:i', strtotime($comment['created_at'])); ?></span>
                                    </div>
                                    <div class="commentthread_comment_text">
                                        <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                    </div>
                                    <?php if ($isLoggedIn && ($current_username === $profile_username || $current_username === $comment['username'])): ?>
                                        <form action="../delete_profile_comment.php" method="post" style="margin-top: 10px;">
                                            <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                            <button type="submit" class="delete-comment-btn"><?php echo $translations['profile_comment_delete'] ?? 'Sil'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p><?php echo $translations['profile_no_comments'] ?? 'Henüz yorum yok.'; ?></p>
                    <?php endif; ?>
                 </div>
            </div>
        </div>

        <div class="profile_rightcol">
            <?php if (!empty($user_data['profile_music'])): ?>
                <div class="profile_module">
                    <div class="module_header"><?php echo $translations['profile_music_controls'] ?? 'Profil Müziği'; ?></div>
                    <audio id="profile-music" preload="none">
                        <source src="../<?php echo htmlspecialchars($user_data['profile_music']); ?>" type="audio/mpeg">
                    </audio>
                    <div class="music-controls">
                        <button onclick="toggleMusic()">
                            <i data-lucide="play" id="play-icon"></i>
                            <i data-lucide="pause" id="pause-icon" style="display: none;"></i>
                        </button>
                        <input type="range" id="volume-slider" min="0" max="1" step="0.1" value="0.5" oninput="setVolume(this.value)">
                    </div>
                </div>
            <?php endif; ?>

            <div class="profile_module">
                <div class="module_header"><?php echo $translations['profile_stats'] ?? 'İstatistikler'; ?></div>
                <a href="#" class="profile_count_link">
                    <span class="count_link_label"><?php echo $translations['profile_total_posts'] ?? 'Toplam Gönderi'; ?></span>
                    <span class="profile_count_link_total"><?php echo (int)$user_data['post_count']; ?></span>
                </a>
                <a href="#" class="profile_count_link">
                    <span class="count_link_label"><?php echo $translations['profile_friends_count'] ?? 'Arkadaşlar'; ?></span>
                    <span class="profile_count_link_total"><?php echo $stats_data['friend_count'] ?? 0; ?></span>
                </a>
                <div class="profile_count_link">
                    <span class="count_link_label"><?php echo $translations['profile_views'] ?? 'Profil Görüntülenmesi'; ?></span>
                    <span class="profile_count_link_total"><?php echo number_format($user_data['profile_views'] ?? 0); ?></span>
                </div>
                <div class="profile_count_link">
                    <span class="count_link_label"><?php echo $translations['profile_join_date'] ?? 'Katılım Tarihi'; ?></span>
                    <span class="profile_count_link_total"><?php echo date('d.m.Y', strtotime($user_data['created_at'])); ?></span>
                </div>
                <div class="profile_count_link">
                    <span class="count_link_label"><?php echo $translations['profile_last_activity'] ?? 'Son Aktivite'; ?></span>
                    <span class="profile_count_link_total">
                        <?php
                        if (!empty($user_data['last_activity'])) {
                            echo date('d.m.Y H:i', strtotime($user_data['last_activity']));
                        } else {
                            echo $translations['profile_never'] ?? 'Bilinmiyor';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <?php if ($lakealts_result->num_rows > 0): ?>
            <div class="profile_module">
                <div class="module_header">Lakealt'lar</div>
                <?php while ($lakealt = $lakealts_result->fetch_assoc()): ?>
                    <a href="lakealt.php?name=<?php echo urlencode($lakealt['name']); ?>"
                       class="lakealt-item">
                        <div class="lakealt-avatar">
                            <?php if (!empty($lakealt['avatar_url'])): ?>
                                <img src="../<?php echo htmlspecialchars($lakealt['avatar_url']); ?>"
                                     alt="<?php echo htmlspecialchars($lakealt['name']); ?> avatar">
                            <?php else: ?>
                                <div class="lakealt-avatar-letter">
                                    <?php echo strtoupper(substr($lakealt['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="lakealt-name">
                            <?php echo htmlspecialchars($lakealt['name']); ?>
                        </span>
                    </a>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <div class="profile_module">
                <div class="module_header"><?php echo $translations['profile_friends'] ?? 'Arkadaşlar'; ?></div>
                <div class="profile_count_link">
                    <span class="count_link_label"><?php echo $translations['profile_friends_total'] ?? 'Toplam Arkadaş'; ?></span>
                    <span class="profile_count_link_total">
                        <?php echo $stats_data['friend_count'] ?? 0; ?>
                    </span>
                </div>

                <?php if ($friends_list_result->num_rows > 0): ?>
                    <?php while ($friend = $friends_list_result->fetch_assoc()): ?>
                        <a href="/profile-page?username=<?php echo urlencode($friend['friend_username']); ?>"
                           class="lakealt-item"> 
                            <div class="lakealt-avatar">
                                <?php if (!empty($friend['friend_avatar_url'])): ?>
                                    <img src="../<?php echo htmlspecialchars($friend['friend_avatar_url']); ?>" alt="<?php echo htmlspecialchars($friend['friend_username']); ?>'s avatar">
                                <?php else: ?>
                                    <div class="lakealt-avatar-letter"><?php echo strtoupper(substr($friend['friend_username'], 0, 1)); ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="lakealt-name">
                                <?php echo htmlspecialchars($friend['friend_username']); ?>
                            </span>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p><?php echo $translations['profile_no_friends'] ?? 'Henüz hiç arkadaşı yok.'; ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="reportModal" style="display: none; position: fixed; z-index: 2001; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7);">
    <div style="background-color: rgba(27, 40, 56, 0.8); margin: 15% auto; padding: 20px; border: 1px solid rgba(255,255,255,0.1); width: 80%; max-width: 500px; border-radius: var(--border-radius-module); box-shadow: 0 8px 32px 0 rgba(0,0,0,0.5); backdrop-filter: blur(10px); color: var(--text-color);">
        <span style="color: var(--text-color); float: right; font-size: 28px; font-weight: bold; cursor: pointer;" onclick="closeReportModal()">&times;</span>
        <h2><?php echo $translations['profile_report_user_title'] ?? 'Kullanıcıyı Şikayet Et'; ?>: <span id="reportUsernameDisplay"></span></h2>
        <form id="reportForm" style="margin-top: 20px;">
            <input type="hidden" id="reportedUsernameInput" name="reported_username">

            <label for="reportReason" style="display: block; margin-bottom: 8px;"><?php echo $translations['report_reason_label'] ?? 'Şikayet Nedeni:'; ?></label>
            <select id="reportReason" name="report_reason" style="width: 100%; padding: 10px; margin-bottom: 15px; border-radius: var(--border-radius-module); border: 1px solid rgba(255,255,255,0.1); background-color: rgba(0,0,0,0.2); color: var(--text-color);" required>
                <option value="spam"><?php echo $translations['report_reason_spam'] ?? 'Spam veya İstenmeyen İçerik'; ?></option>
                <option value="inappropriate_content"><?php echo $translations['report_reason_inappropriate'] ?? 'Uygunsuz Profil İçeriği'; ?></option>
                <option value="harassment"><?php echo $translations['report_reason_harassment'] ?? 'Taciz veya Zorbalık'; ?></option>
                <option value="impersonation"><?php echo $translations['report_reason_impersonation'] ?? 'Kimliğe Bürünme'; ?></option>
                <option value="other"><?php echo $translations['report_reason_other'] ?? 'Diğer'; ?></option>
            </select>

            <label for="reportDescription" style="display: block; margin-bottom: 8px;"><?php echo $translations['report_description_label'] ?? 'Açıklama (isteğe bağlı):'; ?></label>
            <textarea id="reportDescription" name="report_description" rows="5" style="width: 100%; padding: 10px; margin-bottom: 15px; border-radius: var(--border-radius-module); border: 1px solid rgba(255,255,255,0.1); background-color: rgba(0,0,0,0.2); color: var(--text-color); resize: vertical;"></textarea>

            <button type="submit" class="action-button" style="width: 100%;"><?php echo $translations['report_submit_button'] ?? 'Şikayeti Gönder'; ?></button>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();

    // Müzik kontrolü
    var profileMusic = document.getElementById('profile-music');
    var playIcon = document.getElementById('play-icon');
    var pauseIcon = document.getElementById('pause-icon');
    if(profileMusic) { profileMusic.volume = 0.5; }
    function toggleMusic() {
        if (profileMusic && profileMusic.paused) {
            profileMusic.play();
            if(playIcon) playIcon.style.display = 'none';
            if(pauseIcon) pauseIcon.style.display = 'inline';
        } else if (profileMusic) {
            profileMusic.pause();
            if(playIcon) playIcon.style.display = 'inline';
            if(pauseIcon) pauseIcon.style.display = 'none';
        }
    }
    function setVolume(value) { if(profileMusic) profileMusic.volume = value; }
    
    // Profil linkini kopyalama
    function copyProfileLink() {
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('<?php echo addslashes($translations['profile_link_copied'] ?? 'Profil linki panoya kopyalandı!'); ?>');
        });
    }
    
    // Lightbox açma
    function openLightbox(imgElement) {
        const overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';
        overlay.onclick = function() { document.body.removeChild(overlay); };
        const enlargedImg = document.createElement('img');
        enlargedImg.src = imgElement.src;
        enlargedImg.className = 'lightbox-image';
        overlay.appendChild(enlargedImg);
        document.body.appendChild(overlay);
    }
    
    // Kullanıcı şikayet etme
    function reportUser(username) {
        document.getElementById('reportUsernameDisplay').innerText = username;
        document.getElementById('reportedUsernameInput').value = username;
        document.getElementById('reportModal').style.display = 'flex';
    }
    function closeReportModal() { document.getElementById('reportModal').style.display = 'none'; }
    
    // Şikayet formu gönderimi
    document.getElementById('reportForm').addEventListener('submit', function(event) {
        event.preventDefault();
        const formData = new FormData(this);
        fetch('/report_user.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.success) closeReportModal();
        })
        .catch(error => { console.error('Hata:', error); alert('Şikayet gönderilirken bir hata oluştu.'); });
    });
    
    // Video oynatıcı kontrolü
    document.addEventListener('DOMContentLoaded', () => {
        const video = document.getElementById('customVideo');
        if (!video) return;

        const playPauseBtn = document.querySelector('.play-pause');
        const muteBtn = document.querySelector('.mute-btn');
        const volumeSlider = document.querySelector('.volume-slider');
        const progressBar = document.getElementById('progressBar');
        const currentTimeDisplay = document.getElementById('currentTime');
        const durationDisplay = document.getElementById('duration');
        const fullscreenBtn = document.querySelector('.fullscreen-btn');
        const progressContainer = document.querySelector('.progress-container');

        const formatTime = (seconds) => {
            const min = Math.floor(seconds / 60);
            const sec = Math.floor(seconds % 60);
            return `${min.toString().padStart(2, '0')}:${sec.toString().padStart(2, '0')}`;
        }

        video.addEventListener('loadedmetadata', () => {
            durationDisplay.textContent = formatTime(video.duration);
        });
        
        video.addEventListener('timeupdate', () => {
            progressBar.style.width = `${(video.currentTime / video.duration) * 100}%`;
            currentTimeDisplay.textContent = formatTime(video.currentTime);
        });
        
        playPauseBtn.addEventListener('click', () => {
            if (video.paused) { 
                video.play(); 
                playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>'; 
            } else { 
                video.pause(); 
                playPauseBtn.innerHTML = '<i class="fas fa-play"></i>'; 
            }
        });
        
        muteBtn.addEventListener('click', () => {
            video.muted = !video.muted;
            muteBtn.innerHTML = video.muted ? '<i class="fas fa-volume-mute"></i>' : '<i class="fas fa-volume-up"></i>';
            if (!video.muted) volumeSlider.value = video.volume;
        });
        
        volumeSlider.addEventListener('input', (e) => {
            video.volume = e.target.value;
            video.muted = e.target.value == 0;
            muteBtn.innerHTML = video.muted ? '<i class="fas fa-volume-mute"></i>' : '<i class="fas fa-volume-up"></i>';
        });
        
        progressContainer.addEventListener('click', (e) => {
            const rect = progressContainer.getBoundingClientRect();
            const newTime = ((e.clientX - rect.left) / rect.width) * video.duration;
            video.currentTime = newTime;
        });
        
        fullscreenBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) { 
                document.querySelector('.video-player').requestFullscreen().catch(err => console.error(err)); 
            } else { 
                document.exitFullscreen(); 
            }
        });
    });

    // HEADER SCROLL EFFECT
    window.addEventListener('scroll', function() {
        const header = document.querySelector('.header');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // LANGUAGE CHANGE FUNCTION
    function changeLanguage(lang) {
        window.location.href = window.location.pathname + '?lang=' + lang;
    }
</script>
</body>
</html>