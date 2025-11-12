
<?php
function sendWebSocketMessage($message) {
    $ws_url = 'wss://lakeban.com:8000';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $socket = @stream_socket_client($ws_url, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) return false;

    fwrite($socket, $message . "\n");
    fclose($socket);
    return true;
}
?>