<?php
session_start();
require_once 'db.php'; // Veritabanı bağlantısı

$userId = $_SESSION['user_id']; // Oturum açan kullanıcının ID'si
$friendId = $_POST['friend_id']; // Arkadaşın ID'si

// Yazma durumunu veritabanından al
$stmt = $db->prepare("SELECT is_typing, username FROM typing_status 
                      JOIN users ON typing_status.user_id = users.id 
                      WHERE user_id = :friend_id AND friend_id = :user_id AND last_updated > NOW() - INTERVAL 3 SECOND");
$stmt->execute([
    ':friend_id' => $friendId,
    ':user_id' => $userId,
]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    echo json_encode([
        'isTyping' => (bool)$result['is_typing'],
        'username' => $result['username'],
    ]);
} else {
    echo json_encode(['isTyping' => false]);
}