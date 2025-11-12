<?php
session_start();
require_once 'db_connection.php'; // Database bağlantısı için

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

// Tüm arkadaşlar için okunmamış mesaj sayılarını al
$stmt = $db->prepare("
    SELECT 
        u.id as friend_id,
        COUNT(m.id) as unread_count
    FROM friends f
    JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
    LEFT JOIN messages1 m ON m.sender_id = u.id 
        AND m.receiver_id = :user_id 
        AND m.read_status = FALSE
    WHERE (f.user_id = :user_id OR f.friend_id = :user_id) 
        AND u.id != :user_id
    GROUP BY u.id
");
$stmt->execute([':user_id' => $userId]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'counts' => array_column($results, 'unread_count', 'friend_id')
]);