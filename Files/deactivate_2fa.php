<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz istek']);
    exit();
}

try {
    // 2FA'yı devre dışı bırak
    $stmt = $db->prepare("UPDATE users SET two_factor_enabled = 0 WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>