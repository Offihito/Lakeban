<?php
session_start();
require 'db_connection.php';

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'User not logged in']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Arkadaşlık ilişkisini iki yönlü kontrol et ve users tablosundan status al
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.username,
            u.status,  -- users tablosundan status (online, idle, dnd, offline)
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, u.last_activity, NOW()) <= 2 THEN 1 
                ELSE 0 
            END as is_online
        FROM friends f
        JOIN users u ON (
            (f.user_id = :user_id AND u.id = f.friend_id) OR  
            (f.friend_id = :user_id AND u.id = f.user_id)     
        )
        WHERE :user_id IN (f.user_id, f.friend_id)
        GROUP BY u.id
    ");
    
    $stmt->execute(['user_id' => $userId]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Her arkadaş için status alanının geçerli olduğundan emin ol
    foreach ($friends as &$friend) {
        $friend['status'] = $friend['status'] ?? 'offline'; // Varsayılan olarak offline
    }

    header('Content-Type: application/json');
    echo json_encode($friends);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>