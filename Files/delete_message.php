<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connection.php'; // Veritabanı bağlantınızı dahil edin

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açılmamış']);
    exit;
}

$userId = $_SESSION['user_id'];
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($messageId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz mesaj ID']);
    exit;
}

try {
    // Mesajın göndereni kontrol et (sadece kendi mesajlarını silebilir)
    $stmt = $db->prepare("SELECT sender_id FROM messages1 WHERE id = ?");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        echo json_encode(['success' => false, 'error' => 'Mesaj bulunamadı']);
        exit;
    }

    if ($message['sender_id'] != $userId) {
        echo json_encode(['success' => false, 'error' => 'Bu mesajı silme yetkiniz yok']);
        exit;
    }

    // Mesajı sil
    $stmt = $db->prepare("DELETE FROM messages1 WHERE id = ?");
    $stmt->execute([$messageId]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Mesaj silme hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>