<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantısı

$channel_id = $_GET['channel_id'] ?? 0;

try {
    $stmt = $db->prepare("SELECT name FROM channels WHERE id = ?");
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch();

    if ($channel) {
        echo json_encode([
            'success' => true,
            'channel_name' => $channel['name']
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}