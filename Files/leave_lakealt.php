<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

error_log("leave_lakealt.php çağrıldı, POST verileri: " . json_encode($_POST));

if (!isset($_SESSION['user_id'])) {
    error_log("Hata: Kullanıcı oturumu yok");
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

if (!isset($_POST['lakealt_id']) || !isset($_POST['csrf_token'])) {
    error_log("Hata: lakealt_id veya csrf_token eksik");
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
    exit;
}

if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    error_log("Hata: CSRF token uyumsuz, gönderilen: " . $_POST['csrf_token'] . ", beklenen: " . $_SESSION['csrf_token']);
    echo json_encode(['success' => false, 'message' => 'Güvenlik hatası']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$lakealt_id = (int)$_POST['lakealt_id'];

try {
    $sql = "DELETE FROM lakealt_members WHERE lakealt_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL prepare hatası: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Veritabanı hatası']);
        exit;
    }
    $stmt->bind_param("ii", $lakealt_id, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        error_log("Başarılı: Kullanıcı $user_id, lakealt $lakealt_id'den ayrıldı");
        echo json_encode(['success' => true, 'message' => 'Başarıyla ayrıldınız']);
    } else {
        error_log("Hata: Kullanıcı $user_id, lakealt $lakealt_id'de üye değil");
        echo json_encode(['success' => false, 'message' => 'Üye değilsiniz']);
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Hata: Ayrılma başarısız, mesaj: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ayrılma başarısız: ' . $e->getMessage()]);
}
$conn->close();
?>