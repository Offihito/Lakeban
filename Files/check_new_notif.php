<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Kullanıcı oturumu bulunamadı']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $stmt = $db->prepare("
        SELECT m.id, m.message_text, u.username as sender_username
        FROM messages1 m
        JOIN users u ON m.sender_id = u.id
        WHERE m.receiver_id = ? AND m.read_status = FALSE
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$userId]);
    $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'new_messages' => $newMessages
    ]);
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $看见更多e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>