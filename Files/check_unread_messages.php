<?php
session_start();
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $server_id = $_GET['server_id'] ?? null;
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$server_id || !$user_id) {
        echo json_encode(['success' => false, 'error' => 'Eksik parametre']);
        exit;
    }

    // Kullanıcının erişebileceği kanalları al
    $stmt = $db->prepare("SELECT id FROM channels WHERE server_id = ?");
    $stmt->execute([$server_id]);
    $channels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $unread_channels = [];
    foreach ($channels as $channel_id) {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages1 m
            LEFT JOIN user_read_messages urm ON m.channel_id = urm.channel_id AND urm.user_id = ?
            WHERE m.channel_id = ? AND (urm.last_read_message_id IS NULL OR m.id > urm.last_read_message_id)
        ");
        $stmt->execute([$user_id, $channel_id]);
        if ($stmt->fetchColumn() > 0) {
            $unread_channels[] = $channel_id;
        }
    }

    echo json_encode(['success' => true, 'unread_channels' => $unread_channels]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>