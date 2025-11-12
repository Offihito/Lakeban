<?php
session_start();
require 'db_connection.php'; // Veritabanı bağlantınızı içe aktarın

if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Geçersiz istek");

// Yetki kontrolü
if (!isset($_SESSION['user_id'])) die("Yetkisiz erişim");

$data = json_decode(file_get_contents('php://input'), true);
$serverId = $data['serverId'];

// Sunucu sahibi mi veya manage_channels izni var mı kontrol et
$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch();

$isOwner = ($server['owner_id'] == $_SESSION['user_id']);
$hasPermission = checkPermission($_SESSION['user_id'], $serverId, 'manage_channels');

if (!$isOwner && !$hasPermission) die("Yetkiniz yok");

// Pozisyonları güncelle
foreach ($data['newOrder'] as $channel) {
    $stmt = $db->prepare("UPDATE channels SET position = ? WHERE id = ? AND server_id = ?");
    $stmt->execute([$channel['position'], $channel['id'], $serverId]);
}

echo json_encode(['success' => true]);

function checkPermission($userId, $serverId, $permission) {
    global $db;
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM user_roles ur 
        JOIN roles r ON ur.role_id = r.id 
        WHERE ur.user_id = ? 
        AND ur.server_id = ? 
        AND r.permissions LIKE ?
    ");
    $stmt->execute([$userId, $serverId, "%$permission%"]);
    return $stmt->fetchColumn() > 0;
}