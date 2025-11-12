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
    echo json_encode(['error' => 'Unable to connect to the database.']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Role ID is missing.']);
    exit;
}

$role_id = $_GET['id'];

$stmt = $db->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$role_id]);
$role = $stmt->fetch();

if ($role) {
    // Permissions are stored as JSON, decode them
    $role['permissions'] = json_decode($role['permissions'], true);
    echo json_encode($role);
} else {
    echo json_encode(['error' => 'Role not found.']);
}
?>