<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

$response = ['success' => false, 'messages' => [], 'error' => ''];

try {
    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'Oturum aktif değil';
        echo json_encode($response);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    if (isset($_GET['friend_id'])) {
        $friendId = (int)$_GET['friend_id'];
        // Birebir mesajlar için sabitlenmiş mesajları çek
        $stmt = $db->prepare("
            SELECT m.*, u.username, up.avatar_url
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE m.is_pinned = 1
            AND ((m.sender_id = :user_id AND m.receiver_id = :friend_id)
                OR (m.sender_id = :friend_id AND m.receiver_id = :user_id))
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId, ':friend_id' => $friendId]);
    } elseif (isset($_GET['group_id'])) {
        $groupId = (int)$_GET['group_id'];
        // Grup mesajları için sabitlenmiş mesajları çek
        $stmt = $db->prepare("
            SELECT m.*, u.username, up.avatar_url
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE m.is_pinned = 1
            AND m.group_id = :group_id
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([':group_id' => $groupId]);
        // Kullanıcının grupta olduğunu kontrol et
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = :group_id AND user_id = :user_id");
        $checkStmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
        if ($checkStmt->fetchColumn() == 0) {
            $response['error'] = 'Bu gruba üye değilsiniz';
            echo json_encode($response);
            exit;
        }
    } else {
        $response['error'] = 'Sohbet belirtilmedi';
        echo json_encode($response);
        exit;
    }

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['success'] = true;
    $response['messages'] = $messages;
} catch (Exception $e) {
    error_log('get_pinned_messages.php hatası: ' . $e->getMessage());
    $response['error'] = 'Sunucu hatası: ' . $e->getMessage();
}

echo json_encode($response);
?>