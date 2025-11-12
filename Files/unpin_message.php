<?php
session_start();
require_once 'db_connection.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Oturum açık değil';
    echo json_encode($response);
    exit;
}

if (!isset($_POST['message_id']) || !isset($_POST['server_id']) || !isset($_POST['channel_id'])) {
    $response['message'] = 'Eksik parametreler';
    echo json_encode($response);
    exit;
}

$messageId = (int)$_POST['message_id'];
$serverId = (int)$_POST['server_id'];
$channelId = (int)$_POST['channel_id'];
$userId = (int)$_SESSION['user_id'];

// Kullanıcın yetkilerini kontrol et
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND ur.server_id = ? AND (r.permissions LIKE '%pin_messages%' OR ? = (SELECT owner_id FROM servers WHERE id = ?))
");
$stmt->execute([$userId, $serverId, $userId, $serverId]);
$hasPermission = $stmt->fetchColumn() > 0;

if (!$hasPermission) {
    $response['message'] = 'Bu işlem için yetkiniz yok';
    echo json_encode($response);
    exit;
}

// Mesajın sabitlenmiş olduğunu kontrol et
$stmt = $db->prepare("SELECT is_pinned FROM messages1 WHERE id = ? AND server_id = ? AND channel_id = ?");
$stmt->execute([$messageId, $serverId, $channelId]);
$message = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$message) {
    $response['message'] = 'Mesaj bulunamadı';
    echo json_encode($response);
    exit;
}

if (!$message['is_pinned']) {
    $response['message'] = 'Mesaj zaten sabitlenmemiş';
    echo json_encode($response);
    exit;
}

// Mesajın sabitlemesini kaldır
$stmt = $db->prepare("UPDATE messages1 SET is_pinned = 0 WHERE id = ? AND server_id = ? AND channel_id = ?");
$stmt->execute([$messageId, $serverId, $channelId]);

if ($stmt->rowCount() > 0) {
    $response['success'] = true;
    $response['message'] = 'Mesajın sabitlemesi başarıyla kaldırıldı';
} else {
    $response['message'] = 'Sabitleme kaldırılırken bir hata oluştu';
}

header('Content-Type: application/json');
echo json_encode($response);
?>