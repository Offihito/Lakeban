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

header('Content-Type: application/json');

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
    
    if (!isset($_POST['action'])) {
        throw new Exception('Action not provided');
    }

    $action = $_POST['action'];

    if ($action === 'find_user') {
        if (!isset($_POST['username'])) {
            throw new Exception('Username not provided');
        }

        $username = trim($_POST['username']);

        // Check if user exists
        $stmt = $db->prepare("SELECT id, username FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        echo json_encode(['success' => true, 'user_id' => $user['id'], 'username' => $user['username']]);
    } 
    elseif ($action === 'send_request') {
        if (!isset($_POST['receiver_id'])) {
            throw new Exception('Receiver ID not provided');
        }

        $receiver_id = $_POST['receiver_id'];
        $sender_id = $_SESSION['user_id'];

        // Check if trying to add self
        if ($sender_id == $receiver_id) {
            echo json_encode(['success' => false, 'message' => 'You cannot add yourself as a friend']);
            exit;
        }

        // Check if already friends
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM friends 
            WHERE (user_id = ? AND friend_id = ?) 
            OR (user_id = ? AND friend_id = ?)
        ");
        $stmt->execute([$sender_id, $receiver_id, $receiver_id, $sender_id]);

        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Already friends with this user']);
            exit;
        }

        // Check if pending request exists
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM friend_requests 
            WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'
        ");
        $stmt->execute([$sender_id, $receiver_id]);

        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Friend request already pending']);
            exit;
        }

        // Check if blocked
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM blocked_friends 
            WHERE (user_id = ? AND blocked_user_id = ?) 
            OR (user_id = ? AND blocked_user_id = ?)
        ");
        $stmt->execute([$sender_id, $receiver_id, $receiver_id, $sender_id]);

        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'You cannot send a friend request to this user']);
            exit;
        }

        // Send friend request
        $stmt = $db->prepare("INSERT INTO friend_requests (sender_id, receiver_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
        $stmt->execute([$sender_id, $receiver_id]);

        // Get pending count for receiver
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'");
        $stmt->execute([$receiver_id]);
        $pendingCount = $stmt->fetch()['count'];

        echo json_encode([
            'success' => true, 
            'message' => 'Friend request sent successfully',
            'pending_count' => $pendingCount,
            'receiver_id' => $receiver_id
        ]);
    } 
   elseif ($action === 'accept_request') {
    if (!isset($_POST['sender_id'])) {
        throw new Exception('Sender ID not provided');
    }

    $sender_id = $_POST['sender_id'];
    $receiver_id = $_SESSION['user_id'];

    // 1. Friend request durumunu güncelle
    $stmt = $db->prepare("
        UPDATE friend_requests 
        SET status = 'accepted', updated_at = NOW() 
        WHERE sender_id = ? 
        AND receiver_id = ? 
        AND status = 'pending'
    ");
    $stmt->execute([$sender_id, $receiver_id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'No pending request found']);
        exit;
    }

    // 2. Sıralı ID'ler oluştur (küçük ID her zaman solda)
    $min_id = min($sender_id, $receiver_id);
    $max_id = max($sender_id, $receiver_id);

    // 3. Çift kayıt önleme kontrolü
    $check = $db->prepare("
        SELECT 1 
        FROM friends 
        WHERE user_id = ? 
        AND friend_id = ?
    ");
    $check->execute([$min_id, $max_id]);

    // 4. Eğer kayıt yoksa ekle
    if ($check->rowCount() === 0) {
        $stmt = $db->prepare("
            INSERT INTO friends (user_id, friend_id) 
            VALUES (?, ?)
        ");
        $stmt->execute([$min_id, $max_id]);
    }

    // 5. Güncel pending count'u al
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM friend_requests 
        WHERE receiver_id = ? 
        AND status = 'pending'
    ");
    $stmt->execute([$receiver_id]);
    $pendingCount = $stmt->fetch()['count'];

    echo json_encode([
        'success' => true, 
        'message' => 'Friend request accepted',
        'pending_count' => $pendingCount,
        'receiver_id' => $receiver_id
    ]);
}
    elseif ($action === 'reject_request') {
        if (!isset($_POST['sender_id'])) {
            throw new Exception('Sender ID not provided');
        }

        $sender_id = $_POST['sender_id'];
        $receiver_id = $_SESSION['user_id'];

        // Delete the friend request
        $stmt = $db->prepare("DELETE FROM friend_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
        $stmt->execute([$sender_id, $receiver_id]);

        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'No pending request found']);
            exit;
        }

        // Get updated pending count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'");
        $stmt->execute([$receiver_id]);
        $pendingCount = $stmt->fetch()['count'];

        echo json_encode([
            'success' => true, 
            'message' => 'Friend request rejected',
            'pending_count' => $pendingCount,
            'receiver_id' => $receiver_id
        ]);
    } 
    elseif ($action === 'get_pending_count') {
        if (!isset($_SESSION['user_id'])) {
            throw new Exception('User not authenticated');
        }

        $user_id = $_SESSION['user_id'];

        $stmt = $db->prepare("SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'request_count' => $result['count']
        ]);
    }
    else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>