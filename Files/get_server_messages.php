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
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'User not logged in']));
}

if (!isset($_POST['channel_id'])) {
    die(json_encode(['success' => false, 'message' => 'Channel ID is missing']));
}

$channel_id = $_POST['channel_id'];

// Fetch messages for the selected channel
$stmt = $db->prepare("
    SELECT m.*, u.username, up.avatar_url 
    FROM messages1 m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE m.channel_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$channel_id]);
$messages = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'messages' => $messages
]);
?>