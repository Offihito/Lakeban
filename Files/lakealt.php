<?php
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

// Hata raporlamayı etkinleştir
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Dil desteği için header.php'den gelen ayarları kullan


// Helper function for time ago
function time_ago($datetime, $full = false, $translations = []) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculate weeks manually
    $weeks = floor($diff->d / 7);
    $diff->d -= $weeks * 7; // Update days after calculating weeks

    $string = array(
        'y' => $translations['time_ago']['year'] ?? 'yıl',
        'm' => $translations['time_ago']['month'] ?? 'ay',
        'w' => $translations['time_ago']['week'] ?? 'hafta',
        'd' => $translations['time_ago']['day'] ?? 'gün',
        'h' => $translations['time_ago']['hour'] ?? 'saat',
        'i' => $translations['time_ago']['minute'] ?? 'dakika',
        's' => $translations['time_ago']['second'] ?? 'saniye',
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
    return $output ? implode(', ', $output) . ' ' . ($translations['time_ago']['ago'] ?? 'önce') : ($translations['time_ago']['now'] ?? 'şimdi');
}

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Lakealt adı kontrolü
if (!isset($_GET['name'])) {
    header("Location: posts");
    exit;
}

$lakealt_name = $_GET['name'];

// CSRF Token oluşturma
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '');

// Lakealt bilgilerini çek
$sql = "
    SELECT
        l.id,
        l.name,
        l.description,
        l.avatar_url,
        l.banner_url,
        l.rules,
        l.theme_color,
        l.creator_id,
        COUNT(lm.user_id) as member_count
    FROM lakealts l
    LEFT JOIN lakealt_members lm ON l.id = lm.lakealt_id
    WHERE l.name = ?
    GROUP BY l.id";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['error']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.");
}
$stmt->bind_param("s", $lakealt_name);
$stmt->execute();
$lakealt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lakealt) {
    header("Location: posts");
    exit;
}

// Kullanıcının üyelik durumunu kontrol et
$is_member = false;
$sql = "SELECT 1 FROM lakealt_members WHERE lakealt_id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['error']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.");
}
$stmt->bind_param("ii", $lakealt['id'], $_SESSION['user_id']);
$stmt->execute();
$is_member = $stmt->get_result()->fetch_row() !== null;
$stmt->close();

// Kullanıcının kurucu olup olmadığını kontrol et
$is_creator = ($lakealt['creator_id'] == $_SESSION['user_id']);

// Kullanıcının moderatör olup olmadığını kontrol et
$is_moderator = false;
$sql = "SELECT 1 FROM lakealt_moderators WHERE lakealt_id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ii", $lakealt['id'], $_SESSION['user_id']);
    $stmt->execute();
    $is_moderator = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
} else {
    error_log("Moderatör SQL prepare hatası: " . $conn->error);
}

// Moderatörleri çek
$moderators = [];
$sql = "SELECT u.username
        FROM lakealt_moderators lm
        JOIN users u ON lm.user_id = u.id
        WHERE lm.lakealt_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $lakealt['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $moderators[] = $row['username'];
    }
    $stmt->close();
} else {
    error_log("Moderatör SQL prepare hatası: " . $conn->error);
}

// Gönderi Sıralama İşlemi
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

// Gönderileri çek
$sql = "
    SELECT
        posts.id,
        posts.title,
        posts.content,
        posts.created_at,
        posts.upvotes,
        posts.downvotes,
        posts.media_path,
        users.username,
        luf.flair,
        category.name as category_name,
        (SELECT COUNT(*) FROM comments WHERE comments.post_id = posts.id) as comment_count,
        user_profiles.avatar_url as user_avatar_url
    FROM posts
    JOIN users ON posts.user_id = users.id
    LEFT JOIN user_profiles ON users.id = user_profiles.user_id
    LEFT JOIN lakealt_user_flairs luf ON users.id = luf.user_id AND luf.lakealt_id = ?
    JOIN category ON posts.category_id = category.id
    WHERE posts.lakealt_id = ?
    ORDER BY " . $order_by_sql . " LIMIT 20";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['error']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.");
}
$stmt->bind_param("ii", $lakealt['id'], $lakealt['id']);
$stmt->execute();
$result = $stmt->get_result();
$posts = [];
while ($row = $result->fetch_assoc()) {
    $sql_vote = "SELECT vote_type FROM post_votes WHERE user_id = ? AND post_id = ?";
    $stmt_vote = $conn->prepare($sql_vote);
    if ($stmt_vote) {
        $stmt_vote->bind_param("ii", $_SESSION['user_id'], $row['id']);
        $stmt_vote->execute();
        $vote_result = $stmt_vote->get_result();
        $vote = $vote_result->fetch_assoc();
        $row['user_vote'] = $vote ? $vote['vote_type'] : '';
        $stmt_vote->close();
    } else {
        error_log("Oy SQL prepare hatası: " . $conn->error);
    }
    $posts[] = $row;
}
$stmt->close();

