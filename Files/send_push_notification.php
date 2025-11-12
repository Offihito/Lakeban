<?php
header('Content-Type: application/json');

require_once 'db_connection.php'; // Include the PDO database connection

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? null;
$message_id = $input['message_id'] ?? null;
$title = $input['title'] ?? 'New Message';
$body = $input['body'] ?? 'You have a new message!';

if (!$user_id || !$message_id) {
    echo json_encode(['success' => false, 'error' => 'Missing user_id or message_id']);
    exit;
}

try {
    // Fetch user's FCM token from database
    $stmt = $db->prepare('SELECT fcm_token FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['fcm_token'])) {
        echo json_encode(['success' => false, 'error' => 'No FCM token found for user']);
        exit;
    }

    $fcm_token = $user['fcm_token'];

    // FCM server key (get from Firebase Console > Project Settings > Cloud Messaging)
    $server_key = 'BFtS2ob4SPn5SQEmgbLYSs4_OcjYF3QtbRXmTRfFH1ZGnXYCZSE7PnqTYFmLQyuUDA1hZrlXpasFIchgnrUdRZw'; // Replace with your FCM server key

    // FCM API endpoint
    $url = 'https://fcm.googleapis.com/fcm/send';

    $notification = [
        'title' => $title,
        'body' => $body,
        'sound' => 'default'
    ];

    $payload = [
        'to' => $fcm_token,
        'notification' => $notification,
        'data' => [
            'message_id' => $message_id
        ]
    ];

    $headers = [
        'Authorization: key=' . $server_key,
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send FCM notification']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>