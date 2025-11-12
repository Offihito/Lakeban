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

// Parametreleri al
$channel_id = isset($_POST['channel_id']) ? (int)$_POST['channel_id'] : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($channel_id <= 0 || !in_array($action, ['join', 'leave'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Kanalın varlığını kontrol et
$stmt = $db->prepare("SELECT max_users FROM channels WHERE id = ? AND type = 'voice'");
$stmt->execute([$channel_id]);
$channel = $stmt->fetch();

if (!$channel) {
    echo json_encode(['success' => false, 'error' => 'Channel not found']);
    exit;
}

$max_users = $channel['max_users'] ?? 10;

// Katılımcı sayısını kontrol et
$stmt = $db->prepare("SELECT COUNT(*) as count FROM channel_participants WHERE channel_id = ?");
$stmt->execute([$channel_id]);
$participant_count = $stmt->fetchColumn();

if ($action === 'join') {
    if ($participant_count >= $max_users) {
        echo json_encode(['success' => false, 'error' => 'Channel is full']);
        exit;
    }

    // Kullanıcıyı ekle
    $stmt = $db->prepare("
        INSERT IGNORE INTO channel_participants (channel_id, user_id, joined_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$channel_id, $_SESSION['user_id']]);
    $participant_count++;
} else {
    // Kullanıcıyı kaldır
    $stmt = $db->prepare("DELETE FROM channel_participants WHERE channel_id = ? AND user_id = ?");
    $stmt->execute([$channel_id, $_SESSION['user_id']]);
    $participant_count--;
}

if ($data['type'] === 'screen-share-start') {
    // Ekran paylaşımı durumunu güncelle
    $stmt = $pdo->prepare("UPDATE voice_participants SET is_sharing_screen = TRUE WHERE user_id = ? AND channel_id = ?");
    $stmt->execute([$data['userId'], $data['channelId']]);

    // Tüm katılımcılara mesajı yayınla
    $participants = $pdo->query("SELECT user_id FROM voice_participants WHERE channel_id = {$data['channelId']}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($participants as $participantId) {
        if ($participantId != $data['userId']) {
            // WebSocket üzerinden mesaj gönder
            sendWebSocketMessage($participantId, [
                'type' => 'screen-share-start',
                'sender' => $data['userId'],
                'channelId' => $data['channelId']
            ]);
        }
    }
}

echo json_encode([
    'success' => true,
    'participant_count' => $participant_count,
    'max_users' => $max_users
]);
?>