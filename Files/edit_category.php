<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantı dosyanız

// Yetki kontrolü
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

$server_id = $_POST['server_id'] ?? null;
$category_id = $_POST['category_id'] ?? null;
$action = $_POST['action'] ?? null;
$new_category_name = $_POST['category_name'] ?? null;

if (!$server_id || !$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Eksik parametre']);
    exit;
}

// Veritabanı bağlantısı
try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası']);
    exit;
}

// Sunucu sahibi veya yönetici izni kontrolü
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND ur.server_id = ? AND (r.permissions LIKE '%manage_channels%' OR ? = (SELECT owner_id FROM servers WHERE id = ?))
");
$stmt->execute([$_SESSION['user_id'], $server_id, $_SESSION['user_id'], $server_id]);
$hasPermission = $stmt->fetchColumn() > 0;

if (!$hasPermission) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bu işlemi gerçekleştirmek için yetkiniz yok']);
    exit;
}

if ($action === 'edit' && $category_id && $new_category_name) {
    // Kategori adını güncelle
    $stmt = $db->prepare("UPDATE categories SET name = ? WHERE id = ? AND server_id = ?");
    $stmt->execute([$new_category_name, $category_id, $server_id]);
    echo json_encode(['success' => true, 'message' => 'Kategori güncellendi']);
} elseif ($action === 'delete' && $category_id) {
    // Kategoriyi sil
    $stmt = $db->prepare("DELETE FROM categories WHERE id = ? AND server_id = ?");
    $stmt->execute([$category_id, $server_id]);
    // Kategoriye bağlı kanalları güncelle (kategori_id'yi null yap)
    $stmt = $db->prepare("UPDATE channels SET category_id = NULL WHERE category_id = ? AND server_id = ?");
    $stmt->execute([$category_id, $server_id]);
    echo json_encode(['success' => true, 'message' => 'Kategori silindi']);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Geçersiz işlem veya eksik parametre']);
}

exit;
?>