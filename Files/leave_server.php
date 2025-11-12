<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Unable to connect to the database. Please try again later.']));
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Get server ID from POST
if (!isset($_POST['server_id'])) {
    echo json_encode(['success' => false, 'message' => 'Server ID is missing']);
    exit;
}

$server_id = $_POST['server_id'];
$user_id = $_SESSION['user_id'];

// Check if the user is a member of the server
$stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $user_id]);

if ($stmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'You are not a member of this server']);
    exit;
}

// Check if the user is the owner of the server
$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_owner_id = $stmt->fetchColumn();

if ($server_owner_id == $user_id) {
    echo json_encode(['success' => false, 'message' => 'Server owners cannot leave their own server']);
    exit;
}

// Remove the user from the server members
$stmt = $db->prepare("DELETE FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $user_id]);

if ($stmt->rowCount() > 0) {
    // GÜLE GÜLE MESAJI GÖNDER
    try {
        // Kullanıcı adını al
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $username = $user['username'] ?? 'Bir kullanıcı';
        
        // Sunucu adını al
        $stmt = $db->prepare("SELECT name FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        $server = $stmt->fetch();
        $server_name = $server['name'] ?? 'bir sunucu';
        
        // Botların güle güle komutlarını kontrol et
        $stmt = $db->prepare("
            SELECT bsc.goodbye_channel, bsc.goodbye_message, u.username AS bot_username
            FROM bot_special_commands bsc
            JOIN users u ON bsc.bot_id = u.id
            WHERE bsc.goodbye_channel IS NOT NULL AND bsc.goodbye_message != '' 
            AND u.is_active = 1
            AND bsc.bot_id IN (
                SELECT user_id FROM server_members WHERE server_id = ?
            )
        ");
        $stmt->execute([$server_id]);
        $goodbye_commands = $stmt->fetchAll();

        foreach ($goodbye_commands as $command) {
            $message = str_replace(
                ['{user}', '{server}'],
                [htmlspecialchars($username), htmlspecialchars($server_name)],
                $command['goodbye_message']
            );
            
            $stmt = $db->prepare("INSERT INTO messages1 
                (server_id, channel_id, sender_id, message_text) 
                VALUES (?, ?, (SELECT id FROM users WHERE username = ?), ?)");
            $stmt->execute([
                $server_id,
                $command['goodbye_channel'],
                $command['bot_username'],
                $message
            ]);
        }
    } catch (Exception $e) {
        error_log("Güle güle mesajı gönderilemedi: " . $e->getMessage());
    }
    
    echo json_encode(['success' => true, 'message' => 'You have left the server']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to leave the server']);
}
?>