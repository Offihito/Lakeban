<?php
function executeBotScript($script_content, $command, $args, $server_id, $channel_id, $sender_id, $bot_id, $db) {
    // Botun JSON verisini al
    $stmt = $db->prepare("SELECT json_data FROM bot_scripts WHERE bot_id = ?");
    $stmt->execute([$bot_id]);
    $json_data = $stmt->fetchColumn() ?: '{}';

    // Gönderenin kullanıcı adını al
    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$sender_id]);
    $sender_username = $stmt->fetchColumn() ?: 'BilinmeyenKullanıcı';

    // Node.js scriptine veri gönder
    $payload = json_encode([
        'script' => $script_content,
        'command' => $command,
        'args' => $args,
        'server_id' => $server_id,
        'channel_id' => $channel_id,
        'sender_id' => $sender_id,
        'sender_username' => $sender_username, // Yeni eklenen alan
        'json_data' => $json_data
    ]);

    // Node.js scriptini çalıştır
    $node_script = __DIR__ . '/execute_js.js';
    $command = escapeshellcmd('node ' . $node_script);
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w']  // stderr
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (is_resource($process)) {
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        if (!empty($error)) {
            error_log("Node.js execution error: " . $error);
            return null;
        }

        $result = json_decode($output, true);
        if (isset($result['error'])) {
            error_log("Bot script execution error: " . $result['error']);
            return null;
        }

        // JSON verisini güncelle
        if (isset($result['updated_json_data'])) {
            try {
                $stmt = $db->prepare("UPDATE bot_scripts SET json_data = ? WHERE bot_id = ?");
                $stmt->execute([$result['updated_json_data'], $bot_id]);
            } catch (PDOException $e) {
                error_log("Error updating bot json_data: " . $e->getMessage());
            }
        }

        return $result;
    }
    error_log("Failed to execute Node.js script");
    return null;
}
?>