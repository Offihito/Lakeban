
<?php
session_start();
require 'db_connection.php';

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Kullanıcı profili için avatar ve e-posta sorgusu
$avatar_query = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
$avatar_query->execute([$_SESSION['user_id']]);
$avatar_result = $avatar_query->fetch();
$avatar_url = $avatar_result['avatar_url'] ?? '';

$user_query = $db->prepare("SELECT email FROM users WHERE id = ?");
$user_query->execute([$_SESSION['user_id']]);
$user_result = $user_query->fetch();
$email = $user_result['email'] ?? '';

// CSRF token oluştur
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
?>

<!DOCTYPE html>
<html lang="tr" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh;">
<head>
    <meta charset="UTF-8">
    <title>Ayarlar</title>
    <meta name="apple-mobile-web-app-title" content="Ayarlar">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">

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

    <!-- Theme Color -->
    <meta name="theme-color" content="#1E1E1E">

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
            font-size: var(--font-size);
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
        .user-section {
            margin-bottom: 32px;
        }
        .user-row {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 16px;
            padding: 8px 0;
        }
        .avatar {
            width: 75px;
            height: 75px;
            min-width: 75px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
        }
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .avatar input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .user-info {
            min-width: 0;
            flex-grow: 1;
            overflow: hidden;
        }
        .user-info h1 {
            font-size: 24px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-id {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #b9bbbe;
        }
        .user-id svg {
            margin-right: 4px;
        }
        .edit-profile-btn {
            background-color: #4f545c;
            border: none;
            border-radius: 4px;
            color: #ffffff;
            padding: 8px 16px;
            font-size: 14px;
            flex-shrink: 0;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s ease;
            margin-left: auto;
        }
        .edit-profile-btn:hover {
            background-color: #5a6069;
        }
        .setting-row {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            background-color: #2f3136;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .setting-row:hover {
            background-color: #35383e;
        }
        .setting-row svg {
            width: 24px;
            height: 24px;
            margin-right: 12px;
            fill: #b9bbbe;
        }
        .setting-content {
            flex-grow: 1;
        }
        .setting-content .title {
            font-size: 16px;
            font-weight: 600;
            color: #ffffff;
        }
        .setting-content .description {
            font-size: 14px;
            color: #b9bbbe;
        }
        .setting-content .description a {
            color: #3CB371;
            text-decoration: none;
        }
        .setting-content .description a:hover {
            text-decoration: underline;
        }
        .setting-action svg {
            width: 20px;
            height: 20px;
            fill: #b9bbbe;
        }
        .setting-row.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .setting-row.error svg {
            fill: var(--error);
        }
        hr {
            border: none;
            border-top: 1px solid #2f3136;
            margin: 24px 0;
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
        .modal-content input {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            background: #202225;
            border: 1px solid #141414;
            color: #ffffff;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .modal-content button {
            background-color: var(--hover);
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
                z-index: 5;
            }
            .content-container {
                position: absolute;
                width: 100%;
                padding-top: 8px;
                height: 100vh;
                left: 100%;
                margin-left: 0;
                margin-right: 0;
                border-radius: 8px;
            }
            .right-sidebar {
                display: none;
            }
            .user-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .edit-profile-btn {
                width: 100%;
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
        <div class="sidebar">
            <div class="category">Kullanıcı Ayarları</div>
            <div class="sidebar-item active"><i data-lucide="user"></i> Hesabım</div>
            <a href="/profile" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item"><i data-lucide="user-pen"></i> Profilim</div>
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
        <div id="movesidebar" class="content-container">
            <div class="user-section">
                <div class="user-row">
                    <div class="avatar">
                        <form id="avatarForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="file" name="avatar" accept="image/*" onchange="uploadAvatar()">
                            <?php if (!empty($avatar_url)): ?>
                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Profile avatar">
                            <?php else: ?>
                                <span class="avatar-initial"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></span>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="user-info">
                        <h1><?php echo htmlspecialchars($_SESSION['username']); ?></h1>
                        <div class="user-id">
                            <svg viewBox="0 0 24 24" height="16" width="16" fill="currentColor">
                                <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                            </svg>
                            ID: <?php echo htmlspecialchars($_SESSION['user_id']); ?>
                        </div>
                    </div>
                    <a href="/profile">
                        <button class="edit-profile-btn">Profili Düzenle</button>
                    </a>
                </div>
            </div>
            <div class="setting-row" onclick="openUsernameModal()">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10c1.466 0 2.961-.371 4.442-1.104l-.885-1.793C14.353 19.698 13.156 20 12 20c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8v1c0 .692-.313 2-1.5 2-1.396 0-1.494-1.819-1.5-2V8h-2v.025A4.954 4.954 0 0 0 12 7c-2.757 0-5 2.243-5 5s2.243 5 5 5c1.45 0 2.748-.631 3.662-1.621.524.89 1.408 1.621 2.838 1.621 2.273 0 3.5-2.061 3.5-4v-1c0-5.514-4.486-10-10-10zm0 13c-1.654 0-3-1.346-3-3s1.346-3 3-3 3 1.346 3 3-1.346 3-3 3z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title">Kullanıcı Adı</div>
                    <div class="description"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                </div>
                <div class="setting-action">
                    <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                        <path d="M8.707 19.707 18 10.414 13.586 6l-9.293 9.293a1.003 1.003 0 0 0-.263.464L3 21l5.242-1.03c.176-.044.337-.135.465-.263zM21 7.414a2 2 0 0 0 0-2.828L19.414 3a2 2 0 0 0-2.828 0L15 4.586 19.414 9 21 7.414z"></path>
                    </svg>
                </div>
            </div>
            <div class="setting-row" onclick="openEmailModal()">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                    <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4.7-8 8-8-8V6.297l8 8 8-8V8.7z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title">E-posta</div>
                    <div class="description">
                        <span id="emailDisplay">•••••••••••@••••••.•••</span>
                        <a href="#" onclick="event.stopPropagation(); showEmail();">Göster</a>
                    </div>
                </div>
                <div class="setting-action">
                    <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                        <path d="M8.707 19.707 18 10.414 13.586 6l-9.293 9.293a1.003 1.003 0 0 0-.263.464L3 21l5.242-1.03c.176-.044.337-.135.465-.263zM21 7.414a2 2 0 0 0 0-2.828L19.414 3a2 2 0 0 0-2.828 0L15 4.586 19.414 9 21 7.414z"></path>
                    </svg>
                </div>
            </div>
            <div class="setting-row" onclick="openPasswordModal()">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                    <path d="M3.433 17.325 3.079 19.8a1 1 0 0 0 1.131 1.131l2.475-.354C7.06 20.524 8 18 8 18s.472.405.665.466c.412.13.813-.274.948-.684L10 16.01s.577.292.786.335c.266.055.524-.109.707-.293a.988.988 0 0 0 .241-.391L12 14.01s.675.187.906.214c.263.03.519-.104.707-.293l1.138-1.137a5.502 5.502 0 0 0 5.581-1.338 5.507 5.507 0 0 0 0-7.778 5.507 5.507 0 0 0-7.778 0 5.5 5.5 0 0 0-1.338 5.581l-7.501 7.5a.994.994 0 0 0-.282.566zM18.504 5.506a2.919 2.919 0 0 1 0 4.122l-4.122-4.122a2.919 2.919 0 0 1 4.122 0z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title">Şifre</div>
                    <div class="description">•••••••••</div>
                </div>
                <div class="setting-action">
                    <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                        <path d="M8.707 19.707 18 10.414 13.586 6l-9.293 9.293a1.003 1.003 0 0 0-.263.464L3 21l5.242-1.03c.176-.044.337-.135.465-.263zM21 7.414a2 2 0 0 0 0-2.828L19.414 3a2 2 0 0 0-2.828 0L15 4.586 19.414 9 21 7.414z"></path>
                    </svg>
                </div>
            </div>
            <hr>
            <h3>Çift Faktörlü Doğrulama</h3>
            <h5>Hesabınızda 2FA'yı etkinleştirerek ekstra bir güvenlik katmanı ekleyin.</h5>
            <div class="setting-row disabled" title="Bu özellik şu anda kullanılamıyor.">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor" class="error">
                    <path d="M12 2C9.243 2 7 4.243 7 7v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7c0-2.757-2.243-5-5-5zM9 7c0-1.654 1.346-3 3-3s3 1.346 3 3v3H9V7zm4 10.723V20h-2v-2.277a1.993 1.993 0 0 1 .567-3.677A2.001 2.001 0 0 1 14 16a1.99 1.99 0 0 1-1 1.723z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title">Kimlik Doğrulayıcı Ekle</div>
                    <div class="description">Zaman tabanlı tek kullanımlık şifre ayarlayın.</div>
                </div>
                <div class="setting-action"></div>
            </div>
            <div class="tip">
                <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                </svg>
                <span>İki aşamalı kimlik doğrulama etkin değil!</span>
            </div>
            <hr>
            <h3>Hesap Yönetimi</h3>
            <h5>Hesabınızı istediğiniz zaman devre dışı bırakın veya silin.</h5>
            <div class="setting-row disabled" title="Bu özellik şu anda kullanılamıyor.">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor" class="error">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM4 12c0-1.846.634-3.542 1.688-4.897l11.209 11.209A7.946 7.946 0 0 1 12 20c-4.411 0-8-3.589-8-8zm14.312 4.897L7.103 5.688A7.948 7.948 0 0 1 12 4c4.411 0 8 3.589 8 8a7.954 7.954 0 0 1-1.688 4.897z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title">Hesabı Devre Dışı Bırak</div>
                    <div class="description">Bu özellik şu anda kullanılamıyor. Destek ekibiyle iletişime geçin.</div>
                </div>
                <div class="setting-action">
                    <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                        <path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z"></path>
                    </svg>
                </div>
            </div>
            <div class="setting-row" onclick="openDeleteAccountModal()">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                    <path d="M6 7H5v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7H6zm4 12H8v-9h2v9zm6 0h-2v-9h2v9zm.618-15L15 2H9L7.382 4H3v2h18V4z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title">Hesabı Sil</div>
                    <div class="description">Hesabınız ve tüm verileriniz (mesajlar ve arkadaş listesi dahil) silinmek için sıraya alınacak.</div>
                </div>
                <div class="setting-action">
                    <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                        <path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z"></path>
                    </svg>
                </div>
            </div>
            <div class="tip">
                <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                </svg>
                <span>Herkese açık profilinizi özelleştirmek mi istiyorsunuz?</span>
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

        <!-- Modals -->
        <div id="usernameModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeUsernameModal()">×</span>
                <h2>Kullanıcı Adını Değiştir</h2>
                <form id="usernameForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="text" name="new_username" placeholder="Yeni kullanıcı adı" required>
                    <input type="password" name="password" placeholder="Şifreniz" required>
                    <button type="submit">Değişiklikleri Kaydet</button>
                </form>
            </div>
        </div>
        <div id="passwordModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closePasswordModal()">×</span>
                <h2>Şifreyi Değiştir</h2>
                <form id="passwordForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="password" name="current-password" placeholder="Mevcut Şifre" required>
                    <input type="password" name="new-password" placeholder="Yeni Şifre" required>
                    <button type="submit">Değişiklikleri Kaydet</button>
                </form>
            </div>
        </div>
        <div id="emailModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeEmailModal()">×</span>
                <h2>E-postayı Değiştir</h2>
                <form id="emailForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="email" name="new_email" placeholder="Yeni e-posta" required>
                    <input type="password" name="password" placeholder="Şifreniz" required>
                    <button type="submit">Değişiklikleri Kaydet</button>
                </form>
            </div>
        </div>
        <div id="deleteAccountModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeDeleteAccountModal()">×</span>
                <h2>Hesabı Sil</h2>
                <p>Hesabınızı silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.</p>
                <form id="deleteAccountForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="password" name="password" placeholder="Şifreniz" required>
                    <button type="submit">Hesabı Sil</button>
                </form>
            </div>
        </div>

        <!-- JavaScript -->
        <script>
            lucide.createIcons();

            // Modal açma/kapama fonksiyonları
            function openPasswordModal() {
                document.getElementById('passwordModal').style.display = 'block';
            }
            function closePasswordModal() {
                document.getElementById('passwordModal').style.display = 'none';
            }
            function openUsernameModal() {
                document.getElementById('usernameModal').style.display = 'block';
            }
            function closeUsernameModal() {
                document.getElementById('usernameModal').style.display = 'none';
            }
            function openEmailModal() {
                document.getElementById('emailModal').style.display = 'block';
            }
            function closeEmailModal() {
                document.getElementById('emailModal').style.display = 'none';
            }
            function openDeleteAccountModal() {
                document.getElementById('deleteAccountModal').style.display = 'block';
            }
            function closeDeleteAccountModal() {
                document.getElementById('deleteAccountModal').style.display = 'none';
            }
            function closeSettings() {
                window.location.href = '/directmessages';
            }

            // Modal dışı tıklama ile kapatma
            window.onclick = function(event) {
                const modals = ['usernameModal', 'passwordModal', 'emailModal', 'deleteAccountModal'];
                modals.forEach(id => {
                    const modal = document.getElementById(id);
                    if (event.target == modal) {
                        modal.style.display = 'none';
                    }
                });
            }

            // ESC tuşu ile kapatma
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeSettings();
                }
            });

            // Form gönderimi için genel fonksiyon
            function submitForm(formId, url, successMessage, modalId) {
                const form = document.getElementById(formId);
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const formData = new FormData(form);
                    
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        
                        if (result.success) {
                            alert(successMessage);
                            document.getElementById(modalId).style.display = 'none';
                            if (modalId === 'deleteAccountModal') {
                                window.location.href = '/';
                            } else {
                                location.reload();
                            }
                        } else {
                            alert(result.error);
                        }
                    } catch (error) {
                        alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                    }
                });
            }

            // E-posta gösterme
            async function showEmail() {
                const password = prompt('E-postanızı görmek için şifrenizi girin:');
                if (!password) return;

                try {
                    const response = await fetch('show_email.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `password=${encodeURIComponent(password)}`
                    });
                    const result = await response.json();

                    if (result.email) {
                        document.getElementById('emailDisplay').textContent = result.email;
                    } else {
                        alert(result.error);
                    }
                } catch (error) {
                    alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                }
            }

            // Profil resmi yükleme
            async function uploadAvatar() {
                const form = document.getElementById('avatarForm');
                const formData = new FormData(form);
                
                try {
                    const response = await fetch('update_avatar.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success) {
                        alert('Profil resmi güncellendi.');
                        location.reload();
                    } else {
                        alert(result.error);
                    }
                } catch (error) {
                    alert('Bir hata oluştu.');
                }
            }

            // DOM yüklendiğinde
            document.addEventListener('DOMContentLoaded', function() {
                // Tema seçimi
                const themeSelector = document.querySelector('.action-button-select');
                const savedTheme = localStorage.getItem('selectedTheme') || 'root';
                document.body.classList.add(savedTheme);
                if (themeSelector) {
                    themeSelector.value = savedTheme;
                    themeSelector.addEventListener('change', function() {
                        document.body.classList.remove('root', 'red-theme', 'blue-theme');
                        const selectedTheme = this.value;
                        document.body.classList.add(selectedTheme);
                        localStorage.setItem('selectedTheme', selectedTheme);
                    });
                }

                // Sidebar item tıklama
                const sidebarItems = document.querySelectorAll('.sidebar-item');
                sidebarItems.forEach(item => {
                    item.addEventListener('click', () => {
                        sidebarItems.forEach(i => i.classList.remove('active'));
                        item.classList.add('active');
                    });
                });

                // Kilitli ayarlar için tıklama engelleme
                document.querySelectorAll('.setting-row.disabled').forEach(row => {
                    row.style.cursor = 'not-allowed';
                    row.addEventListener('click', (e) => {
                        e.preventDefault();
                        alert('Bu özellik şu anda kullanılamıyor. Lütfen destek ekibiyle iletişime geçin.');
                    });
                });

                // Formları bağla
                submitForm('usernameForm', 'update_username.php', 'Kullanıcı adı başarıyla güncellendi!', 'usernameModal');
                submitForm('emailForm', 'update_email.php', 'E-posta güncellendi. Doğrulama e-postası gönderildi!', 'emailModal');
                submitForm('passwordForm', 'update_password.php', 'Şifre başarıyla güncellendi!', 'passwordModal');
                submitForm('deleteAccountForm', 'delete_account.php', 'Hesabınız silindi. Ana sayfaya yönlendiriliyorsunuz...', 'deleteAccountModal');
            });
            
// Mobil Kaydırma hareketi
const movesidebar = document.getElementById("movesidebar");

let startX, endX; // Hareket başlangıç ve bitiş noktaları

// Ekran genişliği 768px veya daha küçükse kaydırma işlemi etkinleştirilsin
if (window.innerWidth <= 768) {
  // Dokunma başlangıcını algıla
  document.addEventListener("touchstart", (e) => {
    startX = e.touches[0].clientX;
  });

  // Dokunma bitişini algıla ve hareketi kontrol et
  document.addEventListener("touchend", (e) => {
    endX = e.changedTouches[0].clientX;
    handleSwipe();
  });

  // Hareketi işleyen fonksiyon
  function handleSwipe() {
    const deltaX = startX - endX;

    // Minimum hassasiyet
    if (Math.abs(deltaX) < 100) return; // 100px altında hiçbir işlem yapma

    // Sağdan sola kaydırma: Sidebar kapanıyor
    if (deltaX > 100) {
      closeSidebar();
    }
    // Soldan sağa kaydırma: Sidebar açılıyor
    else if (deltaX < -100) {
      openSidebar();
    }
  }

  // Sidebar’ı açan fonksiyon
  function openSidebar() {
    movesidebar.style.left = "0"; // Sağdan sıfıra hareket
  }

  // Sidebar’ı kapatan fonksiyon
  function closeSidebar() {
    movesidebar.style.left = "-100%"; // Sağdan kaybolma
  }
}
        </script>

        <noscript>
            <div>
                <h1>JavaScript Gerekli</h1>
                <p>Bu uygulama, tam işlevsellik için JavaScript gerektirir. Lütfen tarayıcınızda JavaScript'i etkinleştirin.</p>
                <p>Daha fazla bilgi için <a href="/help">Yardım Sayfamızı</a> ziyaret edin.</p>
                <a href="settings" target="_blank">Yeniden Yükle</a>
            </div>
        </noscript>
    </body>
</html>
