<?php
// Hata raporlamayı açalım
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php'; // Veritabanı bağlantısı için

// DEBUG: Oturum değişkenlerini kontrol edelim
// Bu çıktıyı görebiliyorsanız, oturum başlatılmış ve bu dosya işleniyor demektir.
/*
echo "<pre>";
echo "Is user_id set? " . (isset($_SESSION['user_id']) ? "Yes (" . $_SESSION['user_id'] . ")" : "No") . "\n";
echo "Is is_admin set? " . (isset($_SESSION['is_admin']) ? "Yes (" . ($_SESSION['is_admin'] ? "true" : "false") . ")" : "No") . "\n";
echo "</pre>";
*/
// END DEBUG

// YÖNETİCİ YETKİLENDİRME KONTROLÜ
if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] !== true) {
    // Yönetici değilse veya giriş yapmamışsa ana sayfaya yönlendir
    header("Location: index.php"); // veya başka bir hata sayfasına yönlendirebilirsiniz
    exit();
}

// CSRF Token oluştur
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// Admin IP log fonksiyonu
function logAdminAction($conn, $action, $report_id = null) {
    $admin_id = $_SESSION['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, report_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isiss", $admin_id, $action, $report_id, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
}

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

// buildQueryString fonksiyonu eksik, ekleyelim
function buildQueryString($exclude_params = []) {
    $query_params = $_GET;
    foreach ($exclude_params as $param) {
        unset($query_params[$param]);
    }
    return !empty($query_params) ? '&' . http_build_query($query_params) : '';
}

// Şikayet işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF kontrolü
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }

    // Tekli işlemler
    if (isset($_POST['report_id']) && isset($_POST['action'])) {
        $report_id = (int)$_POST['report_id'];
        $action = $_POST['action'];

        $new_status = '';
        if ($action === 'review') {
            $new_status = 'reviewed';
            logAdminAction($conn, "Marked as reviewed", $report_id);
        } elseif ($action === 'reject') {
            $new_status = 'rejected';
            logAdminAction($conn, "Marked as rejected", $report_id);
        } elseif ($action === 'ban') {
            // Kullanıcı yasaklama
            $stmt = $conn->prepare("SELECT reported_user_id FROM user_reports WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $user_id = $row['reported_user_id'];
                $ban_reason = "Banned due to report #$report_id";
                $stmt_ban = $conn->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?");
                if ($stmt_ban) {
                    $stmt_ban->bind_param("si", $ban_reason, $user_id);
                    $stmt_ban->execute();
                    $stmt_ban->close();
                    logAdminAction($conn, "Banned user from report", $report_id);
                }
            }
            $stmt->close();
        }

        if (!empty($new_status)) {
            $stmt_update = $conn->prepare("UPDATE user_reports SET status = ? WHERE id = ?");
            if ($stmt_update) {
                $stmt_update->bind_param("si", $new_status, $report_id);
                $stmt_update->execute();
                $stmt_update->close();
            }
        }
    }
    
    // Toplu işlemler
    if (isset($_POST['bulk_action']) && !empty($_POST['selected_reports'])) {
        $selected_reports = array_map('intval', $_POST['selected_reports']);
        $placeholders = implode(',', array_fill(0, count($selected_reports), '?'));
        
        if ($_POST['bulk_action'] === 'bulk_review') {
            $stmt = $conn->prepare("UPDATE user_reports SET status = 'reviewed' WHERE id IN ($placeholders)");
            if ($stmt) {
                $stmt->bind_param(str_repeat('i', count($selected_reports)), ...$selected_reports);
                $stmt->execute();
                $stmt->close();
                logAdminAction($conn, "Bulk review reports", null);
            }
        } elseif ($_POST['bulk_action'] === 'bulk_reject') {
            $stmt = $conn->prepare("UPDATE user_reports SET status = 'rejected' WHERE id IN ($placeholders)");
            if ($stmt) {
                $stmt->bind_param(str_repeat('i', count($selected_reports)), ...$selected_reports);
                $stmt->execute();
                $stmt->close();
                logAdminAction($conn, "Bulk reject reports", null);
            }
        }
    }

    // İşlem sonrası sayfayı yenilemek için yönlendirme yap
    header("Location: admin_reports.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Filtreleme parametreleri
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$allowed_statuses = ['pending', 'reviewed', 'rejected', 'all'];
if (!in_array($filter_status, $allowed_statuses)) {
    $filter_status = 'pending';
}

$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search_username = isset($_GET['search_username']) ? $_GET['search_username'] : '';
$search_reason = isset($_GET['search_reason']) ? $_GET['search_reason'] : '';

// Sayfalama
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Sıralama
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
$allowed_sorts = ['created_at', 'reported_username', 'reporter_username', 'report_reason'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'created_at';
}

// Şikayetleri çekme sorgusu
$reports = [];
$count_sql = "SELECT COUNT(*) as total FROM user_reports ur JOIN users ru ON ur.reported_user_id = ru.id LEFT JOIN users reru ON ur.reporter_user_id = reru.id"; // user_profiles join'leri count_sql'den çıkarıldı
$sql = "SELECT
        ur.id,
        ur.report_reason,
        ur.report_description,
        ur.created_at,
        ur.status,
        ru.username AS reported_username,
        ru_profile.avatar_url AS reported_avatar_url,
        reru.username AS reporter_username,
        reru_profile.avatar_url AS reporter_avatar_url
    FROM
        user_reports ur
    JOIN
        users ru ON ur.reported_user_id = ru.id
    LEFT JOIN
        user_profiles ru_profile ON ru.id = ru_profile.user_id
    LEFT JOIN
        users reru ON ur.reporter_user_id = reru.id
    LEFT JOIN
        user_profiles reru_profile ON reru.id = reru_profile.user_id";

$where = [];
$params = [];
$types = '';

if ($filter_status !== 'all') {
    $where[] = "ur.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if (!empty($date_from)) {
    $where[] = "ur.created_at >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where[] = "ur.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}

if (!empty($search_username)) {
    $where[] = "(ru.username LIKE ? OR reru.username LIKE ?)";
    $params[] = "%$search_username%";
    $params[] = "%$search_username%";
    $types .= 'ss';
}

if (!empty($search_reason)) {
    $where[] = "ur.report_reason LIKE ?";
    $params[] = "%$search_reason%";
    $types .= 's';
}

if (!empty($where)) {
    $where_clause = " WHERE " . implode(" AND ", $where);
    $sql .= $where_clause;
    $count_sql .= $where_clause;
}

// Toplam kayıt sayısını al
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count) { // Kontrol eklendi
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_result = $stmt_count->get_result();
    $total_rows = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $per_page);
    $stmt_count->close();
} else {
    $total_rows = 0;
    $total_pages = 0;
}


