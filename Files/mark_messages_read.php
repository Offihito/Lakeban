<?php
session_start();
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $channel_id = $_POST['channel_id'] ?? null;
    $user_id = $_POST['user_id'] ?? null;

    if (!$channel_id || !$user_id) {
        echo json_encode(['success' => false, 'error' => 'Eksik parametre']);
        exit;
    }

    // Son mesajın ID'sini al
    $stmt = $db->prepare("SELECT MAX(id) FROM messages1 WHERE channel_id = ?");
    $stmt->execute([$channel_id]);
    $last_message_id = $stmt->fetchColumn();

    // Okundu bilgisini güncelle
    $stmt = $db->prepare("
        INSERT INTO user_read_messages (user_id, channel_id, last_read_message_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE last_read_message_id = ?
    ");
    $stmt->execute([$user_id, $channel_id, $last_message_id, $last_message_id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>