<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'is_pinned' => false];

try {
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'Oturum açık değil';
        echo json_encode($response);
        exit;
    }

    if (!isset($_POST['message_id']) || !isset($_POST['server_id']) || !isset($_POST['channel_id']) || !isset($_POST['action'])) {
        $response['message'] = 'Eksik parametreler';
        echo json_encode($response);
        exit;
    }

    $messageId = (int)$_POST['message_id'];
    $serverId = (int)$_POST['server_id'];
    $channelId = (int)$_POST['channel_id'];
    $action = $_POST['action'];
    $userId = (int)$_SESSION['user_id'];

    // Yetki kontrolü
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

    // Mesajın varlığını kontrol et
    $stmt = $db->prepare("SELECT is_pinned FROM messages1 WHERE id = ? AND server_id = ? AND channel_id = ?");
    $stmt->execute([$messageId, $serverId, $channelId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        $response['message'] = 'Mesaj bulunamadı';
        echo json_encode($response);
        exit;
    }

    $currentPinnedState = $message['is_pinned'];
    $newPinnedState = $action === 'pin' ? true : false;

    if ($currentPinnedState === $newPinnedState) {
        $response['message'] = $newPinnedState ? 'Mesaj zaten sabitlenmiş' : 'Mesaj zaten sabitlenmemiş';
        echo json_encode($response);
        exit;
    }

    if ($action === 'pin') {
        // Sabitlenmiş mesaj sayısını kontrol et
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages1 WHERE is_pinned = 1 AND server_id = ? AND channel_id = ?");
        $stmt->execute([$serverId, $channelId]);
        $pinnedCount = $stmt->fetchColumn();

        if ($pinnedCount >= 5) {
            $response['message'] = 'Bir kanalda en fazla 5 mesaj sabitlenebilir';
            echo json_encode($response);
            exit;
        }
    }

    // Mesajın sabitleme durumunu güncelle
    $stmt = $db->prepare("UPDATE messages1 SET is_pinned = ? WHERE id = ? AND server_id = ? AND channel_id = ?");
    $stmt->execute([$newPinnedState, $messageId, $serverId, $channelId]);

    if ($stmt->rowCount() > 0) {
        $response['success'] = true;
        $response['message'] = $newPinnedState ? 'Mesaj başarıyla sabitlendi' : 'Mesaj sabitlemesi kaldırıldı';
        $response['is_pinned'] = $newPinnedState;
    } else {
        $response['message'] = 'Mesaj durumu değiştirilemedi';
    }

    echo json_encode($response);
} catch (Exception $e) {
    error_log('Hata in pin_message.php: ' . $e->getMessage());
    $response['message'] = 'Sunucu hatası: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}
?>