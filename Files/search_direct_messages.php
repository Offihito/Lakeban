<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim.']);
    exit;
}

if (!isset($_GET['query']) || (!isset($_GET['friend_id']) && !isset($_GET['group_id']))) {
    echo json_encode(['success' => false, 'error' => 'Eksik parametreler.']);
    exit;
}

$current_user = $_SESSION['user_id'];
$query = trim($_GET['query']);
$friend_id = isset($_GET['friend_id']) ? $_GET['friend_id'] : null;
$group_id = isset($_GET['group_id']) ? $_GET['group_id'] : null;

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'messages' => []]);
    exit;
}

// Veritabanı bağlantısı
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
    error_log("Database connection error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı bağlantı hatası.']);
    exit;
}

$searchTerm = "%" . $query . "%";
$messages = [];

if ($friend_id) {
    // Birebir mesaj araması
    $stmt = $db->prepare("
        SELECT
            m.id,
            m.message_text,
            m.created_at,
            u.username,
            up.avatar_url,
            'friend' AS message_type
        FROM messages1 AS m
        JOIN users AS u ON m.sender_id = u.id
        LEFT JOIN user_profiles AS up ON u.id = up.user_id
        WHERE 
            ((m.sender_id = :current_user AND m.receiver_id = :friend_id) OR 
             (m.sender_id = :friend_id AND m.receiver_id = :current_user))
            AND m.message_text LIKE :query
            AND m.group_id IS NULL
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->bindParam(':current_user', $current_user, PDO::PARAM_INT);
    $stmt->bindParam(':friend_id', $friend_id, PDO::PARAM_INT);
    $stmt->bindParam(':query', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $messages = array_merge($messages, $stmt->fetchAll());
}

if ($group_id) {
    // Grup mesaj araması
    $stmt = $db->prepare("
        SELECT
            m.id,
            m.message_text,
            m.created_at,
            u.username,
            up.avatar_url,
            'group' AS message_type,
            g.name AS group_name
        FROM messages1 AS m
        JOIN users AS u ON m.sender_id = u.id
        LEFT JOIN user_profiles AS up ON u.id = up.user_id
        JOIN groups AS g ON m.group_id = g.id
        WHERE 
            m.group_id = :group_id
            AND m.message_text LIKE :query
            AND EXISTS (
                SELECT 1 
                FROM group_members gm 
                WHERE gm.group_id = :group_id 
                AND gm.user_id = :current_user
            )
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->bindParam(':group_id', $group_id, PDO::PARAM_INT);
    $stmt->bindParam(':current_user', $current_user, PDO::PARAM_INT);
    $stmt->bindParam(':query', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $messages = array_merge($messages, $stmt->fetchAll());
}

echo json_encode(['success' => true, 'messages' => $messages]);
?>