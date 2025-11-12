<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

// Hata raporlamayı etkinleştir
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Varsayılan dil
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


// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$current_user_id = $_SESSION['user_id'] ?? null;

// Gönderi ID kontrolü
if (!isset($_GET['id'])) {
    header("Location: posts");
    exit;
}

$post_id = (int)$_GET['id'];

// Gönderi bilgilerini çek
$sql = "
    SELECT
        posts.id,
        posts.title,
        posts.content,
        posts.created_at,
        posts.upvotes,
        posts.downvotes,
        posts.media_path,
        posts.edited_at,
        posts.user_id,
        users.username,
        category.name as category_name,
        lakealts.name as lakealt_name,
        lakealts.theme_color,
        user_profiles.avatar_url as user_avatar_url
    FROM posts
    JOIN users ON posts.user_id = users.id
    LEFT JOIN user_profiles ON users.id = user_profiles.user_id
    JOIN category ON posts.category_id = category.id
    JOIN lakealts ON posts.lakealt_id = lakealts.id
    WHERE posts.id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['error']['general'] ?? "Bir sorun oluştu, lütfen tekrar deneyin.");
}
$stmt->bind_param("i", $post_id);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$post) {
    header("Location: posts");
    exit;
}

// Kullanıcının oy durumunu çek
$sql = "SELECT vote_type FROM post_votes WHERE user_id = ? AND post_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ii", $_SESSION['user_id'], $post_id);
    $stmt->execute();
    $vote_result = $stmt->get_result();
    $vote = $vote_result->fetch_assoc();
    $post['user_vote'] = $vote ? $vote['vote_type'] : '';
    $stmt->close();
} else {
    error_log("Oy SQL prepare hatası: " . $conn->error);
}

// Yorumları çek
$sql = "
    SELECT
        comments.id,
        comments.post_id,
        comments.content,
        comments.created_at,
        comments.upvotes,
        comments.downvotes,
        comments.parent_id,
        comments.is_deleted,
        comments.edited_at,
        users.username,
        user_profiles.avatar_url,
        IFNULL(cv.vote_type, '') as user_vote
    FROM comments
    LEFT JOIN users ON comments.user_id = users.id
    LEFT JOIN user_profiles ON users.id = user_profiles.user_id
    LEFT JOIN comment_votes cv ON comments.id = cv.comment_id AND cv.user_id = ?
    WHERE comments.post_id = ?
    ORDER BY comments.created_at DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['error']['general'] ?? "Bir sorun oluştu, lütfen tekrar deneyin.");
}
$stmt->bind_param("ii", $_SESSION['user_id'], $post_id);
$stmt->execute();
$result = $stmt->get_result();
$comments = [];
$comment_count = 0;
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
    $comment_count++;
}
$stmt->close();

// Profil resmi çek
$profilePicture = null;
$isLoggedIn = isset($_SESSION['user_id']);
if ($isLoggedIn) {
    try {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("SET NAMES utf8mb4");

        $stmt = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
        if ($stmt) {
            $stmt->execute([$_SESSION['user_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && isset($result['avatar_url'])) {
                $profilePicture = $result['avatar_url'];
            }
        } else {
            error_log("PDO prepare hatası: SELECT avatar_url");
        }
    } catch (PDOException $e) {
        error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
    }
}

// Üye olunan lakealtları çek
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
    error_log("Üye lakealt SQL prepare hatası: " . $conn->error);
}

// Moderatör olunan lakealtları çek
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
    error_log("Moderatör lakealt SQL prepare hatası: " . $conn->error);
}

$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";

