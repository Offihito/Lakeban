<?php
session_start();
require 'db_connection.php';

$server_id = $_GET['server_id'];
$channel_id = $_GET['channel_id'];
$before_id = isset($_GET['before_id']) ? (int)$_GET['before_id'] : null;

// Kullanıcının bu kanala erişim izni olup olmadığını kontrol et
$stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);
if ($stmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

// Mesajları al
$query = "
    SELECT m.*, 
           UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
           u.username,
           up.display_username,  
           up.avatar_url,
           up.avatar_frame_url,
           (SELECT r.color 
            FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = m.sender_id 
              AND ur.server_id = ?
            ORDER BY r.importance DESC 
            LIMIT 1) AS role_color,
           (SELECT r.icon 
            FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = m.sender_id 
              AND ur.server_id = ?
            ORDER BY r.importance DESC 
            LIMIT 1) AS role_icon
    FROM messages1 m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE m.channel_id = ?
";
$params = [$server_id, $server_id, $channel_id];

if ($before_id) {
    $query .= " AND m.id < ?";
    $params[] = $before_id;
}

$query .= " ORDER BY m.created_at DESC, m.id DESC LIMIT 40";

$stmt = $db->prepare($query);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mesajları ters çevir (eski mesajlar önce gelsin)
$messages = array_reverse($messages);

// Kanal adını al
$stmt = $db->prepare("SELECT name FROM channels WHERE id = ?");
$stmt->execute([$channel_id]);
$channel_name = $stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'messages' => $messages,
    'channel_name' => $channel_name
]);
?>