<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$senderId = $_SESSION['user_id'];
$receiverId = $_POST['receiver_id'];

// İsteği veritabanından sil
$stmt = $db->prepare("DELETE FROM friend_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
$success = $stmt->execute([$senderId, $receiverId]);

if ($success && $stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'İstek bulunamadı veya zaten işlenmiş']);
}
?>