function displayComments($comments, $parent_id = null, $level = 0, $current_user, $post_id, $defaultProfilePicture, $translations) {
    foreach ($comments as $comment) {
        if ($comment['parent_id'] == $parent_id) {
            $is_deleted = $comment['is_deleted'] == 1;
            $username = $is_deleted ? '[deleted]' : ($comment['username'] ?? '[deleted]');
            $avatar_url = $is_deleted ? $defaultProfilePicture : ($comment['avatar_url'] ?? $defaultProfilePicture);

            echo '<div class="comment" style="margin-left: ' . ($level * 20) . 'px;">';
            echo '<div class="comment-left-section">';
            echo '<div class="comment-vote">';
            echo '<button class="vote-btn upvote ' . ($comment['user_vote'] === 'upvote' ? 'active' : '') . '" data-comment-id="' . $comment['id'] . '" data-vote-type="upvote">';
            echo '<i class="fas fa-arrow-up"></i>';
            echo '</button>';
            echo '<span class="vote-count">' . ($comment['upvotes'] - $comment['downvotes']) . '</span>';
            echo '<button class="vote-btn downvote ' . ($comment['user_vote'] === 'downvote' ? 'active' : '') . '" data-comment-id="' . $comment['id'] . '" data-vote-type="downvote">';
            echo '<i class="fas fa-arrow-down"></i>';
            echo '</button>';
            echo '</div>';
            echo '<div class="comment-profile-pic-wrapper">';
            echo '<img src="' . htmlspecialchars($avatar_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . ($translations['post']['profile_alt'] ?? 'Profil Resmi') . '" class="comment-profile-pic">';
            echo '</div>';
            echo '</div>';
            echo '<div class="comment-content">';
            echo '<div class="comment-header">';
            echo '<a href="/profile-page?username=' . urlencode($username) . '">' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            echo '<span>•</span>';
            echo '<span>' . date('d.m.Y H:i', strtotime($comment['created_at'])) . '</span>';
            if (!empty($comment['edited_at']) && $comment['edited_at'] !== $comment['created_at']) {
                echo ' <span class="text-xs text-gray-400">(' . ($translations['post']['edited'] ?? 'düzenlendi') . ': ' . date('d.m.Y H:i', strtotime($comment['edited_at'])) . ')</span>';
            }
            $is_owner = false;
            if (!$is_deleted && !empty($current_user) && isset($_SESSION['username']) && $username === $_SESSION['username']) {
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == ($comment['user_id'] ?? 0)) {
                    $is_owner = true;
                }
            }
            if ($is_owner) {
                echo '<button class="edit-comment ml-2 text-sm text-gray-500" data-comment-id="' . $comment['id'] . '">' . ($translations['post']['edit'] ?? 'Düzenle') . '</button>';
                echo '<button class="delete-comment ml-2 text-sm text-gray-500" data-comment-id="' . $comment['id'] . '">' . ($translations['post']['delete'] ?? 'Sil') . '</button>';
            }
            echo '</div>';
            echo '<div class="comment-body">' . htmlspecialchars($comment['content'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            echo '<form class="reply-form mt-2 hidden" data-comment-id="' . $comment['id'] . '">';
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            echo '<input type="hidden" name="post_id" value="' . $post_id . '">';
            echo '<input type="hidden" name="parent_id" value="' . $comment['id'] . '">';
            echo '<textarea name="content" placeholder="' . ($translations['post']['reply_placeholder'] ?? 'Yanıt yazın...') . '" required></textarea>';
            echo '<button type="submit" class="submit-btn">' . ($translations['post']['reply'] ?? 'Yanıtla') . '</button>';
            echo '</form>';
            echo '<button class="reply-toggle text-sm text-gray-500 mt-1">' . ($translations['post']['reply'] ?? 'Yanıtla') . '</button>';
            echo '</div>';
            echo '</div>';
            displayComments($comments, $comment['id'], $level + 1, $current_user, $post_id, $defaultProfilePicture, $translations);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($post['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' - ' . ($translations['post']['title_suffix'] ?? 'Lakeban'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-bg: #1a1a1a;
            --secondary-bg: #252525;
            --accent-color: <?php echo htmlspecialchars($post['theme_color'] ?? '#34d399', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>;
            --text-primary: #f3f4f6;
            --text-secondary: #d1d5db;
            --border-color: #2d2d2d;
            --vote-active: <?php echo htmlspecialchars($post['theme_color'] ?? '#34d399', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>;
            --vote-down: #f87171;
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

        .content {
            flex: 1;
            max-width: 800px;
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
        .comments-btn {
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

        .comments-btn svg {
            width: 16px;
            height: 16px;
        }

        .comment-section {
            margin-top: 1.5rem;
        }

        .comment-form {
            margin-bottom: 1rem;
        }

        .comment-form textarea {
            width: 100%;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.5rem;
            color: var(--text-primary);
            resize: vertical;
            min-height: 100px;
        }

        .submit-btn {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            margin-top: 0.5rem;
        }

        .submit-btn:hover {
            background-color: #2e8b57;
        }

        .comment {
            display: flex;
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 0.75rem;
            position: relative;
        }

        .comment::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }

        .comment-left-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            background-color: var(--primary-bg);
            border-radius: 6px 0 0 6px;
        }

        .comment-profile-pic-wrapper {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            margin-bottom: 0.5rem;
            flex-shrink: 0;
        }

        .comment-profile-pic {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .comment-vote {
            width: auto;
            padding: 0.25rem 0.5rem;
        }

        .comment-vote .vote-btn {
            font-size: 1rem;
            padding: 0.1rem;
        }

        .comment-vote .vote-count {
            font-size: 0.8rem;
        }

        .comment-content {
            flex: 1;
            padding: 0.75rem;
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .comment-header a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .comment-header a:hover {
            color: var(--accent-color);
        }

        .comment-body {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .reply-form.hidden {
            display: none;
        }

        .reply-toggle {
            cursor: pointer;
        }

        .reply-toggle:hover {
            color: var(--accent-color);
        }

        .post-options {
            position: relative;
            margin-left: auto;
        }

        .post-options-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0.25rem;
        }

        .post-options-btn:hover {
            color: var(--accent-color);
        }

        .post-options-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            min-width: 120px;
            z-index: 10;
            display: none;
        }

        .post-options-dropdown.active {
            display: block;
        }

        .post-options-dropdown button {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            text-align: left;
            background: none;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.9rem;
        }

        .post-options-dropdown button:hover {
            background-color: var(--primary-bg);
            color: var(--accent-color);
        }

        .edit-post-form textarea,
        .edit-post-form input[type="text"] {
            width: 100%;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.5rem;
            color: var(--text-primary);
            resize: vertical;
            margin-bottom: 0.5rem;
        }

        .edit-post-form .submit-btn {
            width: auto;
            float: right;
        }

        .comment-sort select {
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1200px) {
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

        @media (max-width: 768px) {
            .hamburger-btn {
                display: block;
            }
            .header-search,
            .header-actions .header-btn:not(.hamburger-btn) {
                display: none;
            }
            .sidebar-left {
                position: fixed;
                width: 260px;
                top: 0;
                left: 0;
                height: 100%;
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
            }
            .sidebar-left.active {
                transform: translateX(0);
            }
            .post {
                flex-direction: column;
            }
            .post-vote {
                flex-direction: row;
                justify-content: flex-start;
                padding: 0.5rem;
                border-radius: 8px 8px 0 0;
                width: 100%;
            }
            .comment {
                flex-direction: column;
            }
            .comment-left-section {
                flex-direction: row;
                justify-content: flex-start;
                padding: 0.5rem;
                width: 100%;
                border-radius: 6px 6px 0 0;
            }
            .comment-profile-pic-wrapper {
                margin-right: 0.5rem;
                margin-bottom: 0;
            }
            .comment-vote {
                flex-direction: row;
                margin-left: auto;
                padding: 0;
            }
            .comment::before {
                display: none;
            }
            .vote-btn {
                font-size: 1.5rem;
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <?php include 'sidebar.php'; ?>

        <div class="content">
            <div class="post">
                <div class="post-vote">
                    <button class="vote-btn upvote <?php echo $post['user_vote'] === 'upvote' ? 'active' : ''; ?>" data-post-id="<?php echo $post['id']; ?>" data-vote-type="upvote" data-csrf-token="<?php echo $csrfToken; ?>">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <span class="vote-count"><?php echo ($post['upvotes'] - $post['downvotes']); ?></span>
                    <button class="vote-btn downvote <?php echo $post['user_vote'] === 'downvote' ? 'active' : ''; ?>" data-post-id="<?php echo $post['id']; ?>" data-vote-type="downvote" data-csrf-token="<?php echo $csrfToken; ?>">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                </div>
                <div class="post-content">
                    <div class="post-header">
                        <img src="<?php echo htmlspecialchars($post['user_avatar_url'] ?? $defaultProfilePicture, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="<?php echo $translations['post']['profile_alt'] ?? 'Profil Resmi'; ?>" class="user-avatar">
                        <a href="/lakealt?name=<?php echo urlencode($post['lakealt_name'] ?? ''); ?>">
                            l/<?php echo htmlspecialchars($post['lakealt_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </a>
                        <span>•</span>
                        <a href="/profile-page?username=<?php echo urlencode($post['username'] ?? ''); ?>">
                            <?php echo htmlspecialchars($post['username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </a>
                        <span>•</span>
                        <span><?php echo date('d.m.Y H:i', strtotime($post['created_at'])); ?></span>
                        <?php if (!empty($post['edited_at']) && strtotime($post['edited_at']) > strtotime($post['created_at'])): ?>
                            <span class="text-xs text-gray-400 ml-2">(<?php echo $translations['post']['edited'] ?? 'düzenlendi'; ?>: <?php echo date('d.m.Y H:i', strtotime($post['edited_at'])); ?>)</span>
                        <?php endif; ?>
                        <?php if ($current_user_id == $post['user_id']): ?>
                            <div class="post-options">
                                <button class="post-options-btn" aria-label="<?php echo $translations['post']['options'] ?? 'Gönderi seçenekleri'; ?>">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="post-options-dropdown">
                                    <button class="edit-post-btn" data-post-id="<?php echo $post['id']; ?>"><?php echo $translations['post']['edit'] ?? 'Düzenle'; ?></button>
                                    <button class="delete-post-btn" data-post-id="<?php echo $post['id']; ?>"><?php echo $translations['post']['delete'] ?? 'Sil'; ?></button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="post-title" id="post-title-display"><?php echo htmlspecialchars($post['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <div class="post-body" id="post-content-display"><?php echo htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>

                    <div id="edit-post-form-container" class="hidden">
                        <form class="edit-post-form" id="edit-post-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                            <input type="text" name="title" value="<?php echo htmlspecialchars($post['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                            <textarea name="content" required><?php echo htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                            <button type="submit" class="submit-btn"><?php echo $translations['post']['save'] ?? 'Kaydet'; ?></button>
                            <button type="button" class="submit-btn cancel-edit-btn"><?php echo $translations['post']['cancel'] ?? 'İptal'; ?></button>
                        </form>
                    </div>

                    <?php if ($post['media_path'] ?? ''): ?>
                        <div class="post-media">
                            <?php
                            $media_paths = json_decode($post['media_path'], true);
                            if (is_array($media_paths) && !empty($media_paths)) {
                                if (count($media_paths) > 1) {
                                    echo '<div class="image-carousel-container">';
                                    echo '<button class="carousel-control prev"><i class="fas fa-chevron-left"></i></button>';
                                    echo '<div class="image-carousel">';
                                    foreach ($media_paths as $media_url) {
                                        $file_extension = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
                                        if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                            echo '<img src="' . htmlspecialchars($media_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . ($translations['post']['media_alt'] ?? 'Post Media') . '" loading="lazy">';
                                        } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                                            echo '<video controls><source src="' . htmlspecialchars($media_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" type="video/' . $file_extension . '">' . ($translations['post']['video_error'] ?? 'Tarayıcınız video oynatmayı desteklemiyor.') . '</video>';
                                        }
                                    }
                                    echo '</div>';
                                    echo '<button class="carousel-control next"><i class="fas fa-chevron-right"></i></button>';
                                    echo '</div>';
                                } else {
                                    $media_url = $media_paths[0];
                                    $file_extension = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
                                    if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                        echo '<img src="' . htmlspecialchars($media_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . ($translations['post']['media_alt'] ?? 'Post Media') . '" loading="lazy">';
                                    } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                                        echo '<video controls><source src="' . htmlspecialchars($media_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" type="video/' . $file_extension . '">' . ($translations['post']['video_error'] ?? 'Tarayıcınız video oynatmayı desteklemiyor.') . '</video>';
                                    }
                                }
                            } elseif (is_string($post['media_path'])) {
                                $file_extension = strtolower(pathinfo($post['media_path'], PATHINFO_EXTENSION));
                                if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                    echo '<img src="' . htmlspecialchars($post['media_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . ($translations['post']['media_alt'] ?? 'Post Media') . '" loading="lazy">';
                                } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                                    echo '<video controls><source src="' . htmlspecialchars($post['media_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" type="video/' . $file_extension . '">' . ($translations['post']['video_error'] ?? 'Tarayıcınız video oynatmayı desteklemiyor.') . '</video>';
                                }
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                    <div class="post-footer">
                        <div class="share-btn" onclick="sharePost(<?php echo $post['id']; ?>)">
                            <i class="fas fa-share"></i>
                            <span><?php echo $translations['post']['share'] ?? 'Paylaş'; ?></span>
                        </div>
                        <a href="#comments" class="comments-btn">
                            <svg class="icon-comment" fill="currentColor" height="16" viewBox="0 0 20 20" width="16" xmlns="http://www.w3.org/2000/svg">
                                <path d="M10 19H1.871a.886.886 0 0 1-.798-.52.886.886 0 0 1 .158-.941L3.1 15.771A9 9 0 1 1 10 19Zm-6.549-1.5H10a7.5 7.5 0 1 0-5.323-2.219l.54.545L3.451 17.5Z"></path>
                            </svg>
                            <span><?php echo $comment_count; ?></span>
                            <span class="sr-only"><?php echo $translations['post']['comments'] ?? 'Yorumlar'; ?></span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="comment-section" id="comments">
                <h2 class="text-xl font-semibold mb-4"><?php echo $translations['post']['comments'] ?? 'Yorumlar'; ?></h2>
                <div class="comment-form">
                    <form id="comment-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                        <textarea name="content" placeholder="<?php echo $translations['post']['comment_placeholder'] ?? 'Yorumunuzu yazın...'; ?>" required></textarea>
                        <button type="submit" class="submit-btn"><?php echo $translations['post']['submit_comment'] ?? 'Yorum Yap'; ?></button>
                    </form>
                </div>
                <div class="comment-sort mb-4">
                    <select id="comment-sort" class="p-2 bg-gray-800 text-white rounded">
                        <option value="newest"><?php echo $translations['post']['sort_newest'] ?? 'En Yeni'; ?></option>
                        <option value="best"><?php echo $translations['post']['sort_best'] ?? 'En İyi'; ?></option>
                        <option value="controversial"><?php echo $translations['post']['sort_controversial'] ?? 'En Tartışmalı'; ?></option>
                    </select>
                </div>
                <?php if (!empty($comments)): ?>
                    <?php displayComments($comments, null, 0, $_SESSION['username'] ?? '', $post_id, $defaultProfilePicture, $translations); ?>
                <?php else: ?>
                    <p><?php echo $translations['post']['no_comments'] ?? 'Henüz yorum yok. İlk yorumu siz yapın!'; ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Hamburger menü
            $('.hamburger-btn').on('click', function() {
                $('.sidebar-left').toggleClass('active');
            });

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

            // Gönderi oylama işlemi
            $('.post-vote .vote-btn').on('click', function() {
                const button = $(this);
                const postId = button.data('post-id');
                const voteType = button.data('vote-type');
                const csrfToken = button.data('csrf-token');

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
                                alert('<?php echo $translations['error']['vote_failed'] ?? 'Oylama işlemi başarısız'; ?>: ' + data.message);
                            }
                        } catch (e) {
                            console.error('Invalid response:', response);
                            alert('<?php echo $translations['error']['general'] ?? 'Bir hata oluştu.'; ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Oylama AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['vote_error'] ?? 'Oylama sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Yorum oylama işlemi
            $(document).on('click', '.comment-vote .vote-btn', function() {
                const button = $(this);
                const commentId = button.data('comment-id');
                const voteType = button.data('vote-type');
                const csrfToken = '<?php echo $csrfToken; ?>';

                $.ajax({
                    url: '/comment_vote.php',
                    method: 'POST',
                    data: {
                        comment_id: commentId,
                        vote_type: voteType,
                        csrf_token: csrfToken
                    },
                    success: function(response) {
                        try {
                            const data = JSON.parse(response);
                            if (data.status === 'success') {
                                const voteCount = button.closest('.comment-vote').find('.vote-count');
                                voteCount.text(data.new_votes);
                                button.siblings('.vote-btn').removeClass('active');
                                button.addClass('active');
                            } else {
                                alert('<?php echo $translations['error']['comment_vote_failed'] ?? 'Yorum oylama işlemi başarısız'; ?>: ' + data.message);
                            }
                        } catch (e) {
                            console.error('Invalid response:', response);
                            alert('<?php echo $translations['error']['general'] ?? 'Bir hata oluştu.'; ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Yorum oylama AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['comment_vote_error'] ?? 'Yorum oylama sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Yorum yapma işlemi
            $('#comment-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const data = form.serialize();

                $.ajax({
                    url: '/comment_post.php',
                    method: 'POST',
                    data: data,
                    success: function(data) {
                        if (data.status === 'success') {
                            location.reload();
                        } else {
                            alert('<?php echo $translations['error']['general'] ?? 'Hata'; ?>: ' + data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Yorum AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['comment_error'] ?? 'Yorum ekleme sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Yanıt formunu göster/gizle
            $(document).on('click', '.reply-toggle', function() {
                $(this).siblings('.reply-form').toggleClass('hidden');
            });

            // Yanıt yapma işlemi
            $(document).on('submit', '.reply-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const data = form.serialize();

                $.ajax({
                    url: '/comment_post.php',
                    method: 'POST',
                    data: data,
                    success: function(data) {
                        if (data.status === 'success') {
                            location.reload();
                        } else {
                            alert('<?php echo $translations['error']['general'] ?? 'Hata'; ?>: ' + data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Yanıt AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['reply_error'] ?? 'Yanıt ekleme sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Yorum düzenleme
            $(document).on('click', '.edit-comment', function() {
                const commentId = $(this).data('comment-id');
                const content = $(this).closest('.comment').find('.comment-body').text();
                $(this).closest('.comment').find('.comment-body').html(`
                    <form class="edit-comment-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="comment_id" value="${commentId}">
                        <textarea name="content">${content}</textarea>
                        <button type="submit" class="submit-btn"><?php echo $translations['post']['save'] ?? 'Kaydet'; ?></button>
                    </form>
                `);
            });

            $(document).on('submit', '.edit-comment-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const data = form.serialize();

                $.ajax({
                    url: '/edit_comment.php',
                    method: 'POST',
                    data: data,
                    success: function(data) {
                        if (data.status === 'success') {
                            location.reload();
                        } else {
                            alert('<?php echo $translations['error']['general'] ?? 'Hata'; ?>: ' + data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Yorum düzenleme hatası:', status, error);
                        alert('<?php echo $translations['error']['edit_comment_error'] ?? 'Yorum düzenleme sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Yorum silme
            $(document).on('click', '.delete-comment', function() {
                if (confirm('<?php echo $translations['post']['delete_comment_confirm'] ?? 'Yorumu silmek istediğinizden emin misiniz?'; ?>')) {
                    const commentId = $(this).data('comment-id');
                    $.ajax({
                        url: '/delete_comment.php',
                        method: 'POST',
                        data: { comment_id: commentId, csrf_token: '<?php echo $csrfToken; ?>' },
                        success: function(data) {
                            if (data.status === 'success') {
                                location.reload();
                            } else {
                                alert('<?php echo $translations['error']['general'] ?? 'Hata'; ?>: ' + data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Yorum silme hatası:', status, error);
                            alert('<?php echo $translations['error']['delete_comment_error'] ?? 'Yorum silme sırasında bir hata oluştu.'; ?>');
                        }
                    });
                }
            });

            // Yorum sıralama
            $('#comment-sort').on('change', function() {
                const sort = $(this).val();
                $.ajax({
                    url: '/load_comments.php',
                    method: 'POST',
                    data: { post_id: <?php echo $post['id']; ?>, sort: sort },
                    success: function(response) {
                        $('.comment-section').html(response);
                        $('.image-carousel-container').each(function() {
                            if (!$(this).hasClass('initialized')) {
                                initializeCarousel($(this));
                            }
                        });
                    },
                    error: function(xhr, status, error) {
                        console.error('Yorum sıralama AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['sort_comments_error'] ?? 'Yorum sıralama sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Paylaşma fonksiyonu
            function sharePost(postId) {
                const url = `${window.location.origin}/post?id=${postId}`;
                navigator.clipboard.writeText(url).then(() => {
                    alert('<?php echo $translations['post']['share_success'] ?? 'Gönderi bağlantısı kopyalandı!'; ?>');
                }).catch(() => {
                    console.error('Kopyalama başarısız.');
                    alert('<?php echo $translations['error']['share_error'] ?? 'Bağlantı kopyalanamadı, lütfen manuel olarak kopyalayın: '; ?>' + url);
                });
            }

            // Gönderi seçenekleri dropdown'ı
            $(document).on('click', '.post-options-btn', function() {
                $(this).siblings('.post-options-dropdown').toggleClass('active');
            });

            // Dropdown dışına tıklanınca kapatma
            $(document).on('click', function(event) {
                if (!$(event.target).closest('.post-options').length) {
                    $('.post-options-dropdown').removeClass('active');
                }
            });

            // Gönderiyi düzenleme formunu göster
            $(document).on('click', '.edit-post-btn', function() {
                $('#post-title-display').hide();
                $('#post-content-display').hide();
                $('#edit-post-form-container').removeClass('hidden');
                $('.post-options-dropdown').removeClass('active');
            });

            // Gönderi düzenleme formunu iptal et
            $(document).on('click', '.cancel-edit-btn', function() {
                $('#post-title-display').show();
                $('#post-content-display').show();
                $('#edit-post-form-container').addClass('hidden');
            });

            // Gönderiyi düzenleme işlemi
            $('#edit-post-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const data = form.serialize();

                $.ajax({
                    url: '/edit_post.php',
                    method: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            location.reload();
                        } else {
                            alert('<?php echo $translations['error']['general'] ?? 'Hata'; ?>: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Gönderi düzenleme AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['edit_post_error'] ?? 'Gönderi düzenleme sırasında bir hata oluştu.'; ?>');
                    }
                });
            });

            // Gönderiyi silme işlemi
            $(document).on('click', '.delete-post-btn', function() {
                if (confirm('<?php echo $translations['post']['delete_post_confirm'] ?? 'Gönderiyi silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.'; ?>')) {
                    const postId = $(this).data('post-id');
                    const csrfToken = '<?php echo $csrfToken; ?>';

                    $.ajax({
                        url: '/delete_post.php',
                        method: 'POST',
                        data: {
                            post_id: postId,
                            csrf_token: csrfToken
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.status === 'success') {
                                window.location.href = '/posts';
                            } else {
                                alert('<?php echo $translations['error']['general'] ?? 'Hata'; ?>: ' + response.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Gönderi silme AJAX hatası:', status, error);
                            alert('<?php echo $translations['error']['delete_post_error'] ?? 'Gönderi silme sırasında bir hata oluştu.'; ?>');
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>