<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantısı

header('Content-Type: application/json');

$userId = $_SESSION['user_id'];
$response = ['success' => false];

try {
    $stmt = $db->prepare("
        SELECT friend_id, 
               TIMESTAMPDIFF(MINUTE, last_activity, NOW()) <= 5 AS is_online
        FROM friends 
        INNER JOIN users ON users.id = friends.friend_id
        WHERE friends.user_id = ?
    ");
    $stmt->execute([$userId]);
    $onlineStatus = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $onlineStatus[(string)$row['friend_id']] = (bool)$row['is_online'];
}
    
    $response = [
        'success' => true,
        'onlineStatus' => $onlineStatus
    ];
} catch (PDOException $e) {
    $response['error'] = 'Database error';
}

echo json_encode($response);