<?php
session_start();
require_once 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $notification_id = $_POST['notification_id'];

    if (!empty($notification_id)) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $success = $stmt->execute([$notification_id, $user_id]);
        echo json_encode(['success' => $success]);
    } else {
        // Tümünü okundu say
        $stmt = $db->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
        $success = $stmt->execute([$user_id]);
        echo json_encode(['success' => $success]);
    }
}
?>