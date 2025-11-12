<?php
session_start();

// Dil seçimi ve otomatik dil algılama
$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lakeban | Proje Sayfası</title>
    <link rel="icon" type="image/x-icon" href="https://raw.githubusercontent.com/google/gemini-pro-systems/main/assets/google-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet"/>
      <link rel="icon" type="image/x-icon" href="/icon.ico">
    <style>
        :root {
            --bg-dark: #0D0D0D;
            --bg-medium: #1A1A1A;
            --bg-light: #2C2C2C;
            --primary-green: #00F57A;
            --primary-glow: rgba(0, 245, 122, 0.2);
            --text-light: #E0E0E0;
            --text-medium: #A0A0A0;
            --border-radius: 12px;
            --transition-speed: 0.4s;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-light);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        .main-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 20px;
        }

        h1, h2, h3 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        h2 {
            font-size: 2.5rem;
            margin-bottom: 2rem;
            background: linear-gradient(90deg, var(--primary-green), #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 20px var(--primary-glow);
        }

        h4 {
            font-size: 1.8rem;
            margin-top: 2rem;
            margin-bottom: 1rem;
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        p {
            line-height: 1.7;
            color: var(--text-medium);
            font-size: 1.1rem;
            margin-bottom: 1rem; /* Added for general paragraph spacing */
        }

        ul {
            list-style: none;
            padding-left: 0;
            margin-bottom: 1rem;
        }

        ul li {
            margin-bottom: 0.5rem;
            color: var(--text-medium);
            font-size: 1.1rem;
            position: relative;
            padding-left: 20px;
        }

        ul li::before {
            content: '•'; /* Dot or custom icon */
            color: var(--primary-green);
            position: absolute;
            left: 0;
            font-weight: bold;
        }

        section {
            padding: 100px 0;
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }

        section.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 40px;
            background: rgba(13, 13, 13, 0.8);
            backdrop-filter: blur(10px);
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .header-logo {
            height: 40px;
            width: auto;
        }

        .header-nav a {
            color: var(--text-light);
            text-decoration: none;
            margin: 0 20px;
            font-weight: 500;
            position: relative;
            transition: color var(--transition-speed) ease;
        }

        .header-nav a:hover {
            color: var(--primary-green);
        }

        .header-nav a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--primary-green);
            transition: width var(--transition-speed) ease;
        }

        .header-nav a:hover::after {
            width: 100%;
        }

        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            text-align: center;
            padding-top: 80px;
        }

        .hero h1 {
            font-size: 4rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: -1px;
            background: linear-gradient(90deg, #ffffff, var(--text-medium));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero h1 span {
            background: linear-gradient(90deg, #15ff8b, var(--primary-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero .subtitle {
            font-size: 1.4rem;
            max-width: 600px;
            margin-bottom: 2rem;
            color: var(--text-medium);
        }

        .cta-button {
            display: inline-block;
            background: var(--primary-green);
            color: var(--bg-dark);
            padding: 15px 35px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            box-shadow: 0 0 25px var(--primary-glow);
        }

        .cta-button:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 0 40px rgba(0, 245, 122, 0.4);
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 3rem;
        }

        .card {
            background: var(--bg-medium);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
            padding: 30px;
            text-align: center;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease, border-color var(--transition-speed) ease;
        }

        .card:hover {
            transform: translateY(-10px);
            border-color: var(--primary-green);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .card img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 1.5rem;
            border: 3px solid var(--primary-green);
            background-color: var(--bg-light);
        }

        .card h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-light);
        }

        .card .role {
            color: var(--primary-green);
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .card .experience {
            font-size: 0.9rem;
            color: var(--text-medium);
        }

        .join-us-section {
            background-color: var(--bg-medium);
            padding: 80px 40px;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .join-us-section h2 {
            margin-bottom: 1rem;
        }

        .join-us-section p {
            max-width: 700px;
            margin: 0 auto 2.5rem auto;
        }

        .faq-item {
            background: var(--bg-medium);
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .faq-question {
            padding: 25px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .faq-question h3 {
            font-size: 1.2rem;
        }

        .faq-question i {
            transition: transform var(--transition-speed) ease;
        }

        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height var(--transition-speed) ease-in-out;
        }

        .faq-answer p {
            padding: 0 25px 25px 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 25px;
        }

        .faq-item.active .faq-answer {
            max-height: 200px; /* Adjust as needed */
        }

        .faq-item.active .faq-question i {
            transform: rotate(180deg);
            color: var(--primary-green);
        }

        .footer {
            padding: 60px 20px;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background: var(--bg-medium);
        }

        .footer p {
            margin-bottom: 1.5rem;
        }

        .footer a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 500;
        }

        .social-links a {
            color: var(--text-medium);
            font-size: 1.5rem;
            margin: 0 15px;
            transition: color var(--transition-speed) ease, transform var(--transition-speed) ease;
        }

        .social-links a:hover {
            color: var(--primary-green);
            transform: scale(1.2);
        }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1001; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Black w/ opacity */
            backdrop-filter: blur(5px);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: var(--bg-medium);
            margin: auto;
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 245, 122, 0.15);
            animation: fadeInScale 0.3s ease-out forwards;
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .close-button {
            color: var(--text-medium);
            position: absolute;
            top: 15px;
            right: 25px;
            font-size: 35px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-button:hover,
        .close-button:focus {
            color: var(--primary-green);
            text-decoration: none;
        }

        .modal-content h2 {
            font-size: 2rem;
            margin-bottom: 1.5rem;
            text-align: center;
            background: linear-gradient(90deg, var(--primary-green), #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            background-color: var(--bg-dark);
            color: var(--text-light);
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px var(--primary-glow);
            outline: none;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .modal .cta-button {
            width: 100%;
            text-align: center;
            margin-top: 15px;
        }

        /* Success/Error Messages and Loading Spinner */
        .status-message {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            display: none; /* Başlangıçta gizli */
        }

        .status-message.success {
            background-color: rgba(0, 245, 122, 0.2);
            color: var(--primary-green);
            border: 1px solid var(--primary-green);
        }

        .status-message.error {
            background-color: rgba(255, 0, 0, 0.2);
            color: #ff4d4d;
            border: 1px solid #ff4d4d;
        }

        .loading-spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid var(--primary-green);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
            display: none; /* Başlangıçta gizli */
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobil Navigasyon */
        .menu-toggle {
            display: none; /* Masaüstünde gizle */
            font-size: 1.8rem;
            color: var(--text-light);
            cursor: pointer;
            z-index: 1002; /* Menünün üstünde olsun */
        }

        .mobile-nav {
            display: none; /* Başlangıçta gizli */
            position: fixed;
            top: 0;
            right: -250px; /* Başlangıçta ekran dışı */
            width: 250px;
            height: 100%;
            background-color: var(--bg-medium);
            backdrop-filter: blur(10px);
            border-left: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 80px;
            transition: right 0.3s ease-in-out;
            z-index: 1001;
            flex-direction: column;
            align-items: center;
            box-shadow: -5px 0 15px rgba(0,0,0,0.3);
        }

        .mobile-nav.open {
            right: 0; /* Açıldığında ekrana getir */
            display: flex; /* Açıldığında görünür yap */
        }

        .mobile-nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 15px 0;
            display: block;
            width: 80%;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .mobile-nav a:last-child {
            border-bottom: none;
        }

        .mobile-nav a:hover {
            background-color: rgba(0, 245, 122, 0.1);
            color: var(--primary-green);
        }

        @media (max-width: 768px) {
            .header-nav {
                display: none; /* Masaüstü navını gizle */
            }
            .menu-toggle {
                display: block; /* Hamburger ikonunu göster */
            }
            h2 {
                font-size: 2rem;
            }
            .hero h1 {
                font-size: 2.8rem;
            }
            .hero .subtitle {
                font-size: 1.1rem;
            }
        }
        .contributor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 2rem;
}

.contributor-card {
    background: var(--bg-medium);
    border-radius: var(--border-radius);
    padding: 20px;
    text-align: center;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.contributor-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0, 245, 122, 0.2);
}

.contributor-card img {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    margin-bottom: 1rem;
    border: 2px solid var(--primary-green);
}

.contributor-card h3 {
    font-size: 1.4rem;
    color: var(--text-light);
}

.contributor-card .role {
    color: var(--primary-green);
    font-size: 1rem;
    margin-bottom: 0.5rem;
}

.contributor-card .description {
    font-size: 0.9rem;
    color: var(--text-medium);
    margin-bottom: 1rem;
}

.contributor-card .social-link {
    display: inline-block;
    color: var(--primary-green);
    text-decoration: none;
    font-size: 0.9rem;
    margin-top: 0.5rem;
    transition: color 0.3s ease;
}

.contributor-card .social-link:hover {
    color: var(--text-light);
}

.contributor-card .social-link i {
    margin-right: 5px;
}
    </style>
</head>
<body>

<header class="header">
    <img class="header-logo" alt="Lakeban Logo" src="LakebanAssets/icon.png"/>
    <nav class="header-nav" id="mainNav">
        <a href="#hakkimizda">Hakkımızda</a>
        <a href="#degerler-misyon">Değerler & Misyon</a>
        <a href="#fark-yaratan-yonler">Fark Yaratan Yönler</a>
        <a href="#gelistiriciler">Geliştiriciler</a>
        <a href="#tesekkurler">Teşekkürler</a>
        <a href="#katil">Bize Katıl</a>
        <a href="#sss">SSS</a>
    </nav>
    <div class="menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </div>
    <nav class="mobile-nav" id="mobileNav">
        <a href="#hakkimizda">Hakkımızda</a>
        <a href="#degerler-misyon">Değerler & Misyon</a>
        <a href="#fark-yaratan-yonler">Fark Yaratan Yönler</a>
        <a href="#gelistiriciler">Geliştiriciler</a>
        <a href="#tesekkurler">Teşekkürler</a>
        <a href="#katil">Bize Katıl</a>
        <a href="#sss">SSS</a>
    </nav>
</header>

<main class="main-container">

    <section class="hero visible">
        <h1>Bugün, <span>Geleceği kodluyoruz.</span></h1>
        <p class="subtitle">Lakeban projesi, en yeni teknolojilerle kullanıcı odaklı çözümler üreten bir topluluktur. İnovasyon ve iş birliği ruhuyla sınırları zorluyoruz.</p>
        <a href="#katil" class="cta-button">Topluluğa Katıl</a>
    </section>

    <section id="hakkimizda">
        <h2>Hakkımızda</h2>
        <p>Biz, teknolojinin gücünü kullanarak fark yaratmayı hedefleyen tutkulu bir ekibiz. Projemiz, kullanıcıların hayatını kolaylaştıran, verimli ve estetik açıdan tatmin edici dijital deneyimler sunmak üzerine kurulmuştur. Açık kaynak felsefesini benimseyerek, topluluğun gücüyle sürekli gelişen ve büyüyen bir platform oluşturuyoruz.</p>
    </section>

    <section id="degerler-misyon">
        <h2>Değerler ve Misyon</h2>
        <p>Lakeban, sadece bir yazılım projesi değil, geleceği şekillendiren bir vizyonun ve sürekli öğrenmenin birleşimidir. Her satır kodda, kullanıcılarımızın hayatına değer katma ve dijital dünyada yeni ufuklar açma tutkumuz yatar.</p>

        <h4><strong>Misyon Beyanı</strong></h4>
        <p>Lakeban olarak misyonumuz; yenilikçi teknolojileri kullanarak kullanıcıların dijital deneyimlerini dönüştürmek, açık kaynak felsefesiyle bilgi paylaşımını ve iş birliğini teşvik ederek küresel bir geliştirici topluluğu inşa etmektir.</p>

        <h4><strong>Vizyon Beyanı</strong></h4>
        <p>Vizyonumuz, Lakeban'ı dünya genelinde geliştiricilerin ilham aldığı, bir araya geldiği ve birlikte çığır açan çözümler ürettiği, dijital dönüşümün öncü platformu haline getirmektir.</p>

        <h4><strong>Temel Değerler</strong></h4>
        <ul>
            <li><strong>İnovasyon:</strong> Sürekli olarak yeni fikirler keşfetmeye ve mevcut yaklaşımları sorgulamaya odaklanırız. Teknolojinin sınırlarını zorlayarak geleceğin çözümlerini bugünden inşa ederiz.</li>
            <li><strong>İş Birliği:</strong> Gücümüzü çeşitlilikten ve birlikte çalışmaktan alırız. Açık iletişim, karşılıklı saygı ve ortak hedefler doğrultusunda ekip çalışmasına büyük önem veririz.</li>
            <li><strong>Kullanıcı Odaklılık:</strong> Tüm geliştirmelerimizin merkezine kullanıcılarımızı koyarız. Onların ihtiyaçlarını anlamak, beklentilerini aşmak ve en iyi deneyimi sunmak için çabalarız.</li>
            <li><strong>Şeffaflık:</strong> Çalışmalarımızı, kararlarımızı ve süreçlerimizi mümkün olduğunca şeffaf tutarız. Bu, topluluğumuzla güvene dayalı bir ilişki kurmamızı sağlar.</li>
            <li><strong>Sürekli Gelişim:</strong> Her gün daha iyiyi hedefleyerek öğrenmeye ve gelişmeye açık kalırız. Hatalarımızdan ders çıkarır, geri bildirimleri değerlendirir ve kendimizi sürekli yenileriz.</li>
        </ul>
    </section>

    <section id="fark-yaratan-yonler">
        <h2>Fark Yaratan Yönler</h2>
        <p>Lakeban, dijital dünyada fark yaratmayı hedefleyen, yenilikçi yaklaşımı ve güçlü topluluğuyla öne çıkar. Benzer projelerden ayrılan temel özelliklerimizle, kullanıcılarımıza benzersiz bir değer sunuyoruz.</p>

        <h4><strong>Neden Lakeban?</strong></h4>
        <ul>
            <li><strong>Topluluk Gücüyle İnovasyon:</strong> Lakeban, sadece bir ürün değil, aynı zamanda açık kaynak felsefesiyle beslenen canlı bir geliştirici topluluğudur. <strong>Bütün kullanıcıların dediklerini yapıyoruz; bir nevi siteyi beraber kodluyoruz ve her kullanıcının fikri bizim için çok önemli.</strong> Bu iş birliği, projemizi sürekli güncel ve ilgili tutar.</li>
            <li><strong>Son Teknoloji Adaptasyonu:</strong> En son teknoloji trendlerini yakından takip eder ve projelerimize entegre ederiz. Bu, sunduğumuz çözümlerin her zaman modern, verimli ve geleceğe hazır olmasını sağlar.</li>
            <li><strong>Kullanıcı Merkezli Tasarım:</strong> Ürünlerimizin her aşamasında kullanıcı deneyimini ön planda tutarız. Sezgisel arayüzler, kolay kullanım ve erişilebilirlik ilkeleriyle, her seviyeden kullanıcının dijital hedeflerine kolayca ulaşmasını sağlarız.</li>
            <li><strong>Çözüm Odaklı Yaklaşım:</strong> Sadece teknoloji geliştirmekle kalmayız, aynı zamanda kullanıcılarımızın karşılaştığı gerçek zorluklara odaklanarak somut ve ölçülebilir çözümler sunarız.</li>
        </ul>

        <h4><strong>Teknoloji Yığını</strong></h4>
        <p>Lakeban, gücünü modern ve ölçeklenebilir bir teknoloji yığınından almaktadır:</p>
        <ul>
            <li><strong>Frontend:</strong> HTML, CSS , JavaScript</li>
            <li><strong>Backend:</strong> PHP , Node.js</li>
            <li><strong>Veritabanı:</strong> MYSQL INNODB</li>
            <li><strong>Sunucu Ortamı:</strong> Apache</li>
            <li><strong>Sürüm Kontrolü:</strong> Git, GitHub</li>
    </section>

    <section id="gelistiriciler">
        <h2>Geliştirici Ekibimiz</h2>
        <p>Projemizin arkasındaki beyin gücü. Her biri kendi alanında uzman, yenilikçi ve tutkulu geliştiricilerden oluşan ekibimizle tanışın.</p>
        <div class="card-grid">
            <div class="card">
                <img src="https://i.redd.it/0hb9ez031ve71.jpg" alt="Ekip Üyesi" loading="lazy">
                <h3>Offihito</h3>
                <p class="role">Kurucu</p>
                <p class="experience">Lakeban’ın Şuanki tek geliştiricisi</p>
            </div>
        </div>
    </section>


<section id="emegi-gecenler">
    <h2>Emeği Geçenler</h2>
    <p>Bu projeyi hayata geçiren ve destekleyen herkese minnettarız. İşte bizimle bu yolda yürüyenler:</p>
    <div class="contributor-grid">
        <div class="contributor-card">
            <img src="https://lakeban.com/avatars/24_1740242737.jpg" alt="Piotriox" loading="lazy">
            <h3>Piotriox</h3>
            <p class="role">Eski Site Frontend geliştiricisi</p>
            <p class="description">Lakebanda mobil arayüzü ve çoğu arayüzü yeniden tasarladı.</p>
            <a href="https://github.com/Piotriox/" class="social-link"><i class="fab fa-github"></i>Github</a>
        </div>
        <div class="contributor-card">
            <img src="https://lakeban.com/avatars/687bc1dc09700.jpg" alt="Rotten Kozanoglu" loading="lazy">
            <h3>Rotten Kozanoglu</h3>
            <p class="role">Eski Site Frontend geliştiricisi</p>
            <p class="description">En yeni arayüzleri yaptı. </p>
            <a href="https://github.com/rotten-kozanoglu" class="social-link"><i class="fab fa-github"></i> GitHub</a>
        </div>
        <!-- Diğer destekçiler için benzer kartlar eklenebilir -->
    </div>
</section>
    <section id="tesekkurler">
        <h2>Teşekkürler</h2>
        <p>Bu projenin hayata geçmesinde ve büyümesinde bize destek olan, fikirleriyle yol gösteren değerli topluluk üyelerimize sonsuz teşekkürler.</p>
        <div class="card-grid">
            <div class="card">
                <img src="https://lakeban.com/avatars/682cc1016f6bd.jpg" alt="Destekçi" loading="lazy">
                <h3>Kızgın Kuş</h3>
                <p class="role">Site Tanıtımı</p>
            </div>
            <div class="card">
                <img src="https://lakeban.com/avatars/10_1734290666.jpg" alt="Destekçi" loading="lazy">
                <h3>TheMatSet</h3>
                <p class="role">Site Tester</p>
            </div>
             <div class="card">
                <img src="https://lakeban.com/avatars/6821d256d7fbe.png" alt="Destekçi" loading="lazy">
                <h3>Potexa</h3>
                <p class="role">Site Fikirleri</p>
            </div>
             <div class="card">
                <img src="https://lakeban.com/avatars/687b6647efd34.jpeg" alt="Destekçi" loading="lazy">
                <h3>Esktwekts</h3>
                <p class="role">Site Tanıtımı</p>
            </div>
              <div class="card">
                <img src="https://lakeban.com/uploads/5024678b696e2b3dd7fa42d0598afc6f.png" alt="Destekçi" loading="lazy">
                <h3>Karga</h3>
                <p class="role">Site Tanıtımı</p>
            </div>
        </div>
    </section>

    <section id="katil">
        <div class="join-us-section">
            <h2>Aramıza Katılın!</h2>
            <p>Projemize katkıda bulunmak ve bu heyecanın bir parçası olmak ister misiniz? Yetenekli ve istekli yeni ekip arkadaşları arıyoruz. Geliştirme, tasarım, test veya topluluk yönetimi alanlarında bize yardımcı olabilirsiniz.</p>
            <a href="#" class="cta-button">Hemen Başvur</a>
        </div>
    </section>

    <section id="sss">
        <h2>Sıkça Sorulan Sorular</h2>
        <div class="faq-container">
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Sunucuya nasıl katılabilirim?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Sunucumuza katılmak için "Bize Katıl" bölümündeki başvuru butonunu kullanabilir veya sosyal medya hesaplarımızdan davet linki talep edebilirsiniz.</p>
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Topluluk kuralları nelerdir?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Temel kurallarımız saygı, yapıcı eleştiri ve iş birliğine dayanmaktadır. Herkese karşı nazik ve yardımsever olmayı, spam veya uygunsuz içerik paylaşmamayı bekliyoruz. Detaylı kurallar sunucumuzdaki ilgili kanalda bulunmaktadır.</p>
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Projeye nasıl içerik veya fikir katkısı yapabilirim?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Fikirlerinizi ve önerilerinizi sunucumuzdaki "öneriler" kanalında paylaşabilirsiniz.</p>
                </div>
            </div>
        </div>
    </section>

</main>

<div id="applicationModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2>Başvuru Formu</h2>

        <div id="formStatusMessage" class="status-message"></div>
        <div id="loadingSpinner" class="loading-spinner"></div>

        <form id="applicationForm" action="send_application.php" method="POST">
            <div class="form-group">
                <label for="name">Adınız Soyadınız:</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="form-group">
                <label for="email">E-posta Adresiniz:</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="github_username">GitHub Kullanıcı Adınız (isteğe bağlı):</label>
                <input type="text" id="github_username" name="github_username">
            </div>
            <div class="form-group">
                <label for="dev_type">Ne tür geliştiricisiniz?</label>
                <select id="dev_type" name="dev_type" required>
                    <option value="">Seçiniz</option>
                    <option value="frontend">Frontend Geliştirici</option>
                    <option value="backend">Backend Geliştirici</option>
                    <option value="fullstack">Fullstack Geliştirici</option>
                    <option value="other">Diğer</option>
                </select>
            </div>
            <div class="form-group">
                <label for="languages">Hangi dilleri biliyorsunuz? (Örn: HTML, CSS, JavaScript, PHP, Python)</label>
                <input type="text" id="languages" name="languages" required>
            </div>
            <div class="form-group">
                <label for="projects">Önceden ne gibi projelerde çalıştınız?</label>
                <textarea id="projects" name="projects" rows="5"></textarea>
            </div>
            <button type="submit" class="cta-button">Başvuruyu Gönder</button>
        </form>
    </div>
</div>

<footer class="footer">
    <p>Bizimle iletişime geçmek için: <a href="mailto:lakeban@lakeban.com">lakeban@lakeban.com</a></p>
    <div class="social-links">
        <a href="https://discord.gg/equBKqgrfZ" aria-label="Discord"><i class="fab fa-discord"></i></a>
        <a href="https://www.instagram.com/lakebaninc/" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
    </div>
    <p style="margin-top: 2rem; font-size: 0.9rem; color: var(--text-medium);">© 2024-2025 Lakeban. Tüm hakları saklıdır.</p>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const sections = document.querySelectorAll('section');
        const faqItems = document.querySelectorAll('.faq-item');

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        sections.forEach(section => {
            if (section.classList.contains('hero')) return;
            observer.observe(section);
        });

        faqItems.forEach(item => {
            const question = item.querySelector('.faq-question');
            question.addEventListener('click', () => {
                const isActive = item.classList.contains('active');
                faqItems.forEach(i => i.classList.remove('active'));
                if (!isActive) {
                    item.classList.add('active');
                }
            });
        });

        // Modal functionality
        const applicationButton = document.querySelector('#katil .cta-button');
        const modal = document.getElementById('applicationModal');
        const closeButton = document.querySelector('.close-button');

        applicationButton.addEventListener('click', (e) => {
            e.preventDefault(); // Varsayılan bağlantı davranışını engelle
            modal.style.display = 'flex'; // Modalı görünür yapar ve ortalar
            // Modalı açarken mesajı ve spinner'ı gizle
            document.getElementById('formStatusMessage').style.display = 'none';
            document.getElementById('loadingSpinner').style.display = 'none';
        });

        closeButton.addEventListener('click', () => {
            modal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });

        // Form AJAX gönderimi, başarı/hata mesajları ve loading spinner
        const applicationForm = document.getElementById('applicationForm');
        const formStatusMessage = document.getElementById('formStatusMessage');
        const loadingSpinner = document.getElementById('loadingSpinner');

        if (applicationForm) {
            applicationForm.addEventListener('submit', async (e) => {
                e.preventDefault(); // Varsayılan form gönderimini engelle

                // Mesajı temizle ve spinner'ı göster
                formStatusMessage.style.display = 'none';
                formStatusMessage.className = 'status-message'; // Sınıfları temizle
                loadingSpinner.style.display = 'block';
                applicationForm.querySelector('button[type="submit"]').disabled = true; // Butonu devre dışı bırak

                const formData = new FormData(applicationForm);

                try {
                    const response = await fetch(applicationForm.action, {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.text(); // Yanıtı metin olarak al

                    if (response.ok) { // HTTP durumu 200-299 arasındaysa
                        formStatusMessage.textContent = result;
                        formStatusMessage.classList.add('success');
                        formStatusMessage.style.display = 'block';
                        applicationForm.reset(); // Formu sıfırla
                        // Başarılı olursa modalı otomatik kapatabilirsiniz
                        // setTimeout(() => {
                        //     modal.style.display = 'none';
                        // }, 3000);
                    } else {
                        formStatusMessage.textContent = result || 'Başvuru gönderilirken bir hata oluştu.';
                        formStatusMessage.classList.add('error');
                        formStatusMessage.style.display = 'block';
                    }
                } catch (error) {
                    console.error('Gönderim hatası:', error);
                    formStatusMessage.textContent = 'Ağ hatası veya sunucuya ulaşılamadı.';
                    formStatusMessage.classList.add('error');
                    formStatusMessage.style.display = 'block';
                } finally {
                    loadingSpinner.style.display = 'none';
                    applicationForm.querySelector('button[type="submit"]').disabled = false; // Butonu tekrar etkinleştir
                }
            });
        }

        // Mobil menü işlevselliği
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileNav = document.getElementById('mobileNav');
        const mobileNavLinks = mobileNav.querySelectorAll('a');

        mobileMenuToggle.addEventListener('click', () => {
            mobileNav.classList.toggle('open');
        });

        // Mobil menüden bir linke tıklayınca menüyü kapat
        mobileNavLinks.forEach(link => {
            link.addEventListener('click', () => {
                mobileNav.classList.remove('open');
            });
        });

        // Eğer menü açıkken pencere yeniden boyutlandırılırsa kapat (masaüstü görünümüne geçiş durumunda)
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                mobileNav.classList.remove('open');
            }
        });
    });
</script>
</body>
</html>