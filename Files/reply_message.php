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
    die("Unable to connect to the database. Please try again later.");
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $original_message_id = $_POST['original_message_id'];
    $reply_text = $_POST['reply_text'];

    // Insert the reply message
    $stmt = $db->prepare("INSERT INTO messages1 (server_id, channel_id, sender_id, message_text, reply_to_message_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$server_id, $channel_id, $_SESSION['user_id'], $reply_text, $original_message_id]);

    echo json_encode(['success' => true]);
    exit;
}