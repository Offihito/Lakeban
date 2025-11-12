<?php
session_start();
require_once 'db_connection.php'; // DB bağlantısı için

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Yetkisiz erişim']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'] ?? '';
    $allowedStatuses = ['online', 'idle', 'dnd', 'offline'];
    
    if (!in_array($status, $allowedStatuses)) {
        die(json_encode(['success' => false, 'error' => 'Geçersiz durum']));
    }

    try {
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Status update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}