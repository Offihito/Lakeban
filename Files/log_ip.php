<?php
header('Content-Type: application/json');

// MySQL bağlantı ayarları
$host = 'localhost';
$username = 'your_username';
$password = 'your_password';
$database = 'your_database';

// MySQL bağlantısı
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// IP adresini al (proxy veya CDN için)
$ip_address = $_SERVER['REMOTE_ADDR'];
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
}

// User-Agent bilgisini al
$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';

// Ziyaret zamanı
$visit_time = date('Y-m-d H:i:s');

try {
    // IP adresini veritabanına kaydet
    $stmt = $pdo->prepare('INSERT INTO ip_logs (ip_address, visit_time, user_agent) VALUES (?, ?, ?)');
    $stmt->execute([$ip_address, $visit_time, $user_agent]);
    echo json_encode(['success' => true, 'message' => 'IP logged successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error logging IP: ' . $e->getMessage()]);
}
?>