<?php
ob_start(); // Start output buffering
session_start();
require_once 'config.php';
require_once 'db_connection.php'; // Include database connection for theme settings
define('INCLUDE_CHECK', true);

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Language support
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

// Default theme settings
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

// Fetch theme settings from database
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

$isLakebiumUser = false;
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

// Helper function: Calculate time ago
function time_ago($datetime, $full = false) {
    global $translations;
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Manually calculate weeks
    $weeks = floor($diff->d / 7);
    $diff->d -= $weeks * 7;

    $string = array(
        'y' => $translations['time']['year'] ?? 'yıl',
        'm' => $translations['time']['month'] ?? 'ay',
        'w' => $translations['time']['week'] ?? 'hafta',
        'd' => $translations['time']['day'] ?? 'gün',
        'h' => $translations['time']['hour'] ?? 'saat',
        'i' => $translations['time']['minute'] ?? 'dakika',
        's' => $translations['time']['second'] ?? 'saniye',
    );
    $output = array();
    foreach ($string as $k => $v) {
        if ($k == 'w') {
            if ($weeks) {
                $output[] = $weeks . ' ' . $v . ($weeks > 1 ? '' : '');
            }
        } elseif (isset($diff->$k) && $diff->$k) {
            $output[] = $diff->$k . ' ' . $v . ($diff->$k > 1 ? '' : '');
        }
    }

    if (!$full) $output = array_slice($output, 0, 1);
    return $output ? implode(', ', $output) . ' ' . ($translations['time']['ago'] ?? 'önce') : ($translations['time']['now'] ?? 'şimdi');
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// CSRF Token creation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '');

// Post sorting
$sort_by = $_GET['sort'] ?? 'new';
$order_by_sql = "posts.created_at DESC";

switch ($sort_by) {
    case 'hot':
        $order_by_sql = "(posts.upvotes - posts.downvotes) DESC, posts.created_at DESC";
        break;
    case 'top':
        $order_by_sql = "(posts.upvotes - posts.downvotes) DESC, posts.created_at DESC";
        break;
    case 'controversial':
        $order_by_sql = "ABS(posts.upvotes - posts.downvotes) ASC, (posts.upvotes + posts.downvotes) DESC, posts.created_at DESC";
        break;
    case 'new':
    default:
        $order_by_sql = "posts.created_at DESC";
        break;
}

// Current user ID
$current_user_id = $_SESSION['user_id'] ?? null;
// Moderator check (should be fetched from DB in real application)
$is_moderator = false;

// Lazy loading parameters
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$limit = 10; // Posts per load
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Fetch posts with pagination
$sql = "
    SELECT
        posts.id,
        posts.title,
        posts.content,
        posts.created_at,
        posts.upvotes,
        posts.downvotes,
        posts.media_path,
        posts.user_id,
        users.username,
        l.name as lakealt_name,
        category.name as category_name,
        (SELECT COUNT(*) FROM comments WHERE comments.post_id = posts.id) as comment_count,
        user_profiles.avatar_url as user_avatar_url,
        pv.vote_type as user_vote
    FROM posts
    JOIN users ON posts.user_id = users.id
    LEFT JOIN user_profiles ON users.id = user_profiles.user_id
    JOIN lakealts l ON posts.lakealt_id = l.id
    JOIN category ON posts.category_id = category.id
    LEFT JOIN post_votes pv ON posts.id = pv.post_id AND pv.user_id = ?
    ORDER BY " . $order_by_sql . " LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare error: " . $conn->error);
    if ($is_ajax) {
        echo json_encode(['status' => 'error', 'message' => $translations['errors']['server_error'] ?? 'Server error.'], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        die($translations['errors']['server_error'] ?? "Server error. Please try again later.");
    }
}
$stmt->bind_param("iii", $current_user_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$posts = [];
while ($row = $result->fetch_assoc()) {
    $row['is_creator'] = ($current_user_id && $current_user_id === $row['user_id']);
    $posts[] = $row;
}
$stmt->close();

// Handle AJAX request
if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean(); // Clear any previous output
    $html = '';
    ob_start();
    foreach ($posts as $post) {
        include 'post_template.php';
    }
    $html = ob_get_clean();
    echo json_encode(['status' => 'success', 'html' => $html, 'has_more' => count($posts) === $limit], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch joined lakealts (for sidebar)
$joined_lakealts = [];
$sql = "SELECT l.name FROM lakealt_members lm JOIN lakealts l ON lm.lakealt_id = l.id WHERE lm.user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $joined_lakealts[] = $row['name'];
    }
    $stmt->close();
} else {
    error_log("Joined lakealt SQL prepare error: " . $conn->error);
}

// Fetch moderated lakealts (for sidebar)
$moderated_lakealts = [];
$sql = "SELECT l.name FROM lakealt_moderators lm JOIN lakealts l ON lm.lakealt_id = l.id WHERE lm.user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $moderated_lakealts[] = $row['name'];
    }
    $stmt->close();
} else {
    error_log("Moderated lakealt SQL prepare error: " . $conn->error);
}

