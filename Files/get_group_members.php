<?php
session_start();
require_once 'db_connection.php'; // Orijinal dosyadaki DB bağlantısını içeren dosya

header('Content-Type: application/json');

if(!isset($_GET['group_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$groupId = $_GET['group_id'];

try {
    if (!isset($_GET['group_id']) || !isset($_SESSION['user_id'])) {
        throw new Exception('Yetkisiz erişim');
    }

    $groupId = filter_var($_GET['group_id'], FILTER_VALIDATE_INT);
    if (!$groupId || $groupId < 1) {
        throw new Exception('Geçersiz grup ID');
    }

    // Yeni SQL sorgusu
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.username,
            up.avatar_url,
            (g.creator_id = u.id) AS is_owner,
            (TIMESTAMPDIFF(MINUTE, u.last_activity, NOW()) <= 2) AS is_online
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        JOIN groups g ON gm.group_id = g.id
        WHERE gm.group_id = ?
    ");
    
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($members);

} catch (PDOException $e) {
    error_log("PDO Hatası: " . $e->getMessage());
    echo json_encode(['error' => 'Veritabanı hatası: ' . $e->getMessage()]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}