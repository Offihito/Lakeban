<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantınızı içe aktarın

$response = ['success' => false, 'hasNewMessages' => false];

if(isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    
    // Son kontrol zamanını al (session'da sakla)
    $lastCheck = $_SESSION['last_message_check'] ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
    
    // Okunmamış mesajları kontrol et
    $stmt = $db->prepare("
        SELECT COUNT(*) as unread 
        FROM messages1 
        WHERE receiver_id = ? 
        AND read_status = 0 
        AND created_at > ?
    ");
    
    $stmt->execute([$userId, $lastCheck]);
    $result = $stmt->fetch();
    
    $response['hasNewMessages'] = ($result['unread'] > 0);
    $response['success'] = true;
    
    // Son kontrol zamanını güncelle
    $_SESSION['last_message_check'] = date('Y-m-d H:i:s');
}

header('Content-Type: application/json');
echo json_encode($response);