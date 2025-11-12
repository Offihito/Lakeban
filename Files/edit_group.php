<?php
session_start();
require 'db_connection.php'; // DB bağlantısı için

if (!isset($_SESSION['user_id'])) {
    die("Yetkisiz erişim!");
}

$group_id = $_POST['group_id'];
$new_name = $_POST['name'];
$current_user = $_SESSION['user_id'];

// Grup sahibi mi kontrolü
$check = $db->prepare("SELECT creator_id FROM groups WHERE id = ?");
$check->execute([$group_id]);
$group = $check->fetch();

if ($group['creator_id'] != $current_user) {
    die("Sadece grup sahibi ayarları değiştirebilir!");
}

// Avatar güncelleme
$avatar_url = $group['avatar_url'];
if ($_FILES['avatar']['size'] > 0) {
    $uploadDir = 'group_avatars/';
    $fileName = uniqid() . '_' . basename($_FILES['avatar']['name']);
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
        $avatar_url = $targetPath;
    }
}

// Veritabanını güncelle
$stmt = $db->prepare("UPDATE groups SET name = ?, avatar_url = ? WHERE id = ?");
$stmt->execute([$new_name, $avatar_url, $group_id]);

echo json_encode(['success' => true]);
?>