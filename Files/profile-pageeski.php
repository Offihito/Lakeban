<?php
// Hata raporlamayı açalım
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';



// URL'den kullanıcı adını al
$profile_username = isset($_GET['username']) ? $_GET['username'] : null;

if (!$profile_username) {
    header("Location: index.php");
    exit;
}

// Veritabanından kullanıcı bilgilerini çek
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.created_at, u.post_count, up.avatar_url, up.bio, up.background_url, up.profile_music, up.profile_theme, u.last_activity,
           up.profile_header_color, up.profile_text_color, up.profile_button_color, up.video_url, up.profile_views, up.country,
           up.youtube_url, up.instagram_url, up.spotify_url, up.steam_url, up.github_url
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

// Biyografi kontrolü
$bio = !empty($user_data['bio']) ? htmlspecialchars(substr($user_data['bio'], 0, 200)) : ($translations['profile_no_bio'] ?? 'No bio available.');

// Profil görüntüleme sayacını artır
if ($profile_username && $user_data) {
    if (!isset($_SESSION['viewed_profiles'])) {
        $_SESSION['viewed_profiles'] = [];
    }

    if (
        !in_array($user_data['id'], $_SESSION['viewed_profiles']) && 
        (!$isLoggedIn || $current_user_id != $user_data['id'])
    ) {
        $conn->begin_transaction();
        try {
            $updateViews = $conn->prepare("UPDATE user_profiles SET profile_views = profile_views + 1 WHERE user_id = ?");
            $updateViews->bind_param("i", $user_data['id']);
            $updateViews->execute();
            
            $_SESSION['viewed_profiles'][] = $user_data['id'];
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Hata: " . $e->getMessage());
        }
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

// Arkadaşları çeken sorgu (Home sekmesi için sadece 3 tane çekiyoruz)
$friends_stmt = $conn->prepare("
    SELECT u.id, u.username, up.avatar_url, f.unread_messages 
    FROM friends f
    JOIN users u ON (f.friend_id = u.id AND f.user_id = ?) OR (f.user_id = u.id AND f.friend_id = ?)
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.id != ?
    LIMIT 3
");
$friends_stmt->bind_param("iii", $user_data['id'], $user_data['id'], $user_data['id']);
$friends_stmt->execute();
$friends_result = $friends_stmt->get_result();

// Tüm arkadaşlar için ayrı sorgu (Arkadaşlar sekmesi)
$all_friends_stmt = $conn->prepare("
    SELECT u.id, u.username, up.avatar_url, f.unread_messages 
    FROM friends f
    JOIN users u ON (f.friend_id = u.id AND f.user_id = ?) OR (f.user_id = u.id AND f.friend_id = ?)
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.id != ?
");
$all_friends_stmt->bind_param("iii", $user_data['id'], $user_data['id'], $user_data['id']);
$all_friends_stmt->execute();
$all_friends_result = $all_friends_stmt->get_result();

// İstatistikler için sorgu
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT pc.id) as comment_count,
        COUNT(DISTINCT f.id) as friend_count,
        COUNT(DISTINCT p.id) as post_count
    FROM users u
    LEFT JOIN profile_comments pc ON u.id = pc.profile_user_id
    LEFT JOIN friends f ON (u.id = f.user_id OR u.id = f.friend_id) AND (f.user_id = ? OR f.friend_id = ?)
    LEFT JOIN posts p ON u.id = p.user_id
    WHERE u.id = ?
");
$stats_stmt->bind_param("iii", $user_data['id'], $user_data['id'], $user_data['id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats_data = $stats_result->fetch_assoc();
// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı dilini al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    // Desteklenen dilleri kontrol et
    $supported_languages = ['tr', 'en', 'fi', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Dil seçeneğini kontrol et
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang; // Tarayıcı dilini varsayılan olarak ayarla
}

$lang = $_SESSION['lang'];

// Dil dosyalarını yükleme fonksiyonu
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
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profile_username ?? 'Profile'); ?> - <?php echo isset($translations['profile_page_title']) ? $translations['profile_page_title'] : 'Profile'; ?></title>
    
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide@latest/css/lucide.css">
    
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.5.0/css/flag-icon.min.css">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        :root {
            --profile-header-color: <?php echo !empty($user_data['profile_header_color']) ? $user_data['profile_header_color'] : '#bb00ff'; ?>;
            --profile-text-color: <?php echo !empty($user_data['profile_text_color']) ? $user_data['profile_text_color'] : '#e100ff'; ?>;
            --profile-button-color: <?php echo !empty($user_data['profile_button_color']) ? $user_data['profile_button_color'] : '#2bff00'; ?>;
            --profile-font: 'Inter', 'Arial', sans-serif;
            --card-bg: rgba(23, 26, 33, 0.85);
            --card-border: rgba(255, 255, 255, 0.1);
            --accent-color: #bb00ff;
            --avatar-shape: circle;
            --tab-bg: rgba(23, 26, 33, 0.85);
            --tab-active-bg: var(--profile-button-color);
            --tab-text-color: #ffffff;
            --tab-active-text: #0d1117;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body {
            font-family: var(--profile-font);
            color: var(--profile-text-color);
            margin: 0;
            padding: 0;
            line-height: 1.6;
            min-height: 100vh;
            position: relative;
            background-color: #0d1117;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            overflow-x: hidden;
        }

        .full-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            z-index: -2;
            background-attachment: fixed;

            transition: background-image 0.5s ease;
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 5px;
        }

        .profile-header {
            background: linear-gradient(135deg, rgba(23, 26, 33, 0.85) 0%, rgba(27, 40, 56, 0.85) 100%);
            border-radius: 16px 16px 0 0;
            padding: 30px;
            margin-bottom: 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
            border-bottom: none;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            animation: fadeInUp 0.8s ease;
        }

        .profile-header:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--profile-button-color), var(--profile-header-color));
            transition: all 0.3s ease;
        }

        .profile-header-content {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .profile-avatar-section {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar-wrapper {
            width: 140px;
            height: 140px;
            border-radius: var(--avatar-shape);
            padding: 5px;
            background: linear-gradient(135deg, var(--profile-button-color), var(--profile-header-color));
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .online-indicator {
            display: none; 
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

        .profile-avatar-wrapper:hover {
            transform: scale(1.05) rotate(3deg);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: var(--avatar-shape);
            overflow: hidden;
            background: var(--profile-header-color);
            border: 3px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .profile-avatar:hover img {
            transform: scale(1.1);
        }

        .avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            color: white;
            background: linear-gradient(135deg, var(--profile-header-color), var(--profile-button-color));
            font-weight: bold;
        }

        .profile-info-section {
            flex-grow: 1;
            min-width: 300px;
        }

        .profile-name {
            font-size: 2rem;
            color: var(--profile-text-color);
            margin: 0 0 10px 0;
            font-weight: 600;
            position: relative;
            display: inline-block;
            background: linear-gradient(90deg, var(--profile-text-color), var(--profile-button-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-fill-color: transparent;
        }

        .profile-name::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--profile-button-color), var(--profile-header-color));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .profile-name:hover::after {
            width: 100%;
        }

        .country-info {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .bio {
            color: rgba(255, 255, 255, 0.85);
            margin: 16px 0;
            font-size: 0.95rem;
            line-height: 1.6;
            position: relative;
            padding-left: 15px;
        }

        .bio::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(to bottom, var(--profile-button-color), var(--profile-header-color));
            border-radius: 3px;
        }

        .profile-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .social-link {
            color: var(--profile-text-color);
            text-decoration: none;
            font-size: 0.9rem;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .social-link:hover {
            background: var(--profile-button-color);
            color: #0d1117;
            transform: translateY(-2px);
        }

        .profile-tabs {
            display: flex;
            gap: 0;
            background: var(--tab-bg);
            border: 1px solid var(--card-border);
            border-top: none;
            border-radius: 0 0 16px 16px;
            margin-bottom: 0;
        }

        .tab {
            flex: 1;
            text-align: center;
            padding: 12px 20px;
            color: var(--tab-text-color);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .tab.active {
            background: var(--tab-active-bg);
            color: var(--tab-active-text);
        }

        .tab:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .tab::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--profile-button-color);
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }

        .tab.active::after {
            width: 50%;
        }

        .tab-content {
            display: none;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-top: none;
            border-radius: 0 0 16px 16px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .tab-content.active {
            display: block;
        }

        .profile-details {
            display: flex;
            gap: 20px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .profile-stats {
            background: rgba(0, 0, 0, 0.25);
            border-radius: 12px;
            padding: 15px 20px;
            min-width: 120px;
            text-align: center;
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .profile-stats:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            background: rgba(0, 0, 0, 0.35);
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .stat-value {
            color: var(--profile-text-color);
            font-size: 1.4rem;
            font-weight: 600;
        }

        .profile-content {
            display: grid;
            grid-template-columns: 280px 1fr 280px; 
            gap: 25px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .profile-main {
            max-width: 100%;
        }

        @media (max-width: 1200px) {
            .profile-content {
                grid-template-columns: 1fr;
                max-width: 100%;
            }
            
            .left-sidebar, .right-sidebar {
                order: 2;
            }
            
            .profile-main {
                order: 1;
                max-width: 100%;
            }
        }

        .left-sidebar, .right-sidebar, .profile-main {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid var(--card-border);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .left-sidebar:hover, .right-sidebar:hover, .profile-main:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .left-sidebar, .right-sidebar {
            position: relative;
            top: 20px;
            height: fit-content;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--profile-button-color) transparent;
        }

        .left-sidebar::-webkit-scrollbar,
        .right-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .left-sidebar::-webkit-scrollbar-track,
        .right-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .left-sidebar::-webkit-scrollbar-thumb,
        .right-sidebar::-webkit-scrollbar-thumb {
            background-color: var(--profile-button-color);
            border-radius: 6px;
        }

        .action-button {
            background: linear-gradient(135deg, var(--profile-button-color), var(--profile-header-color));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-block;
            margin-top: 15px;
            transition: all 0.3s ease;
            width: 100%;
            text-align: center;
            font-weight: 500;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 14px rgba(0, 0, 0, 0.2);
            filter: brightness(1.1);
        }

        .share-button {
            background: rgba(0, 0, 0, 0.2);
            color: var(--profile-text-color);
            padding: 8px 15px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .share-button:hover {
            background: var(--profile-button-color);
            color: #0d1117;
        }

        .profile-comments {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid var(--card-border);
        }

        .profile-comments h3, .friends-list h3, .profile-music h3, .stats-sidebar h3, .posts-list h3 {
            color: var(--profile-text-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
            font-weight: 600;
            position: relative;
            padding-bottom: 10px;
        }

        .profile-comments h3::after, .friends-list h3::after, .profile-music h3::after, .stats-sidebar h3::after, .posts-list h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--profile-button-color), var(--profile-header-color));
            border-radius: 3px;
        }

        .comment-form {
            margin-bottom: 30px;
            background: rgba(0, 0, 0, 0.25);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
        }

        .comment-form textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
            color: #fff;
            padding: 15px;
            margin: 8px 0;
            border-radius: 8px;
            resize: vertical;
            box-sizing: border-box;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: var(--profile-button-color);
            box-shadow: 0 0 0 2px rgba(71, 191, 255, 0.2);
            background: rgba(0, 0, 0, 0.4);
        }

        .comment {
            display: flex;
            gap: 15px;
            padding: 20px;
            margin-bottom: 20px;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 12px;
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
            animation: fadeIn 0.5s ease;
        }

        .comment:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            background: rgba(0, 0, 0, 0.35);
        }

        .comment-avatar {
            flex-shrink: 0;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--profile-header-color);
            border: 2px solid var(--profile-button-color);
            transition: all 0.3s ease;
        }

        .comment-avatar:hover {
            transform: rotate(10deg) scale(1.1);
        }

        .comment-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .comment-avatar:hover img {
            transform: scale(1.1);
        }

        .comment-avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
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
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .comment-username {
            color: var(--profile-text-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            background: linear-gradient(90deg, var(--profile-text-color), var(--profile-button-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .comment-username:hover {
            color: var(--profile-button-color);
        }

        .comment-date {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.8rem;
        }

        .comment-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .delete-comment-btn {
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid rgba(255, 0, 0, 0.2);
            color: #ff6b6b;
            cursor: pointer;
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .delete-comment-btn:hover {
            background: rgba(255, 0, 0, 0.2);
            color: #ff4545;
            transform: translateY(-2px);
        }

        .no-comments, .no-friends {
            color: rgba(255, 255, 255, 0.6);
            text-align: center;
            padding: 30px;
            font-size: 0.9rem;
        }

        .friends-list {
            margin-top: 30px;
        }

        .friend {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .friend:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(5px);
        }

        .friend-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--profile-header-color);
            border: 2px solid var(--profile-button-color);
            transition: all 0.3s ease;
        }

        .friend-avatar:hover {
            transform: rotate(10deg) scale(1.1);
        }

        .friend-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .friend-avatar:hover img {
            transform: scale(1.1);
        }

        .friend-avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: linear-gradient(135deg, var(--profile-header-color), var(--profile-button-color));
            font-weight: bold;
        }

        .friend-username {
            color: var(--profile-text-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            background: linear-gradient(90deg, var(--profile-text-color), var(--profile-button-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .friend-username:hover {
            color: var(--profile-button-color);
        }

        .profile-music {
            margin-top: 30px;
        }

        .music-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 15px;
        }

        .music-controls button {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: var(--profile-text-color);
            cursor: pointer;
            font-size: 24px;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .music-controls button:hover {
            background: var(--profile-button-color);
            transform: scale(1.1);
            color: #0d1117;
        }

        .music-controls input[type="range"] {
            flex-grow: 1;
            height: 6px;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.1);
            outline: none;
            -webkit-appearance: none;
            cursor: pointer;
        }

        .music-controls input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--profile-button-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .music-controls input[type="range"]::-webkit-slider-thumb:hover {
            transform: scale(1.2);
            background: var(--profile-header-color);
        }

        .video-container {
            margin-bottom: 30px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            position: relative;
            transition: all 0.3s ease;
        }

        .video-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .video-container video {
            width: 100%;
            display: block;
            transition: all 0.3s ease;
        }

        .video-container:hover video {
            transform: scale(1.02);
        }

        .post {
            background: rgba(0, 0, 0, 0.25);
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
        }

        .post:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            background: rgba(0, 0, 0, 0.35);
        }

        .post h3 {
            color: var(--profile-text-color);
            margin: 0 0 15px 0;
            font-size: 1.2rem;
            font-weight: 500;
        }

        .post p {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .lazy-load {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .lazy-load.loaded {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .profile-header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-name::after {
                left: 50%;
                transform: translateX(-50%);
            }
            
            .profile-details {
                justify-content: center;
            }
            
            .profile-avatar-wrapper {
                margin: 0 auto;
            }

            .profile-tabs {
                flex-direction: column;
            }

            .tab {
                padding: 10px;
            }

            .profile-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="full-background lazy-load" style="background-image: url('../<?php echo !empty($user_data['background_url']) ? htmlspecialchars($user_data['background_url']) : 'images/default_background.jpg'; ?>')" data-src="../<?php echo !empty($user_data['background_url']) ? htmlspecialchars($user_data['background_url']) : 'images/default_background.jpg'; ?>"></div>
    
    <div class="content-wrapper">
        <div class="profile-header">
            <div class="profile-header-content">
                <div class="profile-avatar-section">
                    <div class="profile-avatar-wrapper">
                        <div class="profile-avatar lazy-load" data-src="../<?php echo !empty($user_data['avatar_url']) ? htmlspecialchars($user_data['avatar_url']) : ''; ?>">
                            <?php if (!empty($user_data['avatar_url'])): ?>
                                <img src="../<?php echo htmlspecialchars($user_data['avatar_url']); ?>" alt="<?php echo htmlspecialchars($profile_username); ?>'s avatar" loading="lazy">
                            <?php else: ?>
                                <div class="avatar-letter"><?php echo strtoupper(substr($profile_username, 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="profile-info-section">
                    <h1 class="profile-name"><?php echo htmlspecialchars($profile_username); ?></h1>
                    <?php if (!empty($user_data['country'])): ?>
                        <div class="country-info">
                            <span class="flag-icon flag-icon-<?php echo strtolower($user_data['country']); ?>"></span>
                            <span>
                                <?php 
                                $countries = [
                                    'tr' => 'Türkiye',
                                    'us' => 'United States',
                                    'gb' => 'United Kingdom',
                                    'de' => 'Germany',
                                    'fr' => 'France',
                                    'ru' => 'Russia'
                                ];
                                echo $countries[$user_data['country']] ?? $user_data['country'];
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="bio"><?php echo $bio; ?></div>
                    
                    <div class="profile-actions">
                        <button class="action-button share-button" onclick="copyProfileLink()">
                            <i class="fas fa-share"></i> <?php echo isset($translations['profile_share']) ? $translations['profile_share'] : 'Share Profile'; ?>
                        </button>
                    </div>
                    
                    <div class="social-links">
                        <?php if (!empty($user_data['youtube_url'])): ?>
                            <a href="<?php echo htmlspecialchars($user_data['youtube_url']); ?>" class="social-link" target="_blank">
                                <i class="fab fa-youtube"></i> YouTube
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($user_data['instagram_url'])): ?>
                            <a href="<?php echo htmlspecialchars($user_data['instagram_url']); ?>" class="social-link" target="_blank">
                                <i class="fab fa-instagram"></i> Instagram
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($user_data['spotify_url'])): ?>
                            <a href="<?php echo htmlspecialchars($user_data['spotify_url']); ?>" class="social-link" target="_blank">
                                <i class="fab fa-spotify"></i> Spotify
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($user_data['steam_url'])): ?>
                            <a href="<?php echo htmlspecialchars($user_data['steam_url']); ?>" class="social-link" target="_blank">
                                <i class="fab fa-steam"></i> Steam
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($user_data['github_url'])): ?>
                            <a href="<?php echo htmlspecialchars($user_data['github_url']); ?>" class="social-link" target="_blank">
                                <i class="fab fa-github"></i> GitHub
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="profile-tabs">
            <div class="tab active" data-tab="home"><?php echo isset($translations['profile_home']) ? $translations['profile_home'] : 'Home'; ?></div>
            <div class="tab" data-tab="posts"><?php echo isset($translations['profile_posts']) ? $translations['profile_posts'] : 'Posts'; ?></div>
            <div class="tab" data-tab="friends"><?php echo isset($translations['profile_friends']) ? $translations['profile_friends'] : 'Friends'; ?></div>
        </div>

        <div class="profile-content">
            <div class="left-sidebar">
                <?php if (!empty($user_data['profile_music'])): ?>
                    <div class="profile-music">
                        <h3><?php echo isset($translations['profile_music_controls']) ? $translations['profile_music_controls'] : 'Music Controls'; ?></h3>
                        <audio id="profile-music" preload="none">
                            <source src="../<?php echo htmlspecialchars($user_data['profile_music']); ?>" type="audio/mpeg">
                            Your browser does not support the audio element.
                        </audio>
                        <div class="music-controls">
                            <button onclick="toggleMusic()">
                                <i data-lucide="play" id="play-icon"></i>
                                <i data-lucide="pause" id="pause-icon" style="display: none;"></i>
                            </button>
                            <input type="range" id="volume-slider" min="0" max="1" step="0.1" value="1" oninput="setVolume(this.value)">
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="friends-list">
                    <h3><?php echo isset($translations['profile_friends']) ? $translations['profile_friends'] : 'Friends'; ?></h3>
                    <?php if ($friends_result->num_rows > 0): ?>
                        <?php while ($friend = $friends_result->fetch_assoc()): ?>
                            <div class="friend">
                                <div class="friend-avatar lazy-load" data-src="../<?php echo !empty($friend['avatar_url']) ? htmlspecialchars($friend['avatar_url']) : ''; ?>">
                                    <?php if (!empty($friend['avatar_url'])): ?>
                                        <img src="../<?php echo htmlspecialchars($friend['avatar_url']); ?>" alt="<?php echo htmlspecialchars($friend['username']); ?>'s avatar" loading="lazy">
                                    <?php else: ?>
                                        <div class="friend-avatar-letter">
                                            <?php echo strtoupper(substr($friend['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <a href="profile-page.php?username=<?php echo urlencode($friend['username']); ?>" class="friend-username"><?php echo htmlspecialchars($friend['username']); ?></a>
                                <?php if ($friend['unread_messages'] > 0): ?>
                                    <span class="unread-messages"><?php echo $friend['unread_messages']; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                        <a href="javascript:void(0)" class="action-button" onclick="document.querySelector('.tab[data-tab=\'friends\']').click();">
                            <?php echo isset($translations['profile_view_all_friends']) ? $translations['profile_view_all_friends'] : 'View All Friends'; ?>
                        </a>
                    <?php else: ?>
                        <p class="no-friends"><?php echo isset($translations['profile_no_friends']) ? $translations['profile_no_friends'] : 'No friends yet.'; ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-main">
                <div class="tab-content active" id="home-content">
                    <?php if (!empty($user_data['video_url'])): ?>
                        <div class="video-container">
                            <video controls>
                                <source src="../<?php echo htmlspecialchars($user_data['video_url']); ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    <?php endif; ?>
                    
                    <div class="posts-list">
                        <h3><?php echo isset($translations['profile_posts']) ? $translations['profile_posts'] : 'Posts'; ?></h3>
                        <?php
                        $post_stmt = $conn->prepare("SELECT title, content FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
                        $post_stmt->bind_param("i", $user_data['id']);
                        $post_stmt->execute();
                        $post_result = $post_stmt->get_result();

                        if ($post_result->num_rows > 0) {
                            while ($post = $post_result->fetch_assoc()) {
                                echo "<div class='post'>";
                                echo "<h3>" . htmlspecialchars($post['title'], ENT_QUOTES) . "</h3>";
                                echo "<p>" . htmlspecialchars($post['content'], ENT_QUOTES) . "</p>";
                                echo "</div>";
                            }
                            echo '<a href="javascript:void(0)" class="action-button" onclick="document.querySelector(\'.tab[data-tab=\\\'posts\\\']\').click();">';
                            echo isset($translations['profile_view_all_posts']) ? $translations['profile_view_all_posts'] : 'View All Posts';
                            echo '</a>';
                        } else {
                            echo "<p class='bio'>" . (isset($translations['profile_no_posts']) ? $translations['profile_no_posts'] : 'No posts yet.') . "</p>";
                        }
                        ?>
                    </div>
                    
                    <div class="profile-comments">
                        <h3><?php echo isset($translations['profile_comments']) ? $translations['profile_comments'] : 'Profile Comments'; ?></h3>
                        
                        <?php if ($isLoggedIn && $current_username !== $profile_username): ?>
                        <div class="comment-form">
                            <form action="../add_profile_comment.php" method="post">
                                <input type="hidden" name="profile_user_id" value="<?php echo $user_data['id']; ?>">
                                <textarea name="comment" rows="3" placeholder="<?php echo isset($translations['profile_comment_placeholder']) ? $translations['profile_comment_placeholder'] : 'Leave a comment on this profile...'; ?>" required></textarea>
                                <button type="submit" class="action-button"><?php echo isset($translations['profile_comment_submit']) ? $translations['profile_comment_submit'] : 'Submit Comment'; ?></button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <div class="comments-list">
                            <?php if ($comments_result->num_rows > 0): ?>
                                <?php while ($comment = $comments_result->fetch_assoc()): ?>
                                    <div class="comment">
                                        <div class="comment-avatar lazy-load" data-src="../<?php echo !empty($comment['avatar_url']) ? htmlspecialchars($comment['avatar_url']) : ''; ?>">
                                            <?php if (!empty($comment['avatar_url'])): ?>
                                                <img src="../<?php echo htmlspecialchars($comment['avatar_url']); ?>" alt="<?php echo htmlspecialchars($comment['username']); ?>'s avatar" loading="lazy">
                                            <?php else: ?>
                                                <div class="comment-avatar-letter">
                                                    <?php echo strtoupper(substr($comment['username'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="comment-content">
                                            <div class="comment-header">
                                                <a href="profile-page.php?username=<?php echo urlencode($comment['username']); ?>" class="comment-username"><?php echo htmlspecialchars($comment['username']); ?></a>
                                                <span class="comment-date">
                                                    <?php echo date('d.m.Y H:i', strtotime($comment['created_at'])); ?>
                                                </span>
                                            </div>
                                            <div class="comment-text">
                                                <?php echo htmlspecialchars($comment['comment']); ?>
                                            </div>
                                            <?php if ($isLoggedIn && ($current_username === $profile_username || $current_username === $comment['username'])): ?>
                                                <div class="comment-actions">
                                                    <form action="../delete_profile_comment.php" method="post" class="delete-comment-form">
                                                        <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                        <button type="submit" class="delete-comment-btn"><?php echo isset($translations['profile_comment_delete']) ? $translations['profile_comment_delete'] : 'Delete Comment'; ?></button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p class="no-comments"><?php echo isset($translations['profile_no_comments']) ? $translations['profile_no_comments'] : 'No comments yet.'; ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-content" id="posts-content">
                    <h3><?php echo isset($translations['profile_posts']) ? $translations['profile_posts'] : 'Posts'; ?></h3>
                    <?php
                    $post_stmt = $conn->prepare("SELECT title, content FROM posts WHERE user_id = ? ORDER BY created_at DESC");
                    $post_stmt->bind_param("i", $user_data['id']);
                    $post_stmt->execute();
                    $post_result = $post_stmt->get_result();

                    if ($post_result->num_rows > 0) {
                        while ($post = $post_result->fetch_assoc()) {
                            echo "<div class='post'>";
                            echo "<h3>" . htmlspecialchars($post['title'], ENT_QUOTES) . "</h3>";
echo "<p>" . htmlspecialchars($post['content'], ENT_QUOTES) . "</p>";
                        
                            echo "</div>";
                        }
                    } else {
                        echo "<p class='bio'>" . (isset($translations['profile_no_posts']) ? $translations['profile_no_posts'] : 'No posts yet.') . "</p>";
                    }
                    ?>
                </div>

                <div class="tab-content" id="friends-content">
                    <h3><?php echo isset($translations['profile_friends']) ? $translations['profile_friends'] : 'Friends'; ?></h3>
                    <div class="friends-list">
                        <?php 
                        if ($all_friends_result->num_rows > 0) {
                            $all_friends_result->data_seek(0); 
                        }
                        
                        if ($all_friends_result->num_rows > 0): ?>
                            <?php while ($friend = $all_friends_result->fetch_assoc()): ?>
                                <div class="friend">
                                    <div class="friend-avatar lazy-load" data-src="../<?php echo !empty($friend['avatar_url']) ? htmlspecialchars($friend['avatar_url']) : ''; ?>">
                                        <?php if (!empty($friend['avatar_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($friend['avatar_url']); ?>" alt="<?php echo htmlspecialchars($friend['username']); ?>'s avatar" loading="lazy">
                                        <?php else: ?>
                                            <div class="friend-avatar-letter">
                                                <?php echo strtoupper(substr($friend['username'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="profile-page.php?username=<?php echo urlencode($friend['username']); ?>" class="friend-username"><?php echo htmlspecialchars($friend['username']); ?></a>
                                    <?php if ($friend['unread_messages'] > 0): ?>
                                        <span class="unread-messages"><?php echo $friend['unread_messages']; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="no-friends"><?php echo isset($translations['profile_no_friends']) ? $translations['profile_no_friends'] : 'No friends yet.'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="right-sidebar">
                <div class="stats-sidebar">
                    <h3><?php echo isset($translations['profile_stats']) ? $translations['profile_stats'] : 'Statistics'; ?></h3>
                    <div class="profile-stats">
                        <div class="stat-label"><?php echo isset($translations['profile_total_posts']) ? $translations['profile_total_posts'] : 'Total Posts'; ?></div>
                        <div class="stat-value"><?php echo (int)$user_data['post_count']; ?></div>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-label"><?php echo isset($translations['profile_total_comments']) ? $translations['profile_total_comments'] : 'Total Comments'; ?></div>
                        <div class="stat-value"><?php echo $stats_data['comment_count'] ?? 0; ?></div>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-label"><?php echo isset($translations['profile_friends_count']) ? $translations['profile_friends_count'] : 'Friends Count'; ?></div>
                        <div class="stat-value"><?php echo $stats_data['friend_count'] ?? 0; ?></div>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-label"><?php echo isset($translations['profile_join_date']) ? $translations['profile_join_date'] : 'Join Date'; ?></div>
                        <div class="stat-value"><?php echo date('d.m.Y', strtotime($user_data['created_at'])); ?></div>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-label"><?php echo isset($translations['profile_last_activity']) ? $translations['profile_last_activity'] : 'Last activity'; ?></div>
                        <div class="stat-value">
                            <?php 
                            if (!empty($user_data['last_activity'])) {
                                $lastLogin = new DateTime($user_data['last_activity']);
                                echo $lastLogin->format('d.m.Y H:i');
                            } else {
                                echo isset($translations['profile_never']) ? $translations['profile_never'] : 'Never';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-label"><?php echo isset($translations['profile_views']) ? $translations['profile_views'] : 'Profile Views'; ?></div>
                        <div class="stat-value"><?php echo number_format($user_data['profile_views'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });

        lucide.createIcons();

        document.addEventListener("DOMContentLoaded", function() {
            const lazyLoadElements = document.querySelectorAll('.lazy-load');
            
            const lazyLoadObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const lazyElement = entry.target;
                        
                        if (lazyElement.dataset.src) {
                            if (lazyElement.tagName === 'IMG') {
                                lazyElement.src = lazyElement.dataset.src;
                            } else if (lazyElement.classList.contains('full-background')) {
                                lazyElement.style.backgroundImage = `url(${lazyElement.dataset.src})`;
                            } else if (lazyElement.tagName === 'VIDEO') {
                                const source = lazyElement.querySelector('source');
                                source.src = lazyElement.dataset.src;
                                lazyElement.load();
                            }
                        }
                        
                        lazyElement.classList.add('loaded');
                        observer.unobserve(lazyElement);
                    }
                });
            }, {
                rootMargin: '100px 0px',
                threshold: 0.1
            });

            lazyLoadElements.forEach(element => {
                lazyLoadObserver.observe(element);
            });

            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const tabId = tab.dataset.tab;

                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    tab.classList.add('active');
                    document.getElementById(`${tabId}-content`).classList.add('active');
                });
            });
        });

        var profileMusic = document.getElementById('profile-music');
        var playIcon = document.getElementById('play-icon');
        var pauseIcon = document.getElementById('pause-icon');

        function toggleMusic() {
            if (profileMusic.paused) {
                profileMusic.play();
                playIcon.style.display = 'none';
                pauseIcon.style.display = 'inline';
            } else {
                profileMusic.pause();
                playIcon.style.display = 'inline';
                pauseIcon.style.display = 'none';
            }
        }

        function setVolume(value) {
            profileMusic.volume = value;
        }

        function copyProfileLink() {
            const profileUrl = window.location.href;
            navigator.clipboard.writeText(profileUrl).then(() => {
                alert('<?php echo isset($translations['profile_link_copied']) ? $translations['profile_link_copied'] : 'Profile link copied to clipboard!'; ?>');
            }).catch(err => {
                console.error('Failed to copy link:', err);
            });
        }
    </script>
</body>
</html>