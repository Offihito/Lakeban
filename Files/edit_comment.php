<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

if (!isset($_SESSION['user_id']) || !isset($_POST['comment_id']) || !isset($_POST['content']) || !isset($_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Eksik parametreler']);
    exit;
}

if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz CSRF token']);
    exit;
}

$comment_id = (int)$_POST['comment_id'];
$content = trim($_POST['content']);

// Yorumun sahibini ve silinip silinmediğini kontrol et
$sql = "SELECT user_id, is_deleted FROM comments WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $comment_id);
$stmt->execute();
$comment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$comment || $comment['user_id'] !== $_SESSION['user_id'] || $comment['is_deleted'] == 1) {
    echo json_encode(['status' => 'error', 'message' => 'Yetkisiz işlem veya yorum silinmiş']);
    exit;
}

// Yorumu güncelle - edited_at alanı eklendi
$sql = "UPDATE comments SET content = ?, edited_at = NOW() WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $content, $comment_id);
if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Yorum güncellenemedi']);
}
$stmt->close();
?>