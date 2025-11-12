<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$group_id = $data['group_id'] ?? null;
$new_owner_id = $data['new_owner_id'] ?? null;

if (!$group_id || !$new_owner_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$stmt = $db->prepare("SELECT creator_id FROM groups WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();
if (!$group || $group['creator_id'] != $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Not the group owner']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->execute([$group_id, $new_owner_id]);
if ($stmt->rowCount() == 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Selected user is not a group member']);
    exit;
}

$stmt = $db->prepare("UPDATE groups SET creator_id = ? WHERE id = ?");
$stmt->execute([$new_owner_id, $group_id]);

echo json_encode(['success' => true]);
?>