<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once 'config.php'; // config.php dosyasının doğru şekilde bağlandığından emin ol

// Hata çıktısını kapat, hataları sadece loglara yaz
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL); // Tüm hataları loglara yazmaya devam et

// Gelen POST parametrelerini kontrol et
if (!isset($_SESSION['user_id']) || !isset($_POST['comment_id']) || !isset($_POST['csrf_token'])) {
    error_log("delete_comment.php: Eksik POST parametreleri. Session user_id: " . ($_SESSION['user_id'] ?? 'Yok') . ", comment_id: " . ($_POST['comment_id'] ?? 'Yok') . ", csrf_token: " . ($_POST['csrf_token'] ?? 'Yok'));
    echo json_encode(['status' => 'error', 'message' => 'Eksik parametreler sağlandı.']);
    exit;
}

// CSRF token kontrolü
if ($_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    error_log("delete_comment.php: Geçersiz CSRF token. POST: " . $_POST['csrf_token'] . ", SESSION: " . ($_SESSION['csrf_token'] ?? 'Yok'));
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz güvenlik belirteci.']);
    exit;
}

$comment_id = (int)$_POST['comment_id'];
$user_id = (int)$_SESSION['user_id']; // Oturumdaki kullanıcı ID'si

// Veritabanı bağlantısının ($conn) geçerli olduğundan emin ol
// config.php'den gelen $conn bir mysqli nesnesi olmalı
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("delete_comment.php: Veritabanı bağlantısı (\$conn) mevcut değil veya geçerli bir mysqli nesnesi değil.");
    echo json_encode(['status' => 'error', 'message' => 'Veritabanı bağlantı hatası.']);
    exit;
}


// Yorumun sahibini kontrol et
$sql = "SELECT user_id FROM comments WHERE id = ? AND is_deleted = 0";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("delete_comment.php: Yorum sahibi sorgusu prepare hatası: " . $conn->error);
    echo json_encode(['status' => 'error', 'message' => 'Yorum yetkisi kontrol edilirken bir hata oluştu.']);
    exit;
}
$stmt->bind_param("i", $comment_id);
$stmt->execute();
$comment_result = $stmt->get_result(); // Sonucu almak için get_result() kullanıyoruz
$comment = $comment_result->fetch_assoc();
$stmt->close();


// Yorum bulunamadıysa veya kullanıcı yorumun sahibi değilse
if (!$comment) {
    error_log("delete_comment.php: Yorum ID " . $comment_id . " bulunamadı veya silinmiş.");
    echo json_encode(['status' => 'error', 'message' => 'Yorum bulunamadı veya zaten silinmiş.']);
    exit;
}
if ($comment['user_id'] !== $user_id) {
    error_log("delete_comment.php: Yetkisiz işlem denemesi. Kullanıcı ID: " . $user_id . ", Yorum sahibi ID: " . $comment['user_id']);
    echo json_encode(['status' => 'error', 'message' => 'Bu yorumu silme yetkiniz yok.']);
    exit;
}


// Yorumu silindi olarak işaretle
// user_id = 0 ataması kaldırıldı, çünkü bu dış anahtar kısıtlamasını ihlal ediyor.
// Yorumun içeriği ve silinme durumu güncellenir, user_id orijinal kalır.
$sql = "UPDATE comments SET content = '[silindi]', is_deleted = 1 WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    // MySQLi için errorInfo yerine $conn->error kullanılır
    error_log("delete_comment.php: Yorum silme sorgusu prepare hatası: " . $conn->error);
    echo json_encode(['status' => 'error', 'message' => 'Yorum silinirken bir veritabanı hatası oluştu.']);
    exit;
}
$stmt->bind_param("i", $comment_id);
if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Yorum başarıyla silindi.']);
} else {
    // MySQLi için errorInfo yerine $stmt->error kullanılır
    error_log("delete_comment.php: Yorum ID " . $comment_id . " silinirken execute hatası: " . $stmt->error);
    echo json_encode(['status' => 'error', 'message' => 'Yorum silinirken bir hata oluştu.']);
}
$stmt->close(); // Statement'ı kapat
?>