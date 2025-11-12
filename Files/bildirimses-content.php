<?php
session_start();
require_once 'db_connection.php';

// Bu dosyanın doğrudan çağrılmasını veya AJAX dışı post isteklerini yönetebiliriz.
// Şimdilik basit tutuyoruz.

// Ses seçenekleri
$sounds = [
    '/bildirim.mp3' => 'Klasik Bildirim',
    '/bildiri.mp3' => 'Modern Bildirim',
    '/bildir.mp3' => 'Uzay Boşluğu',
    '/bildirim2.mp3' => 'Xp sesi',
];

// POST isteği geldiğinde (form gönderildiğinde)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); // JSON yanıtı döndüreceğimizi belirtiyoruz.

    // CSRF Koruması
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz güvenlik anahtarı.']);
        exit;
    }

    $selectedSound = $_POST['notification_sound'];
    if (!array_key_exists($selectedSound, $sounds)) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz bildirim sesi seçimi.']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE users SET notification_sound = ? WHERE id = ?");
        $stmt->execute([$selectedSound, $_SESSION['user_id']]);
        echo json_encode(['status' => 'success', 'message' => 'Bildirim sesi başarıyla güncellendi!']);
    } catch (PDOException $e) {
        // Gerçek bir uygulamada hatayı loglamak daha iyidir.
        echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası oluştu.']);
    }
    exit; // İşlem bitti, HTML içeriğini göndermeye gerek yok.
}

// Mevcut bildirim sesini yükle (Sayfa içeriği istendiğinde çalışır)
$currentSound = '';
try {
    $userStmt = $db->prepare("SELECT notification_sound FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $currentSound = $userStmt->fetchColumn();
} catch (PDOException $e) {
    // Hata yönetimi
    $currentSound = array_key_first($sounds); // Hata olursa varsayılan sesi seç
}

// CSRF token oluştur
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
?>

<div class="content-container">
    <h1>Bildirim Sesi Seçin</h1>

    <div id="notification-message"></div>

    <form id="sound-form" method="POST">
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

        <button type="submit" class="edit-profile-btn">
            <i data-lucide="save"></i> Değişiklikleri Kaydet
        </button>
    </form>
</div>

<script>
    // Ses önizleme fonksiyonu
    function previewSound(soundPath) {
        const audio = new Audio(soundPath);
        audio.play().catch(e => console.error("Ses çalınamadı:", e));
    }

    const soundForm = document.getElementById('sound-form');
    const messageDiv = document.getElementById('notification-message');

    if (soundForm) {
        soundForm.addEventListener('submit', async function(e) {
            e.preventDefault(); // Formun normal gönderimini engelle

            const formData = new FormData(this);
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true; // Butonu geçici olarak devre dışı bırak
            submitButton.innerHTML = '<i data-lucide="loader-2" class="animate-spin"></i> Kaydediliyor...';
            lucide.createIcons();


            try {
                const response = await fetch('bildirimses-content.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                // Mesajı göster
                let messageClass = result.status === 'success' ? 'tip" style="background-color: #2f3136; color: var(--hover);"' : 'tip" style="background-color: #2f3136; color: var(--error);"';
                let icon = result.status === 'success' ? 'check-circle' : 'x-circle';
                
                messageDiv.innerHTML = `<div class="${messageClass}"><i data-lucide="${icon}"></i> ${result.message}</div>`;
                lucide.createIcons(); // Mesajdaki yeni ikonu render et

                // Mesajı bir süre sonra kaldır
                setTimeout(() => {
                    messageDiv.innerHTML = '';
                }, 5000);

            } catch (error) {
                messageDiv.innerHTML = `<div class="tip" style="background-color: #2f3136; color: var(--error);"><i data-lucide="x-circle"></i> Bir hata oluştu.</div>`;
                 lucide.createIcons();
            } finally {
                // Butonu tekrar aktif et
                submitButton.disabled = false;
                submitButton.innerHTML = '<i data-lucide="save"></i> Değişiklikleri Kaydet';
                lucide.createIcons();
            }
        });
    }
</script>