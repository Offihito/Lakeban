<?php
header('Content-Type: application/json');
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Veritabanı bağlantısı
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    echo json_encode(['error' => 'Unable to connect to the database.']);
    exit;
}

if (!isset($_GET['role_id']) || !isset($_GET['server_id'])) {
    echo json_encode(['error' => 'Role ID or Server ID is missing.']);
    exit;
}

$role_id = $_GET['role_id'];
$server_id = $_GET['server_id'];

try {
    // SORGU GÜNCELLENDİ:
    // 1. Yalnızca o sunucunun üyelerini getirmek için `server_members` tablosu kullanıldı.
    // 2. `user_profiles` tablosu ile LEFT JOIN yapılarak avatar_url bilgisi eklendi.
    $stmt = $db->prepare("
        SELECT u.id, u.username, up.avatar_url AS profile_picture
        FROM users u
        JOIN server_members sm ON u.id = sm.user_id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE sm.server_id = ?
        AND u.id NOT IN (
            SELECT user_id FROM user_roles WHERE role_id = ? AND server_id = ?
        )
        ORDER BY u.username ASC
    ");
    $stmt->execute([$server_id, $role_id, $server_id]);
    $users = $stmt->fetchAll();

    echo json_encode($users);

} catch (PDOException $e) {
    error_log("Query failed for get_users_without_role: " . $e->getMessage());
    echo json_encode(['error' => 'A database error occurred.']);
}
?>