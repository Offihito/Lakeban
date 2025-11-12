
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
    echo json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası']);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_POST['user_ids']) || !isset($_POST['role_id']) || !isset($_POST['server_id'])) {
    echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
    exit;
}

$user_ids = $_POST['user_ids'];
$role_id = (int)$_POST['role_id'];
$server_id = (int)$_POST['server_id'];

// Yetki kontrolü
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND (owner_id = ? OR id IN (SELECT server_id FROM user_roles WHERE user_id = ? AND role_id IN (SELECT id FROM roles WHERE permissions LIKE '%manage_roles%')))");
$stmt->execute([$server_id, $_SESSION['user_id'], $_SESSION['user_id']]);
if ($stmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok']);
    exit;
}

try {
    $db->beginTransaction();
    foreach ($user_ids as $user_id) {
        $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND server_id = ?");
        $stmt->execute([(int)$user_id, $role_id, $server_id]);
    }

    // Audit log entry
    $stmt = $db->prepare("INSERT INTO role_audit_log (role_id, user_id, action, details, server_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$role_id, $_SESSION['user_id'], 'remove_users_from_role', json_encode(['user_ids' => $user_ids]), $server_id]);

    $db->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>