// Profil resmi ve flair çek
$profilePicture = null;
$userFlair = null;
$isLoggedIn = isset($_SESSION['user_id']);
if ($isLoggedIn) {
    try {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("SET NAMES utf8mb4");

        $stmt = $db->prepare("
            SELECT up.avatar_url, luf.flair
            FROM user_profiles up
            LEFT JOIN lakealt_user_flairs luf ON up.user_id = luf.user_id AND luf.lakealt_id = ?
            WHERE up.user_id = ?
        ");
        if ($stmt) {
            $stmt->execute([$lakealt['id'], $_SESSION['user_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $profilePicture = $result['avatar_url'] ?? null;
                $userFlair = $result['flair'] ?? null;
            }
        } else {
            error_log("PDO prepare hatası: SELECT avatar_url, flair");
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

$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe2e65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";
$defaultLakealtAvatar = "https://www.redditstatic.com/avatars/avatar_default_02_3CB371.png";
$defaultBanner = "https://www.redditstatic.com/desktop2x/img/community-header-placeholder.png";
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>l/<?php echo htmlspecialchars($lakealt['name']); ?> - <?php echo $translations['header']['logo_text'] ?? 'Lakeban'; ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-bg: #202020;
            --secondary-bg: #181818;
            --accent-color: <?php echo htmlspecialchars($lakealt['theme_color'] ?? '#3CB371'); ?>;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --border-color: #101010;
            --vote-active: <?php echo htmlspecialchars($lakealt['theme_color'] ?? '#3CB371'); ?>;
            --vote-down: #ED4245;
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Arial', sans-serif;
            margin: 0;
        }

        .header {
            background-color: var(--secondary-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-nav {
            display: flex;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            height: 48px;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--text-primary);
        }

        .header-logo img {
            width: 32px;
            height: 32px;
        }

        .header-logo span {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .header-search {
            flex: 1;
            margin: 0 1rem;
        }

        .search-form {
            display: flex;
            align-items: center;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
        }

        .search-input {
            background: none;
            border: none;
            color: var(--text-primary);
            width: 100%;
            outline: none;
            font-size: 0.9rem;
        }

        .search-input::placeholder {
            color: var(--text-secondary);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-btn {
            background: none;
            border: none;
            color: var(--text-primary);
            padding: 0.5rem;
            cursor: pointer;
            font-size: 1.25rem;
        }

        .header-btn:hover {
            color: var(--accent-color);
        }

        .profile-btn {
            width: 130px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .profile-btn img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .hamburger-btn {
            display: none;
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
        }

        .sidebar-nav a:hover {
            color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
        }

        .sidebar-nav a.active {
            color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
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

        .lakealt-header {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }

        .lakealt-banner {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px 8px 0 0;
            margin-bottom: 1rem;
        }

        .lakealt-profile {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-bg);
            position: absolute;
            top: 100px;
            left: 20px;
        }

        .lakealt-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            margin-top: 2rem;
        }

        .lakealt-description {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }

        .lakealt-members {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .lakealt-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .join-btn,
        .leave-btn,
        .create-post-btn {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .leave-btn {
            background-color: #ED4245;
        }

        .join-btn:hover,
        .create-post-btn:hover {
            background-color: #2e8b57;
        }

        .leave-btn:hover {
            background-color: #c13538;
        }

        .settings-btn {
            background-color: #6b7280;
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .settings-btn:hover {
            background-color: #4b5563;
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
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .post-filters a:hover,
        .post-filters a.active {
            color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
        }

        .post {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 0.75rem;
            display: flex;
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
        }

        .vote-btn.upvote.active {
            color: var(--vote-active);
        }

        .vote-btn.downvote.active {
            color: var(--vote-down);
        }

        .vote-btn:hover {
            color: var(--accent-color);
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
        }

        .post-title:hover {
            text-decoration: underline;
        }

        .post-body {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
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
            background-color: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            padding: 10px;
            cursor: pointer;
            z-index: 10;
            font-size: 1.5rem;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.7;
        }

        .carousel-control:hover {
            opacity: 1;
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
        }

        .share-btn:hover,
        .comments-btn:hover {
            color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
        }
        .delete-btn:hover {
            color: #ED4245;
            background-color: rgba(237, 66, 69, 0.1);
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
        }

        .sidebar-right ul li a:hover {
            color: var(--accent-color);
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
                z-index: 1001;
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
            .lakealt-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            .post-filters {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <?php include 'sidebar.php'; ?>

        <div class="content">
            <div class="lakealt-header">
                <img src="<?php echo htmlspecialchars($lakealt['banner_url'] ?: $defaultBanner); ?>" alt="<?php echo $translations['lakealt']['banner_alt'] ?? 'Lakealt Banner'; ?>" class="lakealt-banner">
                <img src="<?php echo htmlspecialchars($lakealt['avatar_url'] ?: $defaultLakealtAvatar); ?>" alt="<?php echo $translations['lakealt']['avatar_alt'] ?? 'Lakealt Avatar'; ?>" class="lakealt-profile">
                <div class="lakealt-title">l/<?php echo htmlspecialchars($lakealt['name']); ?></div>
                <div class="lakealt-members"><?php echo $lakealt['member_count']; ?> <?php echo $translations['lakealt']['members'] ?? 'üye'; ?></div>
                <div class="lakealt-description"><?php echo htmlspecialchars($lakealt['description'] ?: ($translations['lakealt']['no_description'] ?? 'Bu lakealt için henüz bir açıklama yok.')); ?></div>
                <div class="lakealt-actions">
                    <?php if ($is_member && !$is_creator): ?>
                        <button class="leave-btn" data-lakealt-id="<?php echo $lakealt['id']; ?>" data-csrf-token="<?php echo $csrfToken; ?>"><?php echo $translations['lakealt']['leave'] ?? 'Ayrıl'; ?></button>
                    <?php elseif (!$is_member): ?>
                        <button class="join-btn" data-lakealt-id="<?php echo $lakealt['id']; ?>" data-csrf-token="<?php echo $csrfToken; ?>"><?php echo $translations['lakealt']['join'] ?? 'Katıl'; ?></button>
                    <?php endif; ?>
                    <a href="/create_post?lakealt_id=<?php echo $lakealt['id']; ?>" class="create-post-btn"><?php echo $translations['lakealt']['create_post'] ?? 'Gönderi Oluştur'; ?></a>
                    <?php if ($is_creator || $is_moderator): ?>
                        <a href="/customize_lakealt?lakealt_id=<?php echo $lakealt['id']; ?>" class="settings-btn"><?php echo $translations['lakealt']['settings'] ?? 'Ayarlar'; ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="post-filters">
                <a href="?name=<?php echo urlencode($lakealt_name); ?>&sort=hot" class="<?php echo $sort_by === 'hot' ? 'active' : ''; ?>"><?php echo $translations['lakealt']['hot'] ?? 'Popüler'; ?></a>
                <a href="?name=<?php echo urlencode($lakealt_name); ?>&sort=new" class="<?php echo $sort_by === 'new' ? 'active' : ''; ?>"><?php echo $translations['lakealt']['new'] ?? 'Yeni'; ?></a>
                <a href="?name=<?php echo urlencode($lakealt_name); ?>&sort=top" class="<?php echo $sort_by === 'top' ? 'active' : ''; ?>"><?php echo $translations['lakealt']['top'] ?? 'En İyi'; ?></a>
                <a href="?name=<?php echo urlencode($lakealt_name); ?>&sort=controversial" class="<?php echo $sort_by === 'controversial' ? 'active' : ''; ?>"><?php echo $translations['lakealt']['controversial'] ?? 'Tartışmalı'; ?></a>
            </div>

            <?php if (!empty($posts)): ?>
                <?php foreach ($posts as $post): ?>
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
                                <img src="<?php echo htmlspecialchars($post['user_avatar_url'] ?: $defaultProfilePicture); ?>" alt="<?php echo $translations['lakealt']['user_avatar_alt'] ?? 'User Avatar'; ?>" class="user-avatar">
                                <a href="/lakealt?name=<?php echo urlencode($lakealt['name']); ?>">
                                    l/<?php echo htmlspecialchars($lakealt['name']); ?>
                                </a>
                                <span>•</span>
                                <a href="/profile-page?username=<?php echo urlencode($post['username']); ?>">
                                    <?php echo htmlspecialchars($post['username']); ?>
                                </a>
                                <?php if (!empty($post['flair'])): ?>
                                    <span class="user-flair"><?php echo htmlspecialchars($post['flair']); ?></span>
                                <?php endif; ?>
                                <span>•</span>
                                <span><?php echo time_ago($post['created_at'], false, $translations); ?></span>
                            </div>
                            <div class="post-title" onclick="window.location.href='/post?id=<?php echo $post['id']; ?>'">
                                <?php echo htmlspecialchars($post['title']); ?>
                            </div>
                            <div class="post-body">
                                <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                            </div>
                            <?php if (!empty($post['media_path'])): ?>
                                <div class="post-media">
                                    <?php
                                    $media_paths = json_decode($post['media_path'], true);
                                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($media_paths) || empty($media_paths)) {
                                        $media_paths = [$post['media_path']];
                                    }

                                    if (count($media_paths) > 1) {
                                        echo '<div class="image-carousel-container">';
                                        echo '<button class="carousel-control prev"><i class="fas fa-chevron-left"></i></button>';
                                        echo '<div class="image-carousel">';
                                        foreach ($media_paths as $media_url) {
                                            $file_extension = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
                                            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                                echo '<img src="' . htmlspecialchars($media_url) . '" alt="' . ($translations['lakealt']['post_media_alt'] ?? 'Post Media') . '" loading="lazy">';
                                            } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                                                echo '<video controls preload="metadata">
                                                        <source src="' . htmlspecialchars($media_url) . '" type="video/' . $file_extension . '">
                                                        ' . ($translations['lakealt']['video_unsupported'] ?? 'Tarayıcınız video oynatmayı desteklemiyor.') . '
                                                      </video>';
                                            }
                                        }
                                        echo '</div>';
                                        echo '<button class="carousel-control next"><i class="fas fa-chevron-right"></i></button>';
                                        echo '</div>';
                                    } else {
                                        $media_url = !empty($media_paths) ? $media_paths[0] : null;
                                        if ($media_url) {
                                            $file_extension = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
                                            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                                echo '<img src="' . htmlspecialchars($media_url) . '" alt="' . ($translations['lakealt']['post_media_alt'] ?? 'Post Media') . '" loading="lazy">';
                                            } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                                                echo '<video controls preload="metadata">
                                                        <source src="' . htmlspecialchars($media_url) . '" type="video/' . $file_extension . '">
                                                        ' . ($translations['lakealt']['video_unsupported'] ?? 'Tarayıcınız video oynatmayı desteklemiyor.') . '
                                                      </video>';
                                            }
                                        }
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                            <div class="post-footer">
                                <div class="share-btn" onclick="sharePost(<?php echo $post['id']; ?>)">
                                    <i class="fas fa-share"></i>
                                    <span><?php echo $translations['lakealt']['share'] ?? 'Paylaş'; ?></span>
                                </div>
                                <a href="/post?id=<?php echo $post['id']; ?>" class="comments-btn">
                                    <svg class="icon-comment" fill="currentColor" height="16" viewBox="0 0 20 20" width="16" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M10 19H1.871a.886.886 0 0 1-.798-.52.886.886 0 0 1 .158-.941L3.1 15.771A9 9 0 1 1 10 19Zm-6.549-1.5H10a7.5 7.5 0 1 0-5.323-2.219l.54.545L3.451 17.5Z"></path>
                                    </svg>
                                    <span><?php echo $post['comment_count']; ?></span>
                                    <span class="sr-only"><?php echo $translations['lakealt']['go_to_comments'] ?? 'Yorumlara git'; ?></span>
                                </a>
                                <?php if ($is_creator || $is_moderator): ?>
                                    <div class="delete-btn" data-post-id="<?php echo $post['id']; ?>" data-csrf-token="<?php echo $csrfToken; ?>">
                                        <i class="fas fa-trash"></i>
                                        <span><?php echo $translations['lakealt']['delete'] ?? 'Sil'; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php echo $translations['lakealt']['no_posts'] ?? 'Bu lakealt’ta henüz gönderi bulunmuyor.'; ?></p>
            <?php endif; ?>
        </div>

        <aside class="sidebar-right">
            <div class="section">
                <h3><?php echo $translations['lakealt']['about'] ?? 'Hakkında'; ?></h3>
                <p><?php echo htmlspecialchars($lakealt['description'] ?: ($translations['lakealt']['no_description'] ?? 'Bu lakealt için henüz bir açıklama henüz belirlenmedi.')); ?></p>
                <p><strong><?php echo $translations['lakealt']['members'] ?? 'Üyeler'; ?>:</strong> <?php echo $lakealt['member_count']; ?></p>
            </div>
            <div class="section">
                <h3><?php echo $translations['lakealt']['rules'] ?? 'Kurallar'; ?></h3>
                <?php if (!empty($lakealt['rules'])): ?>
                    <ul>
                        <?php
                        $rules = explode("\n", $lakealt['rules']);
                        foreach ($rules as $index => $rule):
                            if (trim($rule)):
                        ?>
                            <li><?php echo ($index + 1) . '. ' . htmlspecialchars(trim($rule)); ?></li>
                        <?php endif; endforeach; ?>
                    </ul>
                <?php else: ?>
                    <ul>
                        <li>1. <?php echo $translations['lakealt']['rule1'] ?? 'Saygılı ol.'; ?></li>
                        <li>2. <?php echo $translations['lakealt']['rule2'] ?? 'Spam yapma.'; ?></li>
                        <li>3. <?php echo $translations['lakealt']['rule3'] ?? 'Kurallara uygun içerik paylaş.'; ?></li>
                        <li>4. <?php echo $translations['lakealt']['rule4'] ?? 'Başkalarını rahatsız etme.'; ?></li>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="section">
                <h3><?php echo $translations['lakealt']['moderators'] ?? 'Moderatörler'; ?></h3>
                <ul>
                    <?php if (!empty($moderators)): ?>
                        <?php foreach ($moderators as $mod): ?>
                            <li><a href="/profile-page?username=<?php echo urlencode($mod); ?>"><?php echo htmlspecialchars($mod); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><?php echo $translations['lakealt']['no_moderators'] ?? 'Henüz moderatör yok.'; ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </aside>
    </div>

    <script>
        // Hamburger menü
        document.querySelector('.hamburger-btn')?.addEventListener('click', () => {
            document.querySelector('.sidebar-left').classList.toggle('active');
        });

        // Oylama işlemi
        $('.vote-btn').on('click', function() {
            const button = $(this);
            const postId = button.data('post-id');
            const voteType = button.data('vote-type');
            const csrfToken = button.data('csrf-token');

            $.ajax({
                url: '/vote.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    post_id: postId,
                    vote_type: voteType,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    if (data.status === 'success') {
                        const voteCount = button.closest('.post-vote').find('.vote-count');
                        voteCount.text(data.new_votes);
                        
                        const upvoteBtn = button.closest('.post-vote').find('.upvote');
                        const downvoteBtn = button.closest('.post-vote').find('.downvote');
                        
                        upvoteBtn.removeClass('active');
                        downvoteBtn.removeClass('active');

                        if (data.new_vote_type === 'upvote') {
                            upvoteBtn.addClass('active');
                        } else if (data.new_vote_type === 'downvote') {
                            downvoteBtn.addClass('active');
                        }
                    } else {
                        alert('<?php echo $translations['error']['vote_failed'] ?? 'Oylama işlemi başarısız'; ?>: ' + data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Hatası:', status, error);
                    alert('<?php echo $translations['error']['server_error'] ?? 'Sunucuyla iletişim kurulamadı.'; ?>');
                }
            });
        });

        // Katıl işlemi
        $('.join-btn').on('click', function() {
            const button = $(this);
            const lakealtId = button.data('lakealt-id');
            const csrfToken = button.data('csrf-token');

            $.ajax({
                url: '/join_lakealt.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    lakealt_id: lakealtId,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    if (data.success === true) {
                        location.reload();
                    } else {
                        alert('<?php echo $translations['error']['join_failed'] ?? 'Katılma işlemi başarısız'; ?>: ' + data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Katılma AJAX hatası:', status, error);
                    alert('<?php echo $translations['error']['server_error'] ?? 'Sunucuyla iletişim kurulamadı.'; ?>');
                    location.reload();
                }
            });
        });

        // Ayrıl işlemi
        $('.leave-btn').on('click', function() {
            const button = $(this);
            const lakealtId = button.data('lakealt-id');
            const csrfToken = button.data('csrf-token');

            $.ajax({
                url: '/leave_lakealt.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    lakealt_id: lakealtId,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    if (data.success === true) {
                        location.reload();
                    } else {
                        alert('<?php echo $translations['error']['leave_failed'] ?? 'Ayrılma işlemi başarısız'; ?>: ' + data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Ayrılma AJAX hatası:', status, error);
                    alert('<?php echo $translations['error']['server_error'] ?? 'Sunucuyla iletişim kurulamadı.'; ?>');
                    location.reload();
                }
            });
        });

        // Paylaşma fonksiyonu
        function sharePost(postId) {
            const url = `${window.location.origin}/post?id=${postId}`;
            navigator.clipboard.writeText(url).then(() => {
                alert('<?php echo $translations['lakealt']['link_copied'] ?? 'Gönderi bağlantısı kopyalandı!'; ?>');
            }).catch(() => {
                console.error('Kopyalama başarısız.');
                alert('<?php echo $translations['error']['copy_failed'] ?? 'Bağlantı kopyalanamadı, lütfen manuel olarak kopyalayın: '; ?>' + url);
            });
        }

        // delete-btn için AJAX
        $('.delete-btn').on('click', function() {
            const button = $(this);
            const postId = button.data('post-id');
            const csrfToken = button.data('csrf-token');

            if (confirm('<?php echo $translations['lakealt']['confirm_delete'] ?? 'Bu gönderiyi silmek istediğinize emin misiniz?'; ?>')) {
                $.ajax({
                    url: '/delete_post.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        post_id: postId,
                        csrf_token: csrfToken
                    },
                    success: function(data) {
                        if (data.status === 'success') {
                            button.closest('.post').remove();
                        } else {
                            alert('<?php echo $translations['error']['delete_failed'] ?? 'Silme işlemi başarısız'; ?>: ' + data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Silme AJAX hatası:', status, error);
                        alert('<?php echo $translations['error']['server_error'] ?? 'Sunucu hatası'; ?>');
                    }
                });
            }
        });

        // Carousel için JavaScript
        $(document).ready(function() {
            $('.image-carousel-container').each(function() {
                const container = $(this);
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
                    if (itemWidth > 0) {
                        carousel.css('transform', `translateX(${-currentIndex * itemWidth}px)`);
                    }
                }

                $(window).on('resize', updateCarousel).trigger('resize');
                setTimeout(updateCarousel, 500);

                prevBtn.on('click', function() {
                    currentIndex = (currentIndex > 0) ? currentIndex - 1 : totalItems - 1;
                    updateCarousel();
                });

                nextBtn.on('click', function() {
                    currentIndex = (currentIndex < totalItems - 1) ? currentIndex + 1 : 0;
                    updateCarousel();
                });
            });
        });
    </script>
</body>
</html>