// Sıralama ve sayfalama ekle
$sql .= " ORDER BY $sort $order LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

// Şikayetleri çek
$stmt_reports = $conn->prepare($sql);
if ($stmt_reports) {
    if (!empty($params)) {
        $stmt_reports->bind_param($types, ...$params);
    }
    $stmt_reports->execute();
    $result_reports = $stmt_reports->get_result();
    while ($row = $result_reports->fetch_assoc()) {
        $reports[] = $row;
    }
    $stmt_reports->close();
}

// İstatistikleri çek
$stats = [];
$stat_sql = [
    'total' => "SELECT COUNT(*) FROM user_reports",
    'pending' => "SELECT COUNT(*) FROM user_reports WHERE status = 'pending'",
    'today' => "SELECT COUNT(*) FROM user_reports WHERE DATE(created_at) = CURDATE()",
    'week' => "SELECT COUNT(*) FROM user_reports WHERE YEARWEEK(created_at) = YEARWEEK(NOW())"
];

foreach ($stat_sql as $key => $query) {
    $result = $conn->query($query);
    if ($result) { // Kontrol eklendi
        $stats[$key] = $result->fetch_row()[0];
        $result->free();
    } else {
        $stats[$key] = 0; // Hata durumunda varsayılan değer
    }
}

// Admin raporlarına özel incelenen ve reddedilen istatistiklerini ekleyelim
$result_reviewed = $conn->query("SELECT COUNT(*) FROM user_reports WHERE status = 'reviewed'");
if ($result_reviewed) {
    $stats['reviewed'] = $result_reviewed->fetch_row()[0];
    $result_reviewed->free();
} else {
    $stats['reviewed'] = 0;
}

