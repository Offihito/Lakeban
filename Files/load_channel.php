<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection (ana dosyadakiyle aynı)
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
    die(json_encode(['success' => false, 'error' => 'Database connection error']));
}

// Session check
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

// Parametreleri al
$server_id = $_GET['server_id'] ?? null;
$channel_id = $_GET['channel_id'] ?? null;

if (!$server_id || !$channel_id) {
    die(json_encode(['success' => false, 'error' => 'Missing parameters']));
}

// Kullanıcının kanala erişimi kontrol et
$stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);
if ($stmt->rowCount() === 0) {
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Kanal adını al
$stmt = $db->prepare("SELECT name FROM channels WHERE id = ?");
$stmt->execute([$channel_id]);
$channel_name = $stmt->fetchColumn();

// Mesajları al (ana dosyadaki sorguyla aynı)
$stmt = $db->prepare("
    SELECT m.*, 
           UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
           u.username,
           up.avatar_url,
           (SELECT r.color 
            FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = m.sender_id 
              AND ur.server_id = ?
            ORDER BY r.importance DESC 
            LIMIT 1) AS role_color
    FROM messages1 m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE m.channel_id = ?
    ORDER BY m.id DESC
    LIMIT 40
");
$stmt->execute([$server_id, $channel_id]);
$messages = array_reverse($stmt->fetchAll());

// Sonucu döndür
echo json_encode([
    'success' => true,
    'channel_name' => $channel_name,
    'messages' => $messages
]);
?>