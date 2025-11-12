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
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.8">
    <title>LakeBan - Yeni Nesil Sosyal Platform</title>
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

        /* Hero Section - Enhanced */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 0 5%;
            position: relative;
            overflow: hidden;
            background: radial-gradient(circle at top right, rgba(76, 175, 80, 0.15) 0%, transparent 30%), 
                        radial-gradient(circle at bottom left, rgba(129, 199, 132, 0.15) 0%, transparent 30%),
                        linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://assets.codepen.io/1462889/particle-bg.svg') center/cover;
            opacity: 0.2;
            z-index: 0;
            animation: particleMove 20s linear infinite;
        }

        .hero-content {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
        }

        .hero-text {
            animation: fadeInUp 0.8s ease-out;
            position: relative; /* arrow-box için ana kapsayıcı olacak */
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1.5rem;
            background: linear-gradient(to right, #fff, var(--primary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero p {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            color: var(--text-secondary);
            max-width: 90%;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            position: relative; /* arrow-box için referans noktası olacak */
            display: inline-flex; /* Kendi içeriğine göre genişlemesini sağlar */
        }

        .primary-btn, .secondary-btn {
            padding: 0.9rem 2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 1.1rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }

        .primary-btn {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 20px rgba(76, 175, 80, 0.4);
        }

        .primary-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 24px rgba(76, 175, 80, 0.6);
        }

        .secondary-btn {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .secondary-btn:hover {
            background: rgba(255,255,255,0.05);
            transform: translateY(-3px);
            border-color: var(--primary);
        }

        .hero-image {
            position: relative;
            display: flex;
            justify-content: center;
            animation: float 6s ease-in-out infinite;
        }

        .hero-image img {
            max-width: 100%;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 3rem;
        }

        .stat-card {
            background: rgba(255,255,255,0.05);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            text-align: center;
            border: 1px solid var(--card-border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
        }

        .stat-card::before {
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

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 1rem;
            position: relative;
            z-index: 1;
        }

        /* Features Section - Enhanced */
        .features-section {
            padding: 6rem 5%;
            background: var(--darker-bg);
            position: relative;
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: -100px;
            left: 0;
            right: 0;
            height: 100px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='%230f1114' fill-opacity='1' d='M0,96L48,112C96,128,192,160,288,186.7C384,213,480,235,576,213.3C672,192,768,128,864,101.3C960,75,1056,85,1152,101.3C1248,117,1344,139,1392,149.3L1440,160L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z'%3E%3C/path%3E%3C/svg%3E");
            background-size: cover;
            background-position: center;
        }

        .section-title {
            text-align: center;
            margin-bottom: 4rem;
            position: relative;
            z-index: 2;
        }

        .section-title h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
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
            font-size: 1.1rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .feature-card {
            background-color: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 2.5rem 2rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--card-border);
            transform: translateY(30px);
            opacity: 0;
        }

        .feature-card.animate {
            transform: translateY(0);
            opacity: 1;
        }

        .feature-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .feature-card::before {
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

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
            color: white;
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }

        .feature-card:hover .feature-icon {
            transform: rotateY(360deg);
        }

        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--text-light);
            position: relative;
            z-index: 1;
        }

        .feature-card p {
            color: var(--text-secondary);
            position: relative;
            z-index: 1;
        }

        /* Comparison Table */
        .comparison-section {
            padding: 6rem 5%;
            background: var(--dark-bg);
        }

        .comparison-container {
            max-width: 1200px;
            margin: 0 auto;
            overflow-x: auto;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            background: var(--card-bg);
            border: 1px solid var(--card-border);
        }

        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .comparison-table th, 
        .comparison-table td {
            padding: 1.25rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--card-border);
        }

        .comparison-table th {
            background: rgba(0,0,0,0.2);
            font-weight: 600;
            color: white;
        }

        .comparison-table th:first-child {
            text-align: left;
            background: var(--darker-bg);
        }

        .comparison-table tr:last-child td {
            border-bottom: none;
        }

        .comparison-table tr:hover {
            background: rgba(255,255,255,0.03);
        }

        .comparison-table td:first-child {
            text-align: left;
            font-weight: 500;
        }

        .check {
            color: var(--primary);
            font-weight: bold;
            position: relative;
        }

        .check::before {
            content: '✓';
            font-size: 1.4rem;
        }

        .cross {
            color: var(--danger);
            font-weight: bold;
            position: relative;
        }

        .cross::before {
            content: '✕';
            font-size: 1.4rem;
        }

        .platform-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .platform-logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(76, 175, 80, 0.1);
            color: var(--primary);
            font-size: 1.2rem;
        }

        /* Footer - Enhanced */
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            position: relative;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--primary);
            animation: modalFadeIn 0.4s ease-out;
        }

        .close-modal {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            font-size: 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition);
        }

        .close-modal:hover {
            color: white;
        }

        .modal-header {
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--card-border);
        }

        .modal-header h2 {
            font-size: 1.75rem;
            color: white;
        }

        .modal-body {
            padding: 2rem;
        }

        .download-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .download-btn {
            display: flex;
            align-items: center;
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: var(--transition);
            background: var(--darker-bg);
            border: 1px solid var(--card-border);
        }

        .download-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            background: var(--card-bg);
            border-color: var(--primary);
        }

        .download-btn i {
            font-size: 2rem;
            margin-right: 1rem;
            width: 50px;
            text-align: center;
        }

        .windows i {
            color: #00ADEF;
        }

        .android i {
            color: #3DDC84;
        }

        .btn-text {
            text-align: left;
        }

        .btn-text span {
            font-weight: 600;
            color: white;
            display: block;
            margin-bottom: 0.25rem;
        }

        .btn-text small {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            0% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
            100% {
                transform: translateY(0px);
            }
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes particleMove {
            0% {
                background-position: 0 0;
            }
            100% {
                background-position: 1000px 1000px;
            }
        }

        /* Counter Animation */
        .counter {
            display: inline-block;
        }

        /* Arrow Box for Hero */
        .arrow-box {
            position: absolute;
            top: 60px;
            right: -530px;
            display: flex;
            align-items: center;
            gap: 10px;
            pointer-events: none;
            z-index: 10;
        }
        
        .arrow-box p {
            font-family: 'Gochi Hand', cursive;
            font-size: 2rem;
            color: white;
            line-height: 1.1;
            white-space: nowrap;
            margin-top: 110px;
        }
        
        .arrow-box svg {
            width: 250px;
            height: 120px;
        }
        
        #arrow-path {
            stroke: white;
            stroke-width: 4;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Bot Creation Section */
        .bot-section {
            padding: 6rem 5%;
            background: linear-gradient(135deg, var(--darker-bg) 0%, var(--dark-bg) 100%);
            position: relative;
            overflow: hidden;
        }

        .bot-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://i.ibb.co/m0zL3k4/pattern-bg.png') center/cover;
            opacity: 0.05;
        }

        .bot-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            max-width: 1200px;
            margin: 0 auto;
            align-items: center;
        }

        .bot-text {
            position: relative;
            z-index: 2;
        }

        .bot-image {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .bot-image img {
            max-width: 100%;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .bot-features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .bot-feature {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .bot-feature i {
            font-size: 1.5rem;
            color: var(--primary);
            background: rgba(76, 175, 80, 0.1);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .bot-feature div h4 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-light);
        }

        .bot-feature div p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* CTA Section - New */
        .cta-section {
            padding: 6rem 5%;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://i.ibb.co/m0zL3k4/pattern-bg.png') center/cover;
            opacity: 0.1;
            z-index: 0;
        }

        .cta-content {
            position: relative;
            z-index: 1;
            max-width: 800px;
            margin: 0 auto;
        }

        .cta-title {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .cta-description {
            font-size: 1.2rem;
            margin-bottom: 2.5rem;
            color: rgba(255,255,255,0.9);
        }

        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            .hero-text {
                order: 1;
            }
            .hero p {
                max-width: 100%;
                margin-left: auto;
                margin-right: auto;
            }
            .hero-buttons {
                justify-content: center;
                flex-direction: column;
                align-items: center;
                display: flex;
                width: 100%;
            }
            .primary-btn, .secondary-btn {
                width: 100%;
            }
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
            .arrow-box {
                position: relative;
                left: auto;
                right: auto;
                top: auto;
                transform: none;
                margin-top: 20px;
                align-items: center;
            }
            .arrow-box p {
                text-align: center;
                padding-left: 0;
            }
            .arrow-box svg {
                margin-left: auto;
                margin-right: auto;
                transform: translateX(0);
                width: 200px;
                height: 100px;
            }
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }
            .hero p {
                font-size: 1.1rem;
            }
            .section-title h2 {
                font-size: 2rem;
            }
            .user-profile span {
                display: none;
            }
            .footer-links {
                flex-direction: column;
                gap: 1.5rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .cta-title {
                font-size: 2rem;
            }
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            .arrow-box {
                margin-top: 15px;
            }
            .arrow-box p {
                font-size: 1.6rem;
            }
            .arrow-box svg {
                width: 150px;
                height: 80px;
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
            .hero {
                padding: 5rem 1.5rem 3rem;
            }
            .features-section, .comparison-section {
                padding: 4rem 1.5rem;
            }
            .modal-content {
                width: 95%;
            }
            .arrow-box {
                margin-top: 10px;
            }
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
        <a href="login" class="login-btn"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></a>
        <a href="register" class="register-btn"><?php echo $translations['header']['register'] ?? 'Kayıt Ol'; ?></a>
    </div>
<?php endif; ?>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="hero-content">
                <div class="hero-text">
                    <h1><?php echo $translations['welcome'] ?? 'LakeBan, oyuncular ve topluluklar için özel olarak geliştirildi.'; ?></h1>
                    <p><?php echo $translations['description'] ?? 'Kusursuz sesli ve yazılı iletişim deneyimiyle, birlikte oynamayı ve sohbet etmeyi daha keyifli hâle getiren yeni nesil sosyal platform.'; ?></p>
                    <div class="hero-buttons">
                        <a href="#" class="secondary-btn" onclick="openDownloadModal()">
                            <i class="fas fa-download"></i> <?php echo $translations['buttons']['download_app'] ?? 'Uygulamayı İndir'; ?>
                        </a>
                        <a href="/login" class="primary-btn">
                            <i class="fas fa-play-circle"></i> <?php echo $translations['buttons']['login'] ?? 'Hemen Başla'; ?>
                        </a>
                        <div class="arrow-box">
                            <svg width="250" height="120" viewBox="0 0 250 120">
                                <path d="M240 110 L180 100 C150 90 100 70 50 40 C30 25 10 10 0 0" 
                                      fill="none" 
                                      stroke="white" 
                                      stroke-width="4"
                                      stroke-linecap="round"
                                      stroke-linejoin="round"/>
                                <polygon points="15,0 0,15 -15,0" fill="white"/>
                            </svg>
                            <p><?php echo $translations['no_download_needed'] ?? 'İndirmen gerekmiyor!'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="hero-image">
                    <img src="https://lakeban.com/Uploads/688a86d785800_pasted-image.png" alt="LakeBan Sohbet Ekranı">
                </div>
            </div>
        </section>

        <section class="features-section" id="features">
            <div class="section-title">
                <h2><?php echo $translations['features']['title'] ?? 'Tüm araçlar, tek çatı altında.'; ?></h2>
                <p><?php echo $translations['features']['subtitle'] ?? 'Sohbet et, topluluğunu yönet, özelleştir. Karmaşık değil, tam aksine keyifli.'; ?></p>
            </div>
            <div class="features-grid">
                <div class="feature-card reveal">
                    <div class="feature-icon"><i class="fas fa-microphone-alt"></i></div>
                    <h3><?php echo $translations['features']['voice'] ?? 'Net ses, sıfır kopma.'; ?></h3>
                    <p><?php echo $translations['features']['voice_desc'] ?? 'Oyun içi stratejiler ya da gece muhabbetleri hepsi kristal netliğinde.'; ?></p>
                </div>
                <div class="feature-card reveal" style="transition-delay: 0.1s;">
                    <div class="feature-icon"><i class="fas fa-comments"></i></div>
                    <h3><?php echo $translations['features']['chat'] ?? 'Sadece mesaj değil, ifade özgürlüğü.'; ?></h3>
                    <p><?php echo $translations['features']['chat_desc'] ?? 'Zengin metinler, emojiler, dosya paylaşımı… Anlatmak istediğin her şeye yer var.'; ?></p>
                </div>
                <div class="feature-card reveal" style="transition-delay: 0.2s;">
                    <div class="feature-icon"><i class="fas fa-users"></i></div>
                    <h3><?php echo $translations['features']['community'] ?? 'Roller, kanallar, izinler senin kuralların.'; ?></h3>
                    <p><?php echo $translations['features']['community_desc'] ?? 'Topluluğunu kur, özelleştir, düzenle. Hepsi kolayca.'; ?></p>
                </div>
                <div class="feature-card reveal" style="transition-delay: 0.3s;">
                    <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                    <h3><?php echo $translations['features']['security'] ?? 'Verilerin sadece sana ait.'; ?></h3>
                    <p><?php echo $translations['features']['security_desc'] ?? 'Uçtan uca şifreleme, sıfır takipçi, sıfır reklam. Gizliliğine saygılıyız.'; ?></p>
                </div>
                <div class="feature-card reveal" style="transition-delay: 0.4s;">
                    <div class="feature-icon"><i class="fas fa-robot"></i></div>
                    <h3><?php echo $translations['features']['bots'] ?? 'Canın ister, eklersin.'; ?></h3>
                    <p><?php echo $translations['features']['bots_desc'] ?? 'Hazır binlerce bot seni bekliyor. İstersen kendininkini bile 10 saniye de yapabilirsin.'; ?></p>
                </div>
                <div class="feature-card reveal" style="transition-delay: 0.5s;">
                    <div class="feature-icon"><i class="fas fa-puzzle-piece"></i></div>
                    <h3><?php echo $translations['features']['customization'] ?? 'İstediğin gibi görünür, istediğin gibi çalışır.'; ?></h3>
                    <p><?php echo $translations['features']['customization_desc'] ?? 'Temalar, düzenler, kişisel ayarlar – LakeBan senin alanın.'; ?></p>
                </div>
            </div>
        </section>

        <section class="comparison-section" id="comparison">
            <div class="section-title">
                <h2><?php echo $translations['comparison_table']['title'] ?? 'Diğer platformlarla aramızdaki farkı görmek ister misin?'; ?></h2>
                <p><?php echo $translations['comparison_table']['subtitle'] ?? 'Aşağıdaki tabloya bir bak, LakeBan\'ın neden daha iyi bir seçenek olduğunu sen karar ver.'; ?></p>
            </div>
            <div class="comparison-container reveal">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th><?php echo $translations['comparison_table']['features']['feature_platform'] ?? 'Özellik'; ?></th>
                            <th>
                                <div class="platform-header">
                                    <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo" style="width: 30px; height: 30px; border-radius: 50%;">
                                    <span><?php echo $translations['comparison_table']['platforms']['lakeban'] ?? 'LakeBan'; ?></span>
                                </div>
                            </th>
                            <th>
                                <div class="platform-header">
                                    <i class="fab fa-discord platform-logo"></i>
                                    <span><?php echo $translations['comparison_table']['platforms']['discord'] ?? 'Discord'; ?></span>
                                </div>
                            </th>
                            <th>
                                <div class="platform-header">
                                    <i class="fab fa-teamspeak platform-logo"></i>
                                    <span><?php echo $translations['comparison_table']['platforms']['teamspeak'] ?? 'TeamSpeak'; ?></span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['free_communication'] ?? 'Ücretsiz Yüksek Kalite Ses'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                            <td><span class="cross"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['advanced_chat'] ?? 'Gelişmiş Yazılı Sohbet'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                            <td><span class="cross"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['easy_bot_creation'] ?? 'Kolay Bot Oluşturma'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="cross"></span></td>
                            <td><span class="cross"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['customizable_interface'] ?? 'Özelleştirilebilir Arayüz'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="cross"></span></td>
                            <td><span class="cross"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['game_integrations'] ?? 'Yerleşik Oyun Entegrasyonları'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                            <td><span class="cross"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['low_latency'] ?? 'Düşük Gecikme Süresi'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['mobile_desktop_support'] ?? 'Mobil ve Masaüstü Desteği'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                            <td><span class="cross"></span></td>
                        </tr>
                        <tr>
                            <td><?php echo $translations['comparison_table']['features']['advanced_security'] ?? 'Gelişmiş Güvenlik'; ?></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                            <td><span class="check"></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="cta-section">
            <div class="cta-content reveal">
                <h2 class="cta-title"><?php echo $translations['cta']['title'] ?? 'Hazırsan, LakeBan da hazır.'; ?></h2>
                <p class="cta-description"><?php echo $translations['cta']['description'] ?? 'Topluluğunu topla, sohbetini özgürce yap, oyun keyfini katla. Ücretsiz, reklamsız ve tamamen senin için.'; ?></p>
                <div class="cta-buttons">
                    <a href="/register" class="primary-btn">
                        <i class="fas fa-user-plus"></i> <?php echo $translations['cta']['register'] ?? 'Ücretsiz Kayıt Ol'; ?>
                    </a>
                    <a href="#" class="secondary-btn" onclick="openDownloadModal()">
                        <i class="fas fa-download"></i> <?php echo $translations['cta']['download'] ?? 'Uygulamayı İndir'; ?>
                    </a>
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

    <div id="downloadModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeDownloadModal()">&times;</span>
            <div class="modal-header">
                <h2><?php echo $translations['download_title'] ?? 'Uygulamayı İndir'; ?></h2>
            </div>
            <div class="modal-body">
                <div class="download-options">
                    <a href="Lakeban Setup 1.0.0.exe" class="download-btn windows">
                        <i class="fab fa-windows"></i>
                        <div class="btn-text">
                            <span><?php echo $translations['download']['windows'] ?? 'Windows için İndir'; ?></span>
                            <small><?php echo $translations['download']['windows_version'] ?? 'Windows 10 veya üzeri'; ?></small>
                        </div>
                    </a>
                    <a href="Lakeban.apk" class="download-btn android">
                        <i class="fab fa-android"></i>
                        <div class="btn-text">
                            <span><?php echo $translations['download']['android'] ?? 'Android için İndir'; ?></span>
                            <small><?php echo $translations['download']['android_version'] ?? 'Google Play Store'; ?></small>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js"></script>
    <script>
      // Modal functions
        function openDownloadModal() {
            document.getElementById('downloadModal').style.display = 'flex';
        }

        function closeDownloadModal() {
            document.getElementById('downloadModal').style.display = 'none';
        }

        function changeLanguage(lang) {
            window.location.href = window.location.pathname + '?lang=' + lang;
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('downloadModal')) {
                closeDownloadModal();
            }
        }

        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.querySelector('.header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Scroll reveal animation
        function revealElements() {
            const reveals = document.querySelectorAll('.reveal');
            for (let i = 0; i < reveals.length; i++) {
                const windowHeight = window.innerHeight;
                const revealTop = reveals[i].getBoundingClientRect().top;
                const revealPoint = 150;

                if (revealTop < windowHeight - revealPoint) {
                    reveals[i].classList.add('animate');
                } else {
                    reveals[i].classList.remove('animate');
                }
            }
        }

        window.addEventListener('scroll', revealElements);
        window.addEventListener('load', revealElements); // Initial check on load
    </script>
</body>
</html>