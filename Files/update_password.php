<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current-password'];
    $new_password = $_POST['new-password'];
    $user_id = $_SESSION['user_id'];

    // Şifre uzunluğu kontrolü
    if (strlen($new_password) < 8) {
        echo json_encode(['error' => 'Yeni şifre en az 8 karakter olmalıdır.']);
        exit();
    }

    // Mevcut şifreyi doğrulama
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!password_verify($current_password, $user['password'])) {
        echo json_encode(['error' => 'Mevcut şifre yanlış.']);
        exit();
    }

    // Yeni şifreyi hash'le ve güncelle
    $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
    if ($stmt->execute([$new_password_hashed, $user_id])) {
        echo json_encode(['success' => 'Şifre başarıyla güncellendi.']);
    } else {
        echo json_encode(['error' => 'Bir hata oluştu.']);
    }
} else {
    echo json_encode(['error' => 'Geçersiz istek.']);
}
?>