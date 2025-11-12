<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz kullanıcı ID']);
    exit;
}

$userId = (int)$_GET['user_id'];

try {
    $stmt = $db->prepare("
        SELECT u.username, u.status, p.avatar_url, p.avatar_frame_url
        FROM users u
        LEFT JOIN user_profiles p ON u.id = p.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['success' => true, 'user' => [
            'username' => $user['username'],
            'status' => $user['status'] ?? 'offline',
            'avatar_url' => $user['avatar_url'] ?? 'avatars/default-avatar.png',
            'avatar_frame_url' => $user['avatar_frame_url'] ?? null
        ]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Kullanıcı bulunamadı']);
    }
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>