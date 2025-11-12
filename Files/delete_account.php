<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $user_id = $_SESSION['user_id'];

    // Şifreyi doğrulama
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!password_verify($password, $user['password'])) {
        echo json_encode(['error' => 'Geçersiz şifre.']);
        exit();
    }

    // Hesabı sil (veya sıraya al)
    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt->execute([$user_id])) {
        session_destroy();
        echo json_encode(['success' => 'Hesabınız silindi.']);
    } else {
        echo json_encode(['error' => 'Bir hata oluştu.']);
    }
} else {
    echo json_encode(['error' => 'Geçersiz istek.']);
}
?>