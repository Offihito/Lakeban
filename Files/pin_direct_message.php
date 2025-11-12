<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'is_pinned' => false];

try {
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'Session not active';
        echo json_encode($response);
        exit;
    }

    if (!isset($_POST['message_id']) || !isset($_POST['action'])) {
        $response['message'] = 'Missing parameters';
        echo json_encode($response);
        exit;
    }

    $messageId = (int)$_POST['message_id'];
    $action = $_POST['action'];
    $userId = (int)$_SESSION['user_id'];

    // Fetch message details
    $stmt = $db->prepare("SELECT sender_id, receiver_id, is_pinned FROM messages1 WHERE id = ?");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        $response['message'] = 'Message not found';
        echo json_encode($response);
        exit;
    }

    // Verify user is part of the conversation
    if ($userId != $message['sender_id'] && $userId != $message['receiver_id']) {
        $response['message'] = 'You do not have permission for this action';
        echo json_encode($response);
        exit;
    }

    $currentPinnedState = $message['is_pinned'];
    $newPinnedState = $action === 'pin' ? true : false;

    if ($currentPinnedState === $newPinnedState) {
        $response['message'] = $newPinnedState ? 'Message already pinned' : 'Message already unpinned';
        echo json_encode($response);
        exit;
    }

    if ($action === 'pin') {
        // Check pinned message count
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages1 
            WHERE is_pinned = 1 
            AND (
                (sender_id = ? AND receiver_id = ?) 
                OR 
                (sender_id = ? AND receiver_id = ?)
            )
        ");
        $stmt->execute([
            $message['sender_id'], 
            $message['receiver_id'], 
            $message['receiver_id'], 
            $message['sender_id']
        ]);
        $pinnedCount = $stmt->fetchColumn();

        if ($pinnedCount >= 5) {
            $response['message'] = 'A conversation can have a maximum of 5 pinned messages';
            echo json_encode($response);
            exit;
        }
    }

    // Update pin status
    $stmt = $db->prepare("UPDATE messages1 SET is_pinned = ? WHERE id = ?");
    $stmt->execute([$newPinnedState, $messageId]);

    if ($stmt->rowCount() > 0) {
        $response['success'] = true;
        $response['message'] = $newPinnedState ? 'Message pinned successfully' : 'Message unpinned successfully';
        $response['is_pinned'] = $newPinnedState;
    } else {
        $response['message'] = 'Failed to update message status';
    }

    echo json_encode($response);
} catch (Exception $e) {
    error_log('Error in pin_direct_message.php: ' . $e->getMessage());
    $response['message'] = 'Server error: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}
?>