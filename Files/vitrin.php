<?php
session_start();
require 'db_connection.php';

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Kullanıcı profili için mevcut showcase image sorgusu
$showcase_query = $db->prepare("SELECT showcase_image_url FROM user_profiles WHERE user_id = ?");
$showcase_query->execute([$_SESSION['user_id']]);
$showcase_result = $showcase_query->fetch();
$showcase_image_url = $showcase_result['showcase_image_url'] ?? '';

// CSRF token oluştur
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
?>
<!DOCTYPE html>
<html lang="tr" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh;">
<head>
    <meta charset="UTF-8">
    <title>Vitrin Resmi Ayarları</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1E1E1E">

    <!-- App Icons -->
    <link rel="apple-touch-icon" href="/assets/apple-touch.png">
    <link rel="icon" type="image/png" href="/assets/logo_round.png">
    <link rel="icon" type="image/x-icon" href="/icon.ico">

    <!-- Splash Screens for iOS Devices -->
    <link href="/assets/iphone5_splash.png" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
    <link href="/assets/iphone6_splash.png" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
    <link href="/assets/iphoneplus_splash.png" media="(device-width: 621px) and (device-height: 1104px) and (-webkit-device-pixel-ratio: 3)" rel="apple-touch-startup-image">
    <link href="/assets/iphonex_splash.png" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3)" rel="apple-touch-startup-image">
    <link href="/assets/iphonexr_splash.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
    <link href="/assets/iphonexsmax_splash.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3)" rel="apple-touch-startup-image">
    <link href="/assets/ipad_splash.png" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
    <link href="/assets/ipadpro1_splash.png" media="(device-width: 834px) and (device-height: 1112px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
    <link href="/assets/ipadpro3_splash.png" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
    <link href="/assets/ipadpro2_splash.png" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">

    <!-- Lucide Icons -->
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>

    <style>
        noscript {
            background: #242424;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
        }
        noscript > div {
            padding: 12px;
            display: flex;
            font-family: Arial, sans-serif;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }
        noscript > div > h1 {
            margin: 8px 0;
            text-transform: uppercase;
            font-size: 20px;
            font-weight: 700;
        }
        noscript > div > p {
            margin: 4px 0;
            font-size: 14px;
        }
        noscript > div > a {
            align-self: center;
            margin-top: 20px;
            padding: 8px 10px;
            font-size: 14px;
            width: 80px;
            font-weight: 600;
            background: #ed5151;
            border-radius: 4px;
            text-decoration: none;
            color: white;
            transition: background-color 0.2s;
        }
        noscript > div > a:hover {
            background-color: #cf4848;
        }
        noscript > div > a:active {
            background-color: #b64141;
        }

        body {
            background-color: #1E1E1E;
            color: #ffffff;
            font-family: Arial, sans-serif;
            margin: 0;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
        }
        .app-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            height: var(--app-height);
            padding: 24px;
            box-sizing: border-box;
        }
        .sidebar {
            background-color: #242424;
            width: 260px;
            padding: 16px 8px;
            overflow-y: auto;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: #1E1E1E;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #3CB371;
            border-radius: 2px;
        }
        .category {
            color: #b9bbbe;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 8px 16px;
            margin: 8px 0;
        }
        .sidebar-item {
            display: flex;
            align-items: center;
            padding: 8px 16px;
            margin: 2px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            color: #b9bbbe;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .sidebar-item:hover, .sidebar-item.active {
            background-color: #2f3136;
            color: #ffffff;
        }
        .sidebar-item i {
            margin-right: 8px;
        }
        .content-container {
            flex-grow: 1;
            background-color: #242424;
            padding: 24px;
            overflow-y: auto;
            margin-left: 16px;
            margin-right: 16px;
            border-radius: 8px;
        }
        .content-container::-webkit-scrollbar {
            width: 8px;
        }
        .content-container::-webkit-scrollbar-track {
            background: #1E1E1E;
        }
        .content-container::-webkit-scrollbar-thumb {
            background: #2f3136;
            border-radius: 4px;
        }
        .content-container h1 {
            font-size: 20px;
            font-weight: 600;
            color: #ffffff;
            margin: 0 0 24px;
        }
        .content-container h3 {
            font-size: 16px;
            font-weight: 600;
            color: #ffffff;
            margin: 24px 0 8px;
        }
        .content-container h5 {
            font-size: 14px;
            font-weight: 400;
            color: #b9bbbe;
            margin: 8px 0 16px;
        }
        .right-sidebar {
            background-color: #242424;
            width: 72px;
            padding: 16px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .tools__23e6b {
            width: 100%;
            display: flex;
            justify-content: center;
        }
        .container_c2b141 {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .closeButton_c2b141 {
            background-color: #2f3136;
            border-radius: 4px;
            padding: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .closeButton_c2b141:hover {
            background-color: #35383e;
        }
        .closeButton_c2b141 svg {
            width: 18px;
            height: 18px;
            fill: #b9bbbe;
        }
        .keybind_c2b141 {
            color: #b9bbbe;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .showcase-section {
            margin-bottom: 32px;
        }
        .showcase-row {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 16px;
            padding: 8px 0;
        }
        .showcase-image {
            width: 150px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
        }
        .showcase-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .showcase-image input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .showcase-info {
            flex-grow: 1;
        }
        .showcase-info h1 {
            font-size: 24px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
        }
        .modal-content {
            background-color: #2f3136;
            margin: 10% auto;
            padding: 24px;
            border-radius: 8px;
            width: 400px;
            max-width: 90%;
            color: #ffffff;
        }
        .modal-content h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .modal-content button {
            background-color: #3CB371;
            color: #ffffff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 16px;
            transition: background-color 0.2s ease;
        }
        .modal-content button:hover {
            background-color: #248A3D;
        }
        .close {
            color: #b9bbbe;
            float: right;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .close:hover {
            color: #ffffff;
        }
        .tip {
            display: flex;
            align-items: center;
            background-color: #2f3136;
            padding: 12px;
            border-radius: 4px;
            font-size: 14px;
            color: #b9bbbe;
            margin-top: 16px;
        }
        .tip svg {
            width: 20px;
            height: 20px;
            margin-right: 8px;
        }
        .tip a {
            color: #3CB371;
            text-decoration: none;
        }
        .tip a:hover {
            text-decoration: underline;
        }
        @media (max-width: 768px) {
            .app-container {
                padding: 16px;
            }
            .sidebar {
                position: absolute;
                width: 100%;
                height: 100vh;
                left: 0%;
                margin-bottom: 16px;
                border-radius: 8px;
            }
            #back {
                display: flex;
            }
            .content-container {
                padding-left: 6px !important;
                padding: 0;
                position: absolute;
                width: 100%;
                height: 100vh;
                left: 0%;
                margin-left: 0;
                margin-right: 0;
                border-radius: 8px;
                z-index: 5;
            }
            .right-sidebar {
                display: none;
            }
            .showcase-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .modal-content {
                width: 90%;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div id="movesidebar" class="sidebar">
            <a id="back" class="sidebar-item" href="/directmessages" style="width: 50%"><i data-lucide="arrow-left-to-line"></i> Anasayfaya Dön</a>
            <div class="category">Kullanıcı Ayarları</div>
            <a href="/settings" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item"><i data-lucide="user"></i> Hesabım</div>
            </a>
            <a href="/profile" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item active"><i data-lucide="user-pen"></i> Profilim</div>
            </a>
            <div class="sidebar-item"><i data-lucide="shield-check"></i> İçerik Kontrolü</div>
            <div class="sidebar-item"><i data-lucide="link-2"></i> Bağlantılar</div>
            <a href="/language_settings" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item"><i data-lucide="languages"></i> Dil</div>
            </a>
            <div class="category">Özelleştirme</div>
            <div class="sidebar-item"><i data-lucide="palette"></i> Temalar</div>
            <div class="sidebar-item"><i data-lucide="bell"></i> Bildirimler</div>
            <div class="sidebar-item"><i data-lucide="keyboard"></i> Tuş Atamaları</div>
            <div class="category">Erişebilirlik</div>
            <a href="/bildirimses" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item"><i data-lucide="mic"></i> Ses</div>
            </a>
            <div class="category">Gelişmiş</div>
            <div class="sidebar-item"><i data-lucide="circle-ellipsis"></i> Ekstra</div>
        </div>

        <!-- Content -->
        <div id="main-content" class="content-container">
            <div class="showcase-section">
                <h1>Vitrin Resmi</h1>
                <h5>Profilinizde görünecek vitrin resmini yükleyin veya değiştirin.</h5>
                <div class="showcase-row">
                    <div class="showcase-image">
                        <form id="showcaseImageForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="file" name="showcase_image" accept="image/*" onchange="uploadShowcaseImage()">
                            <?php if (!empty($showcase_image_url)): ?>
                                <img src="<?php echo htmlspecialchars($showcase_image_url); ?>" alt="Showcase image">
                            <?php else: ?>
                                <img src="/assets/default_showcase.png" alt="Default showcase image">
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="showcase-info">
                        <h1>Vitrin Resmi Yükle</h1>
                        <p style="color: #b9bbbe;">Maksimum dosya boyutu: 5MB. Desteklenen formatlar: PNG, JPEG.</p>
                    </div>
                </div>
            </div>
            <div class="tip">
                <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                </svg>
                <span>Profilinizi daha fazla özelleştirmek mi istiyorsunuz?</span>
                <a href="/profile">Profil ayarlarınıza gidin.</a>
            </div>
        </div>

        <!-- Right Sidebar -->
        <div class="right-sidebar">
            <div class="tools__23e6b">
                <div class="container_c2b141">
                    <div class="closeButton_c2b141" aria-label="Close" role="button" tabindex="0" onclick="closeSettings()">
                        <svg aria-hidden="true" role="img" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M17.3 18.7a1 1 0 0 0 1.4-1.4L13.42 12l5.3-5.3a1 1 0 0 0-1.42-1.4L12 10.58l-5.3-5.3a1 1 0 0 0-1.4 1.42L10.58 12l-5.3 5.3a1 1 0 1 0 1.42 1.4L12 13.42l5.3 5.3Z"></path>
                        </svg>
                    </div>
                    <div class="keybind_c2b141" aria-hidden="true">ESC</div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        lucide.createIcons();

        function closeSettings() {
            window.location.href = '/directmessages';
        }

        async function uploadShowcaseImage() {
            const form = document.getElementById('showcaseImageForm');
            const formData = new FormData(form);

            try {
                const response = await fetch('update_showcase_image_handler.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    alert('Vitrin resmi başarıyla güncellendi.');
                    location.reload();
                } else {
                    alert(result.error);
                }
            } catch (error) {
                alert('Bir hata oluştu. Lütfen tekrar deneyin.');
            }
        }

        // Mobil kaydırma
        const sidebar = document.getElementById("main-content");
        const leftPanel = document.getElementById("movesidebar");

        function enableSwipeSidebar() {
            const sidebarWidth = sidebar.offsetWidth;

            let isDragging = false;
            let startX = 0;
            let currentTranslate = sidebarWidth;
            let previousTranslate = sidebarWidth;

            sidebar.style.width = `${sidebarWidth}px`;
            sidebar.style.transform = `translateX(${sidebarWidth}px)`;
            sidebar.style.transition = 'transform 0.1s ease-out';

            function handleTouchStart(e) {
                startX = e.touches[0].clientX;
                isDragging = true;
                previousTranslate = currentTranslate;
                sidebar.style.transition = 'none';
            }

            function handleTouchMove(e) {
                if (!isDragging) return;

                const currentX = e.touches[0].clientX;
                const diff = currentX - startX;
                currentTranslate = previousTranslate + diff;

                if (currentTranslate < 0) currentTranslate = 0;
                if (currentTranslate > sidebarWidth) currentTranslate = sidebarWidth;

                sidebar.style.transform = `translateX(${currentTranslate}px)`;
            }

            function handleTouchEnd() {
                isDragging = false;
                sidebar.style.transition = 'transform 0.2s ease-out';

                const threshold = sidebarWidth * 0.5;

                if (currentTranslate < threshold) {
                    openSidebar();
                } else {
                    closeSidebar();
                }
            }

            function openSidebar() {
                currentTranslate = 0;
                sidebar.style.transform = 'translateX(0)';
            }

            function closeSidebar() {
                currentTranslate = sidebarWidth;
                sidebar.style.transform = `translateX(${sidebarWidth}px)`;
            }

            const listeners = [
                { el: leftPanel, type: "touchstart", fn: handleTouchStart },
                { el: leftPanel, type: "touchmove", fn: handleTouchMove },
                { el: leftPanel, type: "touchend", fn: handleTouchEnd },
                { el: sidebar, type: "touchstart", fn: handleTouchStart },
                { el: sidebar, type: "touchmove", fn: handleTouchMove },
                { el: sidebar, type: "touchend", fn: handleTouchEnd },
            ];

            listeners.forEach(({ el, type, fn }) => {
                el.addEventListener(type, fn, { passive: false });
            });
        }

        if (window.innerWidth <= 768) {
            enableSwipeSidebar();
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSettings();
            }
        });
    </script>

    <noscript>
        <div>
            <h1>JavaScript Gerekli</h1>
            <p>Bu uygulama, tam işlevsellik için JavaScript gerektirir. Lütfen tarayıcınızda JavaScript'i etkinleştirin.</p>
            <p>Daha fazla bilgi için <a href="/help">Yardım Sayfamızı</a> ziyaret edin.</p>
            <a href="/settings" target="_blank">Yeniden Yükle</a>
        </div>
    </noscript>
</body>
</html>