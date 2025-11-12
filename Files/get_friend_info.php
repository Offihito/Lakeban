<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

if (!isset($_GET['friend_id'])) {
    echo json_encode(['success' => false, 'error' => 'Arkadaş ID belirtilmedi']);
    exit;
}

$friendId = (int)$_GET['friend_id'];
$userId = $_SESSION['user_id'];

try {
    $query = "
        SELECT 
            u.id, 
            u.username, 
            up.avatar_url,
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, u.last_activity, CURRENT_TIMESTAMP) <= 2 THEN 1 
                ELSE 0 
            END as is_online,
            COALESCE((
                SELECT COUNT(*) 
                FROM messages1 
                WHERE sender_id = u.id AND receiver_id = ? AND read_status = FALSE
            ), 0) as unread_messages,
            COALESCE((
                SELECT MAX(created_at) 
                FROM messages1 
                WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
            ), '1970-01-01 00:00:00') as last_interaction
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ?
    ";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $userId, $friendId, $friendId]);
    $friend = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($friend) {
        echo json_encode(['success' => true, 'friend' => $friend]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Arkadaş bulunamadı']);
    }
} catch (PDOException $e) {
    error_log("Hata: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>