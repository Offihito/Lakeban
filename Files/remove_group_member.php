<?php
session_start();
require_once 'database/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$groupId = $_POST['group_id'] ?? null;
$memberId = $_POST['member_id'] ?? null;

if (!$groupId || !$memberId) {
    echo json_encode(['success' => false, 'error' => 'Missing group_id or member_id']);
    exit;
}

try {
    // Check if the user is the group creator
    $stmt = $db->prepare("SELECT creator_id FROM groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group || $group['creator_id'] != $userId) {
        echo json_encode(['success' => false, 'error' => 'Only the group creator can remove members']);
        exit;
    }

    // Prevent the creator from removing themselves
    if ($memberId == $userId) {
        echo json_encode(['success' => false, 'error' => 'You cannot remove yourself from the group']);
        exit;
    }

    // Remove the member from the group
    $stmt = $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $memberId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Member removed successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Member not found in group']);
    }
} catch (PDOException $e) {
    error_log("Error removing group member: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>