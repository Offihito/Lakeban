<?php
session_start();
header('Content-Type: application/json');

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
    echo json_encode(['success' => false, 'error' => 'Database connection error']);
    exit;
}

$server_id = isset($_GET['server_id']) ? intval($_GET['server_id']) : 0;

if ($server_id === 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid server ID']);
    exit;
}

$stmt = $db->prepare("
    SELECT 
        us.id AS user_id, 
        up.avatar_url, 
        IF(us.last_activity >= NOW() - INTERVAL 2 MINUTE, 'online', 'offline') AS status,
        us.username, 
        GROUP_CONCAT(r.name ORDER BY r.importance DESC) AS role_names, 
        GROUP_CONCAT(r.color ORDER BY r.importance DESC) AS role_colors
    FROM 
        users us
    JOIN 
        server_members s ON us.id = s.user_id
    LEFT JOIN 
        user_profiles up ON us.id = up.user_id
    LEFT JOIN 
        user_roles ur ON us.id = ur.user_id AND ur.server_id = s.server_id
    LEFT JOIN 
        roles r ON ur.role_id = r.id
    WHERE 
        s.server_id = ?
    GROUP BY 
        us.id
");
$stmt->execute([$server_id]);
$members = $stmt->fetchAll();

echo json_encode(['success' => true, 'members' => $members]);
?>