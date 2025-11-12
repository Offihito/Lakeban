<?php
// execute_js_websocket.php
if (!function_exists('sendWebSocketMessage')) {
    function sendWebSocketMessage($data)
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ],
            'http' => ['timeout' => 3]
        ]);

        $fp = @stream_socket_client(
            'ssl://lakeban.com:8000',
            $errno,
            $errstr,
            3,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($fp) {
            fwrite($fp, $payload);
            fclose($fp);
            error_log("WebSocket GÖNDERİLDİ → " . substr($payload, 0, 150));
        } else {
            error_log("WebSocket BAĞLANTI HATASI → $errstr ($errno)");
        }
    }
}

if (!function_exists('_broadcastMessage')) {
    function _broadcastMessage($db, $message_id, $server_id, $channel_id)
    {
        $stmt = $db->prepare("
            SELECT 
                m.*,
                UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
                u.username,
                COALESCE(up.display_username, u.username) AS display_name,
                up.avatar_url,
                up.avatar_frame_url,
                COALESCE((
                    SELECT r.color FROM user_roles ur JOIN roles r ON ur.role_id = r.id 
                    WHERE ur.user_id = m.sender_id AND ur.server_id = m.server_id 
                    ORDER BY r.importance DESC LIMIT 1
                ), '#FFFFFF') AS role_color
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE m.id = ?
        ");
        $stmt->execute([$message_id]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$msg) return;

        $files = $msg['file_path'] ? [[
            'path' => $msg['file_path'],
            'name' => basename($msg['file_path'])
        ]] : [];

        $wsData = [
            'type' => 'message-sent',
            'serverId' => $server_id,
            'channelId' => $channel_id,
            'senderId' => $msg['sender_id'],
            'message' => [
                'id' => $msg['id'],
                'server_id' => $msg['server_id'],
                'channel_id' => $msg['channel_id'],
                'sender_id' => $msg['sender_id'],
                'message_text' => $msg['message_text'],
                'file_path' => $msg['file_path'],
                'reply_to_message_id' => $msg['reply_to_message_id'],
                'created_at_unix' => $msg['created_at_unix'],
                'username' => $msg['display_name'],
                'avatar_url' => $msg['avatar_url'],
                'avatar_frame_url' => $msg['avatar_frame_url'],
                'role_color' => $msg['role_color']
            ],
            'files' => $files
        ];

        sendWebSocketMessage($wsData);
    }
}
?>