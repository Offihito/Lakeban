<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Giriş yapmanız gerekiyor']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $stmt = $db->prepare("
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
            CASE 
                WHEN EXISTS (
                    SELECT 1 
                    FROM hidden_friends hf 
                    WHERE hf.user_id = ? AND hf.friend_id = u.id
                ) THEN 1 
                ELSE 0 
            END as is_hidden
        FROM friends f
        JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN messages1 m ON (m.sender_id = u.id OR m.receiver_id = u.id) 
            AND (m.sender_id = ? OR m.receiver_id = ?)
        WHERE (f.user_id = ? OR f.friend_id = ?) 
        AND u.id != ?
        AND NOT EXISTS (
            SELECT 1 
            FROM hidden_friends hf 
            WHERE hf.user_id = ? AND hf.friend_id = u.id
        )
        GROUP BY u.id
        ORDER BY last_interaction DESC, is_online DESC, u.username ASC
    ");
    
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'friends' => $friends]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>