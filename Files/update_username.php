<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = trim($_POST['new_username']);
    $password = $_POST['password'];
    $user_id = $_SESSION['user_id'];

    // Kullanıcı adı doğrulaması
    if (empty($new_username) || strlen($new_username) < 3 || strlen($new_username) > 20) {
        echo json_encode(['error' => 'Kullanıcı adı 3-20 karakter arasında olmalıdır.']);
        exit();
    }

    // Mevcut şifreyi doğrulama
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!password_verify($password, $user['password'])) {
        echo json_encode(['error' => 'Geçersiz şifre.']);
        exit();
    }

    // Kullanıcı adının zaten kullanılıp kullanılmadığını kontrol et
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$new_username, $user_id]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Bu kullanıcı adı zaten alınmış.']);
        exit();
    }

    // Kullanıcı adını güncelle
    $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
    if ($stmt->execute([$new_username, $user_id])) {
        $_SESSION['username'] = $new_username;
        echo json_encode(['success' => 'Kullanıcı adı başarıyla güncellendi.']);
    } else {
        echo json_encode(['error' => 'Bir hata oluştu.']);
    }
} else {
    echo json_encode(['error' => 'Geçersiz istek.']);
}
?>