<?php
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

// Son 2 dakikada aktif olmayan ve 'online', 'idle' veya 'dnd' durumunda olan kullanıcıları offline yap
$stmt = $db->prepare("UPDATE users SET status = 'offline' WHERE TIMESTAMPDIFF(MINUTE, last_activity, CURRENT_TIMESTAMP) > 2 AND status IN ('online', 'idle', 'dnd')");
$stmt->execute();

echo json_encode(['success' => true, 'message' => 'Offline statuses updated']);
?>