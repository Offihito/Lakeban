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
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection error: ' . $e->getMessage()]));
}

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Not logged in']));
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_as_read') {
        $friend_id = filter_var($_POST['friend_id'], FILTER_SANITIZE_NUMBER_INT);

        $stmt = $db->prepare("UPDATE messages1 SET read_status = TRUE WHERE receiver_id = ? AND sender_id = ? AND read_status = FALSE");
        $stmt->execute([$_SESSION['user_id'], $friend_id]);

        $stmt = $db->prepare("UPDATE friends SET unread_messages = 0 WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $stmt->execute([$_SESSION['user_id'], $friend_id, $friend_id, $_SESSION['user_id']]);

        echo json_encode(['success' => true]);
        exit;
    }

if ($action === 'send_message') {
    $receiver_id = filter_var($_POST['receiver_id'], FILTER_SANITIZE_NUMBER_INT);
    $message = trim($_POST['message'] ?? '');
    $reply_to_message_id = $_POST['reply_to_message_id'] ?? null;
    $file_url = '';

    // Dosya yükleme işlemi (upload.php'den taşındı)
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = uniqid() . '_' . basename($_FILES['file']['name']);
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            $file_url = $targetPath;
        }
    }

    // Mesaj veya dosya olmalı
    if (empty($message) && empty($file_url)) {
        echo json_encode(['success' => false, 'message' => 'Message or file is required']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO messages1 (sender_id, receiver_id, message_text, reply_to_message_id, file_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $receiver_id, $message, $reply_to_message_id, $file_url]);

    echo json_encode(['success' => true, 'file_url' => $file_url]);
    exit;
}

    if ($_POST['action'] === 'check_new_messages') {
        $userId = $_SESSION['user_id'];
        $stmt = $db->prepare("
            SELECT COUNT(*) as unread_messages
            FROM messages1
            WHERE receiver_id = ? AND read_status = FALSE
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        echo json_encode(['success' => true, 'unread_messages' => $result['unread_messages']]);
        exit;
    }

    if ($action === 'get_messages') {
    $friend_id = filter_var($_POST['friend_id'], FILTER_SANITIZE_NUMBER_INT);
    $page = filter_var($_POST['page'], FILTER_SANITIZE_NUMBER_INT) ?? 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $is_group = $_POST['is_group'] ?? false;

    try {
        $query = "
            SELECT 
                m.*,
                UNIX_TIMESTAMP(m.created_at) as timestamp,
                u.username as sender_username,
                up.avatar_url as sender_avatar,
                m.file_url,
                rm.message_text AS reply_to_message_text,
                ru.username AS reply_to_username,
                rup.avatar_url AS reply_to_avatar_url
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            LEFT JOIN messages1 rm ON m.reply_to_message_id = rm.id
            LEFT JOIN users ru ON rm.sender_id = ru.id
            LEFT JOIN user_profiles rup ON ru.id = rup.user_id
            WHERE ";

        if($is_group) {
            $query .= "m.group_id = ?";
            $params = [$friend_id];
        } else {
            $query .= "(m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)";
            $params = [$_SESSION['user_id'], $friend_id, $friend_id, $_SESSION['user_id']];
        }

        $query .= " ORDER BY m.created_at DESC LIMIT $limit OFFSET $offset";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

    if ($action === 'delete_message') {
        $message_id = filter_var($_POST['message_id'], FILTER_SANITIZE_NUMBER_INT);

        $stmt = $db->prepare("DELETE FROM messages1 WHERE id = ? AND sender_id = ?");
        $stmt->execute([$message_id, $_SESSION['user_id']]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'edit_message') {
        $message_id = filter_var($_POST['message_id'], FILTER_SANITIZE_NUMBER_INT);
        $new_message_text = trim($_POST['new_message_text']);

        if (empty($new_message_text)) {
            echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
            exit;
        }

        $stmt = $db->prepare("UPDATE messages1 SET message_text = ? WHERE id = ? AND sender_id = ?");
        $stmt->execute([$new_message_text, $message_id, $_SESSION['user_id']]);

        echo json_encode(['success' => true]);
        exit;
    }
}

// If no valid action was found, return an error
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
?>