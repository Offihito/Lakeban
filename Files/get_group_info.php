<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz grup ID']);
    exit;
}

$groupId = (int)$_GET['group_id'];

try {
    // Grup bilgilerini al
    $stmt = $db->prepare("
        SELECT 
            g.id, 
            g.name, 
            g.avatar_url, 
            g.creator_id,
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
            (SELECT username FROM users WHERE id = g.creator_id) as creator_username
        FROM groups g
        WHERE g.id = ?
    ");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug: Log raw group data
    error_log("Group query result for ID $groupId: " . print_r($group, true));

    if (!$group) {
        echo json_encode(['success' => false, 'error' => 'Grup bulunamadı']);
        exit;
    }

    // Grup üyelerini al
    $stmt = $db->prepare("
        SELECT 
            u.id, 
            u.username, 
            p.avatar_url,
            (u.id = g.creator_id) as is_creator
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        LEFT JOIN user_profiles p ON u.id = p.user_id
        JOIN groups g ON gm.group_id = g.id
        WHERE gm.group_id = ?
        ORDER BY is_creator DESC, u.username ASC
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug: Log members data
    error_log("Members query result for group ID $groupId: " . print_r($members, true));

    echo json_encode([
        'success' => true,
        'group' => [
            'id' => $group['id'],
            'name' => $group['name'],
            'avatar_url' => $group['avatar_url'] ?? 'avatars/default-group-avatar.png',
            'member_count' => $group['member_count'],
            'creator_id' => $group['creator_id'], // Ensure this is included
            'creator_username' => $group['creator_username'] ?? 'Bilinmeyen',
            'members' => $members
        ]
    ]);
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>