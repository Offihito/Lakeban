<?php
header('Content-Type: application/json');

// Manuel olarak gerekli dosyaları dahil et
require_once __DIR__ . '/firebase-php/src/firebase/Factory.php';
require_once __DIR__ . '/firebase-php/src/firebase/Messaging/CloudMessage.php';
require_once __DIR__ . '/firebase-php/src/firebase/Exception/FirebaseException.php'; // Hata işleme için

use Factory;
use Messaging\CloudMessage;

/// Veritabanı bağlantısını dahil et
require_once __DIR__ . 'db_connection.php';

// Read input
$data = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'] ?? null;
$message_id = $data['message_id'] ?? null;
$sender_username = $data['sender_username'] ?? 'Unknown';
$content = $data['content'] ?? 'You have a new message!';

if (!$user_id || !$message_id) {
    echo json_encode(['success' => false, 'error' => 'Missing user_id or message_id']);
    exit;
}

// Veritabanı bağlantısını al
global $conn;
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("s", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$fcm_token = $result->fetch_assoc()['fcm_token'] ?? null;
$stmt->close();
// $conn->close(); // Bağlantıyı burada kapatmayın, db_connection.php bunu yönetebilir

if (!$fcm_token) {
    echo json_encode(['success' => false, 'error' => 'No FCM token found']);
    exit;
}

// Initialize Firebase Admin SDK
try {
    $factory = (new Factory)->withServiceAccount('lakeban-958ed-firebase-adminsdk-fbsvc-ff129854a9.json');
    $messaging = $factory->createMessaging();

    // Send push notification
    $message = CloudMessage::withTarget('token', $fcm_token)
        ->withNotification([
            'title' => "New Message from $sender_username",
            'body' => $content
        ]);

    $messaging->send($message);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>