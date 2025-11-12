<?php
// get_user_roles.php
session_start();
require_once 'db_connection.php';

$userId = $_GET['user_id'] ?? null;
$serverId = $_GET['server_id'] ?? null;

if (!$userId || !$serverId) {
    echo json_encode(['error' => 'User ID and Server ID are required']);
    exit;
}

try {
    // Kullanıcının bu sunucudaki rollerini getir
    $stmt = $db->prepare("
        SELECT r.name, r.color
        FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND ur.server_id = ?
        ORDER BY r.importance DESC
    ");
    $stmt->execute([$userId, $serverId]);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($roles);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}