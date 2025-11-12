<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

if (!isset($_SESSION['user_id']) || !isset($_POST['post_id']) || !isset($_POST['content']) || !isset($_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Eksik parametreler']);
    exit;
}

if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz CSRF token']);
    exit;
}

$post_id = (int)$_POST['post_id'];
$content = trim($_POST['content']);
$parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

// parent_id'nin geçerli bir yorum ID'si olduğunu kontrol et
if ($parent_id !== null) {
    $sql = "SELECT id FROM comments WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz parent_id']);
        exit;
    }
    $stmt->close();
}

$sql = "INSERT INTO comments (post_id, user_id, content, parent_id, created_at) VALUES (?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iisi", $post_id, $_SESSION['user_id'], $content, $parent_id);
if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Oylama başarılı!']); // Bu satırı değiştirin
} else {
    echo json_encode(['status' => 'error', 'message' => 'Yorum eklenemedi']);
}
$stmt->close();
?>