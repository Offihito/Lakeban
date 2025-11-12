<?php
session_start();
require 'db_connection.php'; // Veritabanı bağlantı dosyanız

// Parametreleri güvenli şekilde al ve kontrol et
$channel_id = filter_input(INPUT_GET, 'channel_id', FILTER_VALIDATE_INT);
$oldest_message_id = filter_input(INPUT_GET, 'oldest_message_id', FILTER_VALIDATE_INT);

// Parametre validasyonu
if (!$channel_id || !$oldest_message_id) {
    http_response_code(400);
    die(json_encode(['error' => 'Geçersiz parametreler']));
}

try {
    // Mesajları veritabanından çek
   $stmt = $db->prepare("
    SELECT 
        m.*, 
        UNIX_TIMESTAMP(m.created_at) AS created_at_unix, 
        u.username, 
        up.avatar_url 
    FROM messages1 m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE m.channel_id = :channel_id 
    AND m.id < :oldest_message_id
    ORDER BY m.id DESC  -- Bu satır doğru, en yeni eski mesajlar için
    LIMIT 40
");

    $stmt->execute([
        ':channel_id' => $channel_id,
        ':oldest_message_id' => $oldest_message_id
    ]);

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // created_at_unix fallback
    foreach ($messages as &$msg) {
        if (!isset($msg['created_at_unix']) || !is_numeric($msg['created_at_unix'])) {
            $msg['created_at_unix'] = time(); // Şu anki zamanı ata
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'messages' => $messages,
        'status' => 'success'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Veritabanı hatası',
        'details' => $e->getMessage()
    ]));
}