<?php
session_start();
header('Content-Type: application/json');

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

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$channel_id = $_GET['channel_id'] ?? null;
if (!$channel_id) {
    echo json_encode(['success' => false, 'error' => 'Channel ID is missing']);
    exit;
}

// Check if user is the server owner
$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = (SELECT server_id FROM channels WHERE id = ?)");
$stmt->execute([$channel_id]);
$server_owner_id = $stmt->fetchColumn();
$is_owner = ($server_owner_id == $_SESSION['user_id']);

// Fetch user roles
$stmt = $db->prepare("SELECT role_id FROM user_roles WHERE server_id = (SELECT server_id FROM channels WHERE id = ?) AND user_id = ?");
$stmt->execute([$channel_id, $_SESSION['user_id']]);
$user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Check channel permissions
$has_write_permission = $is_owner; // Owners always have write permission

if (!$has_write_permission) {
    $stmt = $db->prepare("SELECT permissions FROM channels WHERE id = ?");
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch();

    if ($channel) {
        $permissions = json_decode($channel['permissions'] ?? '{}', true);
        $write_allowed_roles = $permissions['write_allowed_roles'] ?? [];
        $write_denied_roles = $permissions['write_denied_roles'] ?? [];
        $write_allowed_users = $permissions['write_allowed_users'] ?? [];
        $write_denied_users = $permissions['write_denied_users'] ?? [];

        // Check user-specific permissions
        if (in_array($_SESSION['user_id'], $write_allowed_users)) {
            $has_write_permission = true;
        } elseif (in_array($_SESSION['user_id'], $write_denied_users)) {
            $has_write_permission = false;
        } elseif (empty($write_allowed_roles)) {
            // If write_allowed_roles is empty, everyone can write unless explicitly denied
            $has_write_permission = !in_array($_SESSION['user_id'], $write_denied_users) && !array_intersect($user_roles, $write_denied_roles);
        } else {
            // Check role-based permissions
            foreach ($user_roles as $role_id) {
                if (in_array($role_id, $write_allowed_roles)) {
                    $has_write_permission = true;
                    break;
                }
                if (in_array($role_id, $write_denied_roles)) {
                    $has_write_permission = false;
                    break;
                }
            }
            // If user has manage_channels permission, they can write
            if (!$has_write_permission) {
                foreach ($user_roles as $role_id) {
                    $stmt = $db->prepare("SELECT permissions FROM roles WHERE id = ?");
                    $stmt->execute([$role_id]);
                    $role_permissions = json_decode($stmt->fetchColumn() ?? '{}', true);
                    if (is_array($role_permissions) && in_array('manage_channels', $role_permissions)) {
                        $has_write_permission = true;
                        break;
                    }
                }
            }
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Channel not found']);
        exit;
    }
}

echo json_encode(['success' => true, 'has_write_permission' => $has_write_permission]);
exit;
?>