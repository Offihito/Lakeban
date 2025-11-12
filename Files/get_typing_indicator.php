<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$friendId = $_POST['friend_id'];

// Burada kullanıcının yazma durumunu kontrol edin.
// Örneğin, bir veritabanında veya oturum değişkeninde saklanmış olabilir.
$isTyping = isset($_SESSION['typing_status'][$friendId]) && $_SESSION['typing_status'][$friendId];

echo json_encode([
    'is_typing' => $isTyping,
    'username' => 'FriendUsername' // Burada arkadaşın kullanıcı adını döndürün
]);