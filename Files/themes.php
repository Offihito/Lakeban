<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantı dosyasını dahil et

// Statik Veriler (Tema seçenekleri)
$themes = [
    'light' => ['name' => 'Aydınlık', 'image' => 'acik.svg'],
    'dark' => ['name' => 'Koyu', 'image' => 'koyu.svg'],
];

// Varsayılan değerler
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

// Lakebium abonelik kontrolü
$isLakebiumUser = false;
try {
    $lakebiumStmt = $db->prepare("SELECT status FROM lakebium WHERE user_id = ? AND status = 'active'");
    $lakebiumStmt->execute([$_SESSION['user_id']]);
    $isLakebiumUser = $lakebiumStmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Lakebium abonelik durumu alınırken bir hata oluştu: ' . $e->getMessage();
}

// Mevcut tema ayarlarını veritabanından yükle
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

try {
    $userStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($userData) {
        // Eğer kullanıcı Lakebium değilse ve tema 'custom' ise, varsayılan temaya dön
        $currentTheme = ($userData['theme'] === 'custom' && !$isLakebiumUser) ? $defaultTheme : ($userData['theme'] ?? $defaultTheme);
        $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
        $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Tema ayarları alınırken bir hata oluştu: ' . $e->getMessage();
}

// CSRF token oluştur
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// POST İsteğini İşleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Koruması
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'CSRF token doğrulanamadı. Lütfen sayfayı yenileyip tekrar deneyin.';
        header("Location: themes.php");
        exit;
    }

    // Yeni bir token oluştur
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Ayarları sıfırlama isteği
    if (isset($_POST['reset_settings'])) {
        try {
            $stmt = $db->prepare("UPDATE users SET theme = ?, custom_color = ?, secondary_color = ? WHERE id = ?");
            $stmt->execute([$defaultTheme, $defaultCustomColor, $defaultSecondaryColor, $_SESSION['user_id']]);
            $_SESSION['settings_updated'] = 'reset';
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Ayarlar sıfırlanırken bir hata oluştu: ' . $e->getMessage();
        }
        header("Location: themes.php");
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

    // Formdan gelen verileri al ve doğrula
    $selectedTheme = isset($_POST['theme']) && (array_key_exists($_POST['theme'], $themes) || ($_POST['theme'] === 'custom' && $isLakebiumUser)) ? $_POST['theme'] : $defaultTheme;
    $selectedCustomColor = $defaultCustomColor;
    $selectedSecondaryColor = $defaultSecondaryColor;

    // Eğer özel tema seçilmişse ve kullanıcı Lakebium abonesiyse, renk seçicilerden gelen değerleri kullan
    if ($selectedTheme === 'custom' && $isLakebiumUser) {
        if (!empty($_POST['custom_theme_color']) && preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $_POST['custom_theme_color'])) {
            $selectedCustomColor = $_POST['custom_theme_color'];
        }
        if (!empty($_POST['secondary_theme_color']) && preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $_POST['secondary_theme_color'])) {
            $selectedSecondaryColor = $_POST['secondary_theme_color'];
        }
    }

    // Veritabanına kaydet
    try {
        $stmt = $db->prepare("UPDATE users SET theme = ?, custom_color = ?, secondary_color = ? WHERE id = ?");
        $stmt->execute([$selectedTheme, $selectedCustomColor, $selectedSecondaryColor, $_SESSION['user_id']]);
        $_SESSION['settings_updated'] = 'saved';
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Tema ayarları kaydedilirken bir hata oluştu: ' . $e->getMessage();
    }

    header("Location: themes.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--app-height: 100vh;">
<head>
    <meta charset="UTF-8">
    <title>Tema Ayarları</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <style>
        /* === GENEL DEĞİŞKENLER VE STİLLER === */
        :root {
            --accent-color: #3CB371;
            --font-size: 16px;
            --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>;
            --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;
        }

        body {
            transition: background-color 0.3s ease, color 0.3s ease;
            background-color: #1E1E1E;
            color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
            font-size: var(--font-size);
        }
        .sidebar, .content-container, .right-sidebar {
            transition: background-color 0.3s ease;
            background-color: #242424;
        }
        .sidebar-item { color: #b9bbbe; }
        .sidebar-item:hover, .sidebar-item.active { background-color: #2f3136; color: #ffffff; }
        .content-container h1, .content-container h3, .theme-option h4, .select-box { color: #ffffff; }
        .content-container h5, .category, .keybind_c2b141, .user-id, .setting-content .description { color: #b9bbbe; }
        hr { border-top: 1px solid #2f3136; }
        .tip {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .tip svg { width: 20px; height: 20px; margin-right: 8px; }

        /* === AYDINLIK TEMA === */
        .light-theme body { background-color: #F2F3F5; color: #2E3338; }
        .light-theme .sidebar, .light-theme .content-container, .light-theme .right-sidebar { background-color: #FFFFFF; }
        .light-theme .sidebar-item { color: #4F5660; }
        .light-theme .sidebar-item:hover, .light-theme .sidebar-item.active { background-color: #e3e5e8; color: #060607; }
        .light-theme .content-container h1, .light-theme .content-container h3, .light-theme .theme-card h4 { color: #060607; }
        .light-theme .content-container h5, .light-theme .category, .light-theme .setting-content .description { color: #4F5660; }
        .light-theme hr { border-top: 1px solid #e3e5e8; }
        .light-theme .theme-card { background-color: #F8F9FA; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        .light-theme .theme-card.active { border-color: #007bff; }
        .light-theme .edit-profile-btn { background-color: var(--accent-color); }
        .light-theme .edit-profile-btn:hover { background-color: #2e9b5e; }
        .light-theme .reset-btn { background-color: #dc3545; }
        .light-theme .reset-btn:hover { background-color: #c82333; }

        /* === KOYU TEMA === */
        .dark-theme body { background-color: #1E1E1E; color: #ffffff; }
        .dark-theme .sidebar, .dark-theme .content-container, .dark-theme .right-sidebar { background-color: #242424; }
        .dark-theme .sidebar-item { color: #b9bbbe; }
        .dark-theme .sidebar-item:hover, .dark-theme .sidebar-item.active { background-color: #2f3136; color: #ffffff; }
        .dark-theme .theme-card { background-color: #2f3136; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); }
        .dark-theme .theme-card.active { border-color: #3CB371; }
        .dark-theme .custom-color-input { border-color: #b9bbbe; }
        .dark-theme .edit-profile-btn { background-color: var(--accent-color); }
        .dark-theme .edit-profile-btn:hover { background-color: #2e9b5e; }
        .dark-theme .reset-btn { background-color: #dc3545; }
        .dark-theme .reset-btn:hover { background-color: #c82333; }

        /* === ÖZEL TEMA === */
        .custom-theme body { 
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%); 
            color: #ffffff; 
        }
        .custom-theme .sidebar, .custom-theme .content-container, .custom-theme .right-sidebar { 
            background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%); 
        }
        .custom-theme .sidebar-item { color: color-mix(in srgb, var(--custom-background-color) 40%, white); }
        .custom-theme .sidebar-item:hover, .custom-theme .sidebar-item.active { 
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%); 
            color: #ffffff; 
        }
        .custom-theme .content-container h1, .custom-theme .content-container h3, .custom-theme .theme-card h4 { color: #ffffff; }
        .custom-theme .content-container h5, .custom-theme .category, .custom-theme .setting-content .description { 
            color: color-mix(in srgb, var(--custom-background-color) 40%, white); 
        }
        .custom-theme hr { 
            border-top: 1px solid color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
        }
        .custom-theme .theme-card { 
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); 
        }
        .custom-theme .theme-card.active { border-color: var(--custom-secondary-color); }
        .custom-theme .edit-profile-btn { 
            background-color: var(--custom-secondary-color); 
        }
        .custom-theme .edit-profile-btn:hover { 
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%); 
        }
        .custom-theme .reset-btn { 
            background-color: color-mix(in srgb, var(--custom-background-color) 50%, #dc3545 50%); 
        }
        .custom-theme .reset-btn:hover { 
            background-color: color-mix(in srgb, var(--custom-background-color) 40%, #c82333 60%); 
        }

        /* === YENİ UI BİLEŞENLERİ === */
        .theme-selector-list {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
        }

        .theme-card {
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            border: 2px solid transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 150px;
            text-align: center;
            position: relative;
        }

        .theme-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .theme-card.active {
            border-color: var(--accent-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .theme-card img {
            width: 120px;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
        }

        .theme-card h4 {
            margin: 0;
            font-size: 16px;
        }

        .checkmark-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            color: var(--accent-color);
            background-color: #fff;
            border-radius: 50%;
            padding: 2px;
            display: none;
        }
        .theme-card.active .checkmark-icon {
            display: block;
        }
        .custom-theme .checkmark-icon {
            color: var(--custom-secondary-color);
        }
        .custom-theme .theme-card.active .checkmark-icon {
            color: #fff;
            background-color: var(--custom-secondary-color);
        }

        .custom-color-picker-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            cursor: pointer;
        }

        .custom-color-input {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            width: 120px;
            height: 45px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            background-color: transparent;
            overflow: hidden;
            padding: 0;
            position: relative;
            box-sizing: border-box;
        }
        .custom-color-input::-webkit-color-swatch { border: none; border-radius: 8px; }
        .custom-color-input::-webkit-color-swatch-wrapper { padding: 0; }
        .custom-color-input::-moz-color-swatch { border: none; border-radius: 8px; }

        .color-picker-label {
            font-size: 14px;
            font-weight: 500;
        }

        /* Orijinal CSS kodunun geri kalanı */
        .app-container{display:flex;max-width:1400px;margin:0 auto;height:var(--app-height);padding:24px;box-sizing:border-box}.sidebar{width:260px;padding:16px 8px;overflow-y:auto;border-radius:8px;flex-shrink:0}.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-track{background:#1E1E1E}.sidebar::-webkit-scrollbar-thumb{background:var(--accent-color);border-radius:2px}.category{font-size:12px;font-weight:600;text-transform:uppercase;padding:8px 16px;margin:8px 0}.sidebar-item{display:flex;align-items:center;padding:8px 16px;margin:2px 8px;border-radius:4px;font-size:14px;font-weight:500;cursor:pointer;transition:background-color .2s ease,color .2s ease}.sidebar-item i{margin-right:8px}.content-container{flex-grow:1;padding:24px;overflow-y:auto;margin-left:16px;margin-right:16px;border-radius:8px}.content-container::-webkit-scrollbar{width:8px}.content-container::-webkit-scrollbar-track{background:#1E1E1E}.content-container::-webkit-scrollbar-thumb{background:#2f3136;border-radius:4px}.content-container h1{font-size:20px;font-weight:600;margin:0 0 24px}.content-container h3{font-size:16px;font-weight:600;margin:24px 0 8px}.content-container h5{font-size:14px;font-weight:400;margin:8px 0 16px}.right-sidebar{width:72px;padding:16px 8px;display:flex;flex-direction:column;align-items:center;border-radius:8px;flex-shrink:0}hr{border:none;margin:24px 0}.edit-profile-btn{background-color:var(--accent-color);border:none;border-radius:8px;color:#ffffff;padding:12px 24px;font-size:16px;font-weight:600;cursor:pointer;transition:background-color .2s ease, transform .2s ease}.edit-profile-btn:hover{transform: translateY(-2px);}.edit-profile-btn:disabled{background-color:#5c6b73;cursor:not-allowed;transform: none;}.reset-btn{background-color:#dc3545;border:none;border-radius:8px;color:#ffffff;padding:12px 24px;font-size:16px;font-weight:600;cursor:pointer;transition:background-color .2s ease, transform .2s ease}.reset-btn:hover{transform: translateY(-2px);}.reset-btn:disabled{background-color:#5c6b73;cursor:not-allowed;transform: none;}
        @media (max-width:1024px){.app-container{flex-direction:column;padding:16px}.sidebar{width:100%;margin-bottom:16px}.content-container{width:100%;margin-left:0;margin-right:0}.right-sidebar{display:none}.theme-selector-list{flex-direction:column;align-items:center}.theme-card{width:90%}}
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
    </style>
</head>
<body style="background-color: <?= $currentTheme === 'custom' && $isLakebiumUser ? htmlspecialchars($currentCustomColor) : '' ?>;">
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
            <div class="sidebar-item active" data-page="themes"><i data-lucide="palette"></i> <?php echo $translations['settings']['sidebar']['themes'] ?? 'Temalar'; ?></div>
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
        <div class="sidebar-item" data-page="notifications" onclick="('notifications', 'bildirimses_content.php')"><i data-lucide="bell"></i> <?php echo $translations['settings']['sidebar']['notifications'] ?? 'Bildirimler'; ?></div>
        </a>
        <div class="sidebar-item" data-page="keybinds" onclick="loadPage('keybinds', 'keybinds.php')"><i data-lucide="keyboard"></i> <?php echo $translations['settings']['sidebar']['keybinds'] ?? 'Tuş Atamaları'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['accessibility'] ?? 'Erişebilirlik'; ?></div>
        <div class="sidebar-item" data-page="voice" onclick="loadPage('voice', 'voice.php')"><i data-lucide="mic"></i> <?php echo $translations['settings']['sidebar']['voice'] ?? 'Ses'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['advanced'] ?? 'Gelişmiş'; ?></div>
        <div class="sidebar-item" data-page="extra" onclick="loadPage('extra', 'extra.php')"><i data-lucide="circle-ellipsis"></i> <?php echo $translations['settings']['sidebar']['extra'] ?? 'Ekstra'; ?></div>
    </div>

        <div id="main-content" class="content-container">
            <h1>Görünüm</h1>

            <?php if (isset($_SESSION['settings_updated'])): ?>
                <?php if ($_SESSION['settings_updated'] === 'saved'): ?>
                    <div class="tip" style="background-color: #2f3136; color: var(--accent-color);">
                        <i data-lucide="check-circle"></i> Ayarlar başarıyla kaydedildi!
                    </div>
                <?php elseif ($_SESSION['settings_updated'] === 'reset'): ?>
                    <div class="tip" style="background-color: #2f3136; color: #007bff;">
                        <i data-lucide="info"></i> Ayarlar varsayılan değerlere sıfırlandı!
                    </div>
                <?php endif; ?>
                <?php unset($_SESSION['settings_updated']); ?>
            <?php elseif (isset($_SESSION['error_message'])): ?>
                <div class="tip" style="background-color: #2f3136; color: #ed5151;">
                    <i data-lucide="x-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <form method="POST" id="themeSettingsForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <h3>Tema</h3>
                <div class="theme-selector-list">
                    <?php foreach ($themes as $key => $theme): ?>
                        <div class="theme-card <?= $key === $currentTheme ? 'active' : '' ?>" data-theme="<?= htmlspecialchars($key) ?>">
                            <i data-lucide="check-circle" class="checkmark-icon"></i>
                            <img src="<?= htmlspecialchars($theme['image']) ?>" alt="<?= htmlspecialchars($theme['name']) ?>">
                            <h4><?= htmlspecialchars($theme['name']) ?></h4>
                            <input type="radio" name="theme impul-theme" value="<?= htmlspecialchars($key) ?>" <?= $key === $currentTheme ? 'checked' : '' ?> style="display: none;">
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if ($isLakebiumUser): ?>
                        <div class="theme-card <?= $currentTheme === 'custom' ? 'active' : '' ?>" data-theme="custom">
                            <i data-lucide="check-circle" class="checkmark-icon"></i>
                            <label for="customThemeColor" class="custom-color-picker-container">
                                <input type="color" id="customThemeColor" name="custom_theme_color" value="<?= htmlspecialchars($currentCustomColor) ?>" class="custom-color-input">
                                <span class="color-picker-label">Birincil Renk</span>
                            </label>
                            <label for="secondaryThemeColor" class="custom-color-picker-container">
                                <input type="color" id="secondaryThemeColor" name="secondary_theme_color" value="<?= htmlspecialchars($currentSecondaryColor) ?>" class="custom-color-input">
                                <span class="color-picker-label">İkincil Renk</span>
                            </label>
                            <input type="radio" name="theme" value="custom" <?= $currentTheme === 'custom' ? 'checked' : '' ?> style="display: none;">
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="edit-profile-btn" id="saveSettingsBtn" style="margin-top: 20px; width: 100%;" disabled>
                    <i data-lucide="save"></i> Değişiklikleri Kaydet
                </button>
            </form>

            <form method="POST" style="margin-top: 15px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="reset_settings" value="true" class="edit-profile-btn reset-btn" style="width: 100%;">
                    <i data-lucide="rotate-ccw"></i> Ayarları Sıfırla
                </button>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const docElement = document.documentElement;
        const themeSettingsForm = document.getElementById('themeSettingsForm');
        const saveSettingsBtn = document.getElementById('saveSettingsBtn');
        const customThemeColorInput = document.getElementById('customThemeColor');
        const secondaryThemeColorInput = document.getElementById('secondaryThemeColor');
        let initialFormState = new FormData(themeSettingsForm);

        function updateSaveButtonState() {
            const currentFormState = new FormData(themeSettingsForm);
            let changed = false;

            const initialTheme = initialFormState.get('theme');
            const currentTheme = currentFormState.get('theme');
            if (initialTheme !== currentTheme) {
                changed = true;
            }
            
            if (currentTheme === 'custom') {
                const initialCustomColor = initialFormState.get('custom_theme_color') || '';
                const currentCustomColor = currentFormState.get('custom_theme_color') || '';
                const initialSecondaryColor = initialFormState.get('secondary_theme_color') || '';
                const currentSecondaryColor = currentFormState.get('secondary_theme_color') || '';
                if (initialCustomColor.toLowerCase() !== currentCustomColor.toLowerCase() ||
                    initialSecondaryColor.toLowerCase() !== currentSecondaryColor.toLowerCase()) {
                    changed = true;
                }
            }

            saveSettingsBtn.disabled = !changed;
        }

        document.querySelectorAll('.theme-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.theme-card').forEach(item => item.classList.remove('active'));
                this.classList.add('active');
                
                const selectedTheme = this.dataset.theme;
                this.querySelector('input[type="radio"]').checked = true;

                docElement.className = docElement.className.replace(/\b(light|dark|custom)-theme\b/g, '');
                docElement.classList.add(selectedTheme + '-theme');

                if (selectedTheme === 'custom') {
                    docElement.style.setProperty('--custom-background-color', customThemeColorInput.value);
                    docElement.style.setProperty('--custom-secondary-color', secondaryThemeColorInput.value);
                } else {
                    docElement.style.removeProperty('--custom-background-color');
                    docElement.style.removeProperty('--custom-secondary-color');
                }

                updateSaveButtonState();
            });
        });

        customThemeColorInput.addEventListener('input', function() {
            document.querySelector('input[name="theme"][value="custom"]').checked = true;
            document.querySelectorAll('.theme-card').forEach(item => item.classList.remove('active'));
            this.closest('.theme-card').classList.add('active');
            
            docElement.className = docElement.className.replace(/\b(light|dark|custom)-theme\b/g, '');
            docElement.classList.add('custom-theme');
            docElement.style.setProperty('--custom-background-color', this.value);

            updateSaveButtonState();
        });

        secondaryThemeColorInput.addEventListener('input', function() {
            document.querySelector('input[name="theme"][value="custom"]').checked = true;
            document.querySelectorAll('.theme-card').forEach(item => item.classList.remove('active'));
            this.closest('.theme-card').classList.add('active');
            
            docElement.className = docElement.className.replace(/\b(light|dark|custom)-theme\b/g, '');
            docElement.classList.add('custom-theme');
            docElement.style.setProperty('--custom-secondary-color', this.value);

            updateSaveButtonState();
        });

        document.addEventListener('DOMContentLoaded', () => {
            initialFormState = new FormData(themeSettingsForm);
            updateSaveButtonState();
        });

        themeSettingsForm.addEventListener('change', updateSaveButtonState);
        themeSettingsForm.addEventListener('input', updateSaveButtonState);
        
        
        
        
        // Mobil kaydırma
        const sidebar = document.getElementById("main-content");
        const leftPanel = document.getElementById("movesidebar");

        function enableSwipeSidebar() {
          const sidebarWidth = sidebar.offsetWidth;

          let isDragging = false;
          let startX = 0;
          let currentTranslate = sidebarWidth;
          let previousTranslate = sidebarWidth;

          // Başlangıç pozisyonu
          sidebar.style.width = `${sidebarWidth}px`;
          sidebar.style.transform = `translateX(${sidebarWidth}px)`;
          sidebar.style.transition = 'transform 0.1s ease-out';

          // Ortak handler'lar
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

            // Sınırlar
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

          // Sadece sürükleme için passive:false — tıklamaya engel olmaz
          const listeners = [
            { el: leftPanel, type: "touchstart", fn: handleTouchStart },
            { el: leftPanel, type: "touchmove", fn: handleTouchMove },
            { el: leftPanel, type: "touchend", fn: handleTouchEnd },
            { el: sidebar,    type: "touchstart", fn: handleTouchStart },
            { el: sidebar,    type: "touchmove", fn: handleTouchMove },
            { el: sidebar,    type: "touchend", fn: handleTouchEnd },
          ];

          // Ekle
          listeners.forEach(({ el, type, fn }) => {
            el.addEventListener(type, fn, { passive: false });
          });
        }

        //  Ekran küçükse başlat
        if (window.innerWidth <= 768) {
          enableSwipeSidebar();
        }
    </script>
</body>
</html>