// Default image paths
$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe2e65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";
$defaultLakealtAvatar = "https://www.redditstatic.com/avatars/avatar_default_02_3CB371.png";
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="<?php echo htmlspecialchars($currentTheme); ?>-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['posts']['title'] ?? 'Gönderiler - Lakeban'; ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-bg: #1a1a1a;
            --secondary-bg: #252525;
            --accent-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
            --text-primary: #f3f4f6;
            --text-secondary: #d1d5db;
            --border-color: #2d2d2d;
            --vote-active: #34d399;
            --vote-down: #f87171;
            --custom-background-color: <?php echo htmlspecialchars($currentCustomColor); ?>;
            --custom-secondary-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
        }

        /* Light Theme */
        .light-theme body {
            background-color: #F2F3F5;
            color: #2E3338;
        }
        .light-theme .sidebar-left,
        .light-theme .sidebar-right,
        .light-theme .post,
        .light-theme .post-filters {
            background-color: #FFFFFF;
        }
        .light-theme .post-vote {
            background-color: #F8F9FA;
        }
        .light-theme .sidebar-nav a,
        .light-theme .post-header a,
        .light-theme .post-body,
        .light-theme .sidebar-right ul li,
        .light-theme .post-footer {
            color: #4F5660;
        }
        .light-theme .sidebar-nav a:hover,
        .light-theme .sidebar-nav a.active,
        .light-theme .post-header a:hover,
        .light-theme .post-title:hover,
        .light-theme .post-filters a:hover,
        .light-theme .post-filters a.active,
        .light-theme .sidebar-right ul li a:hover,
        .light-theme .share-btn:hover,
        .light-theme .comments-btn:hover {
            color: var(--accent-color);
            background-color: #e3e5e8;
        }
        .light-theme .sidebar-right .section,
        .light-theme .post,
        .light-theme .post-filters {
            border-color: #e3e5e8;
        }
        .light-theme ::-webkit-scrollbar-track {
            background: #F8F9FA;
        }
        .light-theme ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
        }
        .light-theme .user-flair {
            background-color: var(--accent-color);
            color: #FFFFFF;
        }
        .light-theme .create-post-btn {
            background-color: var(--accent-color);
            color: #FFFFFF;
        }
        .light-theme .create-post-btn:hover {
            background-color: #2e9b5e;
        }

        /* Dark Theme */
        .dark-theme body {
            background-color: #1E1E1E;
            color: #ffffff;
        }
        .dark-theme .sidebar-left,
        .dark-theme .sidebar-right,
        .dark-theme .post,
        .dark-theme .post-filters {
            background-color: #242424;
        }
        .dark-theme .post-vote {
            background-color: #1E1E1E;
        }
        .dark-theme .sidebar-nav a,
        .dark-theme .post-header a,
        .dark-theme .post-body,
        .dark-theme .sidebar-right ul li,
        .dark-theme .post-footer {
            color: #b9bbbe;
        }
        .dark-theme .sidebar-nav a:hover,
        .dark-theme .sidebar-nav a.active,
        .dark-theme .post-header a:hover,
        .dark-theme .post-title:hover,
        .dark-theme .post-filters a:hover,
        .dark-theme .post-filters a.active,
        .dark-theme .sidebar-right ul li a:hover,
        .dark-theme .share-btn:hover,
        .dark-theme .comments-btn:hover {
            color: var(--accent-color);
            background-color: #2f3136;
        }
        .dark-theme .sidebar-right .section,
        .dark-theme .post,
        .dark-theme .post-filters {
            border-color: #2f3136;
        }
        .dark-theme ::-webkit-scrollbar-track {
            background: #242424;
        }
        .dark-theme ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
        }
        .dark-theme .user-flair {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .dark-theme .create-post-btn {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .dark-theme .create-post-btn:hover {
            background-color: #2e9b5e;
        }

        /* Custom Theme */
        .custom-theme body {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            color: #ffffff;
        }
        .custom-theme .sidebar-left,
        .custom-theme .sidebar-right,
        .custom-theme .post,
        .custom-theme .post-filters {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
        }
        .custom-theme .post-vote {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
        }
        .custom-theme .sidebar-nav a,
        .custom-theme .post-header a,
        .custom-theme .post-body,
        .custom-theme .sidebar-right ul li,
        .custom-theme .post-footer {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .sidebar-nav a:hover,
        .custom-theme .sidebar-nav a.active,
        .custom-theme .post-header a:hover,
        .custom-theme .post-title:hover,
        .custom-theme .post-filters a:hover,
        .custom-theme .post-filters a.active,
        .custom-theme .sidebar-right ul li a:hover,
        .custom-theme .share-btn:hover,
        .custom-theme .comments-btn:hover {
            color: var(--custom-secondary-color);
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
        }
        .custom-theme .sidebar-right .section,
        .custom-theme .post,
        .custom-theme .post-filters {
            border-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme ::-webkit-scrollbar-track {
            background: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
        }
        .custom-theme ::-webkit-scrollbar-thumb {
            background: var(--custom-secondary-color);
        }
        .custom-theme .user-flair {
            background-color: var(--custom-secondary-color);
            color: #ffffff;
        }
        .custom-theme .create-post-btn {
            background-color: var(--custom-secondary-color);
            color: #ffffff;
        }
        .custom-theme .create-post-btn:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', sans-serif;
            margin: 0;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--secondary-bg);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
            transition: background 0.3s ease;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        .container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
            gap: 1rem;
        }

        .sidebar-left {
            width: 260px;
            background-color: var(--secondary-bg);
            border-right: 1px solid var(--border-color);
            position: sticky;
            top: 64px;
            height: calc(100vh - 64px);
            overflow-y: auto;
            animation: fadeIn 0.5s ease-in-out;
        }

        .sidebar-right {
            width: 300px;
            background-color: var(--secondary-bg);
            border-left: 1px solid var(--border-color);
            position: sticky;
            top: 64px;
            height: calc(100vh - 64px);
            overflow-y: auto;
            padding: 1rem;
            animation: fadeIn 0.5s ease-in-out;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            padding: 1rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .sidebar-nav a:hover {
            color: var(--accent-color);
            background-color: rgba(52, 211, 153, 0.1);
            transform: translateX(4px);
        }

        .sidebar-nav a.active {
            color: var(--accent-color);
            background-color: rgba(52, 211, 153, 0.1);
        }

        .sidebar-nav svg {
            width: 20px;
            height: 20px;
            margin-right: 0.5rem;
        }

        .sidebar-section {
            margin: 1rem 0;
            padding: 0 1rem;
        }

        .sidebar-section h3 {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .sidebar-nav .sub-item {
            padding-left: 2rem;
        }

        .content {
            flex: 1;
            max-width: 800px;
        }

        .post-filters {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            display: flex;
            gap: 1rem;
        }

        .post-filters a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .post-filters a:hover,
        .post-filters a.active {
            color: var(--accent-color);
            background-color: rgba(52, 211, 153, 0.1);
            transform: scale(1.05);
        }

        .post {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 0.75rem;
            display: flex;
            animation: fadeIn 0.5s ease-in-out;
        }

        .post-vote {
            width: 40px;
            background-color: var(--primary-bg);
            padding: 0.5rem 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 8px 0 0 8px;
        }

        .vote-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.25rem;
            padding: 0.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .vote-btn.upvote.active {
            color: var(--vote-active);
            transform: scale(1.2);
        }

        .vote-btn.downvote.active {
            color: var(--vote-down);
            transform: scale(1.2);
        }

        .vote-btn:hover {
            color: var(--accent-color);
            transform: scale(1.1);
        }

        .vote-count {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0.25rem 0;
        }

        .post-content {
            flex: 1;
            padding: 0.75rem;
        }

        .post-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .post-header .user-avatar {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            object-fit: cover;
        }

        .post-header a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .post-header a:hover {
            color: var(--accent-color);
        }

        .user-flair {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.1rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
        }

        .post-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .post-title:hover {
            color: var(--accent-color);
        }

        .post-body {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .post-media {
            margin: 0.75rem 0;
            border-radius: 8px;
            overflow: hidden;
            max-height: 400px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #000;
        }

        .post-media img,
        .post-media video {
            width: 100%;
            height: auto;
            max-height: 400px;
            object-fit: contain;
            display: block;
        }

        .image-carousel-container {
            position: relative;
            width: 100%;
            overflow: hidden;
            border-radius: 8px;
            max-height: 400px;
            background-color: #000;
        }

        .image-carousel {
            display: flex;
            transition: transform 0.5s ease-in-out;
            max-height: 400px;
        }

        .image-carousel img,
        .image-carousel video {
            width: 100%;
            flex-shrink: 0;
            object-fit: contain;
            max-height: 400px;
            display: block;
        }

        .carousel-control {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background-color: rgba(0, 0, 0, 0.7);
            color: var(--text-primary);
            border: none;
            padding: 12px;
            cursor: pointer;
            z-index: 10;
            font-size: 1.25rem;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.8;
            transition: all 0.3s ease;
        }

        .carousel-control:hover {
            opacity: 1;
            background-color: var(--accent-color);
            transform: translateY(-50%) scale(1.1);
        }

        .carousel-control.prev {
            left: 10px;
        }

        .carousel-control.next {
            right: 10px;
        }

        .post-footer {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 0.75rem;
        }

        .share-btn,
        .comments-btn,
        .delete-btn {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .share-btn:hover,
        .comments-btn:hover {
            color: var(--accent-color);
            background-color: rgba(52, 211, 153, 0.1);
            transform: scale(1.05);
        }

        .delete-btn:hover {
            color: var(--vote-down);
            background-color: rgba(248, 113, 113, 0.1);
            transform: scale(1.05);
        }

        .comments-btn svg {
            width: 16px;
            height: 16px;
        }

        .sidebar-right .section {
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            animation: fadeIn 0.5s ease-in-out;
        }

        .sidebar-right h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .sidebar-right ul {
            list-style: none;
            padding: 0;
        }

        .sidebar-right ul li {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .sidebar-right ul li a {
            color: var(--text-primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .sidebar-right ul li a:hover {
            color: var(--accent-color);
        }

        /* Modern Loading Spinner */
        #loading {
            display: none;
            text-align: center;
            padding: 1rem;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--accent-color);
            border-top: 4px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1200px) {
            .sidebar-right {
                width: 100%;
                position: static;
                border-left: none;
                border-top: 1px solid var(--border-color);
            }
            .container {
                flex-direction: column;
            }
        }

        @media (max-width: 1024px) {
            .sidebar-left {
                width: 100%;
                position: static;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }
            .content {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <?php include 'sidebar.php'; ?>

        <div class="content">
            <div class="post-filters">
                <a href="?sort=hot" class="<?php echo $sort_by === 'hot' ? 'active' : ''; ?>"><?php echo $translations['posts']['hot'] ?? 'Popüler'; ?></a>
                <a href="?sort=new" class="<?php echo $sort_by === 'new' ? 'active' : ''; ?>"><?php echo $translations['posts']['new'] ?? 'Yeni'; ?></a>
                <a href="?sort=top" class="<?php echo $sort_by === 'top' ? 'active' : ''; ?>"><?php echo $translations['posts']['top'] ?? 'En İyi'; ?></a>
                <a href="?sort=controversial" class="<?php echo $sort_by === 'controversial' ? 'active' : ''; ?>"><?php echo $translations['posts']['controversial'] ?? 'Tartışmalı'; ?></a>
            </div>

            <div id="posts-container">
                <?php if (!empty($posts)): ?>
                    <?php foreach ($posts as $post): ?>
                        <?php include 'post_template.php'; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php echo $translations['posts']['no_posts'] ?? 'Henüz gönderi bulunmuyor.'; ?></p>
                <?php endif; ?>
            </div>

            <div id="loading">
                <div class="spinner"></div>
            </div>
        </div>

        <aside class="sidebar-right">
            <div class="section">
                <h3><?php echo $translations['posts']['joined_lakealts'] ?? 'Üye Olunan Lakealt\'lar'; ?></h3>
                <ul>
                    <?php if (!empty($joined_lakealts)): ?>
                        <?php foreach ($joined_lakealts as $lakealt_name_item): ?>
                            <li><a href="/lakealt?name=<?php echo urlencode($lakealt_name_item); ?>">l/<?php echo htmlspecialchars($lakealt_name_item); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><?php echo $translations['posts']['no_joined_lakealts'] ?? 'Henüz hiçbir lakealt\'a üye değilsiniz.'; ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="section">
                <h3><?php echo $translations['posts']['moderated_lakealts'] ?? 'Moderatör Olunan Lakealt\'lar'; ?></h3>
                <ul>
                    <?php if (!empty($moderated_lakealts)): ?>
                        <?php foreach ($moderated_lakealts as $lakealt_name_item): ?>
                            <li><a href="/lakealt?name=<?php echo urlencode($lakealt_name_item); ?>">l/<?php echo htmlspecialchars($lakealt_name_item); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><?php echo $translations['posts']['no_moderated_lakealts'] ?? 'Henüz moderatör olduğunuz bir lakealt yok.'; ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="section">
                <h3><?php echo $translations['posts']['create_lakealt'] ?? 'Yeni Lakealt Oluştur'; ?></h3>
                <p><?php echo $translations['posts']['create_lakealt_desc'] ?? 'Kendi topluluğunuzu başlatın.'; ?></p>
                <a href="/create_lakealt" class="create-post-btn" style="padding: 0.5rem 1rem; border-radius: 20px; text-decoration: none; display: inline-block;"><?php echo $translations['posts']['create'] ?? 'Oluştur'; ?></a>
            </div>
        </aside>
    </div>

<script>
// Pass PHP translations to JavaScript
const translations = <?php echo json_encode($translations, JSON_UNESCAPED_UNICODE); ?>;
const csrfToken = '<?php echo $csrfToken; ?>';
const currentLang = '<?php echo $lang; ?>';
const limit = <?php echo $limit; ?>;
const sort = '<?php echo $sort_by; ?>';

$(document).ready(function() {
    // Carousel initialization
    function initializeCarousel(container) {
        if (container.hasClass('initialized')) return;
        const carousel = container.find('.image-carousel');
        const prevBtn = container.find('.carousel-control.prev');
        const nextBtn = container.find('.carousel-control.next');
        let currentIndex = 0;
        const items = carousel.children();
        const totalItems = items.length;

        if (totalItems <= 1) {
            prevBtn.hide();
            nextBtn.hide();
            return;
        }

        function updateCarousel() {
            const itemWidth = items.first().width();
            carousel.css('transform', `translateX(${-currentIndex * itemWidth}px)`);
        }

        $(window).on('resize', updateCarousel);
        updateCarousel();

        prevBtn.on('click', function() {
            currentIndex = (currentIndex > 0) ? currentIndex - 1 : totalItems - 1;
            updateCarousel();
        });

        nextBtn.on('click', function() {
            currentIndex = (currentIndex < totalItems - 1) ? currentIndex + 1 : 0;
            updateCarousel();
        });

        container.addClass('initialized');
    }

    $('.image-carousel-container').each(function() {
        initializeCarousel($(this));
    });

    // Voting
    $(document).on('click', '.vote-btn', function() {
        const button = $(this);
        const postId = button.data('post-id');
        const voteType = button.data('vote-type');

        $.ajax({
            url: '/vote.php',
            method: 'POST',
            data: {
                post_id: postId,
                vote_type: voteType,
                csrf_token: csrfToken
            },
            success: function(response) {
                try {
                    const data = JSON.parse(response);
                    if (data.status === 'success') {
                        const voteCount = button.closest('.post-vote').find('.vote-count');
                        voteCount.text(data.new_votes);
                        button.siblings('.vote-btn').removeClass('active');
                        button.addClass('active');
                    } else {
                        alert((translations.errors?.vote_failed || 'Voting failed: ') + data.message);
                    }
                } catch (e) {
                    console.error('Invalid vote response:', response, e);
                    alert(translations.errors?.general_error || 'An error occurred.');
                }
            },
            error: function(xhr, status, error) {
                console.error('Vote AJAX Error:', status, error, 'Response:', xhr.responseText);
                alert(translations.errors?.server_unreachable || 'Could not connect to the server.');
            }
        });
    });

    // Share function
    function sharePost(postId) {
        const url = `${window.location.origin}/post?id=${postId}`;
        navigator.clipboard.writeText(url).then(() => {
            alert(translations.posts?.link_copied || 'Post link copied!');
        }).catch(() => {
            console.error('Copy failed:', url);
            alert((translations.errors?.copy_failed || 'Failed to copy link, please copy manually: ') + url);
        });
    }

    // Delete function
    function deletePost(postId) {
        if (!confirm(translations.posts?.confirm_delete || 'Are you sure you want to delete this post?')) {
            return;
        }

        $.ajax({
            url: '/delete_post.php',
            method: 'POST',
            data: {
                post_id: postId,
                csrf_token: csrfToken
            },
            success: function(response) {
                try {
                    const data = JSON.parse(response);
                    if (data.status === 'success') {
                        $('#post-' + postId).remove();
                        alert(translations.posts?.delete_success || 'Post deleted successfully.');
                    } else {
                        alert((translations.errors?.delete_failed || 'Post deletion failed: ') + data.message);
                    }
                } catch (e) {
                    console.error('Invalid delete response:', response, e);
                    alert(translations.errors?.general_error || 'An error occurred.');
                }
            },
            error: function(xhr, status, error) {
                console.error('Delete AJAX Error:', status, error, 'Response:', xhr.responseText);
                alert(translations.errors?.server_unreachable || 'Could not connect to the server.');
            }
        });
    }

    // Lazy loading
    let offset = limit;
    let loading = false;
    let hasMore = <?php echo count($posts) === $limit ? 'true' : 'false'; ?>;

    // Debounce function to optimize scroll
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

    $(document).on('click', '.delete-btn', function() {
        const postId = $(this).data('post-id');
        deletePost(postId);
    });

    $(document).on('click', '.share-btn', function() {
        const postId = $(this).data('post-id');
        sharePost(postId);
    });

    $(window).on('scroll', debounce(function() {
        if (loading || !hasMore) return;

        if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) {
            loading = true;
            $('#loading').show();

            $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: {
                    ajax: 1,
                    sort: sort,
                    offset: offset,
                    lang: currentLang
                },
                dataType: 'json',
                success: function(data) {
                    if (data.status === 'success') {
                        $('#posts-container').append(data.html);
                        offset += limit;
                        hasMore = data.has_more;

                        $('#posts-container .image-carousel-container').each(function() {
                            if (!$(this).hasClass('initialized')) {
                                initializeCarousel($(this));
                            }
                        });
                    } else {
                        alert((translations.errors?.load_failed || 'Loading failed: ') + data.message);
                    }
                    loading = false;
                    $('#loading').hide();
                },
                error: function(xhr, status, error) {
                    console.error('Lazy Load AJAX Error:', status, error, 'Response:', xhr.responseText);
                    alert(translations.errors?.server_unreachable || 'Could not connect to the server.');
                    loading = false;
                    $('#loading').hide();
                }
            });
        }
    }, 100));
});
</script>
</body>
</html>