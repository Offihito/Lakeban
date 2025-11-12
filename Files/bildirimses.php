<?php
session_start();
require_once 'db_connection.php';

// Ses seçenekleri
$sounds = [
    '/bildirim.mp3' => 'Klasik Bildirim',
    '/bildiri.mp3' => 'Modern Bildirim',
    '/bildir.mp3' => 'Uzay Boşluğu',
    '/bildirim2.mp3' => 'Xp sesi',
];

// Tema ayarları için varsayılan değerler
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

// Mevcut tema ayarlarını veritabanından yükle
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

try {
    $userStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($userData) {
        $currentTheme = $userData['theme'] ?? $defaultTheme;
        $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
        $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Tema ayarları alınırken bir hata oluştu: ' . $e->getMessage();
}

// Bildirim sesi işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Koruması
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'CSRF token doğrulanamadı.';
        header("Location: bildirimses");
        exit;
    }

    $selectedSound = $_POST['notification_sound'];
    // Seçilen sesin geçerli bir seçenek olup olmadığını kontrol et
    if (!array_key_exists($selectedSound, $sounds)) {
        $_SESSION['error_message'] = 'Geçersiz bildirim sesi seçimi.';
        header("Location: bildirimses");
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE users SET notification_sound = ? WHERE id = ?");
        $stmt->execute([$selectedSound, $_SESSION['user_id']]);
        $_SESSION['sound_updated'] = true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Bildirim sesi güncellenirken bir hata oluştu: ' . $e->getMessage();
    }
    header("Location: bildirimses");
    exit;
}
$isLakebiumUser = false;
try {
    $lakebiumStmt = $db->prepare("SELECT status FROM lakebium WHERE user_id = ? AND status = 'active'");
    $lakebiumStmt->execute([$_SESSION['user_id']]);
    $isLakebiumUser = $lakebiumStmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Lakebium abonelik durumu alınırken bir hata oluştu: ' . $e->getMessage();
}

