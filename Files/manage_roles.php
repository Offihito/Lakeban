<?php
session_start();
require 'db_connection.php'; // Veritabanı bağlantısı

if (!isset($_SESSION['user_id']) || !isset($_POST['user_id']) || !isset($_POST['role_id']) || !isset($_POST['action'])) {
    die(json_encode(['success' => false, 'message' => 'Geçersiz istek']));
}

$user_id = $_POST['user_id'];
$role_id = $_POST['role_id'];
$action = $_POST['action'];
$server_id = $_POST['server_id'];

// Yetki kontrolü
$is_owner = $db->query("SELECT owner_id FROM servers WHERE id = $server_id")->fetchColumn() == $_SESSION['user_id'];
$stmt = $db->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND server_id = ? AND role_id IN (SELECT id FROM roles WHERE permissions LIKE '%manage_roles%')");
$stmt->execute([$_SESSION['user_id'], $server_id]);
$has_permission = $stmt->fetchColumn() > 0;

if (!$is_owner && !$has_permission) {
    die(json_encode(['success' => false, 'message' => 'Yetkiniz yok']));
}

try {
    if ($action === 'add') {
        $db->prepare("INSERT INTO user_roles (user_id, role_id, server_id) VALUES (?, ?, ?)")
           ->execute([$user_id, $role_id, $server_id]);
    } else {
        $db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND server_id = ?")
           ->execute([$user_id, $role_id, $server_id]);
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Veritabanı hatası']));
}
?>