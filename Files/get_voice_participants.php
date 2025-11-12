<?php
session_start();
header('Content-Type: application/json');

// Database connection
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Session check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$participants[] = [
    'id' => $row['user_id'],
    'username' => $row['username'],
    'avatar_url' => $row['avatar_url'],
    'is_sharing_screen' => (bool)$row['is_sharing_screen']
];

// Channel ID'yi al
$channel_id = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : 0;
if ($channel_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid channel ID']);
    exit;
}

// Kanaldaki katılımcıları al
$stmt = $db->prepare("
    SELECT u.id, u.username, up.avatar_url
    FROM channel_participants cp
    JOIN users u ON cp.user_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE cp.channel_id = ?
");
$stmt->execute([$channel_id]);
$participants = $stmt->fetchAll();

// Katılımcı sayısını al
$participant_count = count($participants);
$max_users = 10; // Varsayılan maksimum kullanıcı sayısı, gerekirse channels tablosundan çekilebilir

echo json_encode([
    'success' => true,
    'participants' => $participants,
    'participant_count' => $participant_count,
    'max_users' => $max_users
]);
?>