// Mevcut bildirim sesini yükle
$currentSound = '';
try {
    $userStmt = $db->prepare("SELECT notification_sound FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $currentSound = $userStmt->fetchColumn();
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Mevcut bildirim sesi alınırken bir hata oluştu: ' . $e->getMessage();
}

// CSRF token oluştur
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
?>
<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh; --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>; --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;">
<head>
    <meta charset="UTF-8">
    <title>Bildirim Ses Ayarları</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <style>
        :root, .root {
            --hover: #3CB371;
            --gradient: #423d3c;
            --scrollback: #0d3b22;
            --error: #ed5151;
            --font-size: 16px;
            --accent-color: #3CB371;
            --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>;
            --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;
        }
        .red-theme {
            --hover: #870f0f;
            --gradient: #a01414;
            --scrollback: #950014;
        }
        .blue-theme {
            --hover: #1775c2;
            --gradient: #0d2e75;
            --scrollback: #1775c2;
        }
        /* === AYDINLIK TEMA === */
        .light-theme body {
            background-color: #F2F3F5;
            color: #2E3338;
        }
        .light-theme .sidebar, .light-theme .content-container, .light-theme .right-sidebar {
            background-color: #FFFFFF;
        }
        .light-theme .app-container {
            background-color: #F2F3F5;
        }
        .light-theme .sidebar-item {
            color: #4F5660;
        }
        .light-theme .sidebar-item:hover, .light-theme .sidebar-item.active {
            background-color: #e3e5e8;
            color: #060607;
        }
        .light-theme .content-container h1, .light-theme .content-container h3, .light-theme .sound-info .title {
            color: #060607;
        }
        .light-theme .content-container h5, .light-theme .category, .light-theme .keybind_c2b141, .light-theme .sound-info .description {
            color: #4F5660;
        }
        .light-theme hr {
            border-top: 1px solid #e3e5e8;
        }
        .light-theme .sound-option {
            background-color: #F8F9FA;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .light-theme .sound-option:hover {
            background-color: #e3e5e8;
        }
        .light-theme .preview-btn {
            background-color: #D1D5DB;
        }
        .light-theme .preview-btn:hover {
            background-color: #B0B7C0;
        }
        .light-theme .edit-profile-btn {
            background-color: var(--accent-color);
        }
        .light-theme .edit-profile-btn:hover {
            background-color: #2e9b5e;
        }
        .light-theme .tip {
            background-color: #F8F9FA;
        }
        .light-theme .sound-info .icon {
            color: #4F5660;
        }

        /* === KOYU TEMA === */
        .dark-theme body {
            background-color: #1E1E1E;
            color: #ffffff;
        }
        .dark-theme .sidebar, .dark-theme .content-container, .dark-theme .right-sidebar {
            background-color: #242424;
        }
        .dark-theme .app-container {
            background-color: #1E1E1E;
        }
        .dark-theme .sidebar-item {
            color: #b9bbbe;
        }
        .dark-theme .sidebar-item:hover, .dark-theme .sidebar-item.active {
            background-color: #2f3136;
            color: #ffffff;
        }
        .dark-theme .content-container h1, .dark-theme .content-container h3, .dark-theme .sound-info .title {
            color: #ffffff;
        }
        .dark-theme .content-container h5, .dark-theme .category, .dark-theme .keybind_c2b141, .dark-theme .sound-info .description {
            color: #b9bbbe;
        }
        .dark-theme hr {
            border-top: 1px solid #2f3136;
        }
        .dark-theme .sound-option {
            background-color: #2f3136;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .dark-theme .sound-option:hover {
            background-color: #35383e;
        }
        .dark-theme .preview-btn {
            background-color: #4F545C;
        }
        .dark-theme .preview-btn:hover {
            background-color: #5A6069;
        }
        .dark-theme .edit-profile-btn {
            background-color: var(--accent-color);
        }
        .dark-theme .edit-profile-btn:hover {
            background-color: #2e9b5e;
        }
        .dark-theme .tip {
            background-color: #2f3136;
        }

        /* === ÖZEL TEMA === */
        .custom-theme body {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            color: #ffffff;
        }
        .custom-theme .app-container {
            background-color: var(--custom-background-color);
        }
        .custom-theme .sidebar, .custom-theme .content-container, .custom-theme .right-sidebar {
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
        }
        .custom-theme .sidebar-item {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme .sidebar-item:hover, .custom-theme .sidebar-item.active {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            color: #ffffff;
        }
        .custom-theme .content-container h1, .custom-theme .content-container h3, .custom-theme .sound-info .title {
            color: #ffffff;
        }
        .custom-theme .content-container h5, .custom-theme .category, .custom-theme .keybind_c2b141, .custom-theme .sound-info .description {
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme hr {
            border-top: 1px solid color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
        }
        .custom-theme .sound-option {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .custom-theme .sound-option:hover {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
        }
        .custom-theme .preview-btn {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 70%, black);
        }
        .custom-theme .preview-btn:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 60%, white);
        }
        .custom-theme .edit-profile-btn {
            background-color: var(--custom-secondary-color);
        }
        .custom-theme .edit-profile-btn:hover {
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white);
        }
        .custom-theme input[type="radio"]:checked {
            border-color: var(--custom-secondary-color);
        }
        .custom-theme input[type="radio"]:checked::before {
            background-color: var(--custom-secondary-color);
        }

        body {
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
            background: var(--accent-color);
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
        .sound-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            background-color: #2f3136;
            transition: background-color 0.2s ease;
        }
        .sound-option:hover {
            background-color: #35383e;
        }
        .sound-info {
            display: flex;
            align-items: center;
            flex-grow: 1;
        }
        .sound-info .icon {
            margin-right: 12px;
            color: #b9bbbe;
        }
        .sound-info .title {
            font-size: 16px;
            font-weight: 600;
            color: #ffffff;
        }
        .sound-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .preview-btn {
            background-color: #4F545C;
            color: #ffffff;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.2s ease;
        }
        .preview-btn:hover {
            background-color: #5A6069;
        }
        .preview-btn i {
            margin-right: 0;
        }
        input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid #b9bbbe;
            border-radius: 50%;
            outline: none;
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
        }
        input[type="radio"]:checked {
            border-color: var(--hover);
        }
        input[type="radio"]:checked::before {
            content: '';
            display: block;
            width: 10px;
            height: 10px;
            background-color: var(--hover);
            border-radius: 50%;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        input[type="radio"]:focus {
            box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.5);
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
            color: var(--accent-color);
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
        #back {
            display: none;
        }
        /* Responsive Tasarım */
        @media (max-width: 1024px) {
            .app-container {
                flex-direction: column;
                padding: 16px;
            }
            .sidebar {
                width: 100%;
                margin-bottom: 16px;
                border-radius: 8px;
            }
            .content-container {
                width: 100%;
                padding: 16px;
                margin-left: 0;
                margin-right: 0;
                border-radius: 8px;
            }
            .right-sidebar {
                display: none;
            }
            .modal-content {
                width: 90%;
            }
        }
        @media (max-width: 768px) {
            #back {
                display: flex;
            }
            .sidebar {
                position: absolute;
                width: 100%;
                height: 100vh;
                left: 0%;
                margin-bottom: 16px;
                border-radius: 8px;
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
        }
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
          <a href="/settings" style="text-decoration: none; color: inherit;">
        <div class="sidebar-item" data-page="settings" onclick="('settings', null)"><i data-lucide="user"></i> <?php echo $translations['settings']['sidebar']['account'] ?? 'Hesabım'; ?></div>
        </a>
        <a href="/profile" style="text-decoration: none; color: inherit;">
            <div class="sidebar-item" data-page="profile"><i data-lucide="user-pen"></i> <?php echo $translations['settings']['sidebar']['profile'] ?? 'Profilim'; ?></div>
        </a>
        <div class="sidebar-item" data-page="content-control" onclick="loadPage('content-control', 'content_control.php')"><i data-lucide="shield-check"></i> <?php echo $translations['settings']['sidebar']['content_control'] ?? 'İçerik Kontrolü'; ?></div>
        <div class="sidebar-item" data-page="connections" onclick="loadPage('connections', 'connections.php')"><i data-lucide="link-2"></i> <?php echo $translations['settings']['sidebar']['connections'] ?? 'Bağlantılar'; ?></div>
         <a href="/language_settings" style="text-decoration: none; color: inherit;">
        <div class="sidebar-item" data-page="language" onclick="('language', 'language_settings_content.php')"><i data-lucide="languages"></i> <?php echo $translations['settings']['sidebar']['language'] ?? 'Dil'; ?></div>
         </a>
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
        <div class="sidebar-item active" data-page="notifications" onclick="('notifications', 'bildirimses_content.php')"><i data-lucide="bell"></i> <?php echo $translations['settings']['sidebar']['notifications'] ?? 'Bildirimler'; ?></div>
        <div class="sidebar-item" data-page="keybinds" onclick="loadPage('keybinds', 'keybinds.php')"><i data-lucide="keyboard"></i> <?php echo $translations['settings']['sidebar']['keybinds'] ?? 'Tuş Atamaları'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['accessibility'] ?? 'Erişebilirlik'; ?></div>
        <div class="sidebar-item" data-page="voice" onclick="loadPage('voice', 'voice.php')"><i data-lucide="mic"></i> <?php echo $translations['settings']['sidebar']['voice'] ?? 'Ses'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['advanced'] ?? 'Gelişmiş'; ?></div>
        <div class="sidebar-item" data-page="extra" onclick="loadPage('extra', 'extra.php')"><i data-lucide="circle-ellipsis"></i> <?php echo $translations['settings']['sidebar']['extra'] ?? 'Ekstra'; ?></div>
    </div>

        <div id="main-content" class="content-container">
            <h1>Bildirim Sesi Seçin</h1>

            <?php if (isset($_SESSION['sound_updated'])): ?>
                <div class="tip" style="background-color: #2f3136; color: var(--hover);">
                    <i data-lucide="check-circle"></i> Ayarlar başarıyla kaydedildi!
                </div>
                <?php unset($_SESSION['sound_updated']); ?>
            <?php elseif (isset($_SESSION['error_message'])): ?>
                <div class="tip" style="background-color: #2f3136; color: var(--error);">
                    <i data-lucide="x-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <?php foreach ($sounds as $path => $name): ?>
                    <div class="sound-option">
                        <div class="sound-info">
                            <i data-lucide="volume-2" class="icon"></i>
                            <div class="title"><?= htmlspecialchars($name) ?></div>
                        </div>
                        <div class="sound-controls">
                            <input type="radio" name="notification_sound"
                                   value="<?= htmlspecialchars($path) ?>" <?= $path === $currentSound ? 'checked' : '' ?>>
                            <button type="button" class="preview-btn"
                                    onclick="previewSound('<?= htmlspecialchars($path) ?>')">
                                <i data-lucide="play"></i> Önizle
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="tip" style="margin-top: 20px;">
                    <i data-lucide="info"></i>
                    <span>Beğendiğiniz sesi seçmek ve dinlemek için önizleme düğmesini kullanın.</span>
                </div>

                <button type="submit" class="edit-profile-btn" style="margin-top: 20px;">
                    <i data-lucide="save"></i> Değişiklikleri Kaydet
                </button>
            </form>
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
    </div>

    <script>
        lucide.createIcons();
        function closeSettings() {
            window.location.href = '/directmessages';
        }
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSettings();
            }
        });
        function previewSound(soundPath) {
            const audio = new Audio(soundPath);
            audio.play();
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.addEventListener('click', () => {
                    document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
                    item.classList.add('active');
                });
            });
        });
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
                    currentTranslate = 0;
                    sidebar.style.transform = 'translateX(0)';
                } else {
                    currentTranslate = sidebarWidth;
                    sidebar.style.transform = `translateX(${sidebarWidth}px)`;
                }
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
    </script>
</body>
</html>