<?php
session_start();
require_once 'config.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// *** BAŞLIK İÇİN KULLANICI BİLGİLERİNİ ÇEKME BAŞLANGICI ***
$profilePicture = null;
$username = null;
$isLoggedIn = isset($_SESSION['user_id']);
if ($isLoggedIn) {
    try {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("SET NAMES utf8mb4");

        $stmt_user_data = $db->prepare("SELECT u.username, up.avatar_url FROM users u LEFT JOIN user_profiles up ON u.id = up.user_id WHERE u.id = ?");
        if ($stmt_user_data) {
            $stmt_user_data->execute([$_SESSION['user_id']]);
            $result_user_data = $stmt_user_data->fetch(PDO::FETCH_ASSOC);
            if ($result_user_data) {
                $username = htmlspecialchars($result_user_data['username'] ?? '', ENT_QUOTES, 'UTF-8');
                $profilePicture = htmlspecialchars($result_user_data['avatar_url'] ?? '', ENT_QUOTES, 'UTF-8');
            }
            $_SESSION['username'] = $username;
        } else {
            error_log("PDO prepare hatası: SELECT username, avatar_url");
        }
    } catch (PDOException $e) {
        error_log("Veritabanı bağlantı hatası (kullanıcı bilgileri): " . $e->getMessage());
    }
}
// *** BAŞLIK İÇİN KULLANICI BİLGİLERİNİ ÇEKME SONU ***


$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe2e65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";
$defaultLakealtBanner = "https://via.placeholder.com/400x150?text=Lakealt+Banner"; // Varsayılan banner resmi
$defaultLakealtAvatar = "https://via.placeholder.com/50x50?text=L"; // Varsayılan profil resmi (avatar)

// Handle search and category filters (basic implementation)
$search_query = isset($_GET['search']) ? htmlspecialchars($_GET['search'], ENT_QUOTES, 'UTF-8') : '';
$selected_category = isset($_GET['category']) ? htmlspecialchars($_GET['category'], ENT_QUOTES, 'UTF-8') : 'all';
$sort_by = isset($_GET['sort_by']) ? htmlspecialchars($_GET['sort_by'], ENT_QUOTES, 'UTF-8') : 'name_asc';

// Build the base SQL query for all lakealts
$sql = "SELECT l.id, l.name, l.description, l.banner_url, l.avatar_url, COUNT(lm.user_id) AS member_count 
        FROM lakealts l 
        LEFT JOIN lakealt_members lm ON l.id = lm.lakealt_id 
        GROUP BY l.id, l.name, l.description, l.banner_url, l.avatar_url"; // Group by all selected columns

$where_clauses = [];
$params = [];
$param_types = '';

if (!empty($search_query)) {
    $where_clauses[] = "(l.name LIKE ? OR l.description LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
    $param_types .= 'ss';
}

// Add category filtering (assuming a 'category' column or join with a categories table)
// For now, let's keep it simple with a placeholder.
// If you have a 'category' column in 'lakealts' table:
/*
if ($selected_category !== 'all') {
    $where_clauses[] = "l.category = ?";
    $params[] = $selected_category;
    $param_types .= 's';
}
*/

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

// Add sorting
switch ($sort_by) {
    case 'newest':
        $sql .= " ORDER BY l.created_at DESC"; // Assuming 'created_at' column exists
        break;
    case 'most_members':
        $sql .= " ORDER BY member_count DESC";
        break;
    case 'name_asc':
    default:
        $sql .= " ORDER BY l.name ASC";
        break;
}


$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$lakealts = [];
while ($row = $result->fetch_assoc()) {
    $row['banner_url'] = !empty($row['banner_url']) ? htmlspecialchars($row['banner_url'], ENT_QUOTES, 'UTF-8') : $defaultLakealtBanner;
    $row['avatar_url'] = !empty($row['avatar_url']) ? htmlspecialchars($row['avatar_url'], ENT_QUOTES, 'UTF-8') : $defaultLakealtAvatar;
    $lakealts[] = $row;
}
$stmt->close();

