<?php
session_start();
require 'database/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açılmamış']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$group_id = $input['group_id'] ?? null;
$member_ids = $input['member_ids'] ?? [];

if (!$group_id || empty($member_ids)) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz grup ID veya üye listesi']);
    exit;
}

// Grup sahibini kontrol et
$stmt = $db->prepare("SELECT creator_id FROM groups WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();

if (!$group || $group['creator_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'error' => 'Yalnızca grup sahibi üye ekleyebilir']);
    exit;
}

// Mevcut üyeleri al
$stmt = $db->prepare("SELECT user_id FROM group_members WHERE group_id = ?");
$stmt->execute([$group_id]);
$existing_members = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Yeni üyeleri ekle
try {
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
    
    foreach ($member_ids as $member_id) {
        // Üye zaten grupta değilse ekle
        if (!in_array($member_id, $existing_members)) {
            $stmt->execute([$group_id, $member_id]);
        }
    }
    
    $db->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Üye ekleme hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Üyeler eklenirken bir hata oluştu']);
}
?>