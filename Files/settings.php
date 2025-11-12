<?php
session_start();
require 'db_connection.php';

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Dil ayarları
$default_lang = 'tr';
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}
$lang = $_SESSION['lang'];

// Dil dosyasını yükle
$langFile = __DIR__ . '/languages/' . $lang . '.json';
if (file_exists($langFile)) {
    $translations = json_decode(file_get_contents($langFile), true);
} else {
    $translations = [];
}

// Tema ayarları için varsayılan değerler
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

// Kullanıcı verilerini (e-posta, 2FA durumu, tema) veritabanından yükle
$email = '';
$two_factor_enabled = 0;
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

try {
    $user_query = $db->prepare("SELECT email, two_factor_enabled, theme, custom_color, secondary_color FROM users WHERE id = ?");
    $user_query->execute([$_SESSION['user_id']]);
    $user_result = $user_query->fetch(PDO::FETCH_ASSOC);

    if ($user_result) {
        $email = $user_result['email'] ?? '';
        $two_factor_enabled = $user_result['two_factor_enabled'] ?? 0;
        $currentTheme = $user_result['theme'] ?? $defaultTheme;
        $currentCustomColor = $user_result['custom_color'] ?? $defaultCustomColor;
        $currentSecondaryColor = $user_result['secondary_color'] ?? $defaultSecondaryColor;
    }
} catch (PDOException $e) {
    // Hata durumunda loglama veya kullanıcıya mesaj gösterme işlemi yapılabilir.
    // Şimdilik varsayılan tema ile devam edilecek.
}
$isLakebiumUser = false;
try {
    $lakebiumStmt = $db->prepare("SELECT status FROM lakebium WHERE user_id = ? AND status = 'active'");
    $lakebiumStmt->execute([$_SESSION['user_id']]);
    $isLakebiumUser = $lakebiumStmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Lakebium abonelik durumu alınırken bir hata oluştu: ' . $e->getMessage();
}

// Kullanıcı profili için avatar sorgusu
$avatar_query = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
$avatar_query->execute([$_SESSION['user_id']]);
$avatar_result = $avatar_query->fetch();
$avatar_url = $avatar_result['avatar_url'] ?? '';

