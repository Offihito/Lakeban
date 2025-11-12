<?php
session_start();
require 'database/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel_id = $_POST['channel_id'] ?? null;

    if (!$channel_id) {
        echo json_encode(['success' => false, 'error' => 'Missing channel_id']);
        exit;
    }

    try {
        // Kanalın en son mesajını al
        $stmt = $db->prepare("SELECT MAX(id) as max_id FROM messages1 WHERE channel_id = ?");
        $stmt->execute([$channel_id]);
        $last_message = $stmt->fetch();
        $last_message_id = $last_message['max_id'];

        if ($last_message_id) {
            // user_read_messages tablosunu güncelle
            $stmt = $db->prepare("
                INSERT INTO user_read_messages (user_id, channel_id, last_read_message_id)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE last_read_message_id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $channel_id, $last_message_id, $last_message_id]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>