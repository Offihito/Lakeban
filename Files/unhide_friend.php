<?php
session_start();
$userId = $_SESSION['user_id'];
$friendId = json_decode(file_get_contents('php://input'), true)['friend_id'];

$pdo = new PDO("mysql:host=localhost;dbname=your_db", "username", "password");
$stmt = $pdo->prepare("DELETE FROM hidden_friends WHERE user_id = :user_id AND friend_id = :friend_id");
$stmt->execute(['user_id' => $userId, 'friend_id' => $friendId]);

echo json_encode(['success' => true]);
?>