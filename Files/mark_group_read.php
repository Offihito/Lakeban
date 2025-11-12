<?php
session_start();
require_once 'db_connection.php';

$data = json_decode(file_get_contents('php://input'), true);
$groupId = $data['group_id'];
$userId = $_SESSION['user_id'];

$stmt = $db->prepare("
    INSERT INTO group_member_status (user_id, group_id, last_seen)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen = NOW()
");
$stmt->execute([$userId, $groupId]);