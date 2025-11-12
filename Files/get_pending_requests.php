<?php
session_start();
require_once 'database/db_connection.php'; // Adjust path if needed

function getFriendRequests($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            u.id, 
            u.username, 
            up.avatar_url
        FROM friend_requests fr
        JOIN users u ON fr.sender_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE fr.receiver_id = ? AND fr.status = 'pending'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$requests = getFriendRequests($db, $_SESSION['user_id']);
echo json_encode(['success' => true, 'requests' => $requests]);
?>