<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Kullanıcı girişi yapılmamış.']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $db->prepare("
        SELECT n.*, u.username as sender_username, s.name as server_name
        FROM notifications n
        JOIN users u ON n.sender_id = u.id
        LEFT JOIN servers s ON n.server_id = s.id
        WHERE n.user_id = ? AND n.is_read = FALSE
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>