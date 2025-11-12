<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error: ' . $e->getMessage()]);
    exit;
}

$userId = $_SESSION['user_id'];

// Kullanıcı durumunu güncelleme fonksiyonu
function updateUserStatus($db, $userId, $status) {
    try {
        // Sütunların varlığını kontrol et
        $checkColumns = $db->query("SHOW COLUMNS FROM users LIKE 'original_status'");
        if ($checkColumns->rowCount() === 0) {
            error_log('original_status column missing in users table');
            throw new Exception('original_status column missing in users table');
        }

        // Hem status hem de original_status'u güncelle, last_activity'yi yenile
        $stmt = $db->prepare("UPDATE users SET status = ?, original_status = ?, last_activity = NOW() WHERE id = ?");
        $success = $stmt->execute([$status, $status, $userId]);
        if (!$success) {
            error_log('updateUserStatus failed: ' . implode(', ', $stmt->errorInfo()));
            throw new Exception('SQL execution failed: ' . implode(', ', $stmt->errorInfo()));
        }

        // Güncelleme sonrası durumu kontrol et
        $checkStmt = $db->prepare("SELECT status, original_status FROM users WHERE id = ?");
        $checkStmt->execute([$userId]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($user['original_status'] !== $status) {
            error_log("original_status mismatch: expected $status, got " . $user['original_status']);
            throw new Exception("original_status güncellenemedi: beklenen $status, bulunan " . $user['original_status']);
        }
        return true;
    } catch (Exception $e) {
        error_log('updateUserStatus error: ' . $e->getMessage());
        throw $e;
    }
}

// Durum güncelleme işlemi
if (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['status'])) {
    $newStatus = $_POST['status'];
    // Allow "offline" status by including it in the valid statuses
    if (in_array($newStatus, ['online', 'idle', 'dnd', 'offline'])) {
        try {
            if (updateUserStatus($db, $userId, $newStatus)) {
                $_SESSION['status'] = $newStatus;
                // Güncellenen durumu istemciye dön
                $stmt = $db->prepare("SELECT status, original_status FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success' => true,
                    'message' => 'Durum güncellendi',
                    'status' => $user['status'],
                    'original_status' => $user['original_status']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Durum güncellenemedi']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Durum güncellenemedi: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Geçersiz durum']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
?>