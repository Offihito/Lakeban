<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Unable to connect to the database. Please try again later.']);
    exit;
}

// Get channel ID from request
if (!isset($_GET['channel_id'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['error' => 'Channel ID is missing.']);
    exit;
}

$channel_id = $_GET['channel_id'];

// Fetch new messages for the channel
$stmt = $db->prepare("
    SELECT 
        m.*, 
        u.username, 
        up.avatar_url, 
        rm.message_text AS reply_to_message_text,
        ru.username AS reply_to_username,
        rup.avatar_url AS reply_to_avatar_url
    FROM messages1 m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    LEFT JOIN messages1 rm ON m.reply_to_message_id = rm.id
    LEFT JOIN users ru ON rm.sender_id = ru.id
    LEFT JOIN user_profiles rup ON ru.id = rup.user_id
    WHERE m.channel_id = ? AND m.id > ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$channel_id, $_GET['last_message_id'] ?? 0]);
$messages = $stmt->fetchAll();

// Her mesaj için reaksiyonları al
foreach ($messages as &$message) {
    $stmt = $db->prepare("
        SELECT emoji, COUNT(*) as count, GROUP_CONCAT(user_id) as user_ids
        FROM message_reactions
        WHERE message_id = ?
        GROUP BY emoji
    ");
    $stmt->execute([$message['id']]);
    $message['reactions'] = $stmt->fetchAll();
}

header('Content-Type: application/json');
echo json_encode(['messages' => $messages]);
exit;
?>