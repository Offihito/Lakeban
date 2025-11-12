<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Oturum bulunamadı.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $user_id = $_SESSION['user_id'];

    // Şifreyi doğrulama
    $stmt = $db->prepare("SELECT password, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!password_verify($password, $user['password'])) {
        echo json_encode(['error' => 'Geçersiz şifre.']);
        exit();
    }

    echo json_encode(['email' => $user['email']]);
} else {
    echo json_encode(['error' => 'Geçersiz istek.']);
}
?>