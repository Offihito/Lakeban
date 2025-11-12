<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

require_once 'db_connection.php';

header('Content-Type: application/json');

try {
    $serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : 0;
    $channelId = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : 0;

    error_log("[DEBUG] get_pinned_messages.php: server_id=$serverId, channel_id=$channelId");

    if ($serverId === 0 || $channelId === 0) {
        error_log("[ERROR] Invalid server or channel ID: server_id=$serverId, channel_id=$channelId");
        echo json_encode(['success' => false, 'error' => 'Geçersiz sunucu veya kanal ID']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT 
            m.id,
            m.message_text,
            UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
            u.username,
            up.avatar_url,
            (SELECT r.color 
             FROM user_roles ur 
             JOIN roles r ON ur.role_id = r.id 
             WHERE ur.user_id = m.sender_id 
               AND ur.server_id = m.server_id
             ORDER BY r.importance DESC 
             LIMIT 1) AS role_color
        FROM messages1 m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE m.is_pinned = 1 
          AND m.server_id = ? 
          AND m.channel_id = ?
        ORDER BY m.created_at DESC
        LIMIT 5
    ");

    $stmt->execute([$serverId, $channelId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("[DEBUG] get_pinned_messages.php: Number of messages found: " . count($messages));

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    error_log("[ERROR] get_pinned_messages.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Sunucu hatası: ' . $e->getMessage()]);
    exit;
}
?>