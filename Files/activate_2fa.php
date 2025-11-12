<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz istek']);
    exit();
}

$code = trim($_POST['code']);

try {
    // Kodu doğrula
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND two_factor_token = ? AND token_expiry > NOW()");
    $stmt->execute([$_SESSION['user_id'], $code]);
    
    if ($stmt->rowCount() > 0) {
        // 2FA'yı etkinleştir
        $stmt = $db->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_token = NULL, token_expiry = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Geçersiz veya süresi dolmuş kod']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>