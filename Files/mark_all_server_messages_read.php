<?php
session_start();
require_once 'database/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açılmadı']);
    exit;
}

$userId = $_SESSION['user_id'];
$serverId = isset($_POST['server_id']) ? (int)$_POST['server_id'] : null;

if (!$serverId) {
    echo json_encode(['success' => false, 'error' => 'Sunucu ID belirtilmedi']);
    exit;
}

try {
    $db->beginTransaction();

    // Sunucudaki tüm metin ve duyuru kanallarını al
    $stmt = $db->prepare("
        SELECT c.id
        FROM channels c
        WHERE c.server_id = :server_id
        AND c.type IN ('text', 'announcement')
        AND (c.restricted_to_role_id IS NULL 
             OR c.restricted_to_role_id IN (
                 SELECT role_id FROM user_roles WHERE user_id = :user_id AND server_id = :server_id
             )
             OR EXISTS (
                 SELECT 1 FROM servers WHERE id = :server_id AND owner_id = :user_id
             ))
    ");
    $stmt->execute([':server_id' => $serverId, ':user_id' => $userId]);
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($channels as $channel) {
        // Kanalın son mesaj ID'sini al
        $stmt = $db->prepare("
            SELECT MAX(id) as latest_message_id
            FROM messages1
            WHERE channel_id = :channel_id
        ");
        $stmt->execute([':channel_id' => $channel['id']]);
        $latestMessageId = $stmt->fetchColumn();

        if ($latestMessageId) {
            // user_read_messages tablosunu güncelle
            $stmt = $db->prepare("
                INSERT INTO user_read_messages (user_id, channel_id, last_read_message_id)
                VALUES (:user_id, :channel_id, :last_read_message_id)
                ON DUPLICATE KEY UPDATE last_read_message_id = :last_read_message_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':channel_id' => $channel['id'],
                ':last_read_message_id' => $latestMessageId
            ]);
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Tüm mesajlar okundu olarak işaretlendi']);
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Tüm mesajları okundu işaretleme hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Bir hata oluştu']);
}
?>