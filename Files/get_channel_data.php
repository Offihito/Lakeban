<?php
// Çıktı tamponlamasını başlat ve tüm beklenmeyen çıktıları önle
ob_start();

// Hata raporlamasını yapılandır
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hataları ekrana yazdırma, sadece logla
ini_set('log_errors', 1);

// JSON başlığını hemen ayarla
header('Content-Type: application/json; charset=utf-8');

session_start();

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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to connect to the database']);
    exit;
}

// Session check
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get channel_id and server_id from request
$channel_id = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : null;
$server_id = isset($_GET['server_id']) ? (int)$_GET['server_id'] : null;

if (!$channel_id || !$server_id) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing channel_id or server_id']);
    exit;
}

// Check if the user is a member of the server
try {
    $stmt_member = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
    $stmt_member->execute([$server_id, $_SESSION['user_id']]);
    if ($stmt_member->rowCount() === 0) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not a member of this server']);
        exit;
    }
} catch (PDOException $e) {
    error_log("Member check error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server member check failed']);
    exit;
}

// Check if the channel exists
try {
    $stmt_channel = $db->prepare("SELECT name FROM channels WHERE id = ? AND server_id = ?");
    $stmt_channel->execute([$channel_id, $server_id]);
    $channel_info = $stmt_channel->fetch();

    if (!$channel_info) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Channel not found']);
        exit;
    }
} catch (PDOException $e) {
    error_log("Channel check error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Channel check failed']);
    exit;
}

// Fetch messages for the channel
try {
    $stmt_messages = $db->prepare("
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
    $stmt_messages->execute([$server_id, $channel_id]);
    $messages = array_reverse($stmt_messages->fetchAll());
} catch (PDOException $e) {
    error_log("Messages fetch error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch messages']);
    exit;
}

$response = [
    'success' => true,
    'channel_name' => $channel_info['name'],
    'messages' => $messages ?: [] // Mesaj yoksa boş dizi döndür
];

// Çıktı tamponunu temizle ve yanıtı gönder
ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>