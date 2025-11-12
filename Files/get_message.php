<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Veritabanı bağlantısı
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

// Mesaj ID'sini al
if (!isset($_GET['message_id'])) {
    die(json_encode(['error' => 'Message ID is missing.']));
}

$message_id = $_GET['message_id'];

// Mesajı veritabanından çek
try {
    $stmt = $db->prepare("
        SELECT m.*, u.username, up.avatar_url 
        FROM messages1 m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE m.id = ?
    ");
    $stmt->execute([$message_id]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($message) {
        echo json_encode($message); // Mesajı JSON olarak döndür
    } else {
        echo json_encode(['error' => 'Message not found.']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}