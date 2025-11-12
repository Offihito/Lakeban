<?php
// Suppress any output before JSON response
ob_start();
session_start();

// Set JSON content type
header('Content-Type: application/json; charset=UTF-8');

try {
    // Include database connection
    require_once 'database/db_connection.php';

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized', 401);
    }

    $userId = $_SESSION['user_id'];

    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Parse JSON input
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    $action = $data['action'] ?? '';

    if ($action === 'update_group') {
        $groupId = filter_var($data['group_id'] ?? 0, FILTER_VALIDATE_INT);
        $name = trim($data['name'] ?? '');
        $avatarUrl = $data['avatar_url'] ?? null;

        if (!$groupId || $groupId <= 0) {
            throw new Exception('Invalid group ID');
        }

        if (empty($name)) {
            throw new Exception('Group name is required');
        }

        // Check if user is a group member
        $stmt = $db->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
        $isMember = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$isMember) {
            throw new Exception('You must be a group member to edit settings');
        }

        // Validate avatar URL if provided
        if ($avatarUrl && !filter_var($avatarUrl, FILTER_VALIDATE_URL) && !file_exists(__DIR__ . '/' . $avatarUrl)) {
            throw new Exception('Invalid avatar URL');
        }

        // Update group details
        $stmt = $db->prepare("UPDATE groups SET name = ?, avatar_url = ? WHERE id = ?");
        $stmt->execute([$name, $avatarUrl ?: 'avatars/default-group-avatar.png', $groupId]);

        echo json_encode(['success' => true, 'message' => 'Group updated successfully']);
    } elseif ($action === 'delete_group') {
        $groupId = filter_var($data['group_id'] ?? 0, FILTER_VALIDATE_INT);

        if (!$groupId || $groupId <= 0) {
            throw new Exception('Invalid group ID');
        }

        // Check if user is a group member
        $stmt = $db->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
        $isMember = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$isMember) {
            throw new Exception('You must be a group member to delete the group');
        }

        // Delete group and related data
        $db->beginTransaction();
        $db->prepare("DELETE FROM group_members WHERE group_id = ?")->execute([$groupId]);
        $db->prepare("DELETE FROM messages1 WHERE group_id = ?")->execute([$groupId]);
        $db->prepare("DELETE FROM groups WHERE id = ?")->execute([$groupId]);
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Group deleted successfully']);
    } else {
        throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    // Log the error for debugging
    error_log('Error in group_settings.php: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
    
    // Set appropriate HTTP status code
    http_response_code($e->getCode() ?: 500);
    
    // Return JSON error response
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    // Clear output buffer to prevent stray output
    ob_end_flush();
}
?>