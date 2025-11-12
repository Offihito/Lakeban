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
    die("Veritabanı bağlantısı başarısız: " . $e->getMessage());
}

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    die("Oturum açılmamış.");
}

// POST verilerini al
$user_id = $_POST['user_id'] ?? null;
$server_id = $_POST['server_id'] ?? null;

if (!$user_id || !$server_id) {
    die("Geçersiz istek.");
}

// Sunucu sahibi mi kontrol et
$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_owner_id = $stmt->fetchColumn();

// Kullanıcının "kick" izni var mı kontrol et
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND ur.server_id = ? AND r.permissions LIKE '%kick%'
");
$stmt->execute([$_SESSION['user_id'], $server_id]);
$has_kick_permission = $stmt->fetchColumn() > 0;

// Eğer kullanıcı sunucu sahibi değilse ve "kick" izni yoksa işlemi reddet
if ($_SESSION['user_id'] != $server_owner_id && !$has_kick_permission) {
    die("Bu işlemi yapmaya yetkiniz yok.");
}

// Kullanıcıyı sunucudan at
$stmt = $db->prepare("DELETE FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $user_id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true, 'message' => 'Kullanıcı başarıyla atıldı.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Kullanıcı atılamadı.']);
}