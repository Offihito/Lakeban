<?php
session_start();
header('Content-Type: application/json');
error_reporting(0);

try {
    $db = new PDO("mysql:host=localhost;dbname=lakebanc_Database", 'lakebanc_Offihito', 'P4QG(m2jkWXN');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $message_id = $_POST['message_id'] ?? null;
    if (!$message_id) {
        echo json_encode(['success' => false, 'error' => 'Message ID is missing']);
        exit;
    }

    // Mesajın sahibini ve sunucu ID'sini kontrol et
    $stmt = $db->prepare("SELECT sender_id, server_id FROM messages1 WHERE id = ?");
    $stmt->execute([$message_id]);
    $message = $stmt->fetch();

    if (!$message) {
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }

    // Sunucu sahibi mi kontrol et
    $stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
    $stmt->execute([$message['server_id']]);
    $server = $stmt->fetch();
    $is_owner = ($server['owner_id'] == $_SESSION['user_id']);

    // manage_messages iznine sahip mi kontrol et
    $has_manage_messages = false;
    if (!$is_owner && $_SESSION['user_id'] != $message['sender_id']) {
        $stmt = $db->prepare("
            SELECT r.permissions 
            FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = ? AND ur.server_id = ? AND r.permissions LIKE '%manage_messages%'
        ");
        $stmt->execute([$_SESSION['user_id'], $message['server_id']]);
        $has_manage_messages = $stmt->rowCount() > 0;
    }

    // Küfür filtresi tarafından silinmiş mesajlar için özel kontrol
    $is_filter_deleted = false;
    if ($_SESSION['user_id'] == 0) { // Sistem botu ID'si
        $stmt = $db->prepare("SELECT 1 FROM bot_bad_word_filter WHERE bot_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $is_filter_deleted = $stmt->fetch();
    }

    // Yetki kontrolü (mesaj sahibi, sunucu sahibi, manage_messages veya sistem botu)
    if ($_SESSION['user_id'] != $message['sender_id'] && 
        !$is_owner && 
        !$has_manage_messages &&
        !$is_filter_deleted) 
    {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Mesajı sil
    $stmt = $db->prepare("DELETE FROM messages1 WHERE id = ?");
    $stmt->execute([$message_id]);

    echo json_encode(['success' => true]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}