// CSRF token oluştur
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
?>
<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh; --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>; --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;">
<head>
    <meta charset="UTF-8">
    <title><?php echo $translations['settings']['title'] ?? 'Ayarlar'; ?></title>
    <meta name="apple-mobile-web-app-title" content="<?php echo $translations['settings']['title'] ?? 'Ayarlar'; ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">

    <link rel="apple-touch-icon" href="/assets/apple-touch.png">
    <link rel="icon" type="image/png" href="/assets/logo_round.png">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
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

    <meta name="theme-color" content="#1E1E1E">

    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
     <style>
        /* === GENEL DEĞİŞKENLER VE STİLLER === */
        :root {
            --accent-color: #3CB371;
            --font-size: 16px;
            --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>;
            --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;
        }
        
        /* === AYDINLIK TEMA === */
        .light-theme body { background-color: #F2F3F5; color: #2E3338; }
        .light-theme .sidebar, .light-theme .content-container, .light-theme .right-sidebar { background-color: #FFFFFF; }
        .light-theme .sidebar-item { color: #4F5660; }
        .light-theme .sidebar-item:hover, .light-theme .sidebar-item.active { background-color: #e3e5e8; color: #060607; }
        .light-theme .content-container h1, .light-theme .content-container h3, .light-theme .setting-content .title, .light-theme .user-info h1 { color: #060607; }
        .light-theme .content-container h5, .light-theme .category, .light-theme .setting-content .description, .light-theme .user-id { color: #4F5660; }
        .light-theme hr { border-top: 1px solid #e3e5e8; }
        .light-theme .edit-profile-btn { background-color: #4f545c; color: #ffffff; }
        .light-theme .edit-profile-btn:hover { background-color: #5a6069; }
        .light-theme .setting-row, .light-theme .tip, .light-theme .closeButton_c2b141 { background-color: #F8F9FA; }
        .light-theme .setting-row:hover { background-color: #e3e5e8; }
        .light-theme .modal-content { background-color: #FFFFFF; }
        .light-theme .modal-content input { background-color: #F2F3F5; border-color: #e3e5e8; color: #060607; }
        .light-theme .slider { background-color: #cccccc; }

        /* === KOYU TEMA === */
        .dark-theme body { background-color: #1E1E1E; color: #ffffff; }
        .dark-theme .sidebar, .dark-theme .content-container, .dark-theme .right-sidebar { background-color: #242424; }
        .dark-theme .sidebar-item { color: #b9bbbe; }
        .dark-theme .sidebar-item:hover, .dark-theme .sidebar-item.active { background-color: #2f3136; color: #ffffff; }
        .dark-theme .content-container h1, .dark-theme .content-container h3, .dark-theme .setting-content .title, .dark-theme .user-info h1 { color: #ffffff; }
        .dark-theme .content-container h5, .dark-theme .category, .dark-theme .setting-content .description, .dark-theme .user-id { color: #b9bbbe; }
        .dark-theme hr { border-top: 1px solid #2f3136; }
        .dark-theme .edit-profile-btn { background-color: #4f545c; }
        .dark-theme .edit-profile-btn:hover { background-color: #5a6069; }
        .dark-theme .setting-row, .dark-theme .tip, .dark-theme .closeButton_c2b141 { background-color: #2f3136; }
        .dark-theme .setting-row:hover { background-color: #35383e; }
        .dark-theme .modal-content { background-color: #2f3136; }
        .dark-theme .modal-content input { background: #202225; border: 1px solid #141414; color: #ffffff; }
        .dark-theme .slider { background-color: #4f545c; }

        /* === ÖZEL TEMA === */
        .custom-theme body { background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%); color: #ffffff; }
        .custom-theme .sidebar, .custom-theme .content-container, .custom-theme .right-sidebar { background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%); }
        .custom-theme .sidebar-item { color: color-mix(in srgb, var(--custom-background-color) 40%, white); }
        .custom-theme .sidebar-item:hover, .custom-theme .sidebar-item.active { background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%); color: #ffffff; }
        .custom-theme .content-container h1, .custom-theme .content-container h3, .custom-theme .setting-content .title, .custom-theme .user-info h1 { color: #ffffff; }
        .custom-theme .content-container h5, .custom-theme .category, .custom-theme .setting-content .description, .custom-theme .user-id { color: color-mix(in srgb, var(--custom-background-color) 40%, white); }
        .custom-theme hr { border-top: 1px solid color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); }
        .custom-theme .edit-profile-btn { background-color: color-mix(in srgb, var(--custom-secondary-color) 50%, #4f545c 50%); }
        .custom-theme .edit-profile-btn:hover { background-color: color-mix(in srgb, var(--custom-secondary-color) 60%, #5a6069 40%); }
        .custom-theme .setting-row, .custom-theme .tip, .custom-theme .closeButton_c2b141 { background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); }
        .custom-theme .setting-row:hover { background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%); }
        .custom-theme .modal-content { background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); }
        .custom-theme .modal-content input { background-color: var(--custom-background-color); border-color: var(--custom-secondary-color); }
        .custom-theme .modal-content button { background-color: var(--custom-secondary-color); }
        .custom-theme .modal-content button:hover { background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%); }
        .custom-theme input:checked + .slider { background-color: var(--custom-secondary-color); }

        noscript { background: #242424; color: white; position: fixed; top: 0; left: 0; width: 100vw; min-height: 100vh; display: flex; align-items: center; justify-content: center; user-select: none; }
        noscript > div { padding: 12px; display: flex; font-family: Arial, sans-serif; flex-direction: column; justify-content: center; text-align: center; }
        noscript > div > h1 { margin: 8px 0; text-transform: uppercase; font-size: 20px; font-weight: 700; }
        noscript > div > p { margin: 4px 0; font-size: 14px; }
        noscript > div > a { align-self: center; margin-top: 20px; padding: 8px 10px; font-size: 14px; width: 80px; font-weight: 600; background: #ed5151; border-radius: 4px; text-decoration: none; color: white; transition: background-color 0.2s; }
        noscript > div > a:hover { background-color: #cf4848; }
        noscript > div > a:active { background-color: #b64141; }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
            font-size: var(--font-size);
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .app-container { display: flex; max-width: 1400px; margin: 0 auto; height: var(--app-height); padding: 24px; box-sizing: border-box; }
        .sidebar, .content-container, .right-sidebar { transition: background-color 0.3s ease; }
        .sidebar { width: 260px; padding: 16px 8px; overflow-y: auto; border-radius: 8px; flex-shrink: 0; }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: #1E1E1E; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--accent-color); border-radius: 2px; }
        .category { font-size: 12px; font-weight: 600; text-transform: uppercase; padding: 8px 16px; margin: 8px 0; }
        .sidebar-item { display: flex; align-items: center; padding: 8px 16px; margin: 2px 8px; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease, color 0.2s ease; }
        .sidebar-item i { margin-right: 8px; }
        .content-container { flex-grow: 1; padding: 24px; overflow-y: auto; margin-left: 16px; margin-right: 16px; border-radius: 8px; }
        .content-container::-webkit-scrollbar { width: 8px; }
        .content-container::-webkit-scrollbar-track { background: #1E1E1E; }
        .content-container::-webkit-scrollbar-thumb { background: #2f3136; border-radius: 4px; }
        .content-container h1 { font-size: 20px; font-weight: 600; margin: 0 0 24px; }
        .content-container h3 { font-size: 16px; font-weight: 600; margin: 24px 0 8px; }
        .content-container h5 { font-size: 14px; font-weight: 400; margin: 8px 0 16px; }
        .right-sidebar { width: 72px; padding: 16px 8px; display: flex; flex-direction: column; align-items: center; border-radius: 8px; flex-shrink: 0; }
        .tools__23e6b { width: 100%; display: flex; justify-content: center; }
        .container_c2b141 { display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .closeButton_c2b141 { border-radius: 4px; padding: 8px; cursor: pointer; transition: background-color 0.2s ease; }
        .closeButton_c2b141:hover { background-color: #35383e; }
        .closeButton_c2b141 svg { width: 18px; height: 18px; fill: #b9bbbe; }
        .keybind_c2b141 { color: #b9bbbe; font-size: 12px; font-weight: 500; text-transform: uppercase; }
        .user-section { margin-bottom: 32px; }
        .user-row { display: flex; align-items: center; flex-wrap: nowrap; gap: 16px; padding: 8px 0; }
        .avatar { width: 75px; height: 75px; min-width: 75px; border-radius: 50%; overflow: hidden; flex-shrink: 0; position: relative; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar input[type="file"] { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .user-info { min-width: 0; flex-grow: 1; overflow: hidden; }
        .user-info h1 { font-size: 24px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-id { display: flex; align-items: center; font-size: 14px; }
        .user-id svg { margin-right: 4px; }
        .edit-profile-btn { border: none; border-radius: 4px; padding: 8px 16px; font-size: 14px; flex-shrink: 0; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; margin-left: auto; }
        .setting-row { display: flex; align-items: center; padding: 12px; margin-bottom: 8px; border-radius: 4px; cursor: pointer; transition: background-color 0.2s ease; }
        .setting-row svg { width: 24px; height: 24px; margin-right: 12px; fill: #b9bbbe; }
        .setting-content { flex-grow: 1; }
        .setting-content .title { font-size: 16px; font-weight: 600; }
        .setting-content .description { font-size: 14px; }
        .setting-content .description a { color: var(--accent-color); text-decoration: none; }
        .setting-content .description a:hover { text-decoration: underline; }
        .setting-action svg { width: 20px; height: 20px; fill: #b9bbbe; }
        .setting-row.disabled { opacity: 0.5; cursor: not-allowed; }
        .setting-row.error svg { fill: #ed5151; }
        hr { border: none; margin: 24px 0; }
        .tip { display: flex; align-items: center; padding: 12px; border-radius: 4px; font-size: 14px; margin-top: 16px; }
        .tip svg { width: 20px; height: 20px; margin-right: 8px; }
        .tip a { color: var(--accent-color); text-decoration: none; margin-left: 5px; }
        .tip a:hover { text-decoration: underline; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.8); }
        .modal-content { margin: 10% auto; padding: 24px; border-radius: 8px; width: 400px; max-width: 90%; }
        .modal-content h2 { font-size: 18px; font-weight: 600; margin-bottom: 16px; }
        .modal-content input { width: 100%; padding: 10px; margin: 8px 0; border-radius: 4px; box-sizing: border-box; }
        .modal-content button { color: #ffffff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; margin-top: 16px; transition: background-color 0.2s ease; background-color: var(--accent-color); }
        .modal-content button:hover { background-color: #2e9b5e; }
        .close { color: #b9bbbe; float: right; font-size: 24px; cursor: pointer; transition: color 0.2s ease; }
        .close:hover { color: #ffffff; }
        #back { display: none; }
        @media (max-width: 768px) {
            .app-container { padding: 16px; }
            .sidebar { position: absolute; width: 100%; height: 100vh; left: 0%; margin-bottom: 16px; border-radius: 8px; }
            #back { display: flex; }
            .content-container { padding-left: 6px !important; padding: 0; position: absolute; width: 100%; height: 100vh; left: 0%; margin-left: 0; margin-right: 0; border-radius: 8px; z-index: 5; }
            .right-sidebar { display: none; }
            .user-row { flex-direction: column; align-items: flex-start; }
            .edit-profile-btn { width: 100%; }
            .modal-content { width: 90%; }
        }
         .switch { position: relative; display: inline-block; width: 50px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--accent-color); }
        input:checked + .slider:before { transform: translateX(26px); }
     </style>
</head>
<body>
<div class="app-container">
    <div id="movesidebar" class="sidebar">
        <a id="back" class="sidebar-item" href="https://lakeban.com/directmessages" style="width: 50%">
            <i data-lucide="arrow-left-to-line"></i> 
            <?php echo $translations['settings']['sidebar']['back_to_home'] ?? 'Anasayfaya Dön'; ?>
        </a>
        <div class="category"><?php echo $translations['settings']['categories']['user'] ?? 'Kullanıcı Ayarları'; ?></div>
        <div class="sidebar-item active" data-page="settings" onclick="loadPage('settings', null)"><i data-lucide="user"></i> <?php echo $translations['settings']['sidebar']['account'] ?? 'Hesabım'; ?></div>
        <a href="/profile" style="text-decoration: none; color: inherit;">
            <div class="sidebar-item" data-page="profile"><i data-lucide="user-pen"></i> <?php echo $translations['settings']['sidebar']['profile'] ?? 'Profilim'; ?></div>
        </a>
        <div class="sidebar-item" data-page="content-control" onclick="loadPage('content-control', 'content_control.php')"><i data-lucide="shield-check"></i> <?php echo $translations['settings']['sidebar']['content_control'] ?? 'İçerik Kontrolü'; ?></div>
        <div class="sidebar-item" data-page="connections" onclick="loadPage('connections', 'connections.php')"><i data-lucide="link-2"></i> <?php echo $translations['settings']['sidebar']['connections'] ?? 'Bağlantılar'; ?></div>
        <div class="sidebar-item" data-page="language" onclick="loadPage('language', 'language_settings_content.php')"><i data-lucide="languages"></i> <?php echo $translations['settings']['sidebar']['language'] ?? 'Dil'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['customization'] ?? 'Özelleştirme'; ?></div>
        <a href="/themes" style="text-decoration: none; color: inherit;">
            <div class="sidebar-item" data-page="themes"><i data-lucide="palette"></i> <?php echo $translations['settings']['sidebar']['themes'] ?? 'Temalar'; ?></div>
        </a>
        <?php if ($isLakebiumUser): ?>
            <a href="/frame_settings" style="text-decoration: none; color: inherit;">
                <div class="sidebar-item" data-page="avatar-frame" onclick="('avatar-frame', 'avatar_frame_content.php')">
                    <i data-lucide="frame"></i> <?php echo $translations['settings']['sidebar']['avatar_frame'] ?? 'Avatar Çerçevesi'; ?>
                </div>
            </a>
        <?php else: ?>
            <!-- Hata ayıklama için geçici olarak görünür -->
            <div class="sidebar-item" style="color: #ed5151; font-size: 12px; padding: 8px 16px;">
                <i data-lucide="alert-triangle"></i> Avatar Çerçevesi (Lakebium gerekli): <?php echo htmlspecialchars($lakebiumError); ?>
            </div>
        <?php endif; ?>
         <a href="/bildirimses" style="text-decoration: none; color: inherit;">
        <div class="sidebar-item" data-page="notifications" onclick="loadPage('notifications', 'bildirimses_content.php')"><i data-lucide="bell"></i> <?php echo $translations['settings']['sidebar']['notifications'] ?? 'Bildirimler'; ?></div>
         </a>
        <div class="sidebar-item" data-page="keybinds" onclick="loadPage('keybinds', 'keybinds.php')"><i data-lucide="keyboard"></i> <?php echo $translations['settings']['sidebar']['keybinds'] ?? 'Tuş Atamaları'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['accessibility'] ?? 'Erişebilirlik'; ?></div>
        <div class="sidebar-item" data-page="voice" onclick="loadPage('voice', 'voice.php')"><i data-lucide="mic"></i> <?php echo $translations['settings']['sidebar']['voice'] ?? 'Ses'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['advanced'] ?? 'Gelişmiş'; ?></div>
        <div class="sidebar-item" data-page="extra" onclick="loadPage('extra', 'extra.php')"><i data-lucide="circle-ellipsis"></i> <?php echo $translations['settings']['sidebar']['extra'] ?? 'Ekstra'; ?></div>
    </div>

        <div id="main-content" class="content-container">
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
                        <button class="edit-profile-btn"><?php echo $translations['settings']['user_section']['edit_profile'] ?? 'Profili Düzenle'; ?></button>
                    </a>
                </div>
            </div>
            <div class="setting-row" onclick="openUsernameModal()">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10c1.466 0 2.961-.371 4.442-1.104l-.885-1.793C14.353 19.698 13.156 20 12 20c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8v1c0 .692-.313 2-1.5 2-1.396 0-1.494-1.819-1.5-2V8h-2v.025A4.954 4.954 0 0 0 12 7c-2.757 0-5 2.243-5 5s2.243 5 5 5c1.45 0 2.748-.631 3.662-1.621.524.89 1.408 1.621 2.838 1.621 2.273 0 3.5-2.061 3.5-4v-1c0-5.514-4.486-10-10-10zm0 13c-1.654 0-3-1.346-3-3s1.346-3 3-3 3 1.346 3 3-1.346 3-3 3z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title"><?php echo $translations['settings']['setting_rows']['username'] ?? 'Kullanıcı Adı'; ?></div>
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
                    <div class="title"><?php echo $translations['settings']['setting_rows']['email'] ?? 'E-posta'; ?></div>
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
                    <div class="title"><?php echo $translations['settings']['setting_rows']['password'] ?? 'Şifre'; ?></div>
                    <div class="description">•••••••••</div>
                </div>
                <div class="setting-action">
                    <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                        <path d="M8.707 19.707 18 10.414 13.586 6l-9.293 9.293a1.003 1.003 0 0 0-.263.464L3 21l5.242-1.03c.176-.044.337-.135.465-.263zM21 7.414a2 2 0 0 0 0-2.828L19.414 3a2 2 0 0 0-2.828 0L15 4.586 19.414 9 21 7.414z"></path>
                    </svg>
                </div>
            </div>
            <hr>
            <h3><?php echo $translations['settings']['two_factor_auth'] ?? 'Çift Faktörlü Doğrulama'; ?></h3>
            <h5><?php echo $translations['settings']['account_management']['title'] ?? 'Hesabınızda 2FA\'yı etkinleştirerek ekstra bir güvenlik katmanı ekleyin.'; ?></h5>
            
            <div class="setting-row" id="twoFactorRow">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor">
                    <path d="M12 2C9.243 2 7 4.243 7 7v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7c0-2.757-2.243-5-5-5zM9 7c0-1.654 1.346-3 3-3s3 1.346 3 3v3H9V7zm4 10.723V20h-2v-2.277a1.993 1.993 0 0 1 .567-3.677A2.001 2.001 0 0 1 14 16a1.99 1.99 0 0 1-1 1.723z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title"><?php echo $translations['settings']['two_factor_auth'] ?? 'İki Aşamalı Doğrulama'; ?></div>
                    <div class="description" id="2faStatusText">
                        <?php echo $two_factor_enabled ? 
                            ($translations['settings']['two_factor_status']['enabled'] ?? 'Etkin') : 
                            ($translations['settings']['two_factor_status']['disabled'] ?? 'Devre dışı'); ?>
                    </div>
                </div>
                <div class="setting-action">
                    <label class="switch">
                        <input type="checkbox" id="2faToggle" <?php echo $two_factor_enabled ? 'checked' : ''; ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>
            
            <div class="tip" id="2faTip" style="<?php echo $two_factor_enabled ? 'display:none;' : 'display:flex;'; ?>">
                <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                </svg>
                <span><?php echo $translations['settings']['tips']['2fa_disabled'] ?? 'İki aşamalı kimlik doğrulama etkin değil!'; ?></span>
            </div>
            
            <div class="tip" id="2faActiveTip" style="<?php echo !$two_factor_enabled ? 'display:none;' : 'display:flex;'; ?>">
                <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm-1 15h2v-6h-2v6zm0-8h2V7h-2v2z"></path>
                </svg>
                <span><?php echo $translations['settings']['tips']['2fa_enabled'] ?? 'İki aşamalı doğrulama etkin. Girişlerde e-postanıza kod gönderilecektir.'; ?></span>
            </div>
            
            <hr>
            <h3><?php echo $translations['settings']['account_management']['title'] ?? 'Hesap Yönetimi'; ?></h3>
            <h5><?php echo $translations['settings']['account_management']['title'] ?? 'Hesabınızı istediğiniz zaman devre dışı bırakın veya silin.'; ?></h5>
            <div class="setting-row disabled" title="Bu özellik şu anda kullanılamıyor.">
                <svg viewBox="0 0 24 24" height="24" width="24" fill="currentColor" class="error">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM4 12c0-1.846.634-3.542 1.688-4.897l11.209 11.209A7.946 7.946 0 0 1 12 20c-4.411 0-8-3.589-8-8zm14.312 4.897L7.103 5.688A7.948 7.948 0 0 1 12 4c4.411 0 8 3.589 8 8a7.954 7.954 0 0 1-1.688 4.897z"></path>
                </svg>
                <div class="setting-content">
                    <div class="title"><?php echo $translations['settings']['account_management']['disable_account'] ?? 'Hesabı Devre Dışı Bırak'; ?></div>
                    <div class="description"><?php echo $translations['settings']['account_management']['disable_account'] ?? 'Bu özellik şu anda kullanılamıyor. Destek ekibiyle iletişime geçin.'; ?></div>
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
                    <div class="title"><?php echo $translations['settings']['account_management']['delete_account'] ?? 'Hesabı Sil'; ?></div>
                    <div class="description"><?php echo $translations['settings']['account_management']['delete_warning'] ?? 'Hesabınız ve tüm verileriniz (mesajlar ve arkadaş listesi dahil) silinmek için sıraya alınacak.'; ?></div>
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
                <span><?php echo $translations['settings']['account_management']['customize_profile_tip'] ?? 'Herkese açık profilinizi özelleştirmek mi istiyorsunuz?'; ?></span>
                <a href="/profile"><?php echo $translations['settings']['account_management']['go_to_profile_settings'] ?? 'Profil ayarlarınıza gidin.'; ?></a>
            </div>
        </div>

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

        <div id="usernameModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeUsernameModal()">×</span>
                <h2><?php echo $translations['settings']['modals']['change_username'] ?? 'Kullanıcı Adını Değiştir'; ?></h2>
                <form id="usernameForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="text" name="new_username" placeholder="<?php echo $translations['settings']['modals']['placeholders']['new_username'] ?? 'Yeni kullanıcı adı'; ?>" required>
                    <input type="password" name="password" placeholder="<?php echo $translations['settings']['modals']['placeholders']['current_password'] ?? 'Şifreniz'; ?>" required>
                    <button type="submit"><?php echo $translations['settings']['modals']['buttons']['save_changes'] ?? 'Değişiklikleri Kaydet'; ?></button>
                </form>
            </div>
        </div>
        <div id="passwordModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closePasswordModal()">×</span>
                <h2><?php echo $translations['settings']['modals']['change_password'] ?? 'Şifreyi Değiştir'; ?></h2>
                <form id="passwordForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="password" name="current-password" placeholder="<?php echo $translations['settings']['modals']['placeholders']['current_password'] ?? 'Mevcut Şifre'; ?>" required>
                    <input type="password" name="new-password" placeholder="<?php echo $translations['settings']['modals']['placeholders']['new_password'] ?? 'Yeni Şifre'; ?>" required>
                    <button type="submit"><?php echo $translations['settings']['modals']['buttons']['save_changes'] ?? 'Değişiklikleri Kaydet'; ?></button>
                </form>
            </div>
        </div>
        <div id="emailModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeEmailModal()">×</span>
                <h2><?php echo $translations['settings']['modals']['change_email'] ?? 'E-postayı Değiştir'; ?></h2>
                <form id="emailForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="email" name="new_email" placeholder="<?php echo $translations['settings']['modals']['placeholders']['new_email'] ?? 'Yeni e-posta'; ?>" required>
                    <input type="password" name="password" placeholder="<?php echo $translations['settings']['modals']['placeholders']['current_password'] ?? 'Şifreniz'; ?>" required>
                    <button type="submit"><?php echo $translations['settings']['modals']['buttons']['save_changes'] ?? 'Değişiklikleri Kaydet'; ?></button>
                </form>
            </div>
        </div>
        <div id="deleteAccountModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeDeleteAccountModal()">×</span>
                <h2><?php echo $translations['settings']['modals']['delete_account'] ?? 'Hesabı Sil'; ?></h2>
                <p><?php echo $translations['settings']['modals']['delete_account_confirmation'] ?? 'Hesabınızı silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.'; ?></p>
                <form id="deleteAccountForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="password" name="password" placeholder="<?php echo $translations['settings']['modals']['placeholders']['current_password'] ?? 'Şifreniz'; ?>" required>
                    <button type="submit"><?php echo $translations['settings']['modals']['buttons']['delete_account'] ?? 'Hesabı Sil'; ?></button>
                </form>
            </div>
        </div>
        
        <div id="activate2FAModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeActivate2FAModal()">×</span>
                <h2><?php echo $translations['settings']['modals']['activate_2fa'] ?? 'İki Aşamalı Doğrulamayı Etkinleştir'; ?></h2>
                <p><?php echo $translations['settings']['modals']['activate_2fa'] ?? 'E-posta adresinize bir doğrulama kodu gönderildi. Lütfen kodu aşağıya girin.'; ?></p>
                <form id="activate2FAForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="mb-4">
                        <input type="text" name="code" class="form-input w-full text-center" placeholder="<?php echo $translations['settings']['modals']['placeholders']['verification_code'] ?? '6 haneli kod'; ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-full"><?php echo $translations['settings']['modals']['buttons']['activate'] ?? 'Etkinleştir'; ?></button>
                </form>
            </div>
        </div>

        <div id="deactivate2FAModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeDeactivate2FAModal()">×</span>
                <h2><?php echo $translations['settings']['modals']['deactivate_2fa'] ?? 'İki Aşamalı Doğrulamayı Devre Dışı Bırak'; ?></h2>
                <p><?php echo $translations['settings']['modals']['deactivate_2fa'] ?? 'İki aşamalı doğrulamayı devre dışı bırakmak istediğinize emin misiniz?'; ?></p>
                <form id="deactivate2FAForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-primary"><?php echo $translations['settings']['modals']['buttons']['deactivate'] ?? 'Devre Dışı Bırak'; ?></button>
                    <button type="button" class="btn" onclick="closeDeactivate2FAModal()" style="background-color: #4f545c; margin-top: 10px;"><?php echo $translations['settings']['modals']['buttons']['cancel'] ?? 'İptal'; ?></button>
                </form>
            </div>
        </div>

        <script>
            lucide.createIcons();

            // Modal açma/kapama fonksiyonları
            function openPasswordModal() { document.getElementById('passwordModal').style.display = 'block'; }
            function closePasswordModal() { document.getElementById('passwordModal').style.display = 'none'; }
            function openUsernameModal() { document.getElementById('usernameModal').style.display = 'block'; }
            function closeUsernameModal() { document.getElementById('usernameModal').style.display = 'none'; }
            function openEmailModal() { document.getElementById('emailModal').style.display = 'block'; }
            function closeEmailModal() { document.getElementById('emailModal').style.display = 'none'; }
            function openDeleteAccountModal() { document.getElementById('deleteAccountModal').style.display = 'block'; }
            function closeDeleteAccountModal() { document.getElementById('deleteAccountModal').style.display = 'none'; }
            function closeSettings() { window.location.href = '/directmessages'; }
            
            // Yeni 2FA modal fonksiyonları
            function openActivate2FAModal() { document.getElementById('activate2FAModal').style.display = 'block'; send2FACode(); }
            function closeActivate2FAModal() { document.getElementById('activate2FAModal').style.display = 'none'; }
            function openDeactivate2FAModal() { document.getElementById('deactivate2FAModal').style.display = 'block'; }
            function closeDeactivate2FAModal() { document.getElementById('deactivate2FAModal').style.display = 'none'; }

            // Modal dışı tıklama ile kapatma
            window.onclick = function(event) {
                const modals = ['usernameModal', 'passwordModal', 'emailModal', 'deleteAccountModal', 'activate2FAModal', 'deactivate2FAModal'];
                modals.forEach(id => {
                    const modal = document.getElementById(id);
                    if (event.target == modal) { modal.style.display = 'none'; }
                });
            }

            // ESC tuşu ile kapatma
            document.addEventListener('keydown', function(event) { if (event.key === 'Escape') { closeSettings(); } });

            // Form gönderimi için genel fonksiyon
            function submitForm(formId, url, successMessage, modalId) {
                const form = document.getElementById(formId);
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const formData = new FormData(form);
                    try {
                        const response = await fetch(url, { method: 'POST', body: formData });
                        const result = await response.json();
                        if (result.success) {
                            alert(successMessage);
                            document.getElementById(modalId).style.display = 'none';
                            if (modalId === 'deleteAccountModal') { window.location.href = '/'; } 
                            else { location.reload(); }
                        } else { alert(result.error); }
                    } catch (error) { alert('Bir hata oluştu. Lütfen tekrar deneyin.'); }
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
                    if (result.email) { document.getElementById('emailDisplay').textContent = result.email; } 
                    else { alert(result.error); }
                } catch (error) { alert('Bir hata oluştu. Lütfen tekrar deneyin.'); }
            }

            // Profil resmi yükleme
            async function uploadAvatar() {
                const form = document.getElementById('avatarForm');
                const formData = new FormData(form);
                try {
                    const response = await fetch('update_avatar.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) { alert('Profil resmi güncellendi.'); location.reload(); } 
                    else { alert(result.error); }
                } catch (error) { alert('Bir hata oluştu.'); }
            }

            // 2FA toggle işlemleri
            document.getElementById('2faToggle').addEventListener('change', function() {
                if (this.checked) { openActivate2FAModal(); } 
                else { openDeactivate2FAModal(); }
            });
            
            // 2FA kodu gönderme
            async function send2FACode() {
                try {
                    const response = await fetch('send_2fa_code.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', },
                        body: `csrf_token=<?php echo $csrf_token; ?>`
                    });
                    const result = await response.json();
                    if (!result.success) { alert('Kod gönderilemedi: ' + result.error); }
                } catch (error) { alert('Bir hata oluştu: ' + error.message); }
            }
            
            // 2FA aktivasyon formu
            document.getElementById('activate2FAForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                try {
                    const response = await fetch('activate_2fa.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        alert('İki aşamalı doğrulama etkinleştirildi!');
                        location.reload();
                    } else { alert('Hata: ' + result.error); }
                } catch (error) { alert('Bir hata oluştu: ' + error.message); }
            });
            
            // 2FA deaktivasyon formu
            document.getElementById('deactivate2FAForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                try {
                    const response = await fetch('deactivate_2fa.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        alert('İki aşamalı doğrulama devre dışı bırakıldı!');
                        location.reload();
                    } else { alert('Hata: ' + result.error); }
                } catch (error) { alert('Bir hata oluştu: ' + error.message); }
            });

            // DOM yüklendiğinde
            document.addEventListener('DOMContentLoaded', function() {
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
          const initialSettingsContent = document.getElementById('main-content').innerHTML;
          
            // SPA navigation
       async function loadPage(page, url) {
        const contentContainer = document.getElementById('main-content');
        document.querySelectorAll('.sidebar-item').forEach(item => item.classList.remove('active'));
        document.querySelector(`.sidebar-item[data-page="${page}"]`).classList.add('active');

        try {
            if (page === 'settings') {
                contentContainer.innerHTML = initialSettingsContent;
                history.pushState({ page: page }, '', `/settings`);
                lucide.createIcons();
                // Ayarlar sayfasının kendi event listener'larını yeniden bağla
                document.querySelectorAll('.setting-row.disabled').forEach(row => {
                    row.addEventListener('click', (e) => {
                        e.preventDefault();
                        alert('Bu özellik şu anda kullanılamıyor. Lütfen destek ekibiyle iletişime geçin.');
                    });
                });
                // Diğer event listener'lar (modal açma vb.) initialSettingsContent'ten geldiği için çalışacaktır.
            } else if (url) {
                const response = await fetch(url);
                if (!response.ok) throw new Error('İçerik yüklenemedi');
                const html = await response.text();
                contentContainer.innerHTML = html;
                history.pushState({ page: page }, '', `/settings?page=${page}`);
                lucide.createIcons();

                // === YENİ EKLENEN BÖLÜM BAŞLANGICI ===

                if (page === 'language') {
                    // Yüklenen içerikten PHP değişkenlerini al
                    const pageData = window.currentPageData;
                    if (pageData) {
                        const { csrfToken, currentLanguage, defaultAppLanguage } = pageData;

                        // Canlı önizleme için çeviriler
                        const jsTranslations = {
                            'tr': { 'title': 'Dil Ayarları', 'subtitle': 'Tercih ettiğiniz dili seçin.', 'search_placeholder': 'Dil ara...', 'tip_message': 'Dil tercihiniz kaydedildi. Uygulama dili bir sonraki oturumunuzda güncellenecektir.', 'auto_detect': 'Otomatik Algıla (Tarayıcı Dili)', 'reset_to_default': 'Varsayılana Sıfırla (Türkçe)', 'turkish': 'Türkçe', 'english': 'English', 'finnish': 'Suomi', 'french': 'Français', 'german': 'Deutsch', 'russian': 'Русский' },
                            'en': { 'title': 'Language Settings', 'subtitle': 'Choose your preferred language.', 'search_placeholder': 'Search language...', 'tip_message': 'Your language preference has been saved. The application language will be updated on your next session.', 'auto_detect': 'Auto-Detect (Browser Language)', 'reset_to_default': 'Reset to Default (Turkish)', 'turkish': 'Turkish', 'english': 'English', 'finnish': 'Finnish', 'french': 'French', 'german': 'German', 'russian': 'Russian' },
                            'fi': { 'title': 'Kieliasetukset', 'subtitle': 'Valitse haluamasi kieli.', 'search_placeholder': 'Hae kieltä...', 'tip_message': 'Kieliasetuksesi on tallennettu. Sovelluksen kieli päivitetään seuraavalla istunnollasi.', 'auto_detect': 'Automaattinen tunnistus (selaimen kieli)', 'reset_to_default': 'Palauta oletusasetukset (turkki)', 'turkish': 'Turkki', 'english': 'Englanti', 'finnish': 'Suomi', 'french': 'Ranska', 'german': 'Saksa', 'russian': 'Venäjä' },
                            'fr': { 'title': 'Paramètres de langue', 'subtitle': 'Choisissez votre langue préférée.', 'search_placeholder': 'Rechercher une langue...', 'tip_message': 'Votre préférence linguistique a été enregistrée. La langue de l\'application sera mise à jour lors de votre prochaine session.', 'auto_detect': 'Détection automatique (langue du navigateur)', 'reset_to_default': 'Réinitialiser par défaut (Turc)', 'turkish': 'Turc', 'english': 'Anglais', 'finnish': 'Finnois', 'french': 'Français', 'german': 'Allemand', 'russian': 'Russe' },
                            'de': { 'title': 'Spracheinstellungen', 'subtitle': 'Wählen Sie Ihre bevorzugte Sprache.', 'search_placeholder': 'Sprache suchen...', 'tip_message': 'Ihre Spracheinstellung wurde gespeichert. Die Anwendungssprache wird bei Ihrer nächsten Sitzung aktualisiert.', 'auto_detect': 'Automatische Erkennung (Browsersprache)', 'reset_to_default': 'Auf Standard zurücksetzen (Türkisch)', 'turkish': 'Türkisch', 'english': 'Englisch', 'finnish': 'Finnisch', 'french': 'Französisch', 'german': 'Deutsch', 'russian': 'Russisch' },
                            'ru': { 'title': 'Настройки языка', 'subtitle': 'Выберите предпочитаемый язык.', 'search_placeholder': 'Поиск языка...', 'tip_message': 'Ваши языковые предпочтения сохранены. Язык приложения будет обновлен в вашей следующей сессии.', 'auto_detect': 'Автоматическое определение (язык браузера)', 'reset_to_default': 'Сбросить до значений по умолчанию (Турецкий)', 'turkish': 'Турецкий', 'english': 'Английский', 'finnish': 'Финский', 'french': 'Французский', 'german': 'Немецкий', 'russian': 'Русский' }
                        };

                        function showToast(message) {
                            const toast = document.getElementById('toastNotification');
                            if (toast) {
                                toast.textContent = message;
                                toast.style.opacity = '1';
                                setTimeout(() => { toast.style.opacity = '0'; }, 3000);
                            }
                        }
                        
                        function updateLiveText(lang) {
                            const texts = jsTranslations[lang] || jsTranslations['tr'];
                            document.getElementById('pageTitle').textContent = texts.title;
                            document.getElementById('pageSubtitle').textContent = texts.subtitle;
                            document.getElementById('languageSearch').placeholder = texts.search_placeholder;
                            document.getElementById('tipMessage').textContent = texts.tip_message;
                            document.querySelector('.language-option[data-lang="auto"] .language-name').textContent = texts.auto_detect;
                            document.querySelector('.language-option[data-lang="' + defaultAppLanguage + '"] .language-name').textContent = texts.reset_to_default;
                            document.querySelector('.language-option[data-lang="tr"] .language-name').textContent = texts.turkish;
                            document.querySelector('.language-option[data-lang="en"] .language-name').textContent = texts.english;
                            document.querySelector('.language-option[data-lang="fi"] .language-name').textContent = texts.finnish;
                            document.querySelector('.language-option[data-lang="fr"] .language-name').textContent = texts.french;
                            document.querySelector('.language-option[data-lang="de"] .language-name').textContent = texts.german;
                            document.querySelector('.language-option[data-lang="ru"] .language-name').textContent = texts.russian;
                        }

                        const languageOptions = document.querySelectorAll('.language-option');
                        const languageSearchBar = document.getElementById('languageSearch');

                        // Mevcut dili seçili olarak işaretle
                        languageOptions.forEach(option => {
                            if (option.dataset.lang === currentLanguage) {
                                option.classList.add('selected');
                            }
                        });

                        languageOptions.forEach(option => {
                            option.addEventListener('click', async function() {
                                languageOptions.forEach(opt => opt.classList.remove('selected'));
                                this.classList.add('selected');

                                const selectedLangCode = this.dataset.lang;
                                const formData = new FormData();
                                formData.append('selected_language', selectedLangCode);
                                formData.append('csrf_token', csrfToken);

                                try {
                                    // AJAX isteğini content dosyasına yapıyoruz
                                    const response = await fetch('language_settings_content.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const result = await response.json();

                                    if (result.success) {
                                        showToast(result.message);
                                        updateLiveText(result.new_lang);
                                    } else {
                                        showToast('Hata: ' + result.message);
                                    }
                                } catch (error) {
                                    showToast('Bir hata oluştu. Lütfen tekrar deneyin.');
                                    console.error('Dil ayarını kaydederken hata:', error);
                                }
                            });
                        });
                        
                        languageSearchBar.addEventListener('keyup', function() {
                            const searchTerm = languageSearchBar.value.toLowerCase();
                            languageOptions.forEach(option => {
                                const languageName = option.querySelector('.language-name').textContent.toLowerCase();
                                if (languageName.includes(searchTerm)) {
                                    option.style.display = 'flex';
                                } else {
                                    option.style.display = 'none';
                                }
                            });
                        });
                        
                        // Temizleme: Geçici script'i DOM'dan kaldır
                        const tempScript = contentContainer.querySelector('script');
                        if (tempScript) tempScript.remove();
                    }
                }

                // === YENİ EKLENEN BÖLÜM SONU ===

                else if (page === 'notifications') {
                    // Bu bildirimler sayfası için olan mevcut kodunuz, olduğu gibi kalabilir.
                    contentContainer.querySelectorAll('.preview-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const soundPath = btn.getAttribute('data-sound');
                            const audio = new Audio(soundPath);
                            audio.play();
                        });
                    });
                }
            }
        } catch (error) {
            contentContainer.innerHTML = '<div class="tip" style="color: #ed5151;">Hata: İçerik yüklenemedi.</div>';
        }
    }

    // Handle back/forward navigation
    window.addEventListener('popstate', (event) => {
        const page = event.state?.page || 'settings';
        if (page === 'settings') {
            loadPage('settings', null);
        } else {
            loadPage(page, getPageUrl(page));
        }
    });

    // Helper function to map pages to URLs
    function getPageUrl(page) {
        const pageUrls = {
            'language':  'language_settings_content.php',
            'notifications': 'bildirimses_content.php',
            'content-control': 'content_control.php',
            'connections': 'connections.php',
            'keybinds': 'keybinds.php',
            'voice': 'voice.php',
            'extra': 'extra.php'
        };
        return pageUrls[page] || null;
    }
        //Mobil kaydırma
        if (window.innerWidth <= 768) {
            const sidebar = document.getElementById("main-content");
            const leftPanel = document.getElementById("movesidebar");
            const sidebarWidth = sidebar.offsetWidth;
            let isDragging = false, startX = 0, currentTranslate = sidebarWidth, previousTranslate = sidebarWidth;
            
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
                const diff = e.touches[0].clientX - startX;
                currentTranslate = Math.max(0, Math.min(previousTranslate + diff, sidebarWidth));
                sidebar.style.transform = `translateX(${currentTranslate}px)`;
            }
            function handleTouchEnd() {
                isDragging = false;
                sidebar.style.transition = 'transform 0.2s ease-out';
                currentTranslate = (currentTranslate < sidebarWidth * 0.5) ? 0 : sidebarWidth;
                sidebar.style.transform = `translateX(${currentTranslate}px)`;
            }
            const listeners = [
                { el: leftPanel, type: "touchstart", fn: handleTouchStart },
                { el: leftPanel, type: "touchmove", fn: handleTouchMove },
                { el: leftPanel, type: "touchend", fn: handleTouchEnd },
                { el: sidebar, type: "touchstart", fn: handleTouchStart },
                { el: sidebar, type: "touchmove", fn: handleTouchMove },
                { el: sidebar, type: "touchend", fn: handleTouchEnd },
            ];
            listeners.forEach(({ el, type, fn }) => el.addEventListener(type, fn, { passive: false }));
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