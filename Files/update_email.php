<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_email = trim($_POST['new_email']);
    $password = $_POST['password'];
    $user_id = $_SESSION['user_id'];

    // Validate email
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Geçersiz e-posta adresi.']);
        exit();
    }

    // Verify current password
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        echo json_encode(['error' => 'Geçersiz şifre.']);
        exit();
    }

    // Check if email is already in use
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$new_email, $user_id]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Bu e-posta zaten kullanılıyor.']);
        exit();
    }

    // Update email in the database
    $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
    $result = $stmt->execute([$new_email, $user_id]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'E-posta güncellendi.']);
    } else {
        echo json_encode(['error' => 'E-posta güncellenirken bir hata oluştu.']);
    }
    exit();
}
?>