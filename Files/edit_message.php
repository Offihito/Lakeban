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
    $message_id = $_POST['message_id'];
    $edited_message_text = $_POST['edited_message_text'];

    // Check if the user has permission to edit the message
    $stmt = $db->prepare("SELECT sender_id FROM messages1 WHERE id = ?");
    $stmt->execute([$message_id]);
    $message_sender_id = $stmt->fetchColumn();

    if ($message_sender_id == $_SESSION['user_id']) {
        // Update the message
        $stmt = $db->prepare("UPDATE messages1 SET message_text = ? WHERE id = ?");
        $stmt->execute([$edited_message_text, $message_id]);

        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Bu mesajı düzenleme yetkiniz yok.']);
        exit;
    }
}