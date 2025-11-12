<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Mevcut getFriends fonksiyonunu kullan
function getFriends($db, $userId, $includeHidden = false) {
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
            MAX(m.created_at) as last_interaction,
            f.is_hidden
        FROM friends f
        JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN messages1 m ON (m.sender_id = u.id OR m.receiver_id = u.id) 
            AND (m.sender_id = ? OR m.receiver_id = ?)
        WHERE (f.user_id = ? OR f.friend_id = ?) 
        AND u.id != ?
        " . ($includeHidden ? "" : "AND f.is_hidden = FALSE") . "
        GROUP BY u.id
        ORDER BY last_interaction DESC, is_online DESC, u.username ASC
    ";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    return $stmt->fetchAll();
}

$friends = getFriends($db, $_SESSION['user_id']);
echo json_encode(['success' => true, 'friends' => $friends]);
?>