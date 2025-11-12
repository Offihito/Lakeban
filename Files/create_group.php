<?php
session_start();
header('Content-Type: application/json');

// Oturum ve yetki kontrolü
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Yetkisiz erişim']));
}

// Veritabanı bağlantısı
require_once 'db_connection.php';

// Benzersiz istek kimliği oluştur
$requestId = uniqid('req_', true);
error_log("Request ID: $requestId - Grup oluşturma isteği alındı");

try {
    // FormData'dan verileri al
    $groupName = isset($_POST['name']) ? htmlspecialchars(trim($_POST['name'])) : null;
    $members = isset($_POST['members']) ? json_decode($_POST['members'], true) : null;
    $avatar = isset($_FILES['avatar']) ? $_FILES['avatar'] : null;

    // Gelen verileri kontrol et
    if (empty($groupName) || empty($members)) {
        throw new Exception('Grup adı ve üyeler gereklidir');
    }

    // JSON decode kontrolü
    if ($members === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Geçersiz üye listesi: ' . json_last_error_msg());
    }

    $creatorId = (int)$_SESSION['user_id'];
    $members = array_map('intval', $members);

    // Aynı grup adıyla grup oluşturmayı kontrol et
    $stmt = $db->prepare("SELECT id FROM groups WHERE name = ? AND creator_id = ?");
    $stmt->execute([$groupName, $creatorId]);
    if ($stmt->fetch()) {
        throw new Exception('Bu ada sahip bir grup zaten mevcut');
    }

    // Üyelerin kullanıcının arkadaşı olduğunu doğrula
    $validFriends = getFriends($db, $creatorId);
    $validFriendIds = array_column($validFriends, 'id');

    foreach ($members as $memberId) {
        if (!in_array($memberId, $validFriendIds)) {
            throw new Exception('Geçersiz üye seçimi: ID ' . $memberId);
        }
    }

    // Transaction başlat
    $db->beginTransaction();

    // Grup avatarını işle (isteğe bağlı)
    $avatarUrl = null;
    if ($avatar && $avatar['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $avatarName = uniqid() . '_' . basename($avatar['name']);
        $avatarPath = $uploadDir . $avatarName;
        if (!move_uploaded_file($avatar['tmp_name'], $avatarPath)) {
            throw new Exception('Avatar yüklenemedi');
        }
        $avatarUrl = $avatarPath;
    }

    // Grubu oluştur
    $stmt = $db->prepare("INSERT INTO groups (name, creator_id, avatar_url) VALUES (?, ?, ?)");
    $stmt->execute([$groupName, $creatorId, $avatarUrl]);
    $groupId = $db->lastInsertId();

    // Üyeleri ekle (oluşturanı da ekle)
    $members[] = $creatorId;
    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
    foreach (array_unique($members) as $memberId) {
        $stmt->execute([$groupId, $memberId]);
    }

    $db->commit();

    error_log("Request ID: $requestId - Grup ID: $groupId başarıyla oluşturuldu");

    echo json_encode([
        'success' => true,
        'group_id' => $groupId,
        'message' => 'Grup başarıyla oluşturuldu'
    ]);

} catch (PDOException $e) {
    $db->rollBack();
    error_log("Request ID: $requestId - Database Error: " . $e->getMessage() . " at line " . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => 'Veritabanı hatası: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $db->rollBack();
    error_log("Request ID: $requestId - General Error: " . $e->getMessage() . " at line " . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// ananas.php'deki getFriends fonksiyonunu kullanabilmek için
function getFriends($db, $userId) {
    $stmt = $db->prepare("
        SELECT u.id FROM friends f
        JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
        WHERE (f.user_id = ? OR f.friend_id = ?) AND u.id != ?
    ");
    $stmt->execute([$userId, $userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>