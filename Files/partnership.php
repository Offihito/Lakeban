<?php
// Session ayarları
ini_set('session.gc_maxlifetime', 2592000); // 30 gün
session_set_cookie_params(2592000); // 30 gün
session_start();

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

// Kullanıcı giriş durumunu kontrol et
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$username = $isLoggedIn ? ($_SESSION['username'] ?? null) : null;
$profilePicture = null;

if ($isLoggedIn) {
    try {
        // Kullanıcı bilgilerini users ve user_profiles tablolarından çek
        $stmt = $db->prepare("
            SELECT u.username, p.avatar_url 
            FROM users u 
            LEFT JOIN user_profiles p ON u.id = p.user_id 
            WHERE u.id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // Kullanıcı adı oturumla eşleşmiyorsa güncelle
            if ($result['username'] !== $_SESSION['username']) {
                $_SESSION['username'] = $result['username'];
                $username = $result['username'];
            }
            $profilePicture = $result['avatar_url'] ?? null;
            if (!$profilePicture) {
                error_log("Avatar URL bulunamadı: user_id = " . $_SESSION['user_id']);
            }
        } else {
            // Kullanıcı bulunamadı, oturumu sıfırla
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

// Hata raporlama (geliştirme için, üretimde kapatılabilir)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Güncellenmiş partner verileri (6 partner)
$partners = [
    [
        'name' => 'Special Cat 🐈',
        'logo' => 'https://cdn.discordapp.com/icons/1354228159532241026/d3d68675465632de9d55d89a16440340.png?size=80&quality=lossless',
        'description' => $translations['partners']['partner1_desc'] ?? 'LGBT+, Genel Sohbet',
        'website' => 'https://discord.gg/qbC6Ee4upV'
    ],
    [
        'name' => 'Tanuki ー Anime & Manga ● Dizi ● Film ● Çizgi Roman ● Sohbet 🦊',
        'logo' => 'https://cdn.discordapp.com/icons/1346950495943528518/7ef2e218d87466492cbb6ab3da6e5985.png?size=80&quality=lossless',
        'description' => $translations['partners']['partner2_desc'] ?? 'Genel Sohbet, Anime',
        'website' => 'https://discord.gg/yPAhBQbjHT'
    ],
    [
        'name' => 'U.N.A - CLAN',
        'logo' => 'https://cdn.discordapp.com/icons/1277201564037025862/105451aff0320d34d27e95fb1a508830.png?size=80&quality=lossless',
        'description' => $translations['partners']['partner3_desc'] ?? 'Roleplay,Nation',
        'website' => 'https://discord.gg/thbQhNgqH9'
    ],
    [
        'name' => 'Shitpost Türkiye | SHTR',
        'logo' => 'https://lakeban.com/uploads/675f55a0bb881_sad.JPG',
        'description' => $translations['partners']['partner4_desc'] ?? 'Eğlenceli, komik',
        'website' => 'https://lakeban.com/join_server?code=SHTR'
    ],
      [
        'name' => 'PEAKTR',
        'logo' => 'https://cdn.discordapp.com/icons/1407213756559134740/85e5627fa050aa55fa4e6e332d8573cf.png?size=4096',
        'description' => $translations['partners']['partner5_desc'] ?? 'Genel Sohbet, PEAKTR',
        'website' => 'https://discord.gg/ZuKmZTVJ'
    ],
    [
        'name' => 'BKTRヅ',
        'logo' => 'https://cdn.discordapp.com/icons/1035964812586397737/9764ca1a1aabd16c08d40c5ece470ef8.png?size=80&quality=lossless',
        'description' => $translations['partners']['partner5_desc'] ?? 'Genel Sohbet, Eğlenceli',
        'website' => 'https://discord.gg/minecord'
    ],
    [
        'name' => 'Türkiciler',
        'logo' => 'https://media.discordapp.net/attachments/1424421696361857096/1424424015765967079/a95136617cbac9e36ee3c5da0c241ed4.jpg?ex=68e3e5bd&is=68e2943d&hm=b1836de8867b5c5efab37b162439b83fed27a5592a0fd519d3e2872f46e631aa&=&format=webp',
        'description' => $translations['partners']['partner5_desc'] ?? 'Türkiye, Gurbet, Eğlence',
        'website' => 'https://discord.gg/rZytQsnG'
    ],
    [
        'name' => 'Tech Innovators',
        'logo' => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?auto=format&fit=crop&q=80&w=500',
        'description' => $translations['partners']['partner6_desc'] ?? 'Teknoloji ve yenilikler',
        'website' => ''
    ]
    
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.8">
    <title>LakeBan ile Ortaklaşa Çalış</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Gochi+Hand&display=swap" rel="stylesheet">
    <style>
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        }

        body {
            background-color: var(--dark-bg);
            color: var(--text-light);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Header Styles */
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
        
        .cta-btn {
            padding: 0.8rem 2rem;
            border-radius: var(--radius-sm);
            font-weight: 700;
            transition: var(--transition);
            text-decoration: none;
            background: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            margin-top: 1.5rem;
            display: inline-block;
        }

        .cta-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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

        /* Generic Section Styles */
        .page-section {
            padding: 6rem 5%;
            position: relative;
        }

        .page-section.darker {
             background: var(--darker-bg);
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 4rem;
            position: relative;
            z-index: 2;
        }

        .section-title h2 {
            font-size: 2.8rem;
            margin-bottom: 1rem;
            font-weight: 800;
            background: linear-gradient(to right, #fff, var(--primary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: inline-block;
        }

        .section-title p {
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto;
            font-size: 1.2rem;
        }
        
        /* Hero Section */
        .hero-section {
            padding: 10rem 5% 8rem;
            text-align: center;
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, rgba(10,12,15,0.9) 0%, rgba(15,17,20,0.95) 100%), 
                        url('') center/cover;
        }
        
        .hero-section .label {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        /* Features Section */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .feature-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            padding: 2rem;
            border-radius: var(--radius-md);
            text-align: left;
            transition: var(--transition);
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }
        
        .feature-card i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .feature-card p {
            color: var(--text-secondary);
        }

        /* Partners Section */
        .partners-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .partner-card {
            background-color: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 2rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--card-border);
            transform: translateY(0);
            opacity: 1;
            text-align: center;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .partner-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .partner-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.1), transparent);
            opacity: 0;
            transition: var(--transition);
            z-index: 0;
        }

        .partner-card:hover::before {
            opacity: 1;
        }

        .partner-logo {
            width: 120px;
            height: 120px;
            margin: 0 auto 1.5rem;
            border-radius: var(--radius-sm);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--darker-bg);
            position: relative;
            z-index: 1;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .partner-card:hover .partner-logo {
            border-color: var(--primary);
            transform: scale(1.05);
        }

        .partner-logo img {
            max-width: 80%;
            max-height: 80%;
            object-fit: contain;
            transition: all 0.3s ease;
        }

        .partner-card:hover .partner-logo img {
            transform: scale(1.1);
        }

        .partner-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--text-light);
            position: relative;
            z-index: 1;
        }

        .partner-card p {
            color: var(--text-secondary);
            position: relative;
            z-index: 1;
            margin-bottom: 1.5rem;
            flex-grow: 1;
        }

        .partner-card a {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.5rem;
            border-radius: var(--radius-sm);
            background: var(--primary);
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            position: relative;
            z-index: 1;
            margin-top: auto;
        }

        .partner-card a:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .partner-card a.disabled {
            background: #666;
            cursor: not-allowed;
        }

        .partner-card a.disabled:hover {
            background: #666;
            transform: none;
        }

        /* FAQ Section */
        .faq-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .faq-item {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            overflow: hidden;
            transition: var(--transition);
        }
        .faq-item:hover {
            border-color: rgba(76, 175, 80, 0.5);
        }
        .faq-question {
            padding: 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .faq-question:hover {
            background-color: rgba(30, 32, 37, 0.7);
        }
        .faq-question .icon {
            transition: transform 0.3s ease;
        }
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, padding 0.4s ease;
            color: var(--text-secondary);
            line-height: 1.7;
        }
        .faq-answer p {
             padding: 0 1.5rem 1.5rem;
        }
        .faq-item.active .faq-answer {
            max-height: 300px;
        }
        .faq-item.active .icon {
            transform: rotate(45deg);
        }
        
        /* Apply Section */
        .apply-section .content-wrapper {
            display: flex;
            align-items: center;
            gap: 3rem;
            max-width: 1100px;
            margin: 0 auto;
        }
        .apply-section .text-content {
            flex: 1;
        }
         .apply-section .image-content {
            flex: 0 0 300px;
        }
        .apply-section .image-content img {
            max-width: 100%;
        }
        .apply-step {
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background: rgba(30, 32, 37, 0.5);
            border-radius: var(--radius-sm);
            border-left: 3px solid var(--primary);
            transition: var(--transition);
        }
        .apply-step:hover {
            background: rgba(30, 32, 37, 0.8);
            transform: translateX(5px);
        }
        .apply-step .step-label {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-light);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .apply-step .step-label::before {
            content: '';
            width: 24px;
            height: 24px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .apply-step:nth-child(1) .step-label::before { content: '1'; }
        .apply-step:nth-child(2) .step-label::before { content: '2'; }
        .apply-step:nth-child(3) .step-label::before { content: '3'; }
        .apply-step:nth-child(4) .step-label::before { content: '4'; }
         .apply-step p {
             color: var(--text-secondary);
         }
         .apply-step p a {
             color: var(--primary-light);
             text-decoration: none;
             font-weight: 600;
         }
         .apply-step p a:hover {
             text-decoration: underline;
         }

        /* Animasyonlu Arkaplan */
        .animated-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .animated-bg span {
            position: absolute;
            display: block;
            width: 20px;
            height: 20px;
            background: rgba(76, 175, 80, 0.1);
            animation: animate 10s linear infinite;
            bottom: -150px;
        }

        .animated-bg span:nth-child(1) {
            left: 25%;
            width: 80px;
            height: 80px;
            animation-delay: 0s;
        }

        .animated-bg span:nth-child(2) {
            left: 10%;
            width: 20px;
            height: 20px;
            animation-delay: 2s;
            animation-duration: 12s;
        }

        .animated-bg span:nth-child(3) {
            left: 70%;
            width: 20px;
            height: 20px;
            animation-delay: 4s;
        }

        .animated-bg span:nth-child(4) {
            left: 40%;
            width: 60px;
            height: 60px;
            animation-delay: 0s;
            animation-duration: 18s;
        }

        .animated-bg span:nth-child(5) {
            left: 65%;
            width: 20px;
            height: 20px;
            animation-delay: 0s;
        }

        .animated-bg span:nth-child(6) {
            left: 75%;
            width: 110px;
            height: 110px;
            animation-delay: 3s;
        }

        .animated-bg span:nth-child(7) {
            left: 35%;
            width: 150px;
            height: 150px;
            animation-delay: 7s;
        }

        .animated-bg span:nth-child(8) {
            left: 50%;
            width: 25px;
            height: 25px;
            animation-delay: 15s;
            animation-duration: 45s;
        }

        .animated-bg span:nth-child(9) {
            left: 20%;
            width: 15px;
            height: 15px;
            animation-delay: 2s;
            animation-duration: 35s;
        }

        .animated-bg span:nth-child(10) {
            left: 85%;
            width: 150px;
            height: 150px;
            animation-delay: 0s;
            animation-duration: 11s;
        }

        @keyframes animate {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
                border-radius: 0;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }

        /* Footer */
        .footer {
            background: linear-gradient(to top, var(--dark-bg) 0%, var(--darker-bg) 100%);
            padding: 5rem 5% 2rem;
            position: relative;
            overflow: hidden;
        }

        .footer-wave {
            position: absolute;
            top: -100px;
            left: 0;
            right: 0;
            height: 100px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='%230a0c0f' fill-opacity='1' d='M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,101.3C960,75,1056,85,1152,101.3C1248,117,1344,139,1392,149.3L1440,160L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z'%3E%3C/path%3E%3C/svg%3E");
            background-size: cover;
            background-position: center;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
        }

        .footer-logo-section {
            flex: 1;
            min-width: 250px;
            text-align: center;
        }

        .footer-logo-section img {
            height: 60px;
            margin-bottom: 1rem;
        }

        .footer-logo-section .brand {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--primary), var(--primary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 1rem;
            display: block;
        }

        .footer-logo-section p {
            color: var(--text-secondary);
            max-width: 300px;
            margin: 0 auto;
        }

        .footer-links {
            flex: 2;
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .footer-column {
            min-width: 180px;
            text-align: left;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--primary-light);
            position: relative;
            padding-bottom: 10px;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 2px;
            background: var(--primary);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 12px;
        }

        .footer-column ul li a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-column ul li a:hover {
            color: var(--primary-light);
            padding-left: 5px;
        }

        .footer-bottom {
            max-width: 1200px;
            margin: 40px auto 0;
            padding-top: 30px;
            border-top: 1px solid var(--card-border);
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
            width: 100%;
        }

        .social-icons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .social-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            text-decoration: none;
            transition: var(--transition);
            border: 1px solid var(--card-border);
        }

        .social-icon:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-3px);
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .header nav {
                display: none;
            }
            .footer-logo-section {
                text-align: center;
                margin-bottom: 2rem;
            }
            .footer-logo-section p {
                margin: 0 auto;
            }
            .apply-section .content-wrapper {
                flex-direction: column;
            }
        }

        @media (max-width: 768px) {
            .section-title h2 {
                font-size: 2.2rem;
            }
            .user-profile span {
                display: none;
            }
            .footer-links {
                flex-direction: column;
                gap: 1.5rem;
            }
            .partners-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .header {
                padding: 1rem;
            }
            .utils {
                gap: 1rem;
            }
            .language-selector {
                display: none;
            }
            .page-section {
                padding: 4rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="animated-bg">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
    </div>

    <header class="header">
        <div class="logo-container">
            <a href="/" style="display: flex; align-items: center; gap: 0.8rem; text-decoration: none;">
                <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
                <span class="brand">LakeBan</span>
            </a>
        </div>
        <nav>
            <a href="/"><?php echo $translations['header']['nav']['home'] ?? 'Anasayfa'; ?></a>
            <a href="/topluluklar"><?php echo $translations['header']['nav']['communities'] ?? 'Topluluklar'; ?></a>
            <a href="/changelog"><?php echo $translations['header']['nav']['updates'] ?? 'Güncellemeler'; ?></a>
            <a href="/stats"><?php echo $translations['header']['nav']['statistics'] ?? 'İstatistikler'; ?></a>
            <a href="/support"><?php echo $translations['header']['nav']['support'] ?? 'Destek'; ?></a>
        </nav>
        <div class="utils">
            <div class="language-selector">
                <select onchange="changeLanguage(this.value)">
                    <option value="tr" <?php if ($lang == 'tr') echo 'selected'; ?>>Türkçe</option>
                    <option value="en" <?php if ($lang == 'en') echo 'selected'; ?>>English</option>
                    <option value="fi" <?php if ($lang == 'fi') echo 'selected'; ?>>Suomi</option>
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

    <main>
        
        <section class="hero-section page-section">
            <div class="label">LAKEBAN ORTAKLARI</div>
            <div class="section-title">
                <h2>LakeBan Ortaklık Programı</h2>
                <p>LakeBan'e zaman ve emek harcayan toplulukları desteklemek istiyoruz. Etkileşimli bir topluluk oluştur, LakeBan Ortaklık Programı seni ödüllendirsin.</p>
                <a href="#apply" class="cta-btn">Başvuru Süreci</a>
            </div>
        </section>

        <section id="about" class="page-section darker">
             <div class="section-title">
                <h2>Bunun olayı nedir?</h2>
                <p>Canlı ve etkileşim içerisindeki topluluklar için tasarlanan LakeBan Ortaklık Programı, en iyi sunucuları öne çıkarıyor. Platformumuzun tamamına örnek olan Ortak toplulukları, hem yeni hem de gedikli kullanıcılara kendi topluluklarını oluştururken ilham oluyor.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <i class="fas fa-gem"></i>
                    <h3>Gelişmiş Kişiselleştirme</h3>
                    <p>Topluluğunuza özel partner rozeti ve sadece ortaklara sunulan diğer görsel öğelerle markanızı bir üst seviyeye taşıyın.</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-gift"></i>
                    <h3>Ortaklara Özel Avantajlar</h3>
                    <p>Özel ödüller ve topluluğun için avantajlar kazanmanın yanı sıra sadece Ortaklara özel alana erişim sağla.</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-rocket"></i>
                    <h3>Öne Çık</h3>
                    <p>Topluluğuna özel bir rozet kazan ve Keşif sayfamızda diğer toplulukların arasından sıyrıl.</p>
                </div>
            </div>
        </section>

        <section id="partners" class="page-section">
            <div class="section-title">
                <h2>Ortaklarımızla Tanışın</h2>
                <p>Yemek yapmak, fantastik sporlar, en sevdiğin oyunun hayranları veya bu minvalde herhangi bir konuyla ilgili bir topluluğun olabilir; hiç fark etmeksizin etkileşim içinde olan tüm topluluklara ve içerik üreticilerine kapımız açık.</p>
            </div>
            <div class="partners-grid">
                <?php foreach ($partners as $index => $partner): ?>
                    <div class="partner-card" style="transition-delay: <?php echo $index * 0.1; ?>s;">
                        <div class="partner-logo">
                            <img src="<?php echo htmlspecialchars($partner['logo']); ?>" alt="<?php echo htmlspecialchars($partner['name']); ?> Logo">
                        </div>
                        <h3><?php echo htmlspecialchars($partner['name']); ?></h3>
                        <p><?php echo htmlspecialchars($partner['description']); ?></p>
                        <?php if (!empty($partner['website'])): ?>
                            <a href="<?php echo htmlspecialchars($partner['website']); ?>" target="_blank">
                                <i class="fas fa-external-link-alt"></i> <?php echo $translations['partners']['visit'] ?? 'Ziyaret Et'; ?>
                            </a>
                        <?php else: ?>
                            <a href="#" class="disabled">
                                <i class="fas fa-ban"></i> <?php echo $translations['partners']['no_website'] ?? 'Websitesi Yok'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        
        <section id="faq" class="page-section darker">
            <div class="section-title">
                <h2>Sıkça Sorulan Sorular</h2>
                <p>Bilmek isteyebileceğin her şey ve biraz daha fazlası.</p>
            </div>
            <div class="faq-container">
                <div class="faq-item">
                    <div class="faq-question">
                        <span>Ortaklarınızda neler arıyorsunuz?</span>
                        <i class="fas fa-plus icon"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Beni olduğum gibi kabul etmesi ve benimle sahilde uzun yürüyüşlere çıkması, mum ışığında akşam yemeği... bir saniye, sanırım soruyu yanlış okudum.<br><br>LakeBan'a hevesle sarılmış topluluklar ve içerik üreticileri arıyoruz. Bizi destekleyen toplulukları desteklemek istiyoruz.</p>
                    </div>
                </div>
                 <div class="faq-item">
                    <div class="faq-question">
                        <span>Programa kabul için topluluğumun ulaşması gereken sayı hedefi nedir?</span>
                         <i class="fas fa-plus icon"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Başka platformlardaki sayılar, sunucunun her daim canlı ve etkileşimli bir LakeBan topluluğu olduğu anlamına gelmiyor. Bu yüzden de artık LakeBan ile alakasız platformlardaki izlenme veya takipçi sayısı gibi sayılarla ilgilenmiyoruz.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>Bunun sonucunda topluluğum nasıl avantajlara sahip olacak?</span>
                         <i class="fas fa-plus icon"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Yukarıda sıraladığımız avantajların dışında, sunucun daha iyi bir bit hızına sahip olacak. Bu da, ses kanallarında sesler daha iyi duyulacak demenin afili bir yolu denebilir.</p>
                    </div>
                </div>
                 <div class="faq-item">
                    <div class="faq-question">
                        <span>Kabul edilmiş bir aday veya topluluk sahibi olarak ben ne kazanacağım?</span>
                         <i class="fas fa-plus icon"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Yukarıda listelenen her şeyin yanı sıra, toplulukları sağlıklı kalmaya ve programımıza bağlı kalmaya teşvik edecek yeni ödüller ve avantajlar üzerinde çalışmayı her daim sürdürüyoruz.</p>
                    </div>
                </div>
            </div>
        </section>
        
         <section id="apply" class="page-section apply-section">
            <div class="section-title">
                <h2>Başvuru Süreci</h2>
                <p>Ortaklık programına katılmak için aşağıdaki adımları takip edin</p>
            </div>
            <div class="content-wrapper">
                <div class="text-content">
                    <div class="apply-step">
                        <div class="step-label">1. Adım: Kurallara Uyum</div>
                        <p>Topluluğunuzun <a href="/terms">Topluluk Kuralları</a> ve <a href="/terms">Kullanım Koşulları</a>'na uygun olduğundan emin olun. Güvenli ve pozitif bir ortam yaratmak önceliğimizdir.</p>
                    </div>
                     <div class="apply-step">
                        <div class="step-label">2. Adım: Aktiflik ve Etkileşim</div>
                        <p>Topluluğunuzun belirli bir aktiflik ve etkileşim seviyesine ulaşması gerekmektedir. Aktif üyeler, düzenli etkinlikler ve kaliteli içerik, değerlendirme sürecinde önemli rol oynar.</p>
                    </div>
                     <div class="apply-step">
                        <div class="step-label">3. Adım: Bizimle İletişime Geçin</div>
                        <p>Tüm şartları sağladığınıza inanıyorsanız, LakeBan'in resmi <a href="join_server?code=c2b7ba401e9ac2f8" target="_blank">Destek Sunucusu</a>'na katılarak yetkililerle iletişime geçebilirsiniz. Ekibimiz başvurunuzu incelemek için sizinle temasa geçecektir.</p>
                    </div>
                </div>
            </div>
            <div class="section-title">
                <h2>2. Başvuru Süreci - Hibrit Yöntemi (İkinci Yöntem)</h2>
                <p>Topluluğunu LakeBan ile bir üst seviyeye taşımak ister misin? Hibrit Operasyonu ile minimum çabayla sunucunu parlat, hem sen hem topluluğun kazansın!</p>
            </div>
            <div class="content-wrapper">
                <div class="text-content">
                    <div class="apply-step">
                        <div class="step-label">1. Adım: Kanalını Aç</div>
                        <p>Sunucunda <code>#lakeban-partner</code> veya <code>#🌊・lakeban</code> gibi havalı bir kanal aç en üstte gözüksün!, LakeBan enerjisini topluluğuna taşı!</p>
                    </div>
                    <div class="apply-step">
                        <div class="step-label">2. Adım: Mesajını Sabitle</div>
                        <p>LakeBan’in büyüsünü anlatan hazır bir tanıtım mesajını kanala sabitle ve herkesin görmesi için kapıları aç!</p>
                    </div>
                    <div class="apply-step">
                        <div class="step-label">3. Adım: Partner Sayfamızda Parla</div>
                        <p>Biz senin sunucunu resmi partner sayfamızda yıldız yaparız, böylece topluluğun bizimle birlikte büyür!</p>
                    </div>
                    <div class="apply-step">
                        <div class="step-label">4. Adım: Ödülleri Topla</div>
                        <p>Topluluğun büyür, LakeBan parlar! Özel rozetler, avantajlar ve hazır içeriklerimizle işin kolay, kazancın büyük!</p>
                    </div>
                    <a href="join_server?code=c2b7ba401e9ac2f8" target="_blank" class="cta-btn">Hemen Hibrit Yöntemi'ne Katıl!</a>
                </div>
            </div>
        </section>

    </main>

    <footer class="footer">
        <div class="footer-wave"></div>
        <div class="footer-content">
            <div class="footer-logo-section">
                <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
                <span class="brand">LakeBan</span>
                <p><?php echo $translations['footer']['slogan'] ?? 'Yeni nesil sosyal platformunuz.'; ?></p>
                <div class="social-icons">
                    <a href="#" class="social-icon"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-youtube"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-github"></i></a>
                </div>
            </div>
            <div class="footer-links">
                <div class="footer-column">
                    <h3><?php echo $translations['footer']['lakeban'] ?? 'LakeBan'; ?></h3>
                    <ul>
                        <li><a href="/hakkimizda"><?php echo $translations['footer']['about'] ?? 'Hakkımızda'; ?></a></li>
                        <li><a href="/kariyer"><?php echo $translations['footer']['careers'] ?? 'Kariyer'; ?></a></li>
                        <li><a href="/basin"><?php echo $translations['footer']['press'] ?? 'Basın'; ?></a></li>
                        <li><a href="/marka-kitabi"><?php echo $translations['footer']['brand'] ?? 'Marka Kitabı'; ?></a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3><?php echo $translations['footer']['support'] ?? 'Destek'; ?></h3>
                    <ul>
                        <li><a href="/destek"><?php echo $translations['footer']['support_center'] ?? 'Destek Merkezi'; ?></a></li>
                        <li><a href="/yardim"><?php echo $translations['footer']['help_docs'] ?? 'Yardım Dokümanları'; ?></a></li>
                        <li><a href="/forum"><?php echo $translations['footer']['community_forums'] ?? 'Topluluk Forumları'; ?></a></li>
                        <li><a href="/guvenlik"><?php echo $translations['footer']['security'] ?? 'Güvenlik'; ?></a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3><?php echo $translations['footer']['policies'] ?? 'Politikalar'; ?></h3>
                    <ul>
                        <li><a href="/privacy"><?php echo $translations['footer']['privacy'] ?? 'Gizlilik Politikası'; ?></a></li>
                        <li><a href="/terms"><?php echo $translations['footer']['terms'] ?? 'Kullanım Koşulları'; ?></a></li>
                        <li><a href="/cookie"><?php echo $translations['footer']['cookie'] ?? 'Çerez Politikası'; ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <?php echo sprintf($translations['footer']['copyright'] ?? '&copy; %d LakeBan. Tüm hakları saklıdır.', date('Y')); ?>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
    <script>
        function changeLanguage(lang) {
            window.location.href = window.location.pathname + '?lang=' + lang;
        }

        window.addEventListener('scroll', function() {
            const header = document.querySelector('.header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
        
        // SSS Akordeon Mantığı
        const faqItems = document.querySelectorAll('.faq-item');

        faqItems.forEach(item => {
            const question = item.querySelector('.faq-question');
            question.addEventListener('click', () => {
                const isActive = item.classList.contains('active');

                // Önce tüm aktifleri kapat
                faqItems.forEach(i => {
                    if (i !== item) {
                        i.classList.remove('active');
                    }
                });

                // Tıklananı aç/kapat
                item.classList.toggle('active');
            });
        });

    </script>
</body>
</html>