// Fetch joined lakealts for the current user
$joined_lakealts = [];
$sql_joined = "SELECT l.name FROM lakealts l JOIN lakealt_members lm ON l.id = lm.lakealt_id WHERE lm.user_id = ? ORDER BY l.name ASC";
$stmt_joined = $conn->prepare($sql_joined);
if ($stmt_joined) {
    $stmt_joined->bind_param("i", $_SESSION['user_id']);
    $stmt_joined->execute();
    $result_joined = $stmt_joined->get_result();
    while ($row_joined = $result_joined->fetch_assoc()) {
        $joined_lakealts[] = $row_joined;
    }
    $stmt_joined->close();
} else {
    error_log("SQL prepare hatası (katılınan lakealtlar): " . $conn->error);
}

// Fetch popular lakealts (e.g., by member count - you might need a more complex query for activity)
$popular_lakealts = [];
$sql_popular = "SELECT l.id, l.name, l.description, l.banner_url, l.avatar_url, COUNT(lm.user_id) AS member_count 
                FROM lakealts l 
                LEFT JOIN lakealt_members lm ON l.id = lm.lakealt_id 
                GROUP BY l.id 
                ORDER BY member_count DESC 
                LIMIT 5"; // Sadece ilk 5 popüler lakealtı çek
$stmt_popular = $conn->prepare($sql_popular);
if ($stmt_popular) {
    $stmt_popular->execute();
    $result_popular = $stmt_popular->get_result();
    while ($row_popular = $result_popular->fetch_assoc()) {
        $row_popular['banner_url'] = !empty($row_popular['banner_url']) ? htmlspecialchars($row_popular['banner_url'], ENT_QUOTES, 'UTF-8') : $defaultLakealtBanner;
        $row_popular['avatar_url'] = !empty($row_popular['avatar_url']) ? htmlspecialchars($row_popular['avatar_url'], ENT_QUOTES, 'UTF-8') : $defaultLakealtAvatar;
        $popular_lakealts[] = $row_popular;
    }
    $stmt_popular->close();
} else {
    error_log("SQL prepare hatası (popüler lakealtlar): " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lakealt'ları Keşfet - Lakeban</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-bg: #1a1a1a;
            --secondary-bg: #2a2a2a;
            --text-primary: #e0e0e0;
            --text-secondary: #a0a0a0;
            --accent-color: #3cb371; /* MediumSeaGreen */
            --border-color: #444;
            --hover-bg: rgba(60, 179, 113, 0.2);
            --shadow-color: rgba(0, 0, 0, 0.3);
            --card-shadow: rgba(0, 0, 0, 0.2);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px; /* Increased max-width for desktop */
            margin: 2rem auto;
            padding: 1rem;
            background-color: var(--secondary-bg);
            border-radius: 8px;
            box-shadow: 0 4px 12px var(--shadow-color);
        }

        /* Hero Section */
        .hero-section {
            background: linear-gradient(to right, var(--accent-color), #2E8B57);
            color: white;
            padding: 3rem 1rem;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4);
        }

        .hero-section h1 {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: white; /* Override default text-primary */
            border-bottom: none; /* Remove border from here */
            padding-bottom: 0;
        }

        .hero-section p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-section .create-lakealt-btn {
            background-color: white;
            color: var(--accent-color);
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 700;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .hero-section .create-lakealt-btn:hover {
            background-color: #f0f0f0;
            transform: translateY(-3px);
        }

        h1 {
            font-size: 2rem;
            font-weight: bold;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .joined-lakealts, .popular-lakealts {
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            padding: 1rem;
            border-radius: 8px;
            background-color: var(--primary-bg);
            box-shadow: 0 2px 8px var(--card-shadow);
        }

        .joined-lakealts h2, .popular-lakealts h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .joined-lakealt-btn {
            display: inline-block;
            background-color: rgba(60, 179, 113, 0.1);
            color: var(--accent-color);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 500;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            transition: background-color 0.2s, color 0.2s;
        }

        .joined-lakealt-btn:hover {
            background-color: var(--hover-bg);
            color: var(--text-primary);
        }

        .lakealt-list, .popular-lakealt-list {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); /* More columns for desktop */
        }

        .lakealt-card {
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px var(--card-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
            position: relative; /* For member count positioning */
        }

        .lakealt-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 6px 16px var(--shadow-color);
        }

        .lakealt-card-banner {
            width: 100%;
            height: 120px;
            object-fit: cover;
            display: block;
        }

        .lakealt-card-content {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .lakealt-card-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-color);
            margin-top: -30px;
            margin-left: 1rem;
            position: relative;
            z-index: 1;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }

        .lakealt-card h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            margin-top: 0.5rem;
        }

        .lakealt-card h2 a {
            color: var(--text-primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .lakealt-card h2 a:hover {
            color: var(--accent-color);
        }

        .lakealt-card p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.4;
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .member-count {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .member-count i {
            color: var(--accent-color);
        }

        /* Filter and Sort Section */
        .filter-sort-section {
            margin-bottom: 2rem;
            padding: 1rem;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 2px 8px var(--card-shadow);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .filter-sort-section label {
            font-weight: 600;
            color: var(--text-primary);
        }

        .filter-sort-section input[type="text"],
        .filter-sort-section select {
            padding: 0.6rem 1rem;
            border-radius: 5px;
            border: 1px solid var(--border-color);
            background-color: var(--secondary-bg);
            color: var(--text-primary);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
            flex-grow: 1; /* Allow search input to grow */
            min-width: 180px; /* Minimum width for search input */
        }

        .filter-sort-section input[type="text"]:focus,
        .filter-sort-section select:focus {
            border-color: var(--accent-color);
        }

        .category-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .category-buttons .category-btn {
            background-color: rgba(60, 179, 113, 0.1);
            color: var(--accent-color);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: background-color 0.2s, color 0.2s;
            border: 1px solid rgba(60, 179, 113, 0.5);
            cursor: pointer;
        }

        .category-buttons .category-btn:hover {
            background-color: var(--hover-bg);
            color: var(--text-primary);
        }

        .category-buttons .category-btn.active {
            background-color: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
        }

        /* Pagination/Load More (Placeholder) */
        .pagination-controls {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
            gap: 1rem;
        }

        .pagination-controls button {
            background-color: var(--accent-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.2s;
        }

        .pagination-controls button:hover {
            background-color: #2E8B57;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 0.5rem;
            }

            .hero-section h1 {
                font-size: 2rem;
            }

            .hero-section p {
                font-size: 1rem;
            }

            .lakealt-list, .popular-lakealt-list {
                grid-template-columns: 1fr; /* Single column on small screens */
            }

            .filter-sort-section {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="hero-section">
        <h1>Lakealt’larla Topluluğunuzu Bulun!</h1>
        <p>İlgi alanlarınıza uygun topluluklara katılın veya kendi topluluğunuzu yaratın.</p>
        <a href="/create_lakealt.php" class="create-lakealt-btn">
            <i class="fas fa-plus"></i> Lakealt Oluştur
        </a>
    </div>

    <div class="container">
        <h1>Lakealt'ları Keşfet</h1>

        <div class="joined-lakealts">
            <h2>Katıldığınız Lakealt'lar</h2>
            <?php if (!empty($joined_lakealts)): ?>
                <?php foreach ($joined_lakealts as $lakealt): ?>
                    <a href="/lakealt.php?name=<?php echo urlencode($lakealt['name']); ?>" class="joined-lakealt-btn">
                        l/<?php echo htmlspecialchars($lakealt['name']); ?>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: var(--text-secondary);">Henüz bir lakealt'a katılmadınız.</p>
            <?php endif; ?>
        </div>

        <div class="popular-lakealts">
            <h2>Popüler Lakealt'lar</h2>
            <?php if (!empty($popular_lakealts)): ?>
                <div class="popular-lakealt-list lakealt-list">
                    <?php foreach ($popular_lakealts as $lakealt): ?>
                        <div class="lakealt-card">
                            <img src="<?php echo $lakealt['banner_url']; ?>" alt="<?php echo htmlspecialchars($lakealt['name']); ?> Banner" class="lakealt-card-banner">
                            <img src="<?php echo $lakealt['avatar_url']; ?>" alt="<?php echo htmlspecialchars($lakealt['name']); ?> Avatar" class="lakealt-card-avatar">
                            <div class="lakealt-card-content">
                                <h2><a href="/lakealt.php?name=<?php echo urlencode($lakealt['name']); ?>">l/<?php echo htmlspecialchars($lakealt['name']); ?></a></h2>
                                <p><?php echo htmlspecialchars($lakealt['description']); ?></p>
                                <div class="member-count">
                                    <i class="fas fa-users"></i> <?php echo number_format($lakealt['member_count']); ?> Üye
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: var(--text-secondary);">Henüz popüler lakealt yok.</p>
            <?php endif; ?>
        </div>

        <div class="filter-sort-section">
            <label for="search-input"><i class="fas fa-search"></i> Ara:</label>
            <input type="text" id="search-input" placeholder="Lakealt ara..." value="<?php echo $search_query; ?>">

            <label for="sort-select"><i class="fas fa-sort"></i> Sırala:</label>
            <select id="sort-select">
                <option value="name_asc" <?php echo ($sort_by == 'name_asc') ? 'selected' : ''; ?>>Ad A-Z</option>
                <option value="newest" <?php echo ($sort_by == 'newest') ? 'selected' : ''; ?>>En Yeni</option>
                <option value="most_members" <?php echo ($sort_by == 'most_members') ? 'selected' : ''; ?>>En Çok Üye</option>
            </select>

            <div class="category-buttons">
                <label><i class="fas fa-tags"></i> Kategoriler:</label>
                <button class="category-btn <?php echo ($selected_category == 'all') ? 'active' : ''; ?>" data-category="all">Tümü</button>
                <button class="category-btn <?php echo ($selected_category == 'tech') ? 'active' : ''; ?>" data-category="tech">Teknoloji</button>
                <button class="category-btn <?php echo ($selected_category == 'gaming') ? 'active' : ''; ?>" data-category="gaming">Oyun</button>
                <button class="category-btn <?php echo ($selected_category == 'music') ? 'active' : ''; ?>" data-category="music">Müzik</button>
                <button class="category-btn <?php echo ($selected_category == 'art') ? 'active' : ''; ?>" data-category="art">Sanat</button>
                <button class="category-btn <?php echo ($selected_category == 'science') ? 'active' : ''; ?>" data-category="science">Bilim</button>
                </div>
        </div>

        <div class="lakealt-list">
            <?php if (!empty($lakealts)): ?>
                <?php foreach ($lakealts as $lakealt): ?>
                    <div class="lakealt-card">
                        <img src="<?php echo $lakealt['banner_url']; ?>" alt="<?php echo htmlspecialchars($lakealt['name']); ?> Banner" class="lakealt-card-banner">
                        <img src="<?php echo $lakealt['avatar_url']; ?>" alt="<?php echo htmlspecialchars($lakealt['name']); ?> Avatar" class="lakealt-card-avatar">
                        <div class="lakealt-card-content">
                            <h2><a href="/lakealt.php?name=<?php echo urlencode($lakealt['name']); ?>">l/<?php echo htmlspecialchars($lakealt['name']); ?></a></h2>
                            <p><?php echo htmlspecialchars($lakealt['description']); ?></p>
                            <div class="member-count">
                                <i class="fas fa-users"></i> <?php echo number_format($lakealt['member_count']); ?> Üye
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: var(--text-secondary);">Aradığınız kriterlere uygun lakealt bulunamadı. İlk lakealt'ı siz oluşturun!</p>
            <?php endif; ?>
        </div>

        <div class="pagination-controls">
            <button id="load-more-btn">Daha Fazla Yükle</button>
        </div>
    </div>

    <script>
        function applyFilters() {
            const searchQuery = $('#search-input').val();
            const sortBy = $('#sort-select').val();
            const selectedCategory = $('.category-btn.active').data('category') || 'all'; // Get active category button

            let url = new URL(window.location.href);
            url.searchParams.set('search', searchQuery);
            url.searchParams.set('sort_by', sortBy);
            url.searchParams.set('category', selectedCategory); // Add category to URL
            window.location.href = url.toString();
        }

        // Apply filters on search input change (with a small delay for typing)
        let searchTimeout;
        $('#search-input').on('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 500); // Wait 500ms after typing stops
        });

        // Apply filters on sort select change
        $('#sort-select').on('change', function() {
            applyFilters();
        });

        // Apply filters on category button click
        $('.category-btn').on('click', function() {
            $('.category-btn').removeClass('active'); // Remove active from all
            $(this).addClass('active'); // Add active to clicked button
            applyFilters();
        });


        // Placeholder for load more button
        $('#load-more-btn').on('click', function() {
            console.log('Load more lakealts...');
            // In a real application, you'd use AJAX to fetch more lakealts
            // You would also need to keep track of the current page/offset
            alert('Daha fazla lakealt yükleme özelliği henüz aktif değil.');
        });
    </script>
</body>
</html>