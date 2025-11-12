<?php
session_start();
require 'server.php'; // DB bağlantısı için

if (!isset($_GET['channel_id']) || !isset($_GET['page'])) {
    die(json_encode(['error' => 'Invalid request'])); // JSON formatında hata mesajı döndür
}

$channel_id = (int)$_GET['channel_id'];
$page = (int)$_GET['page'];
$limit = 20;
$offset = ($page - 1) * $limit;

$stmt = $db->prepare("SELECT m.*, u.username, up.avatar_url 
                      FROM messages1 m
                      JOIN users u ON m.sender_id = u.id
                      LEFT JOIN user_profiles up ON u.id = up.user_id
                      WHERE m.channel_id = ?
                      ORDER BY m.created_at DESC
                      LIMIT ? OFFSET ?");

$stmt->bindValue(1, $channel_id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);

$stmt->execute();
$messages = array_reverse($stmt->fetchAll());

echo json_encode($messages); // JSON formatında mesajları döndür
?>