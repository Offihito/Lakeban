<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : null;

// Etiket renkleri (Yeni yapı JavaScript tarafında yönetiliyor, bu PHP dizisi artık doğrudan kullanılmıyor ama referans olarak kalabilir)
$tagColors = [
    'new' => '#43b581',
    'improvement' => '#faa61a',
    'bugfix' => '#f04747'
];

// Veritabanı bağlantısı
define('DB_HOST', 'localhost');
define('DB_USER', 'lakebanc_Offihito');
define('DB_PASS', 'P4QG(m2jkWXN');
define('DB_NAME', 'lakebanc_Database');

// Veritabanına bağlan
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// Kullanıcı profili
$profilePicture = null;
if ($isLoggedIn) {
    try {
        $stmt = $db->prepare("
            SELECT u.username, p.avatar_url 
            FROM users u 
            LEFT JOIN user_profiles p ON u.id = p.user_id 
            WHERE u.id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            if ($result['username'] !== $_SESSION['username']) {
                $_SESSION['username'] = $result['username'];
                $username = $result['username'];
            }
            $profilePicture = $result['avatar_url'] ?? null;
            if (!$profilePicture) {
                error_log("Avatar URL bulunamadı: user_id = " . $_SESSION['user_id']);
            }
        } else {
            error_log("Kullanıcı bulunamadı: user_id = " . $_SESSION['user_id']);
            session_unset();
            session_destroy();
            $isLoggedIn = false;
            $username = null;
        }
    } catch (PDOException $e) {
        error_log("Kullanıcı profili sorgulama hatası: " . $e->getMessage());
    }
}

// BİRLEŞTİRİLMİŞ VE TAMAMLANMIŞ Changelog tarihlerini topla
$contributionData = [
        '2025-10-1' =>12,
      '2025-09-18' =>9,
        '2025-08-27' =>17,
      '2025-08-15' =>23,
    '2025-08-5' =>23,
    '2025-07-23' =>18,
    '2025-07-19' =>21,
    '2025-07-12' =>17,
    '2025-07-06' =>15,
    '2025-06-30' =>8,
    '2025-06-27' =>5,
    '2025-06-23' =>6,
    '2025-06-20' =>9,
    '2025-06-15' =>4,
    '2025-06-09' =>2,
    '2025-06-08' =>6,
    '2025-06-05' =>6,
    '2025-06-04' => 5,
    '2025-06-03' => 5,
    '2025-06-01' => 7,
    '2025-05-24' => 4,
    '2025-05-20' => 7, // Her iki dosyada da vardı, yenisi korundu (değeri 6'dan 7'ye güncellendi)
    '2025-05-17' => 7,
    '2025-05-10' => 9,
    '2025-05-04' => 27,
    '2025-05-03' => 5,
    '2025-05-01' => 7,
    '2025-04-20' => 5,
    '2025-04-13' => 6,
    '2025-04-07' => 4,
    '2025-04-03' => 1,
    '2025-04-01' => 2,
    '2025-03-31' => 2,
    '2025-03-30' => 5,
    '2025-03-29' => 3,
    '2025-03-28' => 3,
    '2025-03-23' => 2,
    '2025-03-05' => 3,
    '2025-03-03' => 2,
    '2025-02-27' => 3,
    '2025-02-25' => 4,
    '2025-02-22' => 3,
    '2025-02-18' => 3,
    '2025-02-16' => 4,
    '2025-02-11' => 3,
    '2025-02-08' => 6,
    '2025-02-07' => 4,
    '2025-02-05' => 2,
    '2025-02-03' => 4,
    '2025-01-20' => 4,
    '2025-01-12' => 3,
    '2025-01-10' => 6,
    '2024-12-23' => 7,
    '2024-12-20' => 8,
    '2024-12-18' => 2,
    '2024-12-17' => 5,
    '2024-12-08' => 6,
    '2024-12-06' => 2,
    '2024-12-05' => 2,
    '2024-12-02' => 1,
    '2024-12-01' => 2,
    '2024-11-30' => 4,
    '2024-11-29' => 4,
    '2024-11-28' => 3,
    '2024-11-27' => 3,
    '2024-11-26' => 1,
    '2024-11-25' => 6,
    '2024-11-23' => 1,
    '2024-11-18' => 1,
    '2024-11-16' => 2,
    '2024-11-14' => 3,
    '2024-11-11' => 1,
    '2024-11-09' => 3,
    '2024-11-08' => 1,
    '2024-11-07' => 1,
    '2024-11-06' => 2,
    '2024-11-01' => 1,
    '2024-10-31' => 2,
    '2024-10-29' => 2,
    '2024-10-25' => 7,
    '2024-10-22' => 3,
    '2024-10-20' => 2,
    '2024-10-17' => 2,
    '2024-10-13' => 2,
    '2024-10-12' => 2,
    '2024-10-09' => 1,
    '2024-10-08' => 2,
    '2024-10-07' => 1,
    '2024-10-06' => 1,
    '2024-10-05' => 1,
    '2024-10-04' => 1,
    '2024-10-03' => 1,
    '2024-10-02' => 1,
    '2024-10-01' => 1,
];

// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı dilini al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    // Desteklenen dilleri kontrol et
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
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
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.8">
    <title>LakeBan - Gelişim Zaman Tüneli</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Styles/index.css">
    <style>
        :root {
            --color-background: #050807;
            --color-surface-1: rgba(18, 24, 21, 0.6);
            --color-surface-2: rgba(28, 36, 33, 0.75);
            --color-border: rgba(58, 82, 73, 0.25);
            --color-border-hover: rgba(46, 170, 133, 0.5);
            
            --color-text-primary: #f0f0f5;
            --color-text-secondary: #a0a0b0;
            --color-text-muted: #6c6c80;

            --color-accent-primary: #15ff8b;
            --color-accent-secondary: #34d399;
            --color-accent-glow: rgba(46, 170, 133, 0.3);

            --color-tag-new: #34d399;
            --color-tag-improvement: #60a5fa;
            --color-tag-bugfix: #f87171;
            --color-tag-upcoming: #facc15;

            --font-family-base: 'Inter', sans-serif;
            --font-family-heading: 'Space Grotesk', sans-serif;
            --spacing-unit: 8px;
            --border-radius-small: 6px;
            --border-radius-medium: 12px;
            --border-radius-large: 16px;
            --transition-speed: 0.3s;
            --transition-timing: ease-in-out;
        }

        @keyframes aurora {
            0% { transform: translate(-50%, -50%) rotate(0deg) scale(1.5); }
            50% { transform: translate(-50%, -50%) rotate(180deg) scale(2); }
            100% { transform: translate(-50%, -50%) rotate(360deg) scale(1.5); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; } to { opacity: 1; }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: var(--font-family-base);
            background-color: #000000;
            color: var(--color-text-primary);
            margin: 0;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 50%; left: 50%;
            width: 150vmax; height: 150vmax;
            background: radial-gradient(circle, rgba(46, 170, 133, 0.1), transparent 40%);
            mix-blend-mode: screen;
            animation: aurora 45s linear infinite;
            z-index: -1;
            opacity: 0.7;
        }

        .container {
            max-width: 1024px;
            margin: 0 auto;
            padding: 0 calc(var(--spacing-unit) * 2);
        }

        .page-header { text-align: center; padding: calc(var(--spacing-unit) * 10) 0; }
        .page-header h1 { 
            font-family: var(--font-family-heading);
            font-size: 4rem; font-weight: 700; margin: 0 0 var(--spacing-unit); 
            background: linear-gradient(90deg, #fff, var(--color-text-secondary)); 
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
        }
        .page-header h1 i { color: var(--color-accent-primary); margin-right: 10px; }
        .page-header p { font-size: 1.2rem; color: var(--color-text-secondary); max-width: 600px; margin: 0 auto; line-height: 1.6; }

        .bento-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: calc(var(--spacing-unit) * 2.5);
            margin-bottom: calc(var(--spacing-unit) * 8);
        }
        .bento-item {
            background: var(--color-surface-1);
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius-large);
            padding: calc(var(--spacing-unit) * 3);
            transition: all var(--transition-speed) var(--transition-timing);
            position: relative;
            overflow: hidden;
        }
        .bento-item::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: radial-gradient(circle at 10% 10%, var(--color-accent-glow), transparent 70%);
            opacity: 0;
            transition: opacity var(--transition-speed) var(--transition-timing);
        }
        .bento-item:hover {
            transform: translateY(-5px);
            border-color: var(--color-border-hover);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .bento-item:hover::before { opacity: 1; }
        
        .bento-item.stats-item { text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .bento-item.contribution-item { grid-column: 1 / -1; padding: calc(var(--spacing-unit) * 4); }

        .stats-item h3 { font-family: var(--font-family-heading); font-size: 3.5rem; margin: 0; font-weight: 700; background: linear-gradient(45deg, var(--color-accent-primary), var(--color-accent-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .stats-item p { margin: var(--spacing-unit) 0 0; color: var(--color-text-secondary); font-weight: 500; font-size: 1rem; }
        .contribution-item h3 { margin-top: 0; text-align: left; font-weight: 700; font-size: 1.5rem; font-family: var(--font-family-heading); }
        .contribution-item h3 i { margin-right: 10px; color: var(--color-accent-primary); }
        .graph-wrapper { display: flex; gap: var(--spacing-unit); margin-top: calc(var(--spacing-unit) * 3); }
        .graph-day-labels { display: flex; flex-direction: column; justify-content: space-around; font-size: 11px; color: var(--color-text-muted); padding: 15px 0; }
        .graph-main { width: 100%; }
        .graph-month-labels { display: flex; justify-content: space-around; font-size: 12px; color: var(--color-text-secondary); padding-left: var(--spacing-unit); margin-bottom: var(--spacing-unit); }
        .graph-container { display: grid; grid-template-rows: repeat(7, 1fr); grid-auto-flow: column; gap: 4px; }
        .day { width: 100%; aspect-ratio: 1 / 1; background-color: var(--color-surface-2); border-radius: var(--border-radius-small); position: relative; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .day:hover { transform: scale(1.2); z-index: 2; box-shadow: 0 0 10px var(--color-accent-glow); }
        .day .tooltip { visibility: hidden; opacity: 0; position: absolute; bottom: 125%; left: 50%; transform: translateX(-50%); background-color: #050807; border: 1px solid var(--color-border); color: #fff; padding: 5px 10px; border-radius: var(--border-radius-small); font-size: 12px; white-space: nowrap; z-index: 10; transition: opacity var(--transition-speed) ease; }
        .day:hover .tooltip { visibility: visible; opacity: 1; }
        .graph-legend { display: flex; justify-content: flex-end; align-items: center; font-size: 12px; color: var(--color-text-secondary); margin-top: var(--spacing-unit); }
        .legend-squares { display: flex; margin: 0 var(--spacing-unit); gap: 4px; }
        .legend-squares span { width: 12px; height: 12px; border-radius: 2px; }

        .changelog-controls {
            display: flex; flex-wrap: wrap; gap: calc(var(--spacing-unit) * 2); padding: calc(var(--spacing-unit) * 3);
            background-color: var(--color-surface-1); border-radius: var(--border-radius-large);
            margin-bottom: calc(var(--spacing-unit) * 8); border: 1px solid var(--color-border);
            position: sticky; top: 85px; z-index: 998;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .filter-group { display: flex; flex-direction: column; flex-grow: 1; min-width: 200px; }
        .filter-group label { margin-bottom: var(--spacing-unit); font-weight: 500; color: var(--color-text-secondary); font-size: 14px; }
        .filter-group label i { margin-right: 8px; }
        .filter-group select, .filter-group input { background-color: var(--color-surface-2); color: var(--color-text-primary); border: 1px solid var(--color-border); padding: calc(var(--spacing-unit) * 1.5); border-radius: var(--border-radius-medium); font-family: var(--font-family-base); transition: border-color var(--transition-speed), box-shadow var(--transition-speed); appearance: none; -webkit-appearance: none; background-repeat: no-repeat; background-position: right 12px center; font-size: 1rem; }
        .filter-group select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23a0a0b0' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E"); }
        .filter-group select:focus, .filter-group input:focus { outline: none; border-color: var(--color-accent-primary); box-shadow: 0 0 0 3px var(--color-accent-glow); }
        .filter-group.search-group { flex-grow: 2; }
        
        .timeline {
            position: relative;
            padding: calc(var(--spacing-unit) * 4) 0;
            animation: fadeIn 1s var(--transition-timing);
        }
        .timeline::before {
            content: '';
            position: absolute;
            top: 0; left: 50%;
            transform: translateX(-50%);
            width: 3px;
            height: 100%;
            background-image: linear-gradient(to bottom, transparent, var(--color-accent-primary) 10%, var(--color-accent-primary) 90%, transparent);
            border-radius: 3px;
        }

        .timeline-item {
            position: relative;
            width: 50%;
            padding: 0 calc(var(--spacing-unit) * 5);
            margin-bottom: calc(var(--spacing-unit) * 5);
            opacity: 0.3;
            transform: translateY(20px);
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
        }
        .timeline-item.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .timeline-item:nth-child(odd) { left: 0; }
        .timeline-item:nth-child(even) { left: 50%; }

        .timeline-item::after {
            content: '';
            position: absolute;
            top: 20px;
            width: 15px; height: 15px;
            border-radius: 50%;
            background-color: var(--color-background);
            border: 3px solid var(--color-accent-primary);
            z-index: 1;
            transition: transform 0.3s ease;
        }
        .timeline-item:nth-child(odd)::after { right: -7.5px; transform: translateX(50%); }
        .timeline-item:nth-child(even)::after { left: -7.5px; transform: translateX(-50%); }
        .timeline-item.is-visible::after { transform: translateX(calc(var(--direction, 0) * -50%)) scale(1.2); }

        .timeline-milestone {
            background: var(--color-accent-primary);
            color: var(--color-background);
            font-family: var(--font-family-heading);
            font-weight: 700;
            padding: var(--spacing-unit) calc(var(--spacing-unit) * 2);
            border-radius: 99px;
            position: relative;
            margin: calc(var(--spacing-unit) * 6) auto;
            display: table;
            z-index: 5;
        }
        
        .changelog-card {
            background-color: var(--color-surface-1);
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius-large);
            padding: calc(var(--spacing-unit) * 3);
            transition: all var(--transition-speed) var(--transition-timing);
            position: relative;
        }
        .timeline-item.is-visible .changelog-card {
            border-color: var(--color-border-hover);
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
        }
        
        .changelog-date {
            font-weight: 500; color: var(--color-text-secondary);
            font-size: 0.9rem; margin-bottom: calc(var(--spacing-unit) * 2); display: block;
        }
        .upcoming-features .changelog-date {
            color: var(--color-tag-upcoming); font-weight: 700;
        }

        .changelog-card ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: calc(var(--spacing-unit) * 2); }
        .changelog-card li { display: flex; align-items: flex-start; gap: 12px; line-height: 1.5; }
        .changelog-card .sub-list { padding-left: 20px; margin-top: 8px; list-style-type: '→ '; }

        .tag {
            display: inline-block; padding: 3px 10px; border-radius: 99px;
            font-size: 0.8rem; font-weight: 600; color: #fff; flex-shrink: 0;
            border: 1px solid transparent;
        }
        .tag.new { background-color: rgba(52, 211, 153, 0.15); border-color: var(--color-tag-new); color: var(--color-tag-new); }
        .tag.improvement { background-color: rgba(96, 165, 250, 0.15); border-color: var(--color-tag-improvement); color: var(--color-tag-improvement); }
        .tag.bugfix { background-color: rgba(248, 113, 113, 0.15); border-color: var(--color-tag-bugfix); color: var(--color-tag-bugfix); }
        .tag.removed { background-color: rgba(156, 163, 175, 0.15); border-color: #9ca3af; color: #9ca3af; }


        @media (max-width: 900px) {
            .timeline::before { left: 20px; }
            .timeline-item { width: 100%; padding-left: calc(var(--spacing-unit) * 8); padding-right: 0; }
            .timeline-item:nth-child(even) { left: 0; }
            .timeline-item::after { left: 20px; transform: translateX(-50%); }
            .timeline-item.is-visible::after { transform: translateX(-50%) scale(1.2); }
        }
        @media (max-width: 768px) {
            .header-content { flex-direction: column; gap: 16px; }
            .changelog-controls { flex-direction: column; }
            .page-header h1 { font-size: 3rem; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="logo-container">
        <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
        <span class="brand">LakeBan</span>
    </div>
    <nav>
        <a href="#"><?php echo $translations['header']['nav']['home'] ?? 'Anasayfa'; ?></a>
            <a href="/topluluklar"><?php echo $translations['header']['nav']['communities'] ?? 'Topluluklar'; ?></a>
            <a href="/changelog"><?php echo $translations['header']['nav']['updates'] ?? 'Güncellemeler'; ?></a>
               <a href="/lakebium"> Lakebium</a>
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
                        <?php if ($profilePicture): ?>
                            <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="<?php echo htmlspecialchars($username); ?>'s avatar">
                        <?php else: ?>
                            <?php echo strtoupper(substr($username, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <span><?php echo htmlspecialchars($username); ?></span>
                </a>
            </div>
        <?php else: ?>
            <div class="auth-buttons">
                <a href="/login" class="login-btn"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></a>
                <a href="/register" class="register-btn"><?php echo $translations['header']['register'] ?? 'Kayıt Ol'; ?></a>
            </div>
        <?php endif; ?>
    </div>
</header>

    <main class="container">
        <div class="page-header">
            <h1><i class="fas fa-rocket"></i> Değişim Zaman Tüneli</h1>
            <p>LakeBan'ın gelişim yolculuğuna tanık olun. Platformumuza eklenen en son özellikleri, iyileştirmeleri ve hata düzeltmelerini buradan takip edebilirsiniz.</p>
        </div>

        <div class="bento-grid">
            <div class="bento-item stats-item">
                <h3>315+</h3>
                <p>Kayıtlı Kullanıcı</p>
            </div>
            <div class="bento-item stats-item">
                <h3>50+</h3>
                <p>Aktif Sunucu</p>
            </div>
            <div class="bento-item stats-item">
                <h3>135K+</h3>
                <p>Gönderilen Mesaj</p>
            </div>
            <div class="bento-item contribution-item">
                <h3><i class="fas fa-calendar-alt"></i> Geliştirme Aktiviteleri</h3>
                <div class="graph-wrapper">
                    <div class="graph-day-labels" id="graphDayLabels"></div>
                    <div class="graph-main">
                        <div class="graph-month-labels" id="graphMonthLabels"></div>
                        <div class="graph-container" id="contributionGraph"></div>
                    </div>
                </div>
                <div class="graph-legend">
                    <span>Daha Az</span>
                    <div class="legend-squares" id="legendSquares"></div>
                    <span>Daha Çok</span>
                </div>
            </div>
        </div>

        <div class="changelog-controls">
             <div class="filter-group">
                 <label for="version-filter"><i class="fas fa-filter"></i> Sürüme Göre Filtrele</label>
                 <select id="version-filter">
                     <option value="all">Tüm Sürümler</option>
                     <option value="upcoming">Yaklaşan</option>
                     <option value="1.8">v1.8</option>
                     <option value="1.7.9">v1.7.9 - Geri Dönüş</option>
                       <option value="1.7.8-exp2">v1.7.8-exp2</option>
                               <option value="1.7.8-exp1">v1.7.8-exp1</option>
                      <option value="1.7.8">v1.7.8</option>
                    <option value="1.7.7-exp3">v1.7.7-exp3</option>
                    <option value="1.7.7-exp2">v1.7.7-exp2</option>
                    <option value="1.7.7-exp1">v1.7.7-exp1</option>
                    <option value="1.7.6">v1.7.6</option>
                    <option value="1.7.6-exp3">v1.7.6-exp3</option>
                    <option value="1.7.6-exp2">v1.7.6-exp2</option>
                    <option value="1.7.6-exp1">v1.7.6-exp1</option>
                    <option value="1.7.5">v1.7.5</option>
                    <option value="1.7.5-exp2">v1.7.5-exp2</option>
                    <option value="1.7.5-exp1">v1.7.5-exp1</option>
                    <option value="1.7.4">v1.7.4</option>
                    <option value="1.7.4-exp3">v1.7.4-exp3</option>
                    <option value="1.7.4-exp2">v1.7.4-exp2</option>
                    <option value="1.7.4-exp1">v1.7.4-exp1</option>
                    <option value="1.7.3">v1.7.3</option>
                    <option value="1.7.3-exp2">v1.7.3-exp2</option>
                    <option value="1.7.3-exp1">v1.7.3-exp1</option>
                    <option value="1.7.2">v1.7.2</option>
                    <option value="1.7.1">v1.7.1</option>
                    <option value="1.7.0">v1.7.0</option>
                    <option value="1.6.9">v1.6.9</option>
                    <option value="1.6.8">v1.6.8</option>
                    <option value="1.6.7">v1.6.7</option>
                    <option value="1.6.6">v1.6.6</option>
                    <option value="1.6.5">v1.6.5</option>
                    <option value="1.6.4">v1.6.4</option>
                    <option value="1.6.3">v1.6.3</option>
                    <option value="1.6.2">v1.6.2</option>
                    <option value="1.6.1">v1.6.1</option>
                    <option value="1.6">v1.6</option>
                    <option value="1.5.9">v1.5.9</option>
                    <option value="1.5.8">v1.5.8</option>
                    <option value="1.5.7">v1.5.7</option>
                    <option value="1.5.6">v1.5.6</option>
                    <option value="1.5.5">v1.5.5</option>
                    <option value="1.5.4">v1.5.4</option>
                    <option value="1.5.3">v1.5.3</option>
                    <option value="1.5.2">v1.5.2</option>
                    <option value="1.5.1">v1.5.1</option>
                    <option value="1.5">v1.5</option>
                    <option value="1.4.9">v1.4.9</option>
                    <option value="1.4.8">v1.4.8</option>
                    <option value="1.4.7">v1.4.7</option>
                    <option value="1.4.6">v1.4.6</option>
                    <option value="1.4.5">v1.4.5</option>
                    <option value="1.4.4">v1.4.4</option>
                    <option value="1.4.3">v1.4.3</option>
                    <option value="1.4.2">v1.4.2</option>
                    <option value="1.4.1">v1.4.1</option>
                    <option value="1.4">v1.4</option>
                    <option value="1.3.9">v1.3.9</option>
                    <option value="1.3.8">v1.3.8</option>
                    <option value="1.3.7">v1.3.7</option>
                    <option value="1.3.6">v1.3.6</option>
                    <option value="1.3.5">v1.3.5</option>
                    <option value="1.3.4">v1.3.4</option>
                    <option value="1.3.3">v1.3.3</option>
                    <option value="1.3.2">v1.3.2</option>
                    <option value="1.3.1">v1.3.1</option>
                    <option value="1.3">v1.3</option>
                    <option value="1.2.9">v1.2.9</option>
                    <option value="1.2.8">v1.2.8</option>
                    <option value="1.2.7">v1.2.7</option>
                    <option value="1.2.6">v1.2.6</option>
                    <option value="1.2.5">v1.2.5</option>
                    <option value="1.2.4">v1.2.4</option>
                    <option value="1.2.3">v1.2.3</option>
                    <option value="1.2.2">v1.2.2</option>
                    <option value="1.2.1">v1.2.1</option>
                    <option value="1.2">v1.2</option>
                    <option value="1.1.9">v1.1.9</option>
                    <option value="1.1.8">v1.1.8</option>
                    <option value="1.1.7">v1.1.7</option>
                    <option value="1.1.6">v1.1.6</option>
                    <option value="1.1.5">v1.1.5</option>
                    <option value="1.1.4">v1.1.4</option>
                    <option value="1.1.3">v1.1.3</option>
                    <option value="1.1.2">v1.1.2</option>
                    <option value="1.1.1">v1.1.1</option>
                    <option value="1.1.0">v1.1.0</option>
                    <option value="1.0.9">v1.0.9</option>
                    <option value="1.0.8">v1.0.8</option>
                    <option value="1.0.7">v1.0.7</option>
                    <option value="1.0.6">v1.0.6</option>
                    <option value="1.0.5">v1.0.5</option>
                    <option value="1.0.4">v1.0.4</option>
                    <option value="1.0.3">v1.0.3</option>
                    <option value="1.0.2">v1.0.2</option>
                    <option value="1.0.1">v1.0.1</option>
                    <option value="1.0.0">v1.0.0</option>
                 </select>
             </div>
             <div class="filter-group">
                 <label for="tag-filter"><i class="fas fa-tag"></i> Etikete Göre Filtrele</label>
                 <select id="tag-filter">
                     <option value="all">Tüm Etiketler</option>
                     <option value="new">Yeni</option>
                     <option value="improvement">İyileştirme</option>
                     <option value="bugfix">Hata Düzeltme</option>
                     <option value="removed">Kaldırıldı</option>
                 </select>
             </div>
             <div class="filter-group search-group">
                 <label for="search"><i class="fas fa-search"></i> İçerik Ara</label>
                 <input type="text" id="search" placeholder="Bir özellik, sürüm veya etiket arayın...">
             </div>
        </div>

        <div class="timeline" id="changelogList">
            </div>
    </main>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // BİRLEŞTİRİLMİŞ VE TAMAMLANMIŞ CHANGELOG VERİSİ
    const changelogData = [
        {
            version: 'upcoming',
            date: 'ÇOK YAKINDA',
            title: '<i class="fas fa-road"></i> Yaklaşan Özellikler',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Timeout özelliği ile geçici kullanıcı susturma' },
                { tag: 'new', text: 'Uygulama genelinde SPA (Single Page Application) sistemine geçiş' },
                 { tag: 'new', text: 'Kamera açma ile daha iyi sohbet deneyimi' },
                  { tag: 'new', text: 'Etkinlik API ile başkalarının hangi oyunu oynadığınzı görmesi' }
                
            
            ]
        },
                      {
    version: '1.8',
    date: '1 Ekim 2025',
    tags: ['new', 'improvement', 'feature', 'fix'],
    items: [
        { tag: 'new', text: 'Botlar Yeniden yapıldı.' },
        { tag: 'bugfix', text: 'Service Worker sorunları düzeltildi.' },
        { tag: 'bugfix', text: 'Roller sayfasındaki buglar düzeltildi.' },
        { tag: 'bugfix', text: 'Botlar için audit log düzeltildi.' },
         { tag: 'improvement', text: 'Doğrudan mesajlardaki mesajlaşma sistemi websockete geçirildi.' },
         { tag: 'improvement', text: 'Mobil Uygulama webappden hybrid e dönüştürüldü.' },
         { tag: 'improvement', text: 'Postlar sayfasına dil desteği eklendi.' },
          { tag: 'improvement', text: 'Mobil uygulamaya splash screen eklendi.' },
          { tag: 'improvement', text: 'Mobil uygulama stabil duruma getirildi. artık yavaş değil.' },
          { tag: 'improvement', text: 'Botlar artık javascript ile yazılıyor (hala oto bot oluşturucu mevcut)' },
           { tag: 'new', text: 'Botlara custom kod ekleme getirildi. (Yani Çoğu yapılamayan şeyler artık yapılabilecek.)' },
                  { tag: 'new', text: 'Public Bot ekleme sayfası getirildi' }
    ]
},
                {
    version: '1.7.9 - Geri dönüş',
    date: '18 Eylül 2025',
    tags: ['new', 'improvement', 'feature', 'fix'],
    items: [
        { tag: 'new', text: 'Lakebium Geldi!' },
        { tag: 'new', text: 'Doğrudan mesajlarda tüm mesajlaşma websockete geçirildi.' },
         { tag: 'new', text: 'Sunucularda mesajları okuma tek tıkla kolaylaştırıldı.' },
         { tag: 'improvement', text: 'Mesaj inputları firefox için düzeltildi.' },
         { tag: 'improvement', text: 'Mobil için mesaj inputları yeniden tasarlandı ve + butonu eklendi artık gif, anket, emoji seçme ordan yapılıyor.' },
         { tag: 'improvement', text: 'Mobilde kanal seçince oto mesaj yerine getirmesi eklendi.' },
          { tag: 'improvement', text: 'Kanal editlemeye dil desteği eklendi.' },
           { tag: 'new', text: 'SPA dm ile sunucu arası testler halen yapılıyor gecikme için üzgünüz' },
                  { tag: 'improvement', text: 'Kanal oluşturma kanal editleme sayfaları yenilendi.' }
    ]
},
              {
    version: '1.7.8 -exp2',
    date: '27 Ağustos 2025',
    tags: ['new', 'improvement', 'feature', 'fix'],
    items: [
        { tag: 'new', text: 'Lakebium Malesefki ertelendi kusura bakmayın.' },
          { tag: 'new', text: 'Bilgisayar Uygulamasının yeni sürümünde artık otomatik açılıyor!' },
        { tag: 'bugfix', text: 'Doğrudan mesajlarda sesli sohbette ui in bazı yerlere dokununca kapanması düzeltildi.' },
        { tag: 'improvement', text: 'Dm ile Sunucu arası SPA geliyor.' },
        { tag: 'bugfix', text: 'Sunucularda okunmamış mesaj sayısının ilk kanalda güncellenmemesi düzeltildi.' },
        { tag: 'improvement', text: 'Sunucularda Rol verme iznine sahip olanlardaki izin aktif hale getirildi.' },
         { tag: 'new', text: 'Ayarlar sayfasının yarısı spaya geçirildi artık sayfa yenilenmeden geçiş yapılabilecek.' },
          { tag: 'new', text: 'Mesajlara Profil çerçeveleri eklendi.' },
          { tag: 'new', text: 'Temalar İşlevli hale getirildi.' },
         { tag: 'new', text: 'Lakebiuma özel Custom tema eklendi.' },
      { tag: 'improvement', text: 'Sunucularda sidebar anlık olarak güncelleniyor artık.' },
      { tag: 'improvement', text: 'Doğrudan mesajlarda artık birisine mesaj atılınca o kişi üste geliyor.' },
          { tag: 'bugfix', text: 'Doğrudan mesajlarda ekran paylaşımı kapanınca izleyicideki buglar düzeltildi.' },
              { tag: 'new', text: 'Doğrudan mesajlarda mikrofon susturma işlevli hale getirildi.' },
                 { tag: 'bugfix', text: 'Sunucularda mesaj atınca last_read in güncellenmemesi düzeltildi. (Sunucu Okunmamış Mesaj sayacı)' },
                  { tag: 'new', text: 'Rollere daha fazla özelleştirme eklendi.' },
                  { tag: 'improvement', text: 'Birisi size arkadaş isteği atınca anında gözükmesi sağlandı.' }
    ]
},
          {
    version: '1.7.8 -exp1 güncellemeyi atmayı unutmuşuz :P',
    date: '15 Ağustos 2025',
    tags: ['new', 'improvement', 'feature', 'fix'],
    items: [
        { tag: 'new', text: 'Lakebium 2 güne geliyor!' },
        { tag: 'bugfix', text: 'Sunuculardan çıkınca rollerin gitmemesi düzeltildi.' },
        { tag: 'bugfix', text: 'Bazı özel profil sayfalarının açılmaması düzeltildi.' },
        { tag: 'improvement', text: 'Sunucularda kanal değiştirme artık 3 kat daha hızlı!' },
         { tag: 'new', text: 'Gruplar için ayarlar butonu eklendi.' },
        { tag: 'improvement', text: 'Normal chatlerde grup butonlarının gözükmesi düzeltildi.' },
     { tag: 'bugfix', text: 'Bot oluşturmada boş http500 hataları gelmesi düzeltildi.' },
        { tag: 'new', text: 'Pc uygulamasına push notifications eklendi.' },
       { tag: 'bugfix', text: 'Sunucularda kanal değiştirince sesli bağlantının kesilmesi düzeltildi.' },
       { tag: 'bugfix', text: 'Etiketlenme bölümünde (Notifications) tümünü okundu sayın çalışmaması düzeltildi.' },
  { tag: 'improvement', text: 'Güncellemeler sayfası yenilendi.' },
   { tag: 'improvement', text: 'Mobil uygulama yeni sürüm ile birlikte hızlandırıldı.' },
   { tag: 'improvement', text: 'Pc uygulaması yeni sürümünde artık sürüklenebiliyor ayrıca bazı butonlara erişememe düzeltildi' },
     { tag: 'improvement', text: 'Ayarlar ve profil ayarlama sayfasına dil desteği eklendi anasayfa yeniden çevrildi.' },
      { tag: 'improvement', text: 'Kullanıcı adı değiştirirken her ismi seçme engelleniyor artık minimum 3 karakter' },
        { tag: 'improvement', text: 'Emoji stilleri artık twemoji' },
        { tag: 'improvement', text: 'Mobil için mesaj input yenilendi daha düzgün artık.' },
         { tag: 'improvement', text: 'Youtube linklerinde shorts desteği eklendi.' },
          { tag: 'improvement', text: 'Dmler için emoji picker yenilendi.' },
          { tag: 'improvement', text: 'Belirli linkler artık fotoğrafa dönüşecek.' },
         { tag: 'bugfix', text: 'Post sayfası düzeltildi.' },
        { tag: 'new', text: 'Display username eklendi.' },
        { tag: 'new', text: 'Sunuculara okunmamış mesaj sayısı eklendi.' }
    ]
},
        {
    version: '1.7.8',
    date: '5 Ağustos 2025',
    tags: ['new', 'improvement', 'feature', 'fix'],
    items: [
        { tag: 'new', text: 'Botlara küfür engelleyici eklendi' },
        { tag: 'bugfix', text: 'Sunucularda fotoğraflarda lightboxun iki kere açılması düzeltildi.' },
        { tag: 'bugfix', text: 'Sunucularda eski mesajların açılmaması düzeltildi.' },
        { tag: 'improvement', text: 'Reaksiyonlar optimize edildi artık chatler ve sunucular daha hızlı açılıyor.' },
        { tag: 'improvement', text: 'Anasayfa ve İstatistikler sayfasının görünümü yenilendi.' },
        { tag: 'new', text: 'Doğrudan mesajlarda mini profiller eklendi. (Yeni)' },
        { tag: 'new', text: 'Unread countlarda websocket mesajının gelmemesi düzeltildi.' },
        { tag: 'bugfix', text: 'Doğrudan mesajlarda mesaj silince eski bir mesajın alta gelmesi düzeltildi.' },
        { tag: 'bugfix', text: 'Mesaj silince karşı taraftada silinmemesi düzeltildi.' },
        { tag: 'improvement', text: 'Sunucular optimize edildi.' },
        { tag: 'improvement', text: 'Profil sayfaları ve header yenilendi.' },
        { tag: 'improvement', text: 'Mobilde ayarlar sayfasına animasyonlu kaydırma desteği eklendi!' },
        { tag: 'new', text: 'Sunuculara gif yollama eklendi.' },
        { tag: 'new', text: 'Doğrudan mesajlarda anket yollama eklendi.' },
        { tag: 'new', text: 'Raporlama sistemi eklendi.' },
        { tag: 'new', text: 'Destek yeri eklendi.' },
        { tag: 'improvement', text: 'Hakkımızda sayfası yenilendi.' },
        { tag: 'new', text: 'Lakebium üzerinde çalışılıyor.' },
        { tag: 'improvement', text: 'Mini profilde yetkililer için rollerin kaldırılması için kısa yollar eklendi.' },
        { tag: 'improvement', text: 'Doğrudan mesajlarda grubu aktarma ve gruptan çıkma butonları artık chati açmıyor.' },
        { tag: 'improvement', text: 'Sunucularda mesaj arama kısmı geliştirildi.' },
        { tag: 'improvement', text: 'Sunucularda user sidebarda artık kaç kişinin bir role sahip olduğu kaçının çevrimdışı, çevrimiçi olduğunu gösteren imleç eklendi.' },
        { tag: 'new', text: 'Doğrudan mesajlarda dosya atınca dosyanın ne kadarı yüklendiğini gösteren progress bar eklendi.' }
    ]
},
        {
            version: '1.7.7-exp3',
            date: '23 Temmuz 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Mobil uygulama yayınlandı.' },
                { tag: 'improvement', text: 'Lakeban genel arayüzü yeniden tasarlanmaya başlandı (rework).' },
                { tag: 'bugfix', text: 'Sunucularda fotoğrafların görünmemesi sorunu düzeltildi.' },
                { tag: 'improvement', text: 'Rahatsız Etmeyin (DND) modu artık işlevsel.' },
                { tag: 'improvement', text: 'Mesaj yazarken artık satır atlama (multi-line) desteği eklendi.' },
            ]
        },
        {
            version: '1.7.7-exp2',
            date: '19 Temmuz 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Typing indicator artık birden fazla kullanıcıyı aynı anda gösterebiliyor.' },
                { tag: 'new', text: 'Yeni kullanıcı profil tasarımı yayınlandı.' },
                { tag: 'improvement', text: 'Yanıtlama arayüzü geliştirildi ve cilalandı.' },
                { tag: 'bugfix', text: 'Gruplarda mesaj yazıldığında bildirim sesi çıkmaması sorunu düzeltildi.' },
                { tag: 'bugfix', text: 'Sunucu sesli kanallardaki bağlantı/ses sorunları giderildi.' },
            ]
        },
        {
            version: '1.7.7-exp1',
            date: '12 Temmuz 2025',
            tags: ['new', 'improvement', 'bugfix', 'removed'],
            items: [
                { tag: 'new', text: 'Sunucu ayarlarında sol sidebar\'a bütün kanallar eklendi ve düzeltildi.' },
                { tag: 'improvement', text: 'Sunucu ayarlarında topluluk sayfası ve topluluklar sayfası güncellendi, etiket sistemi eklendi.' },
                { tag: 'new', text: 'Bot sistemi 1.5 yayında: Karşılama komutu, kullanıcı sunucuya katıldığında veya ayrıldığında mesaj gönderiyor.' },
                { tag: 'new', text: 'Botu açıp kapatma özelliği, özel prefix, avatar ve isim değiştirme eklendi.' },
                { tag: 'new', text: 'Anketler özelliği eklendi.' },
                { tag: 'new', text: 'GIF desteği eklendi.' },
                { tag: 'new', text: 'Doğrudan mesajlarda, chat dışında bildirim gelme özelliği eklendi.' },
                { tag: 'improvement', text: 'Chate bakarken bildirim gelmeme sorunu düzeltildi.' },
                { tag: 'new', text: 'Sunucu davet linkleri artık embed mesaj olarak gösteriliyor: Sunucu adı, ikon, üye sayısı ve açıklama içeren şık bir kutucuk.' },
                { tag: 'new', text: 'Sunucu ayarlarına denetim kaydı eklendi, botlarla entegre edildi; bot değişiklikleri denetim kaydında görünüyor.' },
                { tag: 'improvement', text: 'Sunucularda rol düzenlemeleri yapıldı.' },
                { tag: 'improvement', text: 'Sunucularda kısıtlı rol ID sistemi yeniden düzenlendi.' },
                { tag: 'new', text: 'Mobilde avatar düzenleme özelliği eklendi.' },
                { tag: 'new', text: 'Yeni etiket sistemi eklendi.' },
                { tag: 'new', text: 'Yeni video oynatıcı eklendi.' },
                { tag: 'new', text: 'Sunucu ayarları davet bölümüne, davetin dolma süresi ve daveti oluşturanın profili ile ismi eklendi.' },
                { tag: 'bugfix', text: 'Davet silerken diğer davetlerin silinmesi hatası düzeltildi.' },
                { tag: 'removed', text: 'Sunucu ayarlarının anasayfasında emoji yükleme kaldırıldı, bunun yerine emoji yüklemek için özel bir sayfa var.' }
            ]
        },
        {
            version: '1.7.6',
            date: '06 Temmuz 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Bot sistemi 1.0 Early Edition yayında.' },
                { tag: 'new', text: 'DM sesli görüşmelere "incoming call" özelliği eklendi.' },
                { tag: 'new', text: 'DM sesli görüşmelere ekran paylaşımı özelliği eklendi.' },
                { tag: 'improvement', text: 'DM sesli görüşme modali yeniden tasarlandı.' },
                { tag: 'new', text: 'YouTube bağlantıları artık mesaj içinde gömülü olarak gösteriliyor.' },
                { tag: 'bugfix', text: 'Mobilde postlardaki üç nokta menüsü hatası düzeltildi.' },
                { tag: 'new', text: 'Ping-pong sistemi eklendi (bağlantı testi için).' },
                { tag: 'new', text: 'Sunucu emojileri için özel sayfa eklendi.' },
                { tag: 'new', text: 'Sunucu stickerları için yeni sayfa eklendi.' },
                { tag: 'new', text: 'Sunucu URL\'lerine özel link üretme ve istatistik grafik sistemi eklendi (toplam kullanıcı sayısı dahil).' },
                { tag: 'improvement', text: 'Güncellemeler sayfası artık Ajax ile indexten yükleniyor.' },
                { tag: 'improvement', text: 'Sunucu ayarları ana sayfası yeniden tasarlandı.' },
                { tag: 'new', text: 'Müziklere özel oynatıcı eklendi.' },
                { tag: 'new', text: 'Doğrudan mesajlara (DM) reaksiyon verme özelliği eklendi.' },
                { tag: 'improvement', text: 'Sunuculardaki voice-control arayüzü iyileştirildi.' }
            ]
        },
        {
            version: '1.7.6-exp3',
            date: '30 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Doğrudan mesajlara mesaj sabitleme eklendi.' },
                { tag: 'new', text: 'Doğrudan mesajlara Mesaj arama eklendi.' },
                { tag: 'improvement', text: 'Doğrudan mesajlarda sesli sohbete yeni modal eklendi.' },
                { tag: 'bugfix', text: 'Doğrudan mesajlarda sesli sohbet düzeltildi.' },
                { tag: 'bugfix', text: 'Sunucularda mesaj yanıtlayınca başka kanala atması düzeltildi.' },
                { tag: 'improvement', text: 'Doğrudan mesajlarda arkadaşlık menüsünün çıkmaması düzeltildi.' },
                { tag: 'new', text: 'Postlara çoklu resim ekleme eklendi.' },
                { tag: 'improvement', text: 'Sunuculara yeni kısa yollar eklendi.' }
            ]
        },
        {
            version: '1.7.6-exp2',
            date: '27 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Arkadaş gizleme yenilendi (hala biraz iyileştirme gerekiyor)' },
                { tag: 'bugfix', text: 'Sunucularda ekran paylaşma düzeltildi.' },
                { tag: 'improvement', text: 'Sunucu Ayarlarına mobil destek geldi.' },
                { tag: 'bugfix', text: 'Reaksiyonlar düzeltildi.' },
                { tag: 'improvement', text: 'Giriş ve kayıt olma sayfaları yenilendi.' }
            ]
        },
        {
            version: '1.7.6-exp1',
            date: '23 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Sunuculara özel custom emoji eklendi.' },
                { tag: 'new', text: 'Mesajları arama filtresi eklendi.' },
                { tag: 'new', text: 'Mesajları Sabitleme eklendi.' },
                { tag: 'bugfix', text: 'Sunucularda kanal değiştirip mesaj atınca önceki kanala gelmesi engellendi.' },
                { tag: 'improvement', text: 'Sunuculardaki üç noktaya basınca menünün arka planı olmaması düzeltildi. Doğrudan mesajlar ile sunucular arasındaki üç nokta butonu arasındaki farklar 0\'a indirildi.' },
                { tag: 'improvement', text: 'Sunucular listesinde sunucuların yerini değiştirebilme eklendi.' }
            ]
        },
        {
            version: '1.7.5',
            date: '20 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'bugfix', text: 'Profillerdeki Post yerleri Utf-8 e dönüştürüldü.' },
                { tag: 'bugfix', text: 'Sunucularda Mesaj atarken mesajın stacklenmemesi düzeltildi.' },
                { tag: 'bugfix', text: 'Sunucularda yeni atılan mesajlara timestamp eklendi.' },
                { tag: 'bugfix', text: 'Sunucularda Kategori oluşturma düzeltildi' },
                { tag: 'improvement', text: 'Kanal oluşturma, Kategori oluşturma ve sunucuların birazına dil desteği getirildi.' },
                { tag: 'improvement', text: 'Karakter Limiti eklendi. (2000)' },
                { tag: 'new', text: 'Kanallara yazamama eklendi.' },
                { tag: 'bugfix', text: 'Kategorisi olmayan kanallarda sese girememe düzeltildi.' },
                { tag: 'improvement', text: 'Sunucularda Mobilde stacklenmiş mesajların biraz fazla solda olması düzeltildi.' }
            ]
        },
        {
            version: '1.7.5-exp2',
            date: '15 Haziran 2025',
            tags: ['bugfix'],
            items: [
                { tag: 'bugfix', text: 'Sunucularda canlı sohbet stack desteği ile geri döndü' },
                { tag: 'bugfix', text: 'Kanal değiştirirkenki tüm buglar düzeltildi.' },
                { tag: 'bugfix', text: 'Müzik ve Profil resmi değiştirememe düzeltildi.' },
                { tag: 'bugfix', text: 'Sunucularda sesli kanala 2 den fazla kişi girince bozulması düzeltildi.' }
            ]
        },
        {
            version: '1.7.5-exp1',
            date: '9 Haziran 2025',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Sesli kanala giriş/çıkış bildirimleri eklendi katılanlar ve ayrılanlar anlık olarak gösteriliyor.' },
                { tag: 'new', text: 'Sesli bağlantı durumu (WebRTC) eklendi Anlık bağlantı durumu ve gecikme (ms) bilgileri gösteriliyor.' }
            ]
        },
        {
            version: '1.7.4',
            date: '8 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'bugfix', text: 'Giriş sistemi düzeltildi Artık bir ay boyunca tekrar giriş yapmanız istenmeyecek' },
                { tag: 'improvement', text: 'Sunuculara Unread count eklendi.' },
                { tag: 'improvement', text: 'Ayarlar > Profilim sayfası arayüz olarak cilalandı, daha kullanışlı hale geldi.' },
                { tag: 'improvement', text: 'Profil sayfası tamamen yenilendi Artık bağlantılar ekleyebiliyorsunuz! Bu bağlantıları "Profilim" sayfasından düzenleyebilirsiniz.' },
                { tag: 'new', text: 'Mesajlardan DM silme özelliği eklendi.' },
                { tag: 'new', text: 'Mini profillere arkadaş ekleme butonu eklendi.' },
                { tag: 'new', text: 'Yayınlara tam ekran izleme desteği getirildi.' }
            ]
        },
        {
            version: '1.7.4-exp3',
            date: '5 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix', 'removed'],
            items: [
                { tag: 'bugfix', text: 'Üye olmadığınız sunuculara sızma düzeltildi.' },
                { tag: 'improvement', text: 'Sunucularda Kanallar artık sayfa yenilenmeden açılıyor.' },
                { tag: 'improvement', text: 'Gruplarda Kurucuların çıkamaması düzeltildi ve yönetici rolünü transfer etmeleri sağlandı.' },
                { tag: 'new', text: 'Gruplara üye ekleme eklendi.' },
                { tag: 'removed', text: 'Sunucularda Mesaj güncelleme kaldırıldı şuanlık canlı sohbet geçici olarak çalışmicaktır.' },
                { tag: 'removed', text: 'Lakealt sol sidebar kısmında Mod kuyruğu ve mod postası bölümü kaldırıldı.' }
            ]
        },
        {
            version: '1.7.4-exp2',
            date: '4 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'bugfix', text: 'Lakealt anasayfasında üst header kısmının butonları düzeltildi' },
                { tag: 'improvement', text: 'Ses.php sayfası yenilendi' },
                { tag: 'improvement', text: 'Lakealt anasayfa yenilendi, Lakealtlar yenilendi, Ayarlar kısmı yenilendi, Lakealt keşfet kısmı yenilendi' },
                { tag: 'new', text: 'Postlara yeni yorum sistemi, yorum düzenleme, yorum silme eklendi' }
            ]
        },
        {
            version: '1.7.4-exp1',
            date: '3 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'bugfix', text: 'Sunucularda Tepki atma düzeltildi.' },
                { tag: 'improvement', text: 'Sunuculara Mesaj stacklenmesi eklendi.' },
                { tag: 'improvement', text: 'Direkt mesajlarda arkadaşlık isteğini geri alma eklendi.' },
                { tag: 'new', text: 'Postlardaki yorumlara vote sistemi eklendi.' },
                { tag: 'new', text: 'Moderatörlerin postları silmesi eklendi.' }
            ]
        },
        {
            version: '1.7.3',
            date: '1 Haziran 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'bugfix', text: 'Grup kurma düzeltildi.' },
                { tag: 'bugfix', text: 'Sunucu kurma düzeltildi.' },
                { tag: 'bugfix', text: 'Pending count websocketi düzeltildi.' },
                { tag: 'improvement', text: 'Sunuculara profilden arkadaş ekleme eklendi.' },
                { tag: 'improvement', text: 'Sunucularda html ve javascriptteki mesajlar birleştirildi.' },
                { tag: 'new', text: 'Karşılaştırma tablosu eklendi.' },
                { tag: 'new', text: 'Direkt mesajlara dil desteği eklendi.' }
            ]
        },
        {
            version: '1.7.3-exp2',
            date: '24 Mayıs 2025',
            tags: ['improvement', 'bugfix'],
            items: [
                { tag: 'bugfix', text: 'Fotoğraf atarken dosyayı indir demesi düzeltildi.' },
                { tag: 'bugfix', text: 'Arkadaş istekleri ve engellenmiş arkadaş tabları düzeltildi.' },
                { tag: 'improvement', text: 'Sunucularda yanıtlama ve editleme sistemi ajaxa geçirildi.' },
                { tag: 'improvement', text: 'Sunucularda Ui\'ler benzerleştirildi' }
            ]
        },
        {
            version: '1.7.3-exp1',
            date: '20 Mayıs 2025',
            tags: ['new', 'improvement', 'removed'],
            items: [
                { tag: 'new', text: 'İstatistikler sayfası eklendi!' },
                { 
                    tag: 'new', 
                    text: 'Kullanıcı ayarlar sayfası eklendi!',
                    subitems: [
                        'Kullanıcı ayarlar sayfasında hesabım kısmı eklendi!',
                        'Kullanıcı ayarlar sayfasında profilim kısmı eklendi!',
                        'Eposta, kullanıcı adı, şifre değiştirme ve hesabı silme eklendi!'
                    ]
                },
                { tag: 'improvement', text: 'Sunuculardaki mesaj tarih bug ı düzeltildi' },
                { tag: 'improvement', text: 'Anasayfaya ve changelog sayfasına istatistikler butonu eklendi' },
                { tag: 'improvement', text: 'Custom durum üzerinde çalışılıyor.' },
                { tag: 'removed', text: 'Profil sayfalarında ayarlar kısmı kaldırıldı' }
            ]
        },
        {
            version: '1.7.2',
            date: '17 Mayıs 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Sunuculara sesli sohbet geldi!' },
                { tag: 'new', text: 'Bilgisayar uygulaması windows için portlandı!' },
                { tag: 'new', text: 'Sunucu sesli sohbetlere ekran paylaşımı eklendi!' },
                { tag: 'new', text: 'Custom durum üzerinde çalışılıyor.' },
                { tag: 'new', text: 'Sunuculara reaction sisteminin ilk aşaması yapıldı.' },
                { tag: 'bugfix', text: 'Direkt mesajlarda lazy loading düzeltildi.' },
                { tag: 'improvement', text: 'Mesaj yanıtlarken entera basarak yollanması sağlandı.' }
            ]
        },
        {
            version: '1.7.1',
            date: '10 Mayıs 2025',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'new', text: 'Yeni Post mekaniklerimiz ile tanışın! Artık kendi Lakealt\'ınızı açabilir, postlar paylaşabilir, postlara yorumlarda bulunabilirsiniz! Topluluğunuzla keyifli vakitler!' },
                { tag: 'new', text: 'Dm kısmında gönderdiğiniz mesajlar artık stackleniyor, Artık sohbetleriniz daha estetik ve daha efektif.' },
                { tag: 'new', text: 'Artık CTRL-C CTRL-V yaparak fotoğraf gönderebiliyorsunuz.' },
                { tag: 'new', text: 'Lakeban PC uygulamasının demosu hazırlandı, en kısa sürede sizlere sunacağız.' },
                { tag: 'new', text: 'Artık 5\'e kadar çoklu dosya gönderebilirsiniz! tek tek atmaya son.' },
                { tag: 'improvement', text: 'Mesaj konteynırı ve mesajların genel görünümü iyileştirildi.' },
                { tag: 'improvement', text: 'Artık video yollama optimize edildi, videonun yanlışlıkla 2-3 kere atılması düzeltildi.' },
                { tag: 'bugfix', text: 'Enter tuşuna fazla basınca 2-3 kere mesaj yollaması sorunu düzeltildi.' },
                { tag: 'bugfix', text: 'Emoji yollarken oluşan birkaç sorun düzeltildi.' }
            ]
        },
        {
            version: '1.7.0',
            date: '4 Mayıs 2025',
            subtitle: 'Kapsamlı Yönetim Güncellemesi',
            tags: ['new', 'improvement', 'bugfix'],
            items: [
                { tag: 'improvement', text: 'Topluluklar sayfasında daha iyi arama ve yeni filtreler eklendi popüler,tarih,isim' },
                { tag: 'bugfix', text: 'Kullanıcı zaten sunucuya üye ise sunucu sayfasına yönlendirilecek' },
                { tag: 'new', text: 'Profil sayfaları baştan aşağıya yenilendi ve istatistikler eklendi' },
                { tag: 'new', text: 'Grup oluşturma arayüzünde arkadaş arama çubuğu ve arkadaşları filtreleme eklendi' },
                { tag: 'new', text: 'Grup oluşturma arayüzünde arkadaşlar daha iyi badge lerle gösteriliyor' },
                { tag: 'new', text: 'Grup oluşturma arayüzünde animasyonlar ve hata mesajları eklendi' },
                { tag: 'new', text: 'Grup üyeleri butonu eklendi' },
                { tag: 'new', text: 'Changelog sayfasında Son 6 aylık güncelleme aktivitesi, Günlük güncelleme sayısına göre renk skalası, Fareyle üzerine gelince detay görüntüleme' },
                { tag: 'new', text: 'Changelog sayfasında Yaklaşan özellikler bölümü' },
                { tag: 'new', text: 'Changelog sayfasında Mobil uyumlu tasarım' },
                { tag: 'new', text: 'Changelog sayfasında Sürüm karşılaştırma özelliği ve Daha Fazla/Gizle butonu (uzun listeler için)' },
                { tag: 'new', text: 'Yeni sunucu ayarları paneli ve yeni yönetim arayüzü' },
                { tag: 'new', text: 'Gelişmiş URL yönetim paneli' },
                { tag: 'new', text: 'Gelişmiş Roller yönetim paneli' },
                { tag: 'new', text: 'Gelişmiş Moderasyon yönetim paneli' },
                { tag: 'new', text: 'Gelişmiş Topluluklar yönetim paneli' },
                { tag: 'new', text: 'Gelişmiş kategori yönetim paneli' },
                { tag: 'new', text: 'Kategori istatistikleri (sunucu/üye sayıları)' },
                { tag: 'improvement', text: 'Popüler kategoriler öneri sistemi' },
                { tag: 'new', text: 'Özel davet URL sistemi (QR kod + süre sınırlama)' },
                { tag: 'improvement', text: 'URL istatistik takip paneli eklendi' },
                { tag: 'new', text: 'Gelişmiş rol yönetimi (renk seçimi + 6 yeni izin)' },
                { tag: 'new', text: 'Sürükle-bırak rol sıralama ve hiyerarşi desteği' },
                { tag: 'new', text: 'Gelişmiş yasaklama sistemi ve yasaklılar listesi' },
                { tag: 'new', text: 'Gelişmiş moderasyon paneli: AJAX tabanlı filtreleme ve çoklu seçim' },
                { tag: 'new', text: 'Sunucuya özel ban istatistikleri ve 30 günlük grafik görünümü' },
                { tag: 'new', text: 'Toplu unban özelliği ve gelişmiş filtreleme (kullanıcı/banlayan bazlı)' },
                { tag: 'improvement', text: 'Ban sisteminde sunucu izolasyonu: Her sunucu kendi istatistiklerini görür' },
                { tag: 'bugfix', text: 'Toplam ban sayısının yanlış sunucu verilerini gösterme sorunu çözüldü' },
                { tag: 'improvement', text: 'Tüm panellerde tema tutarlılığı sağlandı' },
                { tag: 'improvement', text: 'Mobil uyumluluk ve responsive tasarım' },
                { tag: 'bugfix', text: 'URL kaydetme ve rol hiyerarşisi sorunları giderildi' },
                { tag: 'bugfix', text: 'Veritabanı uyumsuzluk problemleri çözüldü' }
            ]
        },
        {
            version: '1.6.9',
            date: '3 Mayıs 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Dm ve sunucu sayfalarına mobil animasyon ve tasarımlar getirildi / Dm ve sunucu sayfaları artık her cihazda o cihaza özgü boyutlanıcak.' },
                { tag: 'new', text: 'Grup kullanıcılarını gösteren modal eklendi.' },
                { tag: 'improvement', text: 'Gruplara unread count eklendi.' },
                { tag: 'improvement', text: 'Gruplara typing indicator geldi.' },
                { tag: 'improvement', text: 'Gruplara ayarlar kısmı geldi.' }
            ]
        },
        {
            version: '1.6.8',
            date: '1 Mayıs 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Grup sistemi geldi! Artık arkadaşlarınızla dm grupları kurabilirsiniz.' },
                { tag: 'improvement', text: 'Site teması ve efektleri genel olarak değişti ve daha şık hale getirildi.' },
                { tag: 'improvement', text: 'Arkadaş ekleme pop-up\'u ve sunucudan ayrılma pop-up\'u temaya uygun hale getirildi.' },
                { tag: 'improvement', text: 'Dm chat konteynırlarına animasyon eklendi.' },
                { tag: 'improvement', text: 'Dm chat konteynırlarının konumu düzeltildi, artık soldaki dm kutucuklarının üstüne gelmiyor.' },
                { tag: 'improvement', text: 'Sunucularda isme tıklayınca beliren mini profile ufak tema düzenlemesi yapıldı.' },
                { tag: 'improvement', text: 'Tüm arkadaşlar penceresindeki scroll bugu düzeltildi.' }
            ]
        },
        {
            version: '1.6.7',
            date: '20 Nisan 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Okunmamış mesaj sayısı websockete geçirildi.' },
                { tag: 'new', text: 'Kanallara custom ikon seçme geldi' },
                { tag: 'improvement', text: 'Post sayfasının arayüzü geliştirildi' },
                { tag: 'improvement', text: 'Sunucu sahiplerinin diğerlerinin mesajını silmesi eklendi' },
                { tag: 'new', text: 'Sunucularda kaydırarak kanalların pozisyonunu belirleme geldi.' }
            ]
        },
        {
            version: '1.6.6',
            date: '13 Nisan 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Okunmamış mesaj sayısı artık ajax ile periyodik olarak kontrol ediliyor. (Yakında websockete geçirilecek)' },
                { tag: 'improvement', text: 'Direkt mesajlarda typing indicatordaki currentfriendid sorunu düzeldi.' },
                { tag: 'improvement', text: 'Sunucuda arayüz geliştirildi.' },
                { tag: 'improvement', text: 'Sunucularda yukarı kaydırırken aşağı atması düzeltildi.' },
                { tag: 'improvement', text: 'Arkadaş listesindeki scrollbar düzeltildi.' },
                { tag: 'new', text: 'Dosya atarken dosyanın önizlemesi eklendi.' }
            ]
        },
        {
            version: '1.6.5',
            date: '7 Nisan 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'İnsanların aktif olması artık periyodik olarak kontrol ediliyor (Yakın zamanda Websockete geçirilecek)' },
                { tag: 'improvement', text: 'Direkt mesajlarda Lazy Loading Düzeltildi.' },
                { tag: 'improvement', text: 'Direkt mesajlarda arayüz modernleşti.' },
                { tag: 'improvement', text: 'Post sayfasına ufak bir iyileştirme yapıldı ve mobilde hatalı gözükmesi düzeltildi.' }
            ]
        },
        {
            version: '1.6.4',
            date: '3 Nisan 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Pending countlar websocket ile çalışmaya başladı artık gerçek zamanlı.' }
            ]
        },
        {
            version: '1.6.3',
            date: '1 Nisan 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Typing indicator websocket ile çalışmaya başladı artık gerçek zamanlı.' },
                { tag: 'new', text: 'Login, Topluluklar, Register sayfaları çevirildi.' }
            ]
        },
        {
            version: '1.6.2',
            date: '31 Mart 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Typing indicatora yeni animasyonlar eklendi.' },
                { tag: 'new', text: 'Dosya yüklerken yüklendiğinden emin olmak için animasyonlar geldi.' }
            ]
        },
        {
            version: '1.6.1',
            date: '30 Mart 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucularda eski mesajların zaman çizelgesi düzeltildi.' },
                { tag: 'improvement', text: 'Sunucularda lazy loadingin çalışmaması düzeltildi' },
                { tag: 'improvement', text: 'Topluluklara yeni arayüz geldi.' },
                { tag: 'improvement', text: 'Sunucularda mobilde mesajların ters gitmesi düzeltildi.' },
                { tag: 'new', text: 'Gizlilik Politikası ve Kullanım Koşulları eklendi.' }
            ]
        },
        {
            version: '1.6.0',
            date: '29 Mart 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Dosyalar görüntülenirken artık kocaman olmayacak.' },
                { tag: 'improvement', text: 'Sunucularda mesaj silememe sorunu düzeltildi.' },
                { tag: 'improvement', text: 'Sunucularda Dosyaların tek başına atılamaması düzeltildi.' }
            ]
        },
        {
            version: '1.5.9',
            date: '28 Mart 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Artık dosyalar tek başına atılabilecek.' },
                { tag: 'improvement', text: 'Profillere yeni tasarım geldi.' },
                { tag: 'improvement', text: 'Websocketlerin bağlantıyı kaybetmesi düzeltildi.' }
            ]
        },
        {
            version: '1.5.8',
            date: '23 Mart 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Metinlerin Üstüne gelince mavi olması düzeltildi.' },
                { tag: 'improvement', text: 'Uidaki animasyonlar genişletildi temaya uygun hale getirildi.' }
            ]
        },
        {
            version: '1.5.7',
            date: '5 Mart 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucularda yeni mesajlarda yanıtın gözükmemesi düzeltildi.' },
                { tag: 'improvement', text: 'Hesap kurarken artık özel karakter kullanılamayacak ve en az 3 karakter kullanılması gerekicek.' },
                { tag: 'improvement', text: 'Yeni animasyonlar eklendi.' }
            ]
        },
        {
            version: '1.5.6',
            date: '3 Mart 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucuda ilk açılan kanalın yanlış olması düzeltildi.' },
                { tag: 'improvement', text: 'Direkt mesajlarda en yeni mesajta ikonların gözükmemesi düzeltildi.' }
            ]
        },
        {
            version: '1.5.5',
            date: '27 Şubat 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucudaki yeni mesajlarda timestamp düzeltildi.' },
                { tag: 'improvement', text: 'Ui değişiklikleri yapıldı.' },
                { tag: 'new', text: 'Sunucuların içeriğine göre kategoriler geldi.' }
            ]
        },
        {
            version: '1.5.4',
            date: '25 Şubat 2025',
            tags: ['improvement', 'removed'],
            items: [
                { tag: 'improvement', text: 'Direkt mesajlarda arkadaş listesi artık en son gönderilen mesaja göre.' },
                { tag: 'improvement', text: 'Sitenin logosu yenilendi' },
                { tag: 'improvement', text: 'Post atınca Post sayısının artmaması düzeltildi.' },
                { tag: 'removed', text: 'Kar efekti kaldırıldı.' }
            ]
        },
        {
            version: '1.5.3',
            date: '22 Şubat 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Sesli Sohbet Geri Döndü Artık ses kesilmeleri ve kısık sesler yada sesli sohbetin çalışmaması gibi durumlar olmayacak!' },
                { tag: 'improvement', text: 'Direkt mesajlarda mesaj silince eski bir mesajın alta gelmesi düzeltildi' },
                { tag: 'improvement', text: 'Mesaj silince bildirim sesi çalması düzeltildi.' }
            ]
        },
        {
            version: '1.5.2',
            date: '18 Şubat 2025',
            tags: ['new', 'improvement', 'removed'],
            items: [
                { tag: 'new', text: 'Postlara Medya yükleme geldi.' },
                { tag: 'improvement', text: 'Sunucularda Kullanıcıların olduğu yerdeki arayüz sorunları düzeltildi.' },
                { tag: 'removed', text: 'Profillerde mesaj stilini değiştirme kaldırıldı.' }
            ]
        },
        {
            version: '1.5.1',
            date: '16 Şubat 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Timestampler düzeltildi.' },
                { tag: 'new', text: 'Sunuculara lazy loading geldi' },
                { tag: 'improvement', text: 'Direkt mesaj sisteminde Yukarı kaydırdıktan sonra aşağı atması düzeltildi' },
                { tag: 'improvement', text: 'Direkt mesaj sisteminde eski mesajların aşağıda açılması düzeltildi.' }
            ]
        },
        {
            version: '1.5',
            date: '11 Şubat 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Post sistemine oy sistemi eklendi.' },
                { tag: 'improvement', text: 'Post sisteminde oy vererek post daha yüksekte gözükmesi eklendi.' },
                { tag: 'improvement', text: 'Postlarda oy verdikten sonra butonun oy verdiniz renkte kalması yapıldı.' }
            ]
        },
        {
            version: '1.4.9',
            date: '8 Şubat 2025',
            tags: ['improvement', 'removed'],
            items: [
                { tag: 'improvement', text: 'Sunucularda dosya attıktan sonra atılan dosyanın yeniden atılması düzeltildi.' },
                { tag: 'improvement', text: 'Sunucularda mesaj atarken bazen gönderme butonunun kapanmaması düzeltildi.' },
                { tag: 'improvement', text: 'Artık Hesaptan 1 hafta sonra atıyor siteye girilmezse.' },
                { tag: 'improvement', text: 'Tüm uiler güncellendi' },
                { tag: 'removed', text: 'Sesli Sohbet daha iyi bir şekilde dönmek için kaldırıldı.' },
                { tag: 'removed', text: 'Masaüstü cihazlarda Mesaj gönderme butonu kaldırıldı.' }
            ]
        },
        {
            version: '1.4.8',
            date: '7 Şubat 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucularda mesaj atarken sayfanın yenilenmesi düzeltildi.' },
                { tag: 'improvement', text: 'Sunucu mesajlarındaki yanıt verememe üç nokta butonunun garip yerlerde olması düzeltildi.' },
                { tag: 'improvement', text: 'Sunucu Sisteminde Sidebardaki online offline göstergeleri fixlendi ve offline kişiler artık rollerinde değil.' },
                { tag: 'improvement', text: 'Sunucu Sisteminde Roller düzeltildi.' }
            ]
        },
        {
            version: '1.4.7',
            date: '5 Şubat 2025',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Utf 8 olması gereken yerler utf 8 yapıldı.' },
                { tag: 'improvement', text: 'Arayüz bugları fixlendi.' }
            ]
        },
        {
            version: '1.4.6',
            date: '3 Şubat 2025',
            subtitle: 'Geçikme için üzgünüz!',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Mobil arayüzler düzeltildi.' },
                { tag: 'new', text: 'Post atma eklendi.' },
                { tag: 'improvement', text: 'Postlara kategoriler eklendi.' },
                { tag: 'improvement', text: 'Arayüzler benzerleştirildi.' }
            ]
        },
        {
            version: '1.4.5',
            date: '20 Ocak 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'siteden çıkınca direk hesaptan atması düzeltildi' },
                { tag: 'new', text: 'Ban sistemi eklendi' },
                { tag: 'improvement', text: 'Ban listesinin tasarımı iyileşti.' },
                { tag: 'improvement', text: 'İzni olmayan kullanıcıların ban kick atması düzeltildi.' }
            ]
        },
        {
            version: '1.4.4',
            date: '12 Ocak 2025',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: '13 kişi arkadaş eklendikten sonra ui\'in bozulması düzeltildi' },
                { tag: 'new', text: 'Direkt mesajlara typing indicator eklendi.' },
                { tag: 'new', text: 'Sunuculara typing indicator eklendi.' }
            ]
        },
        {
            version: '1.4.3',
            date: '10 Ocak 2025',
            subtitle: 'Uzun Zaman Oldu Değilmi?',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'butonların her mesajta gözükmesi fixlendi.' },
                { tag: 'improvement', text: 'üç nokta butonu ile diğer butonlara kısa yol eklendi.' },
                { tag: 'improvement', text: 'Direkt mesaj ile sunucu mesaj sisteminin yapısı aynı yapıldı.' },
                { tag: 'improvement', text: 'Uiler iyileştirildi.' },
                { tag: 'improvement', text: 'Kimin mesaj attığı canlı hale getirildi.' },
                { tag: 'improvement', text: 'Yanıt rework direkt mesajlara eklendi.' }
            ]
        },
        {
            version: '1.4.2',
            date: '23 Aralık 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Sunuculara canlı chat geldi (biraz buglu)' },
                { tag: 'improvement', text: 'Sunucularda sağ tık ile kanal kategori oluşturma eklendi' },
                { tag: 'improvement', text: 'Sunucularda kurucuların kanal yada kategori oluşturamaması düzeltildi' },
                { tag: 'improvement', text: 'Kategorisi olmayan kanalların gözükmemesi düzeltildi' },
                { tag: 'improvement', text: 'Başkaların sağ tık ile kanal oluşturabilmesi düzeltildi' },
                { tag: 'improvement', text: 'Sunucu ayarları ui\'i geliştirildi ve modernleştirildi' },
                { tag: 'new', text: 'Şifre sıfırlama geldi.' }
            ]
        },
        {
            version: '1.4.1',
            date: '20 Aralık 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'İngilizce, Rusça ve Fince dil desteği eklendi.' },
                { tag: 'improvement', text: 'Mesaj yanıtlama sistemi yeniden düzenlendi.' },
                { tag: 'new', text: 'Sağ tık menüsüyle kanal açma ve kategori oluşturma özelliği eklendi.' },
                { tag: 'improvement', text: 'Sunucu mesaj sistemi yeniden düzenlendi.' },
                { tag: 'new', text: 'Yenilenmiş emoji sistemi ve sunucularda emoji desteği eklendi.' },
                { tag: 'new', text: 'DM kısmında kullanıcıya tıklayınca profil sayfasına yönlendirme eklendi.' },
                { tag: 'new', text: 'Profil sayfalarında kar yağma efekti (event) eklendi.' },
                { tag: 'improvement', text: 'Sunucularda mesajların en yukarıdan başlaması sorunu giderildi.' }
            ]
        },
        {
            version: '1.4',
            date: '18 Aralık 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucu mesaj sistemi canlı hale getirilmeye başlandı.' },
                { tag: 'new', text: 'Bildirim sesleri geldi!' }
            ]
        },
        {
            version: '1.3.9',
            date: '17 Aralık 2024',
            tags: ['new', 'improvement', 'removed'],
            items: [
                { tag: 'improvement', text: 'Yeni Host ile site hızlandırıldı.' },
                { tag: 'improvement', text: 'Profil sayfalarındaki buglar kaldırıldı' },
                { tag: 'removed', text: 'Lakeban genel chat kaldırıldı.' },
                { tag: 'new', text: 'Kaç arkadaş isteği geldiğinin sayacı geldi' },
                { tag: 'new', text: 'Emoji sistemi geldi' }
            ]
        },
        {
            version: '1.3.8',
            date: '8 Aralık 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Direkt mesajlarda sunucuların gözükmemesi düzeltildi.' },
                { tag: 'improvement', text: 'Direkt mesajlarda son mesajda ikonların gözükmemesi düzeltildi.' },
                { tag: 'improvement', text: 'Direkt mesajlarda chatte olsanız bile mesajların okunmamış mesaj olarak sayılması düzeltildi.' },
                { tag: 'improvement', text: 'Direkt mesajlarda sohbet ederken mesaj atmadıkça diğer kişinin mesajının gelmemesi düzeltildi.' },
                { tag: 'new', text: 'Sunucuların topluluklar kısmında gözükmemesini sağlama eklendi.' },
                { tag: 'new', text: 'Arkadaş Bloklama eklendi.' }
            ]
        },
        {
            version: '1.3.7',
            date: '6 Aralık 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucu sisteminde bannerın konumu düzeltildi.' },
                { tag: 'new', text: 'Sunucu sistemine Kategoriler eklendi.' }
            ]
        },
        {
            version: '1.3.6',
            date: '5 Aralık 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Dm sistemi 8 kat optimize edildi.' },
                { tag: 'new', text: 'Arkadaş silme eklendi.' }
            ]
        },
        {
            version: '1.3.5',
            date: '2 Aralık 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucuda Kanal Yönetimi izni işlevsel hale getirildi.' }
            ]
        },
        {
            version: '1.3.4',
            date: '1 Aralık 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Sunucuda Rollere özel kanal oluşturma geldi' },
                { tag: 'new', text: 'Sunuculara Banner Eklendi.' }
            ]
        },
        {
            version: '1.3.3',
            date: '30 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'improvement', text: 'Sunucuda kurucuların çıkabilmesi düzeltildi' },
                { tag: 'new', text: 'Topluluklar sekmesi eklendi' },
                { tag: 'new', text: 'Sunucu silme eklendi' },
                { tag: 'improvement', text: 'Sunucuda profilleri olmayanların gözükmemesi düzeltildi' }
            ]
        },
        {
            version: '1.3.2',
            date: '29 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Sunucu sisteminde Rolleri silme ve düzenleme geldi.' },
                { tag: 'new', text: 'Sunucu sisteminde rollerin hiyerarşisini yükseltme geldi.' },
                { tag: 'new', text: 'Sunucu sisteminde rollere renkler eklendi.' },
                { tag: 'improvement', text: 'Sunucu sayfasındayken diğer sunucuların gözükmemesi çözüldü.' }
            ]
        },
        {
            version: '1.3.1',
            date: '28 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Sunucu sisteminde Rol sistemi eklendi' },
                { tag: 'new', text: 'Sunucuları editleme geldi' },
                { tag: 'improvement', text: 'Sunuculardaki izinler düzeltildi.' }
            ]
        },
        {
            version: '1.3',
            date: '27 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Sunucu sisteminde kanal ekleme ve kanal editleme eklendi.' },
                { tag: 'new', text: 'Sunuculardan ayrılma eklendi.' },
                { tag: 'improvement', text: 'Sunucu oluşturmadaki buglar düzeltildi.' }
            ]
        },
        {
            version: '1.2.9',
            date: '26 Kasım 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Server sisteminde dosya yükleme ve mesajların yukardan yüklenmesi düzeltildi.' }
            ]
        },
        {
            version: '1.2.8',
            date: '25 Kasım 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Server sistemindeki buglar düzeltildi' },
                { tag: 'improvement', text: 'Server sisteminde UI güzelleştirildi' },
                { tag: 'improvement', text: 'giriş sayfasındaki UI iyileştirildi.' }
            ]
        },
        {
            version: '1.2.7',
            date: '25 Kasım 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Profil sayfaların arayüzü değiştirildi ve optimize edildi.' },
                { tag: 'improvement', text: 'Dm sistemindeki arayüz düzeltildi.' },
                { tag: 'improvement', text: 'Anasayfadaki sağ üstteki profil resmi gözükmeme bugu düzeltildi.' }
            ]
        },
        {
            version: '1.2.6',
            date: '23 Kasım 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Dm sistemindeki arkadaşlık sisteminde buglar düzeltildi.' }
            ]
        },
        {
            version: '1.2.5',
            date: '18 Kasım 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Dm sisteminde sesli konuşma eklendi!!!' }
            ]
        },
        {
            version: '1.2.4',
            date: '16 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Dm sisteminde mesaj yanıtlama geldi' },
                { tag: 'improvement', text: 'Dm sistemi optimize edildi artık daha hızlı açılcak ve daha az bellek tüketicek' }
            ]
        },
        {
            version: '1.2.3',
            date: '14 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Dm sisteminde mesaj silme eklendi!' },
                { tag: 'new', text: 'Dm sisteminde mesaj editleme eklendi' },
                { tag: 'improvement', text: 'Dm sisteminde artık 10 saniyede bir sayfa yenilenmiyecek' }
            ]
        },
        {
            version: '1.2.2',
            date: '11 Kasım 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Dm sisteminde Kaç mesaj aldığınız eklendi!' }
            ]
        },
        {
            version: '1.2.1',
            date: '9 Kasım 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Dm sisteminde arayüz değiştirildi ve optimize edildi' },
                { tag: 'improvement', text: 'Profil sayfalarına arkadaşlar kısmı eklendi' },
                { tag: 'improvement', text: 'Profil sayfalarına tema rengi değiştirme geldi' }
            ]
        },
        {
            version: '1.2',
            date: '8 Kasım 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Artık direkt mesajlardan mesaj atarken hata kodu alınmicak.' }
            ]
        },
        {
            version: '1.1.9',
            date: '7 Kasım 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Profillere müzik ekleme geldi!' }
            ]
        },
        {
            version: '1.1.8',
            date: '6 Kasım 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'Dmlere dosya ekleme getirildi!' },
                { tag: 'improvement', text: 'Avatarlarda artık profil resimlerimiz çıkıyor.' }
            ]
        },
        {
            version: '1.1.7',
            date: '1 Kasım 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Test için dm sistemi geldi! (birçok özellik şuanlık yok malesefki.)' }
            ]
        },
        {
            version: '1.1.6',
            date: '31 Ekim 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Mesaj editleme eklendi' },
                { tag: 'new', text: 'Arkadaşlık sistemi geldi' }
            ]
        },
        {
            version: '1.1.5',
            date: '29 Ekim 2024',
            tags: ['new', 'improvement'],
            items: [
                { 
                    tag: 'new', 
                    text: 'Profil sayfalarına yorum ve arkaplan ekleme sistemi eklendi',
                    subitems: [
                        'Kullanıcılar profillerine arkaplan ekleyebilirler (SADECE 8MB)',
                        'Kullanıcılar birbirlerinin profillerine yorum yapabilirler',
                        'Yorum sahipleri ve profil sahipleri yorumları silebilir'
                    ]
                },
                { tag: 'improvement', text: 'Kullanıcıların kendi profillerine yorum yapması engellendi (spam önlemi)' }
            ]
        },
        {
            version: '1.1.4',
            date: '25 Ekim 2024',
            tags: ['new', 'improvement', 'removed'],
            items: [
                { tag: 'new', text: 'LakeBan Chat arayüzü değiştirildi ve yenilendi' },
                { tag: 'new', text: 'Anasayfaya Son Haberler kısmı eklendi' },
                { tag: 'new', text: 'Bildirim sesi eklendi yeni mesaj geldiğinde ses çalacak / Sekme arka plandayken de çalışacak' },
                { tag: 'new', text: 'Sayfa arka plandayken masaüstü bildirimi gösterecek' },
                { 
                    tag: 'improvement', 
                    text: 'Mesaj yükleme fonksiyonu güncellendi',
                    subitems: [
                        'Video oynatılırken otomatik yenilemeyi durdurur',
                        'Scroll pozisyonunu korur'
                    ]
                },
                { tag: 'improvement', text: 'Spam mesaj engelleyici geliştirildi' },
                { tag: 'removed', text: 'Eski bildirim sistemi kaldırıldı' }
            ]
        },
        {
            version: '1.1.3',
            date: '22 Ekim 2024',
            tags: ['new', 'improvement'],
            items: [
                { 
                    tag: 'new', 
                    text: 'Mesajlara dosya yükleme özelliği eklendi',
                    subitems: [
                        'Kullanıcılar artık mesajlarına dosya ekleyebilir (SADECE 8MB)',
                        'Video, fotoğraf ve diğer dosya türleri destekleniyor'
                    ]
                },
                { tag: 'improvement', text: 'Spam mesaj engelleyici eklendi' },
                { tag: 'improvement', text: 'LakeBan Chat optimize edildi' }
            ]
        },
        {
            version: '1.1.2',
            date: '20 Ekim 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'LakeBan Chat’e zaman damgası eklendi' },
                { tag: 'improvement', text: 'Otomatik mesaj yenileme sistemi optimize edildi' }
            ]
        },
        {
            version: '1.1.1',
            date: '17 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'UI/UX iyileştirmeleri yapıldı' },
                { tag: 'improvement', text: 'Mobil cihazlarda chat arayüzü optimize edildi' }
            ]
        },
        {
            version: '1.1.0',
            date: '13 Ekim 2024',
            tags: ['new', 'improvement'],
            items: [
                { 
                    tag: 'new', 
                    text: 'Profil sayfaları eklendi',
                    subitems: [
                        'Kullanıcılar artık kendilerine özel profil sayfalarına sahip',
                        'Profil sayfalarında avatar, kullanıcı adı ve durum düzenlenebilir'
                    ]
                },
                { tag: 'improvement', text: 'Giriş ve kayıt sayfaları yenilendi' }
            ]
        },
        {
            version: '1.0.9',
            date: '12 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Kayıt sayfasındaki hatalar düzeltildi' },
                { tag: 'improvement', text: 'Giriş sayfasındaki hatalar düzeltildi' }
            ]
        },
        {
            version: '1.0.8',
            date: '9 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Mobil cihazlarda kaydırma sorunları düzeltildi' }
            ]
        },
        {
            version: '1.0.7',
            date: '8 Ekim 2024',
            tags: ['new', 'improvement'],
            items: [
                { tag: 'new', text: 'LakeBan Chat’e otomatik mesaj yenileme eklendi' },
                { tag: 'improvement', text: 'Chat arayüzü yenilendi' }
            ]
        },
        {
            version: '1.0.6',
            date: '7 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Giriş ve kayıt sayfaları için doğrulama sistemi optimize edildi' }
            ]
        },
        {
            version: '1.0.5',
            date: '6 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'Kayıt ve giriş sayfalarındaki hatalar düzeltildi' }
            ]
        },
        {
            version: '1.0.4',
            date: '5 Ekim 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Kayıt sistemi eklendi' }
            ]
        },
        {
            version: '1.0.3',
            date: '4 Ekim 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'Giriş sistemi eklendi' }
            ]
        },
        {
            version: '1.0.2',
            date: '3 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'LakeBan Chat’in performansı artırıldı' }
            ]
        },
        {
            version: '1.0.1',
            date: '2 Ekim 2024',
            tags: ['improvement'],
            items: [
                { tag: 'improvement', text: 'UI/UX hataları düzeltildi' }
            ]
        },
        {
            version: '1.0.0',
            date: '1 Ekim 2024',
            tags: ['new'],
            items: [
                { tag: 'new', text: 'LakeBan Chat yayında!' }
            ]
        }
    ];

    const changelogList = document.getElementById('changelogList');

    function renderChangelog(data) {
        changelogList.innerHTML = '';
        let lastVersionPrefix = null;

        // "Yaklaşan Özellikler" bölümünü en başa ekle
        const upcoming = data.find(log => log.version === 'upcoming');
        if (upcoming) {
            const milestone = document.createElement('div');
            milestone.className = 'timeline-milestone';
            milestone.innerHTML = upcoming.title;
            changelogList.appendChild(milestone);
            
            const timelineItem = createTimelineItem(upcoming);
            changelogList.appendChild(timelineItem);
        }

        // Diğer sürümleri işle
        const sortedData = data.filter(log => log.version !== 'upcoming').sort((a, b) => {
            // Sürüm numaralarını karşılaştırmak için basit bir sıralama
            const partsA = a.version.split(/[\.-]/);
            const partsB = b.version.split(/[\.-]/);
            for(let i = 0; i < Math.max(partsA.length, partsB.length); i++) {
                const valA = parseInt(partsA[i]) || 0;
                const valB = parseInt(partsB[i]) || 0;
                if (valA !== valB) return valB - valA;
            }
            return 0;
        });

        sortedData.forEach(log => {
            const timelineItem = createTimelineItem(log);
            changelogList.appendChild(timelineItem);
        });

        setupScrollAnimations();
    }
    
    function createTimelineItem(log) {
        const timelineItem = document.createElement('div');
        timelineItem.className = 'timeline-item';
        timelineItem.dataset.version = log.version;
        timelineItem.dataset.tags = log.tags.join(',');

        const card = document.createElement('div');
        card.className = 'changelog-card';

        let titleHtml = `<h2>Sürüm ${log.version}${log.subtitle ? ` <small>- ${log.subtitle}</small>` : ''}</h2>`;
        if (log.version === 'upcoming') {
            titleHtml = ''; // Başlık milestone olarak eklendi
        }

        const listItems = log.items.map(item => {
            let subItemsHtml = '';
            if (item.subitems && item.subitems.length > 0) {
                subItemsHtml = `<ul class="sub-list">${item.subitems.map(sub => `<li>${sub}</li>`).join('')}</ul>`;
            }
            const tagMap = {'new': 'Yeni', 'improvement': 'İyileştirme', 'bugfix': 'Düzeltme', 'removed': 'Kaldırıldı'};
            const tagName = tagMap[item.tag] || item.tag;
            return `<li><span class="tag ${item.tag}">${tagName}</span> <div>${item.text}${subItemsHtml}</div></li>`;
        }).join('');

        card.innerHTML = `
            ${titleHtml}
            <span class="changelog-date">${log.date}</span>
            <ul>${listItems}</ul>
        `;

        timelineItem.appendChild(card);
        return timelineItem;
    }
    
    renderChangelog(changelogData);
    
    // PHP'den gelen ve birleştirilmiş katkı verisi
    const contributionData = <?= json_encode($contributionData) ?>;
    
    function generateContributionGraph() {
        const graphContainer = document.getElementById('contributionGraph');
        const monthLabelsContainer = document.getElementById('graphMonthLabels');
        const dayLabelsContainer = document.getElementById('graphDayLabels');
        const legendContainer = document.getElementById('legendSquares');
        if (!graphContainer) return;

        graphContainer.innerHTML = ''; monthLabelsContainer.innerHTML = '';
        dayLabelsContainer.innerHTML = '<span></span><div>Pzt</div><span></span><div>Çar</div><span></span><div>Cum</div><span></span>';

        const today = new Date();
        const startDate = new Date();
        startDate.setDate(today.getDate() - 370);
        const dayOfWeek = startDate.getDay();
        startDate.setDate(startDate.getDate() - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));

        const monthNames = ["Oca", "Şub", "Mar", "Nis", "May", "Haz", "Tem", "Ağu", "Eyl", "Eki", "Kas", "Ara"];
        
        // Render Days
        for (let i = 0; i < 53 * 7; i++) {
            const date = new Date(startDate);
            date.setDate(startDate.getDate() + i);
            if (date > today) continue; // Gelecekteki günleri gösterme

            const dateStr = date.toISOString().split('T')[0];
            const count = contributionData[dateStr] || 0;
            const day = document.createElement('div');
            day.classList.add('day');
            
            if (count > 0) {
                const maxContributions = 25;
                const intensity = Math.min(count / maxContributions, 1);
                const lightness = 70 - intensity * 50;
                day.style.backgroundColor = `hsl(158, 65%, ${lightness}%)`;
            }

            const tooltip = document.createElement('span');
            tooltip.classList.add('tooltip');
            tooltip.textContent = `${count} katkı - ${date.toLocaleDateString('tr-TR')}`;
            day.appendChild(tooltip);
            graphContainer.appendChild(day);
        }

        // Render Month Labels
        let lastMonth = -1;
        for (let week = 0; week < 53; week++) {
            const dateInWeek = new Date(startDate);
            dateInWeek.setDate(startDate.getDate() + week * 7);
            const month = dateInWeek.getMonth();
            if (month !== lastMonth) {
                lastMonth = month;
                const monthLabel = document.createElement('span');
                monthLabel.textContent = monthNames[month];
                monthLabel.style.gridColumnStart = week + 1;
                monthLabelsContainer.appendChild(monthLabel);
            }
        }

        const legendColors = ['var(--color-surface-2)', 'hsl(158, 65%, 60%)', 'hsl(158, 65%, 45%)', 'hsl(158, 65%, 30%)', 'hsl(158, 65%, 20%)'];
        legendColors.forEach(color => {
            const square = document.createElement('span');
            square.style.backgroundColor = color;
            legendContainer.appendChild(square);
        });
    }

    function filterItems() {
        const versionFilter = document.getElementById('version-filter').value;
        const tagFilter = document.getElementById('tag-filter').value;
        const searchQuery = document.getElementById('search').value.toLowerCase();
        const allItems = document.querySelectorAll('.timeline-item');
        
        allItems.forEach(item => {
            const version = item.dataset.version;
            const tags = item.dataset.tags ? item.dataset.tags.split(',') : [];
            const content = item.textContent.toLowerCase();
            
            const matchesVersion = versionFilter === 'all' || version === versionFilter;
            const matchesTag = tagFilter === 'all' || tags.includes(tagFilter);
            const matchesSearch = !searchQuery || content.includes(searchQuery);
            
            item.style.display = (matchesVersion && matchesTag && matchesSearch) ? 'block' : 'none';
        });
    }

    function setupScrollAnimations() {
        const elementsToAnimate = document.querySelectorAll('.timeline-item');
        if (!elementsToAnimate.length) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const itemIndex = Array.from(elementsToAnimate).indexOf(entry.target);
                entry.target.style.setProperty('--direction', (itemIndex % 2 === 0) ? 1 : -1);
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                } else {
                     entry.target.classList.remove('is-visible');
                }
            });
        }, { threshold: 0.1 });

        elementsToAnimate.forEach(el => observer.observe(el));
    }

    document.getElementById('version-filter').addEventListener('change', filterItems);
    document.getElementById('tag-filter').addEventListener('change', filterItems);
    document.getElementById('search').addEventListener('input', filterItems);

    generateContributionGraph();
});

function changeLanguage(lang) {
    window.location.href = '?lang=' + lang;
}
</script>
</body>
</html>