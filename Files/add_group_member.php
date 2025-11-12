<?php
session_start();
require 'database/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $group_id = $data['group_id'] ?? null;
    $member_id = $data['member_id'] ?? null;

    if (!$group_id || !$member_id) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    // Grup sahibini kontrol et
    $stmt = $db->prepare("SELECT creator_id FROM groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();

    if (!$group || $group['creator_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'Only group owner can add members']);
        exit;
    }

    // Üyenin zaten grupta olup olmadığını kontrol et
    $stmt = $db->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $member_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'User is already a member']);
        exit;
    }

    // Arkadaşlık kontrolü
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM friends 
        WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
    ");
    $stmt->execute([$_SESSION['user_id'], $member_id, $member_id, $_SESSION['user_id']]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'error' => 'User is not your friend']);
        exit;
    }

    // Yeni üyeyi ekle
    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
    $success = $stmt->execute([$group_id, $member_id]);

    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Member added successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to add member']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>