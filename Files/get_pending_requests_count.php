<?php
session_start();

// Database connection
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function getFriendRequestsCount($db, $userId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as request_count
        FROM friend_requests
        WHERE receiver_id = ? AND status = 'pending'
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result['request_count'];
}

$request_count = getFriendRequestsCount($db, $_SESSION['user_id']);
echo json_encode(['request_count' => $request_count]);
?>