<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// CSRF kontrolü
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz CSRF token.']);
    exit();
}

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açılmadı.']);
    exit();
}

// Dosya yükleme kontrolü
if (!isset($_FILES['showcase_image']) || $_FILES['showcase_image']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => 'Dosya seçilmedi.']);
    exit();
}

$file = $_FILES['showcase_image'];
$allowed_types = ['image/png', 'image/jpeg'];
$max_size = 5 * 1024 * 1024; // 5MB

// Dosya türü ve boyut kontrolü
if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'Sadece PNG ve JPEG dosyaları desteklenir.']);
    exit();
}

if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'Dosya boyutu 5MB\'dan büyük olamaz.']);
    exit();
}

// Dosya yükleme
$upload_dir = 'uploads/showcase/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$file_name = $_SESSION['user_id'] . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
$destination = $upload_dir . $file_name;

if (move_uploaded_file($file['tmp_name'], $destination)) {
    // Veritabanını güncelle
    $stmt = $db->prepare("UPDATE user_profiles SET showcase_image_url = ? WHERE user_id = ?");
    $stmt->execute([$destination, $_SESSION['user_id']]);

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Dosya yüklenirken hata oluştu.']);
}
?>