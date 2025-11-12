<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Veritabanı bağlantısı
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
    error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
    die("Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.");
}

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Tarih aralığını al (varsayılan: son 30 gün)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-29 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Tarihleri doğrula
$start_date = date('Y-m-d', strtotime($start_date));
$end_date = date('Y-m-d', strtotime($end_date));

// Tarih sınırlarını kontrol et
if (strtotime($start_date) > strtotime($end_date)) {
    $start_date = date('Y-m-d', strtotime($end_date . ' -29 days'));
}

// Genel istatistikler
$stats_query = "
    SELECT
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as today_users,
        (SELECT COUNT(*) FROM users WHERE last_activity >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as active_users,
        (SELECT COUNT(*) FROM messages1 WHERE DATE(created_at) = CURDATE()) as today_messages,
        (SELECT COUNT(*) FROM servers) as total_servers,
        (SELECT COUNT(*) FROM posts) as total_posts,
        (SELECT COUNT(*) FROM lakealts) as total_lakealts,
        (SELECT COUNT(*) FROM reactions) as total_reactions
";

$stmt = $db->prepare($stats_query);
$stmt->execute();
$stats = $stmt->fetch();

// Kümülatif ve günlük kullanıcı verileri
$daily_chart_query = "
    SELECT
        DATE(date_field) as date,
        COUNT(DISTINCT CASE WHEN type = 'user' THEN id END) as daily_users,
        0 as cumulative_users
    FROM (
        SELECT id, created_at as date_field, 'user' as type FROM users
        WHERE created_at BETWEEN ? AND ?
    ) combined
    GROUP BY DATE(date_field)
    ORDER BY date ASC
";

$stmt = $db->prepare($daily_chart_query);
$stmt->execute([$start_date, $end_date]);
$daily_chart_data = $stmt->fetchAll();

// Kümülatif kullanıcı sayısını hesapla
$cumulative_users_query = "
    SELECT
        DATE(created_at) as date,
        COUNT(*) as daily_new_users
    FROM users
    WHERE created_at <= ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
";

$stmt = $db->prepare($cumulative_users_query);
$stmt->execute([$end_date]);
$cumulative_raw_data = $stmt->fetchAll();

$cumulative_users = [];
$current_cumulative_count = 0;

$min_user_date_query = "SELECT MIN(DATE(created_at)) as min_date FROM users";
$stmt = $db->prepare($min_user_date_query);
$stmt->execute();
$min_user_date_result = $stmt->fetch();
$first_user_date = $min_user_date_result['min_date'];

if ($first_user_date === null) {
    $first_user_date = $start_date;
}

if ($first_user_date < $start_date) {
    $users_before_start_date_query = "SELECT COUNT(*) as count FROM users WHERE DATE(created_at) < ?";
    $stmt_before = $db->prepare($users_before_start_date_query);
    $stmt_before->execute([$start_date]);
    $users_before_start_date_result = $stmt_before->fetch();
    $current_cumulative_count = $users_before_start_date_result['count'];
}

$daily_new_users_map = [];
foreach ($cumulative_raw_data as $row) {
    $daily_new_users_map[$row['date']] = $row['daily_new_users'];
}

$period = new DatePeriod(
    new DateTime($start_date),
    new DateInterval('P1D'),
    new DateTime($end_date . ' +1 day')
);

foreach ($period as $date_obj) {
    $date = $date_obj->format('Y-m-d');
    $new_users_on_date = $daily_new_users_map[$date] ?? 0;
    $current_cumulative_count += $new_users_on_date;
    $cumulative_users[] = [
        'date' => $date,
        'daily_users' => $new_users_on_date,
        'cumulative_users' => $current_cumulative_count
    ];
}

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
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $translations['stats']['title'] ?? 'Lakeban İstatistik Paneli'; ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

<style>
    :root {
        --neon-green: #3CB371;
        --dark-green: #2E8B57;
        --dark-bg: #0a0a0a;
        --card-bg: rgba(26, 26, 26, 0.75);
        --text-primary: #f5f5f5;
        --text-secondary: #b0b0b0;
        --border-color: rgba(60, 179, 113, 0.2);
        --accent-glow: 0 0 30px rgba(60, 179, 113, 0.6);
        --gradient: linear-gradient(135deg, var(--neon-green) 0%, var(--dark-green) 100%);
    }

    @keyframes moveGrid {
        from { background-position: 0 0; }
        to { background-position: -100px 100px; }
    }

    body {
        background-color: var(--dark-bg);
        font-family: 'Inter', sans-serif;
        color: var(--text-primary);
        overflow-x: hidden;
        -webkit-tap-highlight-color: transparent; /* Mobil cihazlarda tıklama vurgulamasını kaldır */
    }
    
    body::before {
        content: '';
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background-image:
            linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
        background-size: 50px 50px;
        animation: moveGrid 10s linear infinite;
        z-index: -1;
        opacity: 0.5;
    }

    .theme-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        position: relative;
        overflow: hidden;
        touch-action: manipulation; /* Mobil cihazlarda kaydırma sorunlarını önler */
    }
    
    .mobile-br {
        display: none;
    }

    .theme-card::before {
        content: '';
        position: absolute;
        width: 200px;
        height: 200px;
        left: var(--mouse-x, -100px);
        top: var(--mouse-y, -100px);
        background: radial-gradient(circle closest-side, rgba(60, 179, 113, 0.15), transparent);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .theme-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--accent-glow);
        border-color: rgba(60, 179, 113, 0.4);
    }
    
    @keyframes cardEnter {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .bento-item {
        border-radius: 1rem;
        padding: 1.5rem;
        opacity: 0;
        animation: cardEnter 0.6s ease-out forwards;
    }
    .bento-item:nth-child(1) { animation-delay: 0.1s; }
    .bento-item:nth-child(2) { animation-delay: 0.2s; }
    .bento-item:nth-child(3) { animation-delay: 0.3s; }
    .bento-item:nth-child(4) { animation-delay: 0.4s; }
    .bento-item:nth-child(5) { animation-delay: 0.5s; }
    .bento-item:nth-child(6) { animation-delay: 0.6s; }
    .bento-item:nth-child(7) { animation-delay: 0.7s; }
    .bento-item:nth-child(8) { animation-delay: 0.8s; }
    .bento-item:nth-child(9) { animation-delay: 0.9s; }
    
    .bento-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 1.25rem;
    }
    .col-span-1 { grid-column: span 1; }
    .col-span-2 { grid-column: span 2; }
    .col-span-3 { grid-column: span 3; }
    .col-span-4 { grid-column: span 4; }
    .col-span-6 { grid-column: span 6; }
    
    @media (max-width: 1024px) {
        .lg-span-2 { grid-column: span 3; }
        .lg-span-3 { grid-column: span 6; }
        .lg-span-4 { grid-column: span 6; }
        .text-4xl { font-size: 2rem; } /* Mobil için büyük yazı boyutlarını küçült */
        .text-3xl { font-size: 1.5rem; }
        .text-lg { font-size: 1.125rem; }
    }
    @media (max-width: 768px) {
        .mobile-br { display: block; }
        .bento-grid { grid-template-columns: 1fr; gap: 1rem; }
        .md-span-1, .md-span-2, .lg-span-2, .lg-span-3, .lg-span-4 { grid-column: span 1; }
        .text-4xl { font-size: 1.75rem; }
        .text-3xl { font-size: 1.25rem; }
        .text-lg { font-size: 1rem; }
        .text-base { font-size: 0.875rem; }
        .p-4 { padding: 1rem; }
        .btn-green { padding: 0.5rem 1rem; font-size: 0.875rem; }
    }
    @media (max-width: 480px) {
        .bento-grid { grid-template-columns: 1fr; }
        .text-4xl { font-size: 1.5rem; }
        .text-3xl { font-size: 1.125rem; }
        .text-lg { font-size: 0.875rem; }
        .text-base { font-size: 0.75rem; }
        .p-4 { padding: 0.75rem; }
        .btn-green { padding: 0.5rem 0.75rem; font-size: 0.75rem; }
        .date-picker { padding: 0.5rem; font-size: 0.75rem; }
    }

    .date-picker {
        background-color: #222;
        border: 1px solid var(--border-color);
        color-scheme: dark;
        transition: all 0.3s ease;
        -webkit-appearance: none; /* Mobil cihazlarda varsayılan görünümü kaldır */
        font-size: 0.875rem;
    }
    .date-picker:focus {
        outline: none;
        border-color: var(--neon-green);
        box-shadow: 0 0 10px rgba(60, 179, 113, 0.4);
    }
    .date-picker::-webkit-calendar-picker-indicator {
        filter: invert(0.8) sepia(1) saturate(5) hue-rotate(90deg);
        padding: 0.5rem; /* Mobil cihazlarda takvim ikonuna daha fazla alan */
    }
    
    .btn-green {
        background: var(--gradient);
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        touch-action: manipulation; /* Mobil cihazlarda tıklama sorunlarını önler */
    }
    .btn-green:active {
        transform: scale(0.95); /* Mobil cihazlarda tıklama geri bildirimi */
    }
    .btn-green:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 6px 20px rgba(60, 179, 113, 0.4);
    }
    
    .gradient-text {
        background: var(--gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-fill-color: transparent;
    }
</style>
</head>

<body class="p-4 sm:p-6 lg:p-8">
    <main class="max-w-7xl mx-auto">
        <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl lg:text-4xl font-bold gradient-text"><?php echo $translations['stats']['title'] ?? 'İstatistik Paneli'; ?></h1>
                <p class="text-slate-400 mt-1 text-sm sm:text-base"><?php echo $translations['stats']['description'] ?? 'Gerçek zamanlı platform analizi.'; ?></p>
            </div>
            <a href="index" class="btn-green text-white font-semibold py-2 px-4 rounded-lg inline-flex items-center gap-2 mt-4 sm:mt-0">
                <i class="fa-solid fa-arrow-left"></i>
                <span><?php echo $translations['stats']['back'] ?? 'Geri'; ?></span>
            </a>
        </header>

        <div class="bento-grid">
            <div class="theme-card bento-item col-span-2 lg-span-2 md-span-1">
                <i class="fa-solid fa-users text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-base sm:text-lg font-semibold text-slate-300"><?php echo $translations['stats']['total_user'] ?? 'Toplam Kullanıcı'; ?></h3>
                <p class="text-2xl sm:text-4xl font-bold text-white" data-target="<?php echo $stats['total_users']; ?>"><?php echo $stats['total_users']; ?></p>
            </div>
            <br class="mobile-br">
            <div class="theme-card bento-item col-span-1 lg-span-2 md-span-1">
                <i class="fa-solid fa-user-plus text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['register_today'] ?? 'Bugün Kaydolan'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['today_users']; ?>"><?php echo $stats['today_users']; ?></p>
            </div>
            <br class="mobile-br">
            <div class="theme-card bento-item col-span-1 lg-span-2 md-span-1">
                <i class="fa-solid fa-chart-line text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['active_week'] ?? 'Bu Hafta Aktif'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['active_users']; ?>"><?php echo $stats['active_users']; ?></p>
            </div>
            <br class="mobile-br">
            <div class="theme-card bento-item col-span-1 lg-span-2 md-span-1">
                <i class="fa-solid fa-server text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['total_server'] ?? 'Toplam Sunucu'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['total_servers']; ?>"><?php echo $stats['total_servers']; ?></p>
            </div>
            <br class="mobile-br">
            <div class="theme-card bento-item col-span-1 lg-span-2 md-span-1">
                <i class="fa-solid fa-satellite-dish text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['total_lakealt'] ?? 'Toplam Lakealt'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['total_lakealts']; ?>"><?php echo $stats['total_lakealts']; ?></p>
            </div>
            <br class="mobile-br">
            <div class="theme-card bento-item col-span-6 lg-span-4 md-span-1">
                <div class="flex flex-col justify-between items-start mb-4 gap-4">
                    <div>
                        <h3 class="text-base sm:text-lg font-semibold text-white"><?php echo $translations['stats']['graph_long_text'] ?? 'Kullanıcı ve Aktivite Trendleri'; ?></h3>
                        <p class="text-slate-400 text-xs sm:text-sm"><?php echo $translations['stats']['graph_description'] ?? 'Tarihsel verileri analiz etmek için bir tarih aralığı seçin.'; ?></p>
                    </div>
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 w-full">
                        <div class="flex-1">
                            <label for="startDate" class="text-xs text-slate-400"><?php echo $translations['stats']['sdate'] ?? 'Başlangıç'; ?></label>
                            <input type="date" id="startDate" class="date-picker rounded-md p-2 w-full" value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="flex-1">
                            <label for="endDate" class="text-xs text-slate-400"><?php echo $translations['stats']['edate'] ?? 'Bitiş'; ?></label>
                            <input type="date" id="endDate" class="date-picker rounded-md p-2 w-full" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button onclick="updateChart()" class="btn-green text-white font-semibold py-2 px-4 rounded-lg w-full sm:w-auto"><?php echo $translations['stats']['apply'] ?? 'Uygula'; ?></button>
                    </div>
                </div>
                <div class="h-64 sm:h-80 relative">
                    <canvas id="statsChart"></canvas>
                </div>
            </div>
            <div class="theme-card bento-item col-span-2 lg-span-3 md-span-1">
                <i class="fa-solid fa-envelope-open-text text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['today_message'] ?? 'Bugünkü Mesajlar'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['today_messages']; ?>"><?php echo $stats['today_messages']; ?></p>
            </div>
            <div class="theme-card bento-item col-span-2 lg-span-3 md-span-1">
                <i class="fa-solid fa-pen-to-square text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['total_post'] ?? 'Toplam Gönderi'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['total_posts']; ?>"><?php echo $stats['total_posts']; ?></p>
            </div>
            <div class="theme-card bento-item col-span-2 lg-span-3 md-span-1">
                <i class="fa-solid fa-heart text-xl sm:text-2xl text-green-400 mb-3"></i>
                <h3 class="text-sm sm:text-base font-semibold text-slate-300"><?php echo $translations['stats']['total_reaction'] ?? 'Toplam Reaksiyon'; ?></h3>
                <p class="text-xl sm:text-3xl font-bold text-white" data-target="<?php echo $stats['total_reactions']; ?>"><?php echo $stats['total_reactions']; ?></p>
            </div>
        </div>
    </main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const chartData = <?php echo json_encode($cumulative_users); ?>;
    
    const animateCounters = () => {
        const counters = document.querySelectorAll('[data-target]');
        counters.forEach(counter => {
            const target = +counter.getAttribute('data-target');
            let current = 0;
            const duration = 2000;
            const stepTime = 20;
            const steps = duration / stepTime;
            const increment = target / steps;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    clearInterval(timer);
                    counter.innerText = target.toLocaleString('tr-TR');
                } else {
                    counter.innerText = Math.floor(current).toLocaleString('tr-TR');
                }
            }, stepTime);
        });
    };

    const cards = document.querySelectorAll('.theme-card');
    cards.forEach(card => {
        card.addEventListener('mousemove', e => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            card.style.setProperty('--mouse-x', `${x}px`);
            card.style.setProperty('--mouse-y', `${y}px`);
        });
        // Mobil cihazlar için dokunma olayı ekle
        card.addEventListener('touchstart', e => {
            const rect = card.getBoundingClientRect();
            const touch = e.touches[0];
            const x = touch.clientX - rect.left;
            const y = touch.clientY - rect.top;
            card.style.setProperty('--mouse-x', `${x}px`);
            card.style.setProperty('--mouse-y', `${y}px`);
        });
    });

    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const ctx = document.getElementById('statsChart').getContext('2d');
    const chartGradient = ctx.createLinearGradient(0, 0, 0, 300);
    chartGradient.addColorStop(0, 'rgba(60, 179, 113, 0.5)');
    chartGradient.addColorStop(1, 'rgba(60, 179, 113, 0)');

    let statsChart = new Chart(ctx, {
        type: 'line',
        data: { labels: [], datasets: [] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: { 
                        color: '#f5f5f5', 
                        padding: 20,
                        font: { size: window.innerWidth < 768 ? 10 : 12 } // Mobil için daha küçük yazı tipi
                    },
                    onHover: (event, legendItem, legend) => {
                        legend.chart.data.datasets.forEach((dataset, index) => {
                            if (index !== legendItem.datasetIndex) {
                                dataset.borderColor = 'rgba(245, 245, 245, 0.2)';
                                dataset.backgroundColor = 'transparent';
                            }
                        });
                        legend.chart.update();
                    },
                    onLeave: (event, legendItem, legend) => {
                        legend.chart.data.datasets[0].borderColor = '#3CB371';
                        legend.chart.data.datasets[0].backgroundColor = chartGradient;
                        legend.chart.data.datasets[1].borderColor = '#f5f5f5';
                        legend.chart.update();
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(10, 10, 10, 0.8)',
                    titleColor: '#f5f5f5', 
                    bodyColor: '#b0b0b0',
                    borderColor: 'rgba(60, 179, 113, 0.5)', 
                    borderWidth: 1, 
                    padding: window.innerWidth < 768 ? 8 : 10,
                    titleFont: { size: window.innerWidth < 768 ? 10 : 12 },
                    bodyFont: { size: window.innerWidth < 768 ? 10 : 12 }
                }
            },
            scales: {
                x: { 
                    ticks: { 
                        color: '#b0b0b0', 
                        font: { size: window.innerWidth < 768 ? 8 : 10 } // Mobil için daha küçük yazı tipi
                    }, 
                    grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                },
                y: { 
                    position: 'left', 
                    ticks: { 
                        color: '#b0b0b0', 
                        font: { size: window.innerWidth < 768 ? 8 : 10 } 
                    }, 
                    grid: { color: 'rgba(255, 255, 255, 0.1)' } 
                },
                y1: { 
                    position: 'right', 
                    ticks: { 
                        color: '#b0b0b0', 
                        font: { size: window.innerWidth < 768 ? 8 : 10 } 
                    }, 
                    grid: { drawOnChartArea: false } 
                }
            },
            interaction: { intersect: false, mode: 'index' },
            tension: 0.4,
        }
    });

    const updateChart = () => {
        const startDate = new Date(startDateInput.value);
        const endDate = new Date(endDateInput.value);

        if (startDate > endDate) {
            startDateInput.value = '<?php echo $start_date; ?>';
            return;
        }

        const filteredData = chartData.filter(d => new Date(d.date) >= startDate && new Date(d.date) <= endDate);

        statsChart.data.labels = filteredData.map(d => new Date(d.date).toLocaleDateString('tr-TR', { month: 'short', day: 'numeric' }));
        
        statsChart.data.datasets = [
            {
                label: '<?php echo $translations['stats']['cumulative'] ?? 'Kümülatif Kullanıcılar'; ?>',
                data: filteredData.map(d => d.cumulative_users),
                borderColor: '#3CB371', 
                backgroundColor: chartGradient, 
                fill: true,
                pointBackgroundColor: '#3CB371', 
                pointBorderColor: '#f5f5f5',
                pointHoverRadius: window.innerWidth < 768 ? 5 : 7,
                pointHoverBorderWidth: 2, 
                yAxisID: 'y',
            },
            {
                label: '<?php echo $translations['stats']['today_register'] ?? 'Günlük Kayıtlar'; ?>',
                data: filteredData.map(d => d.daily_users),
                borderColor: '#f5f5f5', 
                fill: false,
                pointBackgroundColor: '#f5f5f5', 
                pointBorderColor: '#f5f5f5',
                pointHoverRadius: window.innerWidth < 768 ? 5 : 7,
                pointHoverBorderWidth: 2, 
                yAxisID: 'y1',
            }
        ];
        statsChart.update();

        window.history.pushState({}, '', `stats?start_date=${startDateInput.value}&end_date=${endDateInput.value}`);
    };

    startDateInput.value = '<?php echo $start_date; ?>';
    endDateInput.value = '<?php echo $end_date; ?>';
    
    startDateInput.addEventListener('change', updateChart);
    endDateInput.addEventListener('change', updateChart);
    
    animateCounters();
    updateChart();

    // Ekran boyutu değiştiğinde grafiği güncelle
    window.addEventListener('resize', () => {
        statsChart.options.plugins.legend.labels.font.size = window.innerWidth < 768 ? 10 : 12;
        statsChart.options.plugins.tooltip.titleFont.size = window.innerWidth < 768 ? 10 : 12;
        statsChart.options.plugins.tooltip.bodyFont.size = window.innerWidth < 768 ? 10 : 12;
        statsChart.options.scales.x.ticks.font.size = window.innerWidth < 768 ? 8 : 10;
        statsChart.options.scales.y.ticks.font.size = window.innerWidth < 768 ? 8 : 10;
        statsChart.options.scales.y1.ticks.font.size = window.innerWidth < 768 ? 8 : 10;
        statsChart.update();
    });
});
</script>
</body>
</html>