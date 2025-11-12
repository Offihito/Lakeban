<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Yetkisiz erişim']));
}

$data = json_decode(file_get_contents('php://input'), true);
$groupId = $data['group_id'] ?? null;

if (!$groupId) {
    die(json_encode(['success' => false, 'error' => 'Geçersiz grup']));
}

try {
    // Üyeyi gruptan sil
    $stmt = $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $_SESSION['user_id']]);

    // Grup sahibi kontrolü
    $stmt = $db->prepare("SELECT creator_id FROM groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if ($group['creator_id'] == $_SESSION['user_id']) {
        die(json_encode(['success' => false, 'error' => 'Grup sahibi gruptan ayrılamaz']));
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log("Leave group error: " . $e->getMessage());
    die(json_encode(['success' => false, 'error' => 'Veritabanı hatası']));
}