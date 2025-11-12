<?php
session_start();
require_once 'config.php';

// Hata raporlamayı açalım
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Kullanıcı giriş kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Lakebium (Premium) kontrolü
$isPremium = false;
try {
    $stmt = $conn->prepare("SELECT status, end_date FROM lakebium WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $lakebium = $result->fetch_assoc();
    if ($lakebium && ($lakebium['end_date'] === null || $lakebium['end_date'] > date('Y-m-d H:i:s'))) {
        $isPremium = true;
    }
} catch (Exception $e) {
    error_log("Lakebium sorgu hatası: " . $e->getMessage());
}

// Premium değilse yönlendir
if (!$isPremium) {
    header("Location: index.php");
    exit;
}

// Mevcut çerçeve bilgisini çek
$current_frame = '';
$stmt = $conn->prepare("SELECT avatar_frame_url FROM user_profiles WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user_profile = $result->fetch_assoc();
if ($user_profile && !empty($user_profile['avatar_frame_url'])) {
    $current_frame = $user_profile['avatar_frame_url'];
}

// Çerçeve güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['frame'])) {
    $selected_frame = $_POST['frame'];
    $allowed_frames = ['frames/lakebanframe1.gif', 'frames/lakebanframe2.gif'];
    if (in_array($selected_frame, $allowed_frames) || $selected_frame === 'none') {
        $frame_to_save = $selected_frame === 'none' ? null : $selected_frame;
        $stmt = $conn->prepare("UPDATE user_profiles SET avatar_frame_url = ? WHERE user_id = ?");
        $stmt->bind_param("si", $frame_to_save, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $current_frame = $frame_to_save;
            $_SESSION['settings_updated'] = 'saved';
        } else {
            $_SESSION['error_message'] = "Çerçeve güncellenirken bir hata oluştu.";
        }
    } else {
        $_SESSION['error_message'] = "Geçersiz çerçeve seçimi.";
    }
    header("Location: frame_settings.php");
    exit;
}

// Varsayılan dil ve dil yükleme
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

// Tema ayarlarını yükle
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';
$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

try {
    $stmt = $conn->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    if ($userData) {
        $currentTheme = $userData['theme'] ?? $defaultTheme;
        $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
        $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
    }
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Tema ayarları alınırken bir hata oluştu: ' . $e->getMessage();
}

// CSRF token oluştur
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="<?php echo htmlspecialchars($currentTheme); ?>-theme" style="--app-height: 100vh;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $translations['frame_settings_title'] ?? 'Profil Çerçevesi Ayarları'; ?> - LakeBan</title>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.5.0/css/flag-icon.min.css">
    <style>
        :root {
            --accent-color: <?php echo $currentSecondaryColor; ?>;
            --font-size: 16px;
            --custom-background-color: <?php echo htmlspecialchars($currentCustomColor); ?>;
            --custom-secondary-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
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

        /* Aydınlık Tema */
        .light-theme body { background-color: #F2F3F5; color: #2E3338; }
        .light-theme .sidebar, .light-theme .content-container, .light-theme .right-sidebar { background-color: #FFFFFF; }
        .light-theme .sidebar-item { color: #4F5660; }
        .light-theme .sidebar-item:hover, .light-theme .sidebar-item.active { background-color: #e3e5e8; color: #060607; }
        .light-theme .content-container h1, .light-theme .content-container h3, .light-theme .frame-card h4 { color: #060607; }
        .light-theme .content-container h5, .light-theme .category, .light-theme .setting-content .description { color: #4F5660; }
        .light-theme hr { border-top: 1px solid #e3e5e8; }
        .light-theme .frame-card { background-color: #F8F9FA; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        .light-theme .frame-card.active { border-color: #007bff; }
        .light-theme .edit-profile-btn { background-color: var(--accent-color); }
        .light-theme .edit-profile-btn:hover { background-color: #2e9b5e; }

        /* Koyu Tema */
        .dark-theme body { background-color: #1E1E1E; color: #ffffff; }
        .dark-theme .sidebar, .dark-theme .content-container, .dark-theme .right-sidebar { background-color: #242424; }
        .dark-theme .sidebar-item { color: #b9bbbe; }
        .dark-theme .sidebar-item:hover, .dark-theme .sidebar-item.active { background-color: #2f3136; color: #ffffff; }
        .dark-theme .frame-card { background-color: #2f3136; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); }
        .dark-theme .frame-card.active { border-color: var(--accent-color); }
        .dark-theme .edit-profile-btn { background-color: var(--accent-color); }
        .dark-theme .edit-profile-btn:hover { background-color: #2e9b5e; }

        /* Özel Tema */
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
        .custom-theme .content-container h1, .custom-theme .content-container h3, .custom-theme .frame-card h4 { color: #ffffff; }
        .custom-theme .content-container h5, .custom-theme .category, .custom-theme .setting-content .description { 
            color: color-mix(in srgb, var(--custom-background-color) 40%, white); 
        }
        .custom-theme hr { 
            border-top: 1px solid color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
        }
        .custom-theme .frame-card { 
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); 
        }
        .custom-theme .frame-card.active { border-color: var(--custom-secondary-color); }
        .custom-theme .edit-profile-btn { 
            background-color: var(--custom-secondary-color); 
        }
        .custom-theme .edit-profile-btn:hover { 
            background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, white 20%); 
        }

        /* Genel Stiller */
        .app-container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            height: var(--app-height);
            padding: 24px;
            box-sizing: border-box;
        }
        .sidebar {
            width: 260px;
            padding: 16px 8px;
            overflow-y: auto;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: #1E1E1E; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--accent-color); border-radius: 2px; }
        .category {
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
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .sidebar-item i { margin-right: 8px; }
        .content-container {
            flex-grow: 1;
            padding: 24px;
            overflow-y: auto;
            margin-left: 16px;
            margin-right: 16px;
            border-radius: 8px;
        }
        .content-container::-webkit-scrollbar { width: 8px; }
        .content-container::-webkit-scrollbar-track { background: #1E1E1E; }
        .content-container::-webkit-scrollbar-thumb { background: #2f3136; border-radius: 4px; }
        .content-container h1 { font-size: 20px; font-weight: 600; margin: 0 0 24px; }
        .content-container h3 { font-size: 16px; font-weight: 600; margin: 24px 0 8px; }
        .content-container h5 { font-size: 14px; font-weight: 400; margin: 8px 0 16px; }
        .right-sidebar {
            width: 72px;
            padding: 16px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-radius: 8px;
            flex-shrink: 0;
        }
        hr { border: none; border-top: 1px solid #2f3136; margin: 24px 0; }
        .edit-profile-btn {
            background-color: var(--accent-color);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .edit-profile-btn:hover { transform: translateY(-2px); }
        .edit-profile-btn:disabled { background-color: #5c6b73; cursor: not-allowed; transform: none; }

        /* Frame Seçici */
        .frame-selector-list {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
        }
        .frame-card {
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
        .frame-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }
        .frame-card.active {
            border-color: var(--accent-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .frame-card img {
            width: 120px;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
        }
        .frame-card h4 { margin: 0; font-size: 16px; }
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
        .frame-card.active .checkmark-icon { display: block; }
        .custom-theme .checkmark-icon {
            color: var(--custom-secondary-color);
        }
        .custom-theme .frame-card.active .checkmark-icon {
            color: #fff;
            background-color: var(--custom-secondary-color);
        }
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

        /* Responsive Tasarım */
        @media (max-width: 1024px) {
            .app-container { flex-direction: column; padding: 16px; }
            .sidebar { width: 100%; margin-bottom: 16px; }
            .content-container { width: 100%; margin-left: 0; margin-right: 0; }
            .right-sidebar { display: none; }
            .frame-selector-list { flex-direction: column; align-items: center; }
            .frame-card { width: 90%; }
        }
        @media (max-width: 768px) {
            .app-container { padding: 16px; }
            .sidebar { position: absolute; width: 100%; height: 100vh; left: 0%; margin-bottom: 16px; border-radius: 8px; }
            #back { display: flex; }
            .content-container { padding-left: 6px !important; padding: 0; position: absolute; width: 100%; height: 100vh; left: 0%; margin-left: 0; margin-right: 0; border-radius: 8px; z-index: 5; }
            .right-sidebar { display: none; }
        }
    </style>
</head>
<body style="background-color: <?php echo $currentTheme === 'custom' ? htmlspecialchars($currentCustomColor) : ''; ?>;">
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
                <div class="sidebar-item active" data-page="avatar-frame" onclick="('avatar-frame', 'avatar_frame_content.php')">
                    <i data-lucide="frame"></i> <?php echo $translations['settings']['sidebar']['avatar_frame'] ?? 'Avatar Çerçevesi'; ?>
                </div>
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
            <h1><?php echo $translations['frame_settings_title'] ?? 'Profil Çerçevesi Ayarları'; ?></h1>

            <?php if (isset($_SESSION['settings_updated'])): ?>
                <?php if ($_SESSION['settings_updated'] === 'saved'): ?>
                    <div class="tip" style="background-color: #2f3136; color: var(--accent-color);">
                        <i data-lucide="check-circle"></i> <?php echo $translations['settings_saved'] ?? 'Ayarlar başarıyla kaydedildi!'; ?>
                    </div>
                <?php endif; ?>
                <?php unset($_SESSION['settings_updated']); ?>
            <?php elseif (isset($_SESSION['error_message'])): ?>
                <div class="tip" style="background-color: #2f3136; color: #ed5151;">
                    <i data-lucide="x-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <form method="POST" id="frameSettingsForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <h3><?php echo $translations['frame_selection'] ?? 'Çerçeve Seçimi'; ?></h3>
                <div class="frame-selector-list">
                    <div class="frame-card <?php echo $current_frame === 'frames/lakebanframe1.gif' ? 'active' : ''; ?>" data-frame="frames/lakebanframe1.gif">
                        <i data-lucide="check-circle" class="checkmark-icon"></i>
                        <img src="../frames/lakebanframe1.gif" alt="LakeBan Çerçeve 1">
                        <h4><?php echo $translations['frame1'] ?? 'Çerçeve 1'; ?></h4>
                        <input type="radio" name="frame" value="frames/lakebanframe1.gif" <?php echo $current_frame === 'frames/lakebanframe1.gif' ? 'checked' : ''; ?> style="display: none;">
                    </div>
                    <div class="frame-card <?php echo $current_frame === 'frames/lakebanframe2.gif' ? 'active' : ''; ?>" data-frame="frames/lakebanframe2.gif">
                        <i data-lucide="check-circle" class="checkmark-icon"></i>
                        <img src="../frames/lakebanframe2.gif" alt="LakeBan Çerçeve 2">
                        <h4><?php echo $translations['frame2'] ?? 'Çerçeve 2'; ?></h4>
                        <input type="radio" name="frame" value="frames/lakebanframe2.gif" <?php echo $current_frame === 'frames/lakebanframe2.gif' ? 'checked' : ''; ?> style="display: none;">
                    </div>
                    <div class="frame-card <?php echo $current_frame === null ? 'active' : ''; ?>" data-frame="none">
                        <i data-lucide="check-circle" class="checkmark-icon"></i>
                        <div style="width: 120px; height: 90px; background: rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--custom-background-color);">
                            <?php echo $translations['frame_none'] ?? 'Çerçeve Yok'; ?>
                        </div>
                        <h4><?php echo $translations['frame_none'] ?? 'Çerçeve Yok'; ?></h4>
                        <input type="radio" name="frame" value="none" <?php echo $current_frame === null ? 'checked' : ''; ?> style="display: none;">
                    </div>
                </div>
                <button type="submit" class="edit-profile-btn" id="saveSettingsBtn" style="margin-top: 20px; width: 100%;" disabled>
                    <i data-lucide="save"></i> <?php echo $translations['frame_save'] ?? 'Değişiklikleri Kaydet'; ?>
                </button>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const docElement = document.documentElement;
        const frameSettingsForm = document.getElementById('frameSettingsForm');
        const saveSettingsBtn = document.getElementById('saveSettingsBtn');
        let initialFormState = new FormData(frameSettingsForm);

        function updateSaveButtonState() {
            const currentFormState = new FormData(frameSettingsForm);
            const initialFrame = initialFormState.get('frame');
            const currentFrame = currentFormState.get('frame');
            saveSettingsBtn.disabled = initialFrame === currentFrame;
        }

        document.querySelectorAll('.frame-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.frame-card').forEach(item => item.classList.remove('active'));
                this.classList.add('active');
                this.querySelector('input[type="radio"]').checked = true;
                updateSaveButtonState();
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            initialFormState = new FormData(frameSettingsForm);
            updateSaveButtonState();
        });

        frameSettingsForm.addEventListener('change', updateSaveButtonState);

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

        function changeLanguage(lang) {
            window.location.href = window.location.pathname + '?lang=' + lang;
        }
    </script>
</body>
</html>