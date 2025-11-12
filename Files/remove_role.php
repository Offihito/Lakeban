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
    echo json_encode(['success' => false, 'message' => 'Veritabanına bağlanılamadı.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum açık değil.']);
    exit;
}

$user_id = $_POST['user_id'] ?? null;
$role_id = $_POST['role_id'] ?? null;
$server_id = $_POST['server_id'] ?? null;

if (!$user_id || !$role_id || !$server_id) {
    echo json_encode(['success' => false, 'message' => 'Eksik parametre.']);
    exit;
}

// Check if the current user has permission to manage roles
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND ur.server_id = ? AND r.permissions LIKE '%manage_roles%'
");
$stmt->execute([$_SESSION['user_id'], $server_id]);
$has_manage_roles = $stmt->fetchColumn() > 0;

// Check if the current user is the server owner
$stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$is_owner = $stmt->fetchColumn() == $_SESSION['user_id'];

if (!$has_manage_roles && !$is_owner) {
    echo json_encode(['success' => false, 'message' => 'Rolleri yönetme izniniz yok.']);
    exit;
}

// Remove the role
$stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND server_id = ?");
$stmt->execute([$user_id, $role_id, $server_id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true, 'message' => 'Rol başarıyla kaldırıldı.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Rol kaldırılamadı veya zaten mevcut değil.']);
}
?>