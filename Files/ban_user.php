<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Veritabanı bağlantısı
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()]));
}

// Kullanıcının oturum açıp açmadığını kontrol edin
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Bu işlemi yapmak için oturum açmalısınız.']));
}

// POST verilerini alın
$user_id = $_POST['user_id'] ?? null;
$server_id = $_POST['server_id'] ?? null;

if (!$user_id || !$server_id) {
    die(json_encode(['success' => false, 'message' => 'Geçersiz istek.']));
}

// Kullanıcının `users` tablosunda olup olmadığını kontrol edin
$stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_exists = $stmt->fetch();

if (!$user_exists) {
    die(json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı.']));
}

// Kullanıcıyı banla ve sunucudan çıkar
try {
    // Kullanıcıyı banned_users tablosuna ekle
    $stmt = $db->prepare("INSERT INTO banned_users (user_id, banned_by, server_id) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $_SESSION['user_id'], $server_id]);

    // Kullanıcıyı server_members tablosundan sil
    $stmt = $db->prepare("DELETE FROM server_members WHERE user_id = ? AND server_id = ?");
    $stmt->execute([$user_id, $server_id]);

    echo json_encode(['success' => true, 'message' => 'Kullanıcı başarıyla banlandı ve sunucudan çıkarıldı.']);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Banlama işlemi sırasında bir hata oluştu: ' . $e->getMessage()]));
}
?>