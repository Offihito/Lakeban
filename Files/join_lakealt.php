<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

error_log("join_lakealt.php çağrıldı, POST verileri: " . json_encode($_POST));

if (!isset($_SESSION['user_id']) || !isset($_POST['lakealt_id']) || !isset($_POST['csrf_token'])) {
    error_log("Hata: user_id, lakealt_id veya csrf_token eksik");
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
    $sql = "SELECT 1 FROM lakealt_members WHERE lakealt_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $lakealt_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row()) {
        error_log("Hata: Kullanıcı $user_id zaten lakealt $lakealt_id üyesi");
        echo json_encode(['success' => false, 'message' => 'Zaten üyeysiniz']);
        exit;
    }

    $sql = "INSERT INTO lakealt_members (lakealt_id, user_id) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $lakealt_id, $user_id);
    $stmt->execute();

    error_log("Başarılı: Kullanıcı $user_id, lakealt $lakealt_id'e katıldı");
    echo json_encode(['success' => true, 'message' => 'Başarıyla katıldınız']);
} catch (Exception $e) {
    error_log("Hata: Katılma başarısız, mesaj: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Katılma başarısız: ' . $e->getMessage()]);
}
$conn->close();
?>