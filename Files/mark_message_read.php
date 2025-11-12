<?php
session_start();
require_once 'database/db_connection.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $messageId = $data['message_id'] ?? null;
    $userId = $data['user_id'] ?? null;

    if (!$messageId || !$userId) {
        echo json_encode(['success' => false, 'message' => 'Missing message_id or user_id']);
        exit;
    }

    // Update read_status in messages1 table
    $stmt = $db->prepare("UPDATE messages1 SET read_status = TRUE WHERE id = ? AND receiver_id = ?");
    $stmt->execute([$messageId, $userId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Message not found or already read']);
    }
} catch (PDOException $e) {
    error_log("Error marking message as read: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>