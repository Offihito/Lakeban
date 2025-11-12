<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json; charset=utf-8');

// Configure error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

try {
    $server_id = filter_var($_GET['server_id'] ?? null, FILTER_VALIDATE_INT);
    $channel_id = filter_var($_GET['channel_id'] ?? null, FILTER_VALIDATE_INT);
    $last_message_id = filter_var($_GET['last_message_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$server_id || !$channel_id) {
        throw new Exception('Sunucu ID veya Kanal ID eksik');
    }

    // Check user authorization
    $stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
    if (!$stmt->execute([$server_id, $_SESSION['user_id']])) {
        throw new Exception('Yetki kontrolü başarısız');
    }
    if ($stmt->rowCount() === 0) {
        throw new Exception('Yetkisiz erişim');
    }

    // Fetch messages
    if ($last_message_id) {
        $stmt = $db->prepare("
            SELECT 
                m.*,
                UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
                u.username,
                up.display_username,
                up.avatar_url,
                up.avatar_frame_url,
                COALESCE(
                    (SELECT r.color 
                     FROM user_roles ur 
                     JOIN roles r ON ur.role_id = r.id 
                     WHERE ur.user_id = m.sender_id 
                       AND ur.server_id = ?
                     ORDER BY r.importance DESC 
                     LIMIT 1),
                    '#FFFFFF'
                ) AS role_color,
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
              AND m.server_id = ?
              AND m.id > ?
            ORDER BY m.id ASC
        ");
        if (!$stmt->execute([$server_id, $server_id, $channel_id, $server_id, $last_message_id])) {
            throw new Exception('Mesajlar alınamadı');
        }
    } else {
        $stmt = $db->prepare("
            SELECT 
                m.*,
                UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
                u.username,
                up.display_username,
                up.avatar_url,
                up.avatar_frame_url,
                COALESCE(
                    (SELECT r.color 
                     FROM user_roles ur 
                     JOIN roles r ON ur.role_id = r.id 
                     WHERE ur.user_id = m.sender_id 
                       AND ur.server_id = ?
                     ORDER BY r.importance DESC 
                     LIMIT 1),
                    '#FFFFFF'
                ) AS role_color,
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
              AND m.server_id = ?
            ORDER BY m.id DESC
            LIMIT 10
        ");
        if (!$stmt->execute([$server_id, $server_id, $channel_id, $server_id])) {
            throw new Exception('Mesajlar alınamadı');
        }
    }

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $messages = array_reverse($messages);

    // Fetch channel name
    $stmt = $db->prepare("SELECT name FROM channels WHERE id = ?");
    if (!$stmt->execute([$channel_id])) {
        throw new Exception('Kanal adı alınamadı');
    }
    $channel_name = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'channel_name' => $channel_name,
        'message' => empty($messages) && $last_message_id ? 'Yeni mesaj yok' : null
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Exception $e) {
    error_log('HATA: ' . $e->getMessage() . ' | Dosya: ' . $e->getFile() . ' | Satır: ' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
?>