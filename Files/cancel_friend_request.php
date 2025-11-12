<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json'); // JSON header ekle

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$senderId = (int)$_SESSION['user_id'];
$receiverId = (int)$_POST['receiver_id'];

// Geçerli ID kontrolü
if ($receiverId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz alıcı IDsi']);
    exit;
}

try {
    $stmt = $db->prepare("DELETE FROM friend_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
    $stmt->execute([$senderId, $receiverId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'İstek bulunamadı veya zaten işlenmiş']);
    }
} catch (PDOException $e) {
    error_log("İstek iptal hatası: ".$e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası']);
}
?>