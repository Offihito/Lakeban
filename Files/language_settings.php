<?php
session_start();
require 'db_connection.php'; // Veritabanı bağlantınızın olduğu dosya

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Tema ayarlarını yükleme
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
$isLakebiumUser = false;
try {
    $lakebiumStmt = $db->prepare("SELECT status FROM lakebium WHERE user_id = ? AND status = 'active'");
    $lakebiumStmt->execute([$_SESSION['user_id']]);
    $isLakebiumUser = $lakebiumStmt->fetch(PDO::FETCH_ASSOC) !== false;
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Lakebium abonelik durumu alınırken bir hata oluştu: ' . $e->getMessage();
}

$translations = loadLanguage($lang);
// Varsayılan dil (uygulamanızın varsayılanı)
$default_app_language = 'tr';

// Tarayıcı dilini algılama
function getBrowserLanguage() {
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($langs as $lang) {
            $lang_code = substr($lang, 0, 2);
            // Desteklediğiniz dillerle eşleştirin
            $supported_languages = ['tr', 'en', 'fi', 'fr', 'de', 'ru'];
            if (in_array($lang_code, $supported_languages)) {
                return $lang_code;
            }
        }
    }
    return null; // Tarayıcı dilini bulamazsa null döndür
}

// Dil seçimi kaydedilsin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_language'])) {
    $selected_language = htmlspecialchars($_POST['selected_language']);

    // "Otomatik Algıla" seçeneği seçilirse tarayıcı dilini kullan
    if ($selected_language === 'auto') {
        $selected_language = getBrowserLanguage() ?? $default_app_language;
    }

    // Kullanıcının dil tercihini oturuma kaydedin (kalıcılık için veritabanına da kaydedilebilir)
    $_SESSION['user_language'] = $selected_language;

    echo json_encode(['success' => true, 'message' => 'Dil ayarı başarıyla kaydedildi!', 'new_lang' => $selected_language]);
    exit();
}

// Kullanıcının mevcut dil tercihini al
$current_language = $_SESSION['user_language'] ?? $default_app_language; // Varsayılan olarak uygulama varsayılanı

// CSRF token oluştur
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Canlı önizleme için örnek çeviriler (sadece bu sayfa için)
$translations = [
    'tr' => [
        'title' => 'Dil Ayarları',
        'subtitle' => 'Tercih ettiğiniz dili seçin.',
        'search_placeholder' => 'Dil ara...',
        'tip_message' => 'Dil tercihiniz kaydedildi. Uygulama dili bir sonraki oturumunuzda güncellenecektir.',
        'auto_detect' => 'Otomatik Algıla (Tarayıcı Dili)',
        'reset_to_default' => 'Varsayılana Sıfırla (Türkçe)',
        'turkish' => 'Türkçe',
        'english' => 'English',
        'finnish' => 'Suomi',
        'french' => 'Français',
        'german' => 'Deutsch',
        'russian' => 'Русский',
    ],
    'en' => [
        'title' => 'Language Settings',
        'subtitle' => 'Choose your preferred language.',
        'search_placeholder' => 'Search language...',
        'tip_message' => 'Your language preference has been saved. The application language will be updated on your next session.',
        'auto_detect' => 'Auto-Detect (Browser Language)',
        'reset_to_default' => 'Reset to Default (Turkish)',
        'turkish' => 'Turkish',
        'english' => 'English',
        'finnish' => 'Finnish',
        'french' => 'French',
        'german' => 'German',
        'russian' => 'Russian',
    ],
    // Daha fazla dil için çeviriler eklenebilir
    'fi' => [
        'title' => 'Kieliasetukset',
        'subtitle' => 'Valitse haluamasi kieli.',
        'search_placeholder' => 'Hae kieltä...',
        'tip_message' => 'Kieliasetuksesi on tallennettu. Sovelluksen kieli päivitetään seuraavalla istunnollasi.',
        'auto_detect' => 'Automaattinen tunnistus (selaimen kieli)',
        'reset_to_default' => 'Palauta oletusasetukset (turkki)',
        'turkish' => 'Turkki',
        'english' => 'Englanti',
        'finnish' => 'Suomi',
        'french' => 'Ranska',
        'german' => 'Saksa',
        'russian' => 'Venäjä',
    ],
    'fr' => [
        'title' => 'Paramètres de langue',
        'subtitle' => 'Choisissez votre langue préférée.',
        'search_placeholder' => 'Rechercher une langue...',
        'tip_message' => 'Votre préférence linguistique a été enregistrée. La langue de l\'application sera mise à jour lors de votre prochaine session.',
        'auto_detect' => 'Détection automatique (langue du navigateur)',
        'reset_to_default' => 'Réinitialiser par défaut (Turc)',
        'turkish' => 'Turc',
        'english' => 'Anglais',
        'finnish' => 'Finnois',
        'french' => 'Français',
        'german' => 'Allemand',
        'russian' => 'Russe',
    ],
    'de' => [
        'title' => 'Spracheinstellungen',
        'subtitle' => 'Wählen Sie Ihre bevorzugte Sprache.',
        'search_placeholder' => 'Sprache suchen...',
        'tip_message' => 'Ihre Spracheinstellung wurde gespeichert. Die Anwendungssprache wird bei Ihrer nächsten Sitzung aktualisiert.',
        'auto_detect' => 'Automatische Erkennung (Browsersprache)',
        'reset_to_default' => 'Auf Standard zurücksetzen (Türkisch)',
        'turkish' => 'Türkisch',
        'english' => 'Englisch',
        'finnish' => 'Finnisch',
        'french' => 'Französisch',
        'german' => 'Deutsch',
        'russian' => 'Russisch',
    ],
    'ru' => [
        'title' => 'Настройки языка',
        'subtitle' => 'Выберите предпочитаемый язык.',
        'search_placeholder' => 'Поиск языка...',
        'tip_message' => 'Ваши языковые предпочтения сохранены. Язык приложения будет обновлен в вашей следующей сессии.',
        'auto_detect' => 'Автоматическое определение (язык браузера)',
        'reset_to_default' => 'Сбросить до значений по умолчанию (Турецкий)',
        'turkish' => 'Турецкий',
        'english' => 'Английский',
        'finnish' => 'Финский',
        'french' => 'Французский',
        'german' => 'Немецкий',
        'russian' => 'Русский',
    ],
];

