<?php
// unban_user.php

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

// Check if the user has permission to unban
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unban_user'])) {
    $user_id = $_POST['user_id'];

    // Remove ban record from the database
    $stmt = $db->prepare("DELETE FROM bans WHERE user_id = ?");
    $stmt->execute([$user_id]);

    // Update the user's status back to 'offline' or another appropriate status
    $stmt = $db->prepare("UPDATE users SET status = 'offline' WHERE id = ?");
    $stmt->execute([$user_id]);

    header("Location: server.php?id=" . $_POST['server_id']);
    exit;
}
?>