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
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : null;
    $role_id = isset($_POST['role_id']) ? $_POST['role_id'] : null;
    $server_id = isset($_POST['server_id']) ? $_POST['server_id'] : null; // Sunucu ID'sini de ekleyelim

    if (!$user_id || !$role_id || !$server_id) {
        echo json_encode(['success' => false, 'message' => 'Missing user ID, role ID, or server ID.']);
        exit;
    }

    try {
        $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND server_id = ?");
        $stmt->execute([$user_id, $role_id, $server_id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User role not found or already removed.']);
        }
    } catch (PDOException $e) {
        error_log("Error removing user from role: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error removing user: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>