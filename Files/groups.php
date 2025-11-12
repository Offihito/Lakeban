<?php
session_start();
require_once 'db_connection.php'; // Veritabanı bağlantısı

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'create_group':
        handleCreateGroup();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
}

function handleCreateGroup() {
    global $db;
    
    $creatorId = $_SESSION['user_id'];
    $name = trim($_POST['name'] ?? '');
    $members = $_POST['members'] ?? [];
    
    // Validasyon
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Grup adı gereklidir']);
        return;
    }
    
    if (empty($members)) {
        echo json_encode(['success' => false, 'message' => 'En az bir üye seçmelisiniz']);
        return;
    }
    
    // Avatar işleme
    $avatarUrl = '';
    if (!empty($_FILES['avatar']['name'])) {
        $uploadDir = 'uploads/groups/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = uniqid() . '_' . basename($_FILES['avatar']['name']);
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
            $avatarUrl = $targetPath;
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Grubu oluştur
        $stmt = $db->prepare("INSERT INTO groups (name, creator_id, avatar_url) VALUES (?, ?, ?)");
        $stmt->execute([$name, $creatorId, $avatarUrl]);
        $groupId = $db->lastInsertId();
        
        // Üyeleri ekle (oluşturanı da ekliyoruz)
        $members[] = $creatorId; // Oluşturanı da üye yap
        $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        
        foreach ($members as $memberId) {
            $stmt->execute([$groupId, $memberId]);
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'group_id' => $groupId]);
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Grup oluşturma hatası: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
    }
}