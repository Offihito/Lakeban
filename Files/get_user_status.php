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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die(json_encode(['success' => false, 'error' => 'Veritabanı bağlantı hatası']));
}

// Session check
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Oturum bulunamadı']));
}

// Get server ID from query
$server_id = $_GET['server_id'] ?? null;
if (!$server_id) {
    die(json_encode(['success' => false, 'error' => 'Sunucu ID eksik']));
}

// Fetch server members with their roles, colors, and status
$stmt = $db->prepare("
    SELECT
        us.id AS user_id,
        up.avatar_url,
        us.status,
        us.username,
        up.display_username,
        GROUP_CONCAT(r.name ORDER BY r.importance DESC, r.id ASC) AS role_names,
        GROUP_CONCAT(r.color ORDER BY r.importance DESC, r.id ASC) AS role_colors,
        GROUP_CONCAT(r.id ORDER BY r.importance DESC, r.id ASC) AS role_ids,
        us.last_activity
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
$server_members = $stmt->fetchAll();

// Rollerin importance değerlerini çek
$stmt = $db->prepare("SELECT name, importance FROM roles WHERE server_id = ? ORDER BY importance DESC, id ASC");
$stmt->execute([$server_id]);
$role_importance = [];
foreach ($stmt->fetchAll() as $role) {
    $role_importance[$role['name']] = $role['importance'];
}

// Group members by roles
$role_groups = [];
$online_members = [];
$offline_members = [];
foreach ($server_members as $member) {
    if ($member['status'] === 'offline') {
        $offline_members[] = $member;
        continue;
    }
    $roles = explode(',', $member['role_names'] ?? '');
    $colors = explode(',', $member['role_colors'] ?? '');
    if (empty($roles[0])) {
        $online_members[] = $member;
    } else {
        $highest_role = $roles[0];
        $highest_color = $colors[0];
        if (!isset($role_groups[$highest_role])) {
            $role_groups[$highest_role] = [];
        }
        $member['highest_color'] = $highest_color;
        $role_groups[$highest_role][] = $member;
    }
}

// Sort role groups by importance
uksort($role_groups, function($a, $b) use ($role_importance) {
    $importance_a = $role_importance[$a] ?? 0;
    $importance_b = $role_importance[$b] ?? 0;
    return $importance_b <=> $importance_a;
});

// Prepare response
$response = [
    'success' => true,
    'role_groups' => $role_groups,
    'online_members' => $online_members,
    'offline_members' => $offline_members
];

header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>