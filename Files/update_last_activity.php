<?php
session_start();

// Kullanıcı oturumu kontrolü
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Veritabanı bağlantı bilgileri
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

$userId = $_SESSION['user_id'];
$ipAddress = $_SERVER['REMOTE_ADDR'];

// IP ban kontrolü
$stmt = $db->prepare("SELECT banned FROM ip_tracker WHERE ip_address = ?");
$stmt->execute([$ipAddress]);
$ipData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($ipData && $ipData['banned']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your IP is banned']);
    exit;
}

// Kullanıcının mevcut durumunu ve orijinal durumunu al
$stmt = $db->prepare("SELECT status, original_status FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$currentStatus = $user['status'] ?? null;
$originalStatus = $user['original_status'] ?? null;

// IP adresini ip_tracker tablosuna kaydet veya güncelle
$stmt = $db->prepare("
    INSERT INTO ip_tracker (user_id, ip_address, last_activity) 
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_activity = NOW()
");
$stmt->execute([$userId, $ipAddress]);

// Durum ayarlanmamışsa veya 'offline' ise, orijinal duruma geri dön veya 'online' yap
if ($currentStatus === null || $currentStatus === 'offline') {
    $newStatus = $originalStatus !== null ? $originalStatus : 'online';
    $stmt = $db->prepare("UPDATE users SET status = ?, last_activity = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);
    $currentStatus = $newStatus;
} else {
    // Durum zaten ayarlanmışsa (online, idle, dnd), sadece last_activity'yi güncelle
    $stmt = $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt->execute([$userId]);
}

echo json_encode(['success' => true, 'status' => $currentStatus]);
?>