$result_rejected = $conn->query("SELECT COUNT(*) FROM user_reports WHERE status = 'rejected'");
if ($result_rejected) {
    $stats['rejected'] = $result_rejected->fetch_row()[0];
    $result_rejected->free();
} else {
    $stats['rejected'] = 0;
}
?>


<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['admin_reports_page_title'] ?? 'Şikayet Yönetimi'; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: #bb00ff;
            --secondary-color: #2bff00;
            --text-color: #ffffff;
            --text-color-darker: #b0b3b8;
            --bg-color: #0d1117;
            --surface-color: rgba(30, 33, 40, 0.9);
            --border-color: rgba(255, 255, 255, 0.1);
            --danger-color: #ff4d4d;
            --success-color: #4CAF50;
            --warning-color: #FFC107;
            --info-color: #2196F3;
            --font-primary: 'Inter', 'Arial', sans-serif;
            --spacing-unit: 8px;
            --container-width: 1200px;
            --border-radius-sm: 8px;
            --border-radius-md: 16px;
            --shadow-md: 0 8px 32px rgba(0, 0, 0, 0.4);
            --blur-intensity: 10px;
            
            /* Yeni ve Güncellenmiş Renkler */
            --gradient-start: #6a11cb;
            --gradient-end: #2575fc;
            --card-bg: rgba(255, 255, 255, 0.05);
            --input-bg: rgba(255, 255, 255, 0.08);
            --button-hover-bg: rgba(255, 255, 255, 0.15);
            --chart-bg-color: rgba(0,0,0,0.3);
        }
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        body {
            font-family: var(--font-primary);
            color: var(--text-color);
            background-color: var(--bg-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: calc(var(--spacing-unit) * 4);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }

        .container {
            width: 100%;
            max-width: var(--container-width);
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(var(--blur-intensity));
            padding: calc(var(--spacing-unit) * 4);
            margin-top: calc(var(--spacing-unit) * 4);
            box-sizing: border-box; /* İç boşlukların genişliği etkilemesini engeller */
        }

        h1 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: calc(var(--spacing-unit) * 4);
            font-size: 2.5em;
            font-weight: 700;
            text-shadow: 0 0 10px rgba(187, 0, 255, 0.5);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: calc(var(--spacing-unit) * 3);
            margin-bottom: calc(var(--spacing-unit) * 4);
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            padding: calc(var(--spacing-unit) * 3);
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease-out;
        }
        .stat-card:hover::before {
            transform: scaleX(1);
        }
        .stat-card h3 {
            margin-top: 0;
            color: var(--text-color-darker);
            font-size: 1.1em;
            font-weight: 500;
        }

        .stat-card .value {
            font-size: 2.8em;
            font-weight: 800;
            margin: calc(var(--spacing-unit) * 1) 0;
            transition: color 0.3s ease;
        }

        .stat-card.pending .value { color: var(--warning-color); }
        .stat-card.today .value { color: var(--info-color); }
        .stat-card.week .value { color: var(--secondary-color); }
        .stat-card.total .value { color: var(--primary-color); }
        .stat-card.reviewed .value { color: var(--success-color); } /* Yeni */
        .stat-card.rejected .value { color: var(--danger-color); } /* Yeni */

        .filters-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: calc(var(--spacing-unit) * 2);
            margin-bottom: calc(var(--spacing-unit) * 4);
            align-items: flex-end; /* Butonları hizalar */
        }

        .filter-group {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            padding: calc(var(--spacing-unit) * 2);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filter-group h3 {
            margin-top: 0;
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            font-size: 1.1em;
            color: var(--text-color);
            font-weight: 600;
        }

        .filter-row {
            display: flex;
            gap: calc(var(--spacing-unit) * 1);
            align-items: center;
        }
        .filter-row span {
            color: var(--text-color-darker);
        }

        .filter-input, select.filter-input {
            flex: 1;
            padding: 10px 15px;
            background-color: var(--input-bg);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: var(--border-radius-sm);
            color: var(--text-color);
            font-size: 1em;
            outline: none;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .filter-input:focus, select.filter-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(187, 0, 255, 0.2);
        }
        .filter-input::placeholder {
            color: var(--text-color-darker);
        }

        .filter-button {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease, box-shadow 0.3s ease;
            font-weight: 600;
            font-size: 1em;
            align-self: flex-end; /* Kendi hizalamasını kontrol eder */
        }

        .filter-button:hover {
            background-color: var(--button-hover-bg);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(187, 0, 255, 0.4);
        }
        .filter-button:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .filter-buttons {
            display: flex;
            gap: calc(var(--spacing-unit) * 1.5);
            margin-bottom: calc(var(--spacing-unit) * 4);
            justify-content: center;
            flex-wrap: wrap;
        }

        .filter-button-tab {
            padding: 10px 20px;
            background-color: var(--input-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            transition: background-color 0.3s ease, transform 0.2s ease, border-color 0.3s ease;
            font-weight: 500;
        }
        .filter-button-tab:hover {
            background-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
            border-color: var(--primary-color);
        }
        .filter-button-tab.active {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            font-weight: bold;
            box-shadow: 0 3px 10px rgba(187, 0, 255, 0.3);
        }

        .bulk-actions {
            display: flex;
            gap: calc(var(--spacing-unit) * 1.5);
            margin-bottom: calc(var(--spacing-unit) * 3);
            align-items: center;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            padding: calc(var(--spacing-unit) * 1.5);
            flex-wrap: wrap;
        }

        .bulk-select-all {
            transform: scale(1.2); /* Checkbox boyutunu büyüt */
            margin-right: calc(var(--spacing-unit) * 1);
            accent-color: var(--primary-color); /* Checkbox rengi */
        }
        .bulk-actions label {
            font-size: 1em;
            color: var(--text-color-darker);
            cursor: pointer;
        }

        .report-list {
            display: flex;
            flex-direction: column;
            gap: calc(var(--spacing-unit) * 3);
        }

        .report-item {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            padding: calc(var(--spacing-unit) * 3);
            display: flex;
            flex-direction: column;
            gap: calc(var(--spacing-unit) * 2);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .report-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: calc(var(--spacing-unit) * 1.5);
            padding-bottom: calc(var(--spacing-unit) * 1.5);
            border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
        }

        .report-users {
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 1);
            flex-wrap: wrap;
        }

        .user-link {
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 0.5);
            color: var(--text-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        .user-link:hover { 
            color: var(--primary-color); 
            text-decoration: underline; 
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--secondary-color);
            box-shadow: 0 0 8px rgba(43, 255, 0, 0.5);
        }
        .avatar-letter {
            width: 35px; height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; font-weight: bold; color: white;
            border: 2px solid var(--secondary-color);
        }

        .report-info {
            font-size: 0.95em;
            color: var(--text-color-darker);
            display: flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 1.5);
            flex-wrap: wrap;
        }
        .report-info strong {
            color: var(--text-color);
        }

        .report-description {
            background-color: rgba(0,0,0,0.15);
            border: 1px solid rgba(255,255,255,0.08);
            border-left: 5px solid var(--warning-color);
            padding: calc(var(--spacing-unit) * 2);
            border-radius: var(--border-radius-sm);
            font-style: italic;
            color: var(--text-color-darker);
            line-height: 1.8;
            word-break: break-word; /* Uzun kelimeleri kırar */
        }
        .report-description strong {
            color: var(--text-color);
            font-style: normal;
        }

        .report-actions {
            display: flex;
            gap: calc(var(--spacing-unit) * 1.5);
            margin-top: calc(var(--spacing-unit) * 1.5);
            flex-wrap: wrap;
        }

        .action-button-report {
            padding: 10px 18px;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: opacity 0.3s ease, transform 0.2s ease, box-shadow 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: calc(var(--spacing-unit) * 0.5);
            text-transform: capitalize;
        }
        .action-button-report:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .action-button-report.review {
            background-color: var(--success-color);
            color: white;
        }
        .action-button-report.reject {
            background-color: var(--danger-color);
            color: white;
        }
        .action-button-report.ban {
            background-color: #e74c3c; /* Daha kırmızı */
            color: white;
        }
        .action-button-report:disabled {
            background-color: var(--text-color-darker);
            cursor: not-allowed;
            opacity: 0.6;
            box-shadow: none;
            transform: translateY(0);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: var(--border-radius-sm);
            font-size: 0.85em;
            font-weight: bold;
            text-transform: capitalize;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .status-badge.pending { background-color: var(--warning-color); color: #333; }
        .status-badge.reviewed { background-color: var(--success-color); color: white; }
        .status-badge.rejected { background-color: var(--danger-color); color: white; }

        .no-reports {
            text-align: center;
            padding: calc(var(--spacing-unit) * 5);
            color: var(--text-color-darker);
            font-size: 1.2em;
            background-color: var(--card-bg);
            border-radius: var(--border-radius-sm);
            border: 1px dashed var(--border-color);
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: calc(var(--spacing-unit) * 4);
            gap: calc(var(--spacing-unit) * 1);
            flex-wrap: wrap;
        }

        .pagination a, .pagination span {
            padding: 10px 18px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            color: var(--text-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
            font-weight: 500;
        }

        .pagination a:hover {
            background-color: rgba(255,255,255,0.1);
            border-color: var(--primary-color);
        }

        .pagination .active {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            font-weight: bold;
        }
        .pagination span {
            background-color: transparent;
            border-color: transparent;
            cursor: default;
        }

        .chart-container {
            margin: calc(var(--spacing-unit) * 4) 0;
            background-color: var(--chart-bg-color);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            padding: calc(var(--spacing-unit) * 3);
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            max-width: 800px; /* Chart container boyutunu sınırla */
            margin-left: auto;
            margin-right: auto;
        }

        .sortable {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            color: var(--text-color);
            transition: color 0.3s ease;
        }
        .sortable:hover {
            color: var(--primary-color);
        }

        .sort-icon {
            margin-left: 5px;
            display: inline-flex;
            transition: transform 0.3s ease;
        }
        .sortable[data-sort-order="asc"] .sort-icon {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <h1><?php echo $translations['admin_reports_title'] ?? 'Kullanıcı Şikayetleri Yönetimi'; ?></h1>

        <div class="stats-container">
            <div class="stat-card total">
                <h3><?php echo $translations['admin_reports_total'] ?? 'Toplam Şikayet'; ?></h3>
                <div class="value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="stat-card pending">
                <h3><?php echo $translations['admin_reports_pending'] ?? 'Bekleyenler'; ?></h3>
                <div class="value"><?php echo $stats['pending']; ?></div>
            </div>
            <div class="stat-card reviewed">
                <h3><?php echo $translations['admin_reports_reviewed'] ?? 'İncelenenler'; ?></h3>
                <div class="value"><?php echo $stats['reviewed']; ?></div>
            </div>
            <div class="stat-card rejected">
                <h3><?php echo $translations['admin_reports_rejected'] ?? 'Reddedilenler'; ?></h3>
                <div class="value"><?php echo $stats['rejected']; ?></div>
            </div>
            <div class="stat-card today">
                <h3><?php echo $translations['admin_reports_today'] ?? 'Bugün'; ?></h3>
                <div class="value"><?php echo $stats['today']; ?></div>
            </div>
            <div class="stat-card week">
                <h3><?php echo $translations['admin_reports_week'] ?? 'Bu Hafta'; ?></h3>
                <div class="value"><?php echo $stats['week']; ?></div>
            </div>
        </div>

        <div class="chart-container">
            <canvas id="reportsChart"></canvas>
        </div>

        <div class="filters-container">
            <div class="filter-group">
                <h3><?php echo $translations['admin_reports_date_filter'] ?? 'Tarih Aralığı'; ?></h3>
                <div class="filter-row">
                    <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                    <span><?php echo $translations['admin_reports_to'] ?? 'to'; ?></span>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
            </div>
            <div class="filter-group">
                <h3><?php echo $translations['admin_reports_search_user'] ?? 'Kullanıcı Ara'; ?></h3>
                <input type="text" name="search_username" class="filter-input" placeholder="<?php echo $translations['admin_reports_username_placeholder'] ?? 'Kullanıcı adı...'; ?>" value="<?php echo htmlspecialchars($search_username); ?>">
            </div>
            <div class="filter-group">
                <h3><?php echo $translations['admin_reports_search_reason'] ?? 'Sebep Ara'; ?></h3>
                <input type="text" name="search_reason" class="filter-input" placeholder="<?php echo $translations['admin_reports_reason_placeholder'] ?? 'Şikayet sebebi...'; ?>" value="<?php echo htmlspecialchars($search_reason); ?>">
            </div>
            <button class="filter-button" onclick="applyFilters()"><?php echo $translations['admin_reports_apply_filters'] ?? 'Filtrele'; ?></button>
        </div>

        <div class="filter-buttons">
            <a href="?status=pending<?php echo buildQueryString(['status', 'page']); ?>" class="filter-button-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                <?php echo $translations['admin_reports_pending'] ?? 'Bekleyenler'; ?> (<?php echo $stats['pending']; ?>)
            </a>
            <a href="?status=reviewed<?php echo buildQueryString(['status', 'page']); ?>" class="filter-button-tab <?php echo $filter_status === 'reviewed' ? 'active' : ''; ?>">
                <?php echo $translations['admin_reports_reviewed'] ?? 'İncelenenler'; ?> (<?php echo $stats['reviewed']; ?>)
            </a>
            <a href="?status=rejected<?php echo buildQueryString(['status', 'page']); ?>" class="filter-button-tab <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
                <?php echo $translations['admin_reports_rejected'] ?? 'Reddedilenler'; ?> (<?php echo $stats['rejected']; ?>)
            </a>
            <a href="?status=all<?php echo buildQueryString(['status', 'page']); ?>" class="filter-button-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                <?php echo $translations['admin_reports_all'] ?? 'Tümü'; ?> (<?php echo $stats['total']; ?>)
            </a>
        </div>

        <form method="POST" action="admin_reports.php?<?php echo $_SERVER['QUERY_STRING']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="bulk-actions">
                <input type="checkbox" id="select-all" class="bulk-select-all" onchange="toggleSelectAll(this)">
                <label for="select-all"><?php echo $translations['admin_reports_select_all'] ?? 'Tümünü Seç'; ?></label>
                <select name="bulk_action" class="filter-input">
                    <option value=""><?php echo $translations['admin_reports_bulk_action'] ?? 'Toplu İşlem'; ?></option>
                    <option value="bulk_review"><?php echo $translations['admin_reports_mark_reviewed'] ?? 'İncelendi Olarak İşaretle'; ?></option>
                    <option value="bulk_reject"><?php echo $translations['admin_reports_mark_rejected'] ?? 'Reddet'; ?></option>
                </select>
                <button type="submit" class="filter-button"><?php echo $translations['admin_reports_apply'] ?? 'Uygula'; ?></button>
            </div>

            <div class="report-list">
                <?php if (!empty($reports)): ?>
                    <?php foreach ($reports as $report): ?>
                        <div class="report-item">
                            <div class="report-header">
                                <div class="report-users">
                                    <input type="checkbox" name="selected_reports[]" value="<?php echo $report['id']; ?>" class="report-checkbox">
                                    
                                    <?php if ($report['reporter_username']): ?>
                                        <a href="/profile/<?php echo htmlspecialchars($report['reporter_username']); ?>" class="user-link" title="<?php echo htmlspecialchars($report['reporter_username']); ?>">
                                            <?php if (!empty($report['reporter_avatar_url'])): ?>
                                                <img src="../<?php echo htmlspecialchars($report['reporter_avatar_url']); ?>" alt="Reporter Avatar" class="user-avatar">
                                            <?php else: ?>
                                                <div class="avatar-letter"><?php echo strtoupper(substr($report['reporter_username'], 0, 1)); ?></div>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($report['reporter_username']); ?></span>
                                        </a>
                                        <i data-lucide="chevrons-right" style="width:18px; height:18px; color: var(--text-color-darker);"></i>
                                    <?php else: ?>
                                        <span class="user-link" style="color: var(--text-color-darker); font-style: italic;"><?php echo $translations['admin_reports_anonymous'] ?? 'Anonim'; ?></span>
                                        <i data-lucide="chevrons-right" style="width:18px; height:18px; color: var(--text-color-darker);"></i>
                                    <?php endif; ?>

                                    <a href="/profile/<?php echo htmlspecialchars($report['reported_username']); ?>" class="user-link" title="<?php echo htmlspecialchars($report['reported_username']); ?>">
                                        <?php if (!empty($report['reported_avatar_url'])): ?>
                                            <img src="../<?php echo htmlspecialchars($report['reported_avatar_url']); ?>" alt="Reported Avatar" class="user-avatar">
                                        <?php else: ?>
                                            <div class="avatar-letter"><?php echo strtoupper(substr($report['reported_username'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <span><?php echo htmlspecialchars($report['reported_username']); ?></span>
                                    </a>
                                </div>
                                <div class="report-info">
                                    <span class="status-badge <?php echo htmlspecialchars($report['status']); ?>">
                                        <?php echo $translations['report_status_' . $report['status']] ?? ucfirst($report['status']); ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($translations['admin_reports_reason'] ?? 'Neden'); ?>: <strong><?php echo htmlspecialchars($report['report_reason']); ?></strong></span>
                                    <span class="sortable" onclick="sortTable('created_at')" data-sort-order="<?php echo ($sort === 'created_at' && $order === 'ASC') ? 'asc' : 'desc'; ?>">
                                        <i data-lucide="calendar" style="width:16px; height:16px; margin-right: 4px;"></i>
                                        <?php echo date('d.m.Y H:i', strtotime($report['created_at'])); ?>
                                        <span class="sort-icon">
                                            <?php if ($sort === 'created_at'): ?>
                                                <i data-lucide="<?php echo $order === 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" width="14" height="14"></i>
                                            <?php else: ?>
                                                <i data-lucide="chevrons-up-down" width="14" height="14"></i>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <?php if (!empty($report['report_description'])): ?>
                                <div class="report-description">
                                    <strong><?php echo $translations['admin_reports_description'] ?? 'Açıklama'; ?>:</strong> <?php echo nl2br(htmlspecialchars($report['report_description'])); ?>
                                </div>
                            <?php endif; ?>
                            <div class="report-actions">
                                <form method="POST" action="admin_reports.php?<?php echo $_SERVER['QUERY_STRING']; ?>" style="display: flex; gap: calc(var(--spacing-unit) * 1.5);">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                    <button type="submit" name="action" value="review" class="action-button-report review"
                                        <?php echo ($report['status'] === 'reviewed') ? 'disabled' : ''; ?>>
                                        <i data-lucide="check" style="width:18px; height:18px;"></i>
                                        <?php echo $translations['admin_reports_mark_reviewed'] ?? 'İncelendi Olarak İşaretle'; ?>
                                    </button>
                                    <button type="submit" name="action" value="reject" class="action-button-report reject"
                                        <?php echo ($report['status'] === 'rejected') ? 'disabled' : ''; ?>>
                                        <i data-lucide="x" style="width:18px; height:18px;"></i>
                                        <?php echo $translations['admin_reports_mark_rejected'] ?? 'Reddet'; ?>
                                    </button>
                                    <button type="submit" name="action" value="ban" class="action-button-report ban">
                                        <i data-lucide="ban" style="width:18px; height:18px;"></i>
                                        <?php echo $translations['admin_reports_ban_user'] ?? 'Kullanıcıyı Yasakla'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-reports"><?php echo $translations['admin_reports_no_reports'] ?? 'Bu kategoride henüz şikayet bulunmamaktadır.'; ?></p>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo buildQueryString(['page']); ?>" title="<?php echo $translations['pagination_first_page'] ?? 'İlk Sayfa'; ?>">&laquo;</a>
                    <a href="?page=<?php echo $page - 1; ?><?php echo buildQueryString(['page']); ?>" title="<?php echo $translations['pagination_previous_page'] ?? 'Önceki Sayfa'; ?>">&lsaquo;</a>
                <?php endif; ?>

                <?php 
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                
                if ($start > 1) echo '<span>...</span>';
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo buildQueryString(['page']); ?>" <?php echo $i == $page ? 'class="active"' : ''; ?>>
                        <?php echo $i; ?>
                    </a>
                <?php endfor; 
                
                if ($end < $total_pages) echo '<span>...</span>';
                ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo buildQueryString(['page']); ?>" title="<?php echo $translations['pagination_next_page'] ?? 'Sonraki Sayfa'; ?>">&rsaquo;</a>
                    <a href="?page=<?php echo $total_pages; ?><?php echo buildQueryString(['page']); ?>" title="<?php echo $translations['pagination_last_page'] ?? 'Son Sayfa'; ?>">&raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();

        // Filtreleme fonksiyonu
        function applyFilters() {
            const params = new URLSearchParams(window.location.search);
            
            // Tarih filtreleri
            const dateFrom = document.querySelector('input[name="date_from"]').value;
            const dateTo = document.querySelector('input[name="date_to"]').value;
            
            if (dateFrom) params.set('date_from', dateFrom);
            else params.delete('date_from');
            
            if (dateTo) params.set('date_to', dateTo);
            else params.delete('date_to');
            
            // Kullanıcı adı arama
            const username = document.querySelector('input[name="search_username"]').value;
            if (username) params.set('search_username', username);
            else params.delete('search_username');
            
            // Sebep arama
            const reason = document.querySelector('input[name="search_reason"]').value;
            if (reason) params.set('search_reason', reason);
            else params.delete('search_reason');
            
            // Sayfa numarasını sıfırla
            params.delete('page');

            window.location.search = params.toString();
        }

        // Tümünü seç fonksiyonu
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.report-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        // Sıralama fonksiyonu
        function sortTable(column) {
            const params = new URLSearchParams(window.location.search);
            const currentSort = params.get('sort');
            const currentOrder = params.get('order');
            
            if (currentSort === column) {
                params.set('order', currentOrder === 'asc' ? 'desc' : 'asc');
            } else {
                params.set('sort', column);
                params.set('order', 'desc'); // Varsayılan olarak DESC sırala
            }
            
            // Sayfa numarasını sıfırla
            params.delete('page');

            window.location.search = params.toString();
        }

        // Grafik oluşturma
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('reportsChart').getContext('2d');
            const chart = new Chart(ctx, {
                type: 'doughnut', /* Pasta grafiği yerine doughnut tercih edildi */
                data: {
                    labels: [
                        '<?php echo $translations['admin_reports_pending'] ?? 'Bekleyen'; ?>', 
                        '<?php echo $translations['admin_reports_reviewed'] ?? 'İncelenen'; ?>', 
                        '<?php echo $translations['admin_reports_rejected'] ?? 'Reddedilen'; ?>'
                    ],
                    datasets: [{
                        label: '<?php echo $translations['admin_reports_by_status'] ?? 'Şikayet Durumları'; ?>',
                        data: [
                            <?php echo $stats['pending']; ?>,
                            <?php echo $stats['reviewed'] ?? 0; ?>,
                            <?php echo $stats['rejected'] ?? 0; ?>
                        ],
                        backgroundColor: [
                            'rgba(255, 193, 7, 0.9)', /* Warning Color */
                            'rgba(76, 175, 80, 0.9)', /* Success Color */
                            'rgba(255, 77, 77, 0.9)'  /* Danger Color */
                        ],
                        borderColor: [
                            'rgba(255, 193, 7, 1)',
                            'rgba(76, 175, 80, 1)',
                            'rgba(255, 77, 77, 1)'
                        ],
                        borderWidth: 2,
                        hoverOffset: 10 /* Hover efekti */
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, /* Responsive ayarını esnek yapar */
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: '#ffffff',
                                font: {
                                    size: 14
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(tooltipItem) {
                                    const total = tooltipItem.dataset.data.reduce((acc, current) => acc + current, 0);
                                    const currentValue = tooltipItem.raw;
                                    const percentage = parseFloat(((currentValue / total) * 100).toFixed(1));
                                    return `${tooltipItem.label}: ${currentValue} (${percentage}%)`;
                                }
                            },
                            backgroundColor: 'rgba(0,0,0,0.7)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: 'rgba(255,255,255,0.2)',
                            borderWidth: 1
                        }
                    },
                    cutout: '70%', /* Doughnut grafiğinin iç boşluğu */
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
        });

        // AJAX ile durum güncelleme (Bu kısım zaten vardı ve iyi çalışıyor, ekstra bir iyileştirme gerekirse ayrı konuşulabilir)
        // Şu anki form submit yöntemi, sayfa yenileme ile çalıştığı için buradaki AJAX kodu devre dışı bırakılabilir.
        // Eğer sayfayı yenilemeden güncellemek isterseniz bu kod kullanılabilir, ancak PHP'deki header("Location: ...") kaldırılmalıdır.
        /*
        function updateReportStatus(reportId, action) {
            const formData = new FormData();
            formData.append('report_id', reportId);
            formData.append('action', action);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            
            fetch('admin_reports.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json()) // Varsayılan olarak JSON yanıt bekler, PHP tarafında JSON döndürülmeli
            .then(data => {
                if (data.success) {
                    location.reload(); // Başarılı olursa sayfayı yenile
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during the action.');
            });
        }
        */
    </script>
</body>
</html>