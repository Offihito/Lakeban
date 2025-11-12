<?php
session_start();
require_once 'db_connection.php';

// Tema ayarları için varsayılan değerler (settings.php ile uyumlu)
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';
$currentCustomColor = $_SESSION['custom_color'] ?? $defaultCustomColor;
$currentSecondaryColor = $_SESSION['custom_secondary_color'] ?? $defaultSecondaryColor;

// Ses seçenekleri
$sounds = [
    '/bildirim.mp3' => 'Klasik Bildirim',
    '/bildiri.mp3' => 'Modern Bildirim',
    '/bildir.mp3' => 'Uzay Boşluğu',
    '/bildirim2.mp3' => 'Xp sesi',
];

// Bildirim sesi işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo '<div class="tip" style="background-color: #2f3136; color: #ed5151;"><i data-lucide="x-circle"></i> CSRF token doğrulanamadı.</div>';
        exit;
    }

    $selectedSound = $_POST['notification_sound'];
    if (!array_key_exists($selectedSound, $sounds)) {
        echo '<div class="tip" style="background-color: #2f3136; color: #ed5151;"><i data-lucide="x-circle"></i> Geçersiz bildirim sesi seçimi.</div>';
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE users SET notification_sound = ? WHERE id = ?");
        $stmt->execute([$selectedSound, $_SESSION['user_id']]);
        $_SESSION['sound_updated'] = true;
    } catch (PDOException $e) {
        echo '<div class="tip" style="background-color: #2f3136; color: #ed5151;"><i data-lucide="x-circle"></i> Bildirim sesi güncellenirken hata: ' . htmlspecialchars($e->getMessage()) . '</div>';
        exit;
    }
}

// Mevcut bildirim sesini yükle
$currentSound = '';
try {
    $userStmt = $db->prepare("SELECT notification_sound FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $currentSound = $userStmt->fetchColumn();
} catch (PDOException $e) {
    echo '<div class="tip" style="background-color: #2f3136; color: #ed5151;"><i data-lucide="x-circle"></i> Mevcut bildirim sesi alınırken hata: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
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
    .edit-profile-btn {
        background-color: var(--accent-color);
        color: #ffffff;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }
    .edit-profile-btn:hover {
        background-color: #2e9b5e;
    }
</style>
</head>
<body>
<h1>Bildirim Sesi Seçin</h1>

<?php if (isset($_SESSION['sound_updated'])): ?>
    <div class="tip" style="background-color: #2f3136; color: var(--hover);">
        <i data-lucide="check-circle"></i> Ayarlar başarıyla kaydedildi!
    </div>
    <?php unset($_SESSION['sound_updated']); ?>
<?php elseif (isset($_SESSION['error_message'])): ?>
    <div class="tip" style="background-color: #2f3136; color: #ed5151;">
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
                <input type="radio" name="notification_sound" value="<?= htmlspecialchars($path) ?>" <?= $path === $currentSound ? 'checked' : '' ?>>
                <button type="button" class="preview-btn" data-sound="<?= htmlspecialchars($path) ?>">
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

<script>
    // Ses önizleme için JavaScript
    document.querySelectorAll('.preview-btn').forEach(button => {
        button.addEventListener('click', () => {
            const soundPath = button.getAttribute('data-sound');
            const audio = new Audio(soundPath);
            audio.play();
        });
    });
</script>
    </body>
</html>