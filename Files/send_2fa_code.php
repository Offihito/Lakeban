<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz istek']);
    exit();
}

try {
    // Kullanıcı bilgilerini al
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Kullanıcı bulunamadı']);
        exit();
    }
    
    // 6 haneli kod oluştur
    $token = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Kodu veritabanına kaydet
    $stmt = $db->prepare("UPDATE users SET two_factor_token = ?, token_expiry = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?");
    $stmt->execute([$token, $_SESSION['user_id']]);
    
    // E-posta gönder
    $to = $user['email'];
    $subject = "Lakeban - İki Aşamalı Doğrulama Kodu";
    $message = "Doğrulama kodunuz: $token\n\nBu kod 5 dakika geçerlidir.";
    
    $headers = "From: Lakeban Support <lakebansupport@lakeban.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    if (mail($to, $subject, $message, $headers)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'E-posta gönderilemedi']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>