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
    $user_ids = isset($_POST['user_ids']) ? $_POST['user_ids'] : [];
    $role_id = isset($_POST['role_id']) ? $_POST['role_id'] : null;
    $server_id = isset($_POST['server_id']) ? $_POST['server_id'] : null;

    if (empty($user_ids) || !$role_id || !$server_id) {
        echo json_encode(['success' => false, 'message' => 'Missing data.']);
        exit;
    }

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, server_id) VALUES (?, ?, ?)");
        foreach ($user_ids as $user_id) {
            $stmt->execute([$user_id, $role_id, $server_id]);
        }
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error adding users to role: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error adding users: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>