// Mevcut dile göre çevirileri al
$lang_texts = $translations[$current_language] ?? $translations['tr']; // Bulamazsa varsayılan Türkçe
?>

<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh; --custom-background-color: <?= htmlspecialchars($currentCustomColor) ?>; --custom-secondary-color: <?= htmlspecialchars($currentSecondaryColor) ?>;">
<head>
    <meta charset="UTF-8">
    <title><?php echo $lang_texts['title']; ?></title>
    <meta name="apple-mobile-web-app-title" content="<?php echo $lang_texts['title']; ?>">
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
        .light-theme .language-option {
            background-color: #F8F9FA;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .light-theme .language-option:hover {
            background-color: #e3e5e8;
        }
        .light-theme .language-search-bar {
            background-color: #F8F9FA;
            border: 1px solid #e3e5e8;
            color: #2E3338;
        }
        .light-theme .tip {
            background-color: #F8F9FA;
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
        .dark-theme .language-option {
            background-color: #2f3136;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .dark-theme .language-option:hover {
            background-color: #35383e;
        }
        .dark-theme .language-search-bar {
            background-color: #2a2a2a;
            border: 1px solid #333;
            color: #fff;
        }
        .dark-theme .tip {
            background-color: #2f3136;
            color: #b9bbbe;
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
        .custom-theme .language-option {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .custom-theme .language-option:hover {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
        }
        .custom-theme .language-search-bar {
            background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            border: 1px solid color-mix(in srgb, var(--custom-background-color) 50%, var(--custom-secondary-color) 50%);
            color: #ffffff;
        }
        .custom-theme .tip {
            background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            color: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }
        .custom-theme input[type="radio"]:checked {
            border-color: var(--custom-secondary-color);
        }
        .custom-theme input[type="radio"]:checked::before {
            background-color: var(--custom-secondary-color);
        }

        /* Genel Stiller */
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
            background: var(--background-primary, #1E1E1E);
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--accent-color, #3CB371);
            border-radius: 2px;
        }
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
        .sidebar-item:hover, .sidebar-item.active {
            background-color: var(--background-secondary, #2f3136);
            color: var(--text-normal, #ffffff);
        }
        .sidebar-item i {
            margin-right: 8px;
        }
        .content-container {
            flex-grow: 1;
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
            background: var(--background-primary, #1E1E1E);
        }
        .content-container::-webkit-scrollbar-thumb {
            background: var(--background-secondary, #2f3136);
            border-radius: 4px;
        }
        .content-container h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 24px;
        }
        .content-container h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 24px 0 8px;
        }
        .content-container h5 {
            font-size: 14px;
            font-weight: 400;
            margin: 8px 0 16px;
        }
        .right-sidebar {
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
            background-color: var(--background-secondary, #2f3136);
            border-radius: 4px;
            padding: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .closeButton_c2b141:hover {
            background-color: var(--background-hover, #35383e);
        }
        .closeButton_c2b141 svg {
            width: 18px;
            height: 18px;
            fill: var(--text-muted, #b9bbbe);
        }
        .keybind_c2b141 {
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
        }
        .user-id svg {
            margin-right: 4px;
        }
        .edit-profile-btn {
            background-color: var(--accent-color, #4f545c);
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
            background-color: var(--accent-hover, #5a6069);
        }
        .setting-row {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            background-color: var(--background-secondary, #2f3136);
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .setting-row:hover {
            background-color: var(--background-hover, #35383e);
        }
        .setting-row svg {
            width: 24px;
            height: 24px;
            margin-right: 12px;
            fill: var(--text-muted, #b9bbbe);
        }
        .setting-content {
            flex-grow: 1;
        }
        .setting-content .title {
            font-size: 16px;
            font-weight: 600;
        }
        .setting-content .description {
            font-size: 14px;
        }
        .setting-content .description a {
            color: var(--accent-color, #3CB371);
            text-decoration: none;
        }
        .setting-content .description a:hover {
            text-decoration: underline;
        }
        .setting-action svg {
            width: 20px;
            height: 20px;
            fill: var(--text-muted, #b9bbbe);
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
            border-top: 1px solid var(--background-secondary, #2f3136);
            margin: 24px 0;
        }
        .tip {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 4px;
            font-size: 14px;
            margin-top: 16px;
        }
        .tip svg {
            width: 20px;
            height: 20px;
            margin-right: 8px;
        }
        .tip a {
            color: var(--accent-color, #3CB371);
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
            background-color: var(--background-secondary, #2f3136);
            margin: 10% auto;
            padding: 24px;
            border-radius: 8px;
            width: 400px;
            max-width: 90%;
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
            background: var(--background-primary, #202225);
            border: 1px solid var(--border-color, #141414);
            color: var(--text-normal, #ffffff);
            border-radius: 4px;
            box-sizing: border-box;
        }
        .modal-content button {
            background-color: var(--hover, #3CB371);
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
            background-color: var(--hover-dark, #248A3D);
        }
        .close {
            color: var(--text-muted, #b9bbbe);
            float: right;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .close:hover {
            color: var(--text-normal, #ffffff);
        }
        
        #back {
            display: none;
        }
        @media (max-width: 768px) {
            #back {
            display: flex;
            }
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
            .language-selector {
                padding: 0px;
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
        /* Sadece bu sayfaya özgü stil ayarlamaları */
        .language-option {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            cursor: pointer;
            border-radius: 8px;
            transition: background-color 0.2s ease;
            margin-bottom: 5px;
        }
        .language-option:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        .language-option.selected {
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--accent-color, #4a90e2);
        }
        .language-option .flag-icon {
            width: 24px;
            height: 24px;
            margin-right: 10px;
            border-radius: 3px;
            object-fit: cover;
        }
        .language-option .language-name {
            flex-grow: 1;
            font-size: 16px;
        }
        .language-option .check-icon {
            color: var(--success, #4CAF50);
            display: none;
        }
        .language-option.selected .check-icon {
            display: block;
        }
        .language-search-bar {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 8px;
            box-sizing: border-box;
        }
        .language-list {
            max-height: calc(100vh - 300px);
            overflow-y: auto;
        }
        /* Toast Bildirim Stili */
        .toast-notification {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
        }
        .toast-notification.show {
            opacity: 1;
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
        <div class="sidebar-item active" data-page="language" onclick="('language', 'language_settings_content.php')"><i data-lucide="languages"></i> <?php echo $translations['settings']['sidebar']['language'] ?? 'Dil'; ?></div>
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
        <div class="sidebar-item" data-page="notifications" onclick="('notifications', 'bildirimses_content.php')"><i data-lucide="bell"></i> <?php echo $translations['settings']['sidebar']['notifications'] ?? 'Bildirimler'; ?></div>
        </a>
        <div class="sidebar-item" data-page="keybinds" onclick="loadPage('keybinds', 'keybinds.php')"><i data-lucide="keyboard"></i> <?php echo $translations['settings']['sidebar']['keybinds'] ?? 'Tuş Atamaları'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['accessibility'] ?? 'Erişebilirlik'; ?></div>
        <div class="sidebar-item" data-page="voice" onclick="loadPage('voice', 'voice.php')"><i data-lucide="mic"></i> <?php echo $translations['settings']['sidebar']['voice'] ?? 'Ses'; ?></div>
        <div class="category"><?php echo $translations['settings']['categories']['advanced'] ?? 'Gelişmiş'; ?></div>
        <div class="sidebar-item" data-page="extra" onclick="loadPage('extra', 'extra.php')"><i data-lucide="circle-ellipsis"></i> <?php echo $translations['settings']['sidebar']['extra'] ?? 'Ekstra'; ?></div>
    </div>

        <div id="main-content" class="content-container">
            <h1 id="pageTitle"><?php echo $lang_texts['title']; ?></h1>
            <h5 id="pageSubtitle"><?php echo $lang_texts['subtitle']; ?></h5>

            <input type="text" id="languageSearch" class="language-search-bar" placeholder="<?php echo $lang_texts['search_placeholder']; ?>">

           <div class="language-selector">
    <div class="language-list">
        <!-- Auto-detect option -->
        <div class="language-option" data-lang="auto" onclick="changeLanguage('auto')">
            <i data-lucide="globe" style="width: 24px; height: 24px; margin-right: 10px;"></i>
            <span class="language-name"><?php echo $lang_texts['auto_detect']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'auto') echo 'style="display: inline;"'; ?>></i>
        </div>
        <!-- Reset to default option -->
        <div class="language-option" data-lang="<?php echo $default_app_language; ?>" onclick="changeLanguage('<?php echo $default_app_language; ?>')">
            <img src="https://flagcdn.com/w40/tr.jpg" alt="Turkish Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['reset_to_default']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == $default_app_language) echo 'style="display: inline;"'; ?>></i>
        </div>

        <hr style="margin-top: 10px; margin-bottom: 10px; border-color: rgba(255,255,255,0.1);">

        <!-- Language options -->
        <div class="language-option" data-lang="tr" onclick="changeLanguage('tr')">
            <img src="https://flagcdn.com/w40/tr.jpg" alt="Turkish Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['turkish']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'tr') echo 'style="display: inline;"'; ?>></i>
        </div>
        <div class="language-option" data-lang="en" onclick="changeLanguage('en')">
            <img src="https://flagcdn.com/w40/us.jpg" alt="English Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['english']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'en') echo 'style="display: inline;"'; ?>></i>
        </div>
        <div class="language-option" data-lang="fi" onclick="changeLanguage('fi')">
            <img src="https://flagcdn.com/w40/fi.jpg" alt="Finnish Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['finnish']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'fi') echo 'style="display: inline;"'; ?>></i>
        </div>
        <div class="language-option" data-lang="fr" onclick="changeLanguage('fr')">
            <img src="https://flagcdn.com/w40/fr.jpg" alt="French Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['french']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'fr') echo 'style="display: inline;"'; ?>></i>
        </div>
        <div class="language-option" data-lang="de" onclick="changeLanguage('de')">
            <img src="https://flagcdn.com/w40/de.jpg" alt="German Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['german']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'de') echo 'style="display: inline;"'; ?>></i>
        </div>
        <div class="language-option" data-lang="ru" onclick="changeLanguage('ru')">
            <img src="https://flagcdn.com/w40/ru.jpg" alt="Russian Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['russian']; ?></span>
            <i data-lucide="check" class="check-icon" <?php if ($lang == 'ru') echo 'style="display: inline;"'; ?>></i>
        </div>
    </div>
</div>

            <hr style="margin-top: 20px;">
            <div class="tip">
                <svg viewBox="0 0 24 24" height="20" width="20" fill="currentColor">
                    <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                </svg>
                <span id="tipMessage"><?php echo $lang_texts['tip_message']; ?></span>
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

        <div id="toastNotification" class="toast-notification"></div>
<script src="JS/language.js"></script>
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

            // Canlı önizleme için çeviriler (JavaScript tarafında da olmalı)
            const jsTranslations = {
                'tr': {
                    'title': 'Dil Ayarları',
                    'subtitle': 'Tercih ettiğiniz dili seçin.',
                    'search_placeholder': 'Dil ara...',
                    'tip_message': 'Dil tercihiniz kaydedildi. Uygulama dili bir sonraki oturumunuzda güncellenecektir.',
                    'auto_detect': 'Otomatik Algıla (Tarayıcı Dili)',
                    'reset_to_default': 'Varsayılana Sıfırla (Türkçe)',
                    'turkish': 'Türkçe',
                    'english': 'English',
                    'finnish': 'Suomi',
                    'french': 'Français',
                    'german': 'Deutsch',
                    'russian': 'Русский',
                },
                'en': {
                    'title': 'Language Settings',
                    'subtitle': 'Choose your preferred language.',
                    'search_placeholder': 'Search language...',
                    'tip_message': 'Your language preference has been saved. The application language will be updated on your next session.',
                    'auto_detect': 'Auto-Detect (Browser Language)',
                    'reset_to_default': 'Reset to Default (Turkish)',
                    'turkish': 'Turkish',
                    'english': 'English',
                    'finnish': 'Finnish',
                    'french': 'French',
                    'german': 'German',
                    'russian': 'Russian',
                },
                'fi': {
                    'title': 'Kieliasetukset',
                    'subtitle': 'Valitse haluamasi kieli.',
                    'search_placeholder': 'Hae kieltä...',
                    'tip_message': 'Kieliasetuksesi on tallennettu. Sovelluksen kieli päivitetään seuraavalla istunnollasi.',
                    'auto_detect': 'Automaattinen tunnistus (selaimen kieli)',
                    'reset_to_default': 'Palauta oletusasetukset (turkki)',
                    'turkish': 'Turkki',
                    'english': 'Englanti',
                    'finnish': 'Suomi',
                    'french': 'Ranska',
                    'german': 'Saksa',
                    'russian': 'Venäjä',
                },
                'fr': {
                    'title': 'Paramètres de langue',
                    'subtitle': 'Choisissez votre langue préférée.',
                    'search_placeholder': 'Rechercher une langue...',
                    'tip_message': 'Votre préférence linguistique a été enregistrée. La langue de l\'application sera mise à jour lors de votre prochaine session.',
                    'auto_detect': 'Détection automatique (langue du navigateur)',
                    'reset_to_default': 'Réinitialiser par défaut (Turc)',
                    'turkish': 'Turc',
                    'english': 'Anglais',
                    'finnish': 'Finnois',
                    'french': 'Français',
                    'german': 'Allemand',
                    'russian': 'Russe',
                },
                'de': {
                    'title': 'Spracheinstellungen',
                    'subtitle': 'Wählen Sie Ihre bevorzugte Sprache.',
                    'search_placeholder': 'Sprache suchen...',
                    'tip_message': 'Ihre Spracheinstellung wurde gespeichert. Die Anwendungssprache wird bei Ihrer nächsten Sitzung aktualisiert.',
                    'auto_detect': 'Automatische Erkennung (Browsersprache)',
                    'reset_to_default': 'Auf Standard zurücksetzen (Türkisch)',
                    'turkish': 'Türkisch',
                    'english': 'Englisch',
                    'finnish': 'Finnisch',
                    'french': 'Französisch',
                    'german': 'Deutsch',
                    'russian': 'Russisch',
                },
                'ru': {
                    'title': 'Настройки языка',
                    'subtitle': 'Выберите предпочитаемый язык.',
                    'search_placeholder': 'Поиск языка...',
                    'tip_message': 'Ваши языковые предпочтения сохранены. Язык приложения будет обновлен в вашей следующей сессии.',
                    'auto_detect': 'Автоматическое определение (язык браузера)',
                    'reset_to_default': 'Сбросить до значений по умолчанию (Турецкий)',
                    'turkish': 'Турецкий',
                    'english': 'Английский',
                    'finnish': 'Финский',
                    'french': 'Французский',
                    'german': 'Немецкий',
                    'russian': 'Русский',
                }
            };

            function showToast(message) {
                const toast = document.getElementById('toastNotification');
                toast.textContent = message;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000); // 3 saniye sonra kaybolur
            }

            // Canlı önizleme için metinleri güncelleme fonksiyonu
            function updateLiveText(lang) {
                const texts = jsTranslations[lang] || jsTranslations['tr']; // Bulamazsa varsayılan Türkçe
                document.getElementById('pageTitle').textContent = texts.title;
                document.getElementById('pageSubtitle').textContent = texts.subtitle;
                document.getElementById('languageSearch').placeholder = texts.search_placeholder;
                document.getElementById('tipMessage').textContent = texts.tip_message;

                // Dil seçeneklerindeki isimleri güncelle
                document.querySelector('.language-option[data-lang="auto"] .language-name').textContent = texts.auto_detect;
                // Varsayılana sıfırla metnini de güncelle
                document.querySelector('.language-option[data-lang="<?php echo $default_app_language; ?>"] .language-name').textContent = texts.reset_to_default;
                
                // Diğer dillerin isimlerini güncelle
                document.querySelector('.language-option[data-lang="tr"] .language-name').textContent = texts.turkish;
                document.querySelector('.language-option[data-lang="en"] .language-name').textContent = texts.english;
                document.querySelector('.language-option[data-lang="fi"] .language-name').textContent = texts.finnish;
                document.querySelector('.language-option[data-lang="fr"] .language-name').textContent = texts.french;
                document.querySelector('.language-option[data-lang="de"] .language-name').textContent = texts.german;
                document.querySelector('.language-option[data-lang="ru"] .language-name').textContent = texts.russian;
            }


            document.addEventListener('DOMContentLoaded', function() {
                const languageOptions = document.querySelectorAll('.language-option');
                const currentLanguage = "<?php echo $current_language; ?>"; // PHP'den gelen mevcut dil
                const languageSearchBar = document.getElementById('languageSearch');

                // Mevcut dili seçili olarak işaretle
                languageOptions.forEach(option => {
                    // Özellikle "auto" seçeneği için kontrol
                    if (option.dataset.lang === 'auto' && currentLanguage === (new Intl.NumberFormat().resolvedOptions().locale.substring(0, 2) || '<?php echo $default_app_language; ?>')) {
                        option.classList.add('selected');
                    } else if (option.dataset.lang === currentLanguage) {
                        option.classList.add('selected');
                    }

                    option.addEventListener('click', async function() {
                        // Önceki seçimi kaldır
                        languageOptions.forEach(opt => opt.classList.remove('selected'));
                        // Yeni seçimi ekle
                        this.classList.add('selected');

                        const selectedLangCode = this.dataset.lang;

                        const formData = new FormData();
                        formData.append('selected_language', selectedLangCode);
                        formData.append('csrf_token', "<?php echo $csrf_token; ?>"); // CSRF token'ı ekle

                        try {
                            const response = await fetch('language_settings.php', {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest' // Ajax isteği olduğunu belirtmek için
                                },
                                body: formData
                            });
                            const result = await response.json();

                            if (result.success) {
                                showToast(result.message);
                                // Canlı önizleme için metinleri güncelle
                                updateLiveText(result.new_lang);
                            } else {
                                showToast('Dil ayarı kaydedilirken bir hata oluştu: ' + result.message);
                            }
                        } catch (error) {
                            showToast('Bir hata oluştu. Lütfen tekrar deneyin.');
                            console.error('Dil ayarını kaydederken hata:', error);
                        }
                    });
                });

                // Dil arama işlevi
                languageSearchBar.addEventListener('keyup', function() {
                    const searchTerm = languageSearchBar.value.toLowerCase();
                    languageOptions.forEach(option => {
                        const languageName = option.querySelector('.language-name').textContent.toLowerCase();
                        // "Otomatik Algıla" ve "Varsayılana Sıfırla" seçeneklerini her zaman göster
                        if (option.dataset.lang === 'auto' || option.dataset.lang === '<?php echo $default_app_language; ?>') {
                            option.style.display = 'flex';
                        } else if (languageName.includes(searchTerm)) {
                            option.style.display = 'flex'; // Göster
                        } else {
                            option.style.display = 'none'; // Gizle
                        }
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
            });
//Mobil kaydırma

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

        <noscript>
            <div>
                <h1>JavaScript Gerekli</h1>
                <p>Bu uygulama, tam işlevsellik için JavaScript gerektirir. Lütfen tarayıcınızda JavaScript'i etkinleştirin.</p>
                <p>Daha fazla bilgi için <a href="/help">Yardım Sayfamızı</a> ziyaret edin.</p>
                <a href="language_settings" target="_blank">Yeniden Yükle</a>
            </div>
        </noscript>
    </body>
</html>