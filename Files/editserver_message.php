<?php
session_start();
require 'db_connection.php'; // Veritabanı bağlantınızı içe aktarın

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Yetkisiz erişim']));
}

$message_id = $_POST['message_id'] ?? null;
$edited_text = $_POST['edited_message_text'] ?? null;

// Validasyon
if (!$message_id || !$edited_text) {
    die(json_encode(['success' => false, 'message' => 'Eksik parametreler']));
}

try {
    // Mesaj sahibini ve sunucu bilgilerini kontrol et
    $stmt = $db->prepare("
        SELECT m.sender_id, s.id AS server_id 
        FROM messages1 m
        JOIN channels c ON m.channel_id = c.id
        JOIN servers s ON c.server_id = s.id
        WHERE m.id = ?
    ");
    $stmt->execute([$message_id]);
    $message_info = $stmt->fetch();

    if (!$message_info) {
        die(json_encode(['success' => false, 'message' => 'Mesaj bulunamadı']));
    }

    // Yetki kontrolü: Ya mesaj sahibi ya da sunucu sahibi
    $is_owner = ($message_info['sender_id'] == $_SESSION['user_id']);
    $is_server_owner = $db->query("SELECT owner_id FROM servers WHERE id = {$message_info['server_id']}")
                         ->fetchColumn() == $_SESSION['user_id'];

    if (!$is_owner && !$is_server_owner) {
        die(json_encode(['success' => false, 'message' => 'Yetkiniz yok']));
    }

    // Mesajı güncelle
    $update_stmt = $db->prepare("UPDATE messages1 SET message_text = ? WHERE id = ?");
    $update_stmt->execute([$edited_text, $message_id]);

    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    error_log("DB Error: ".$e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Veritabanı hatası']));
}