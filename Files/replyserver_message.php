<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantısını dahil et

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Gerekli alanları kontrol et
if (empty($data['original_message_id']) || empty($data['reply_text']) || empty($data['server_id']) || empty($data['channel_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Mesajı veritabanına ekle
    $stmt = $db->prepare("INSERT INTO messages1 (server_id, channel_id, sender_id, message_text, reply_to_message_id) 
                         VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['server_id'],
        $data['channel_id'],
        $_SESSION['user_id'],
        trim($data['reply_text']),
        $data['original_message_id']
    ]);

    // Yeni mesajın ID'sini al
    $newMessageId = $db->lastInsertId();

    // Yeni mesajın verilerini getir
    $stmt = $db->prepare("SELECT m.*, u.username, up.avatar_url 
                         FROM messages1 m
                         JOIN users u ON m.sender_id = u.id
                         LEFT JOIN user_profiles up ON u.id = up.user_id
                         WHERE m.id = ?");
    $stmt->execute([$newMessageId]);
    $newMessage = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'message' => $newMessage]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}