<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['post_id']) || !isset($_POST['content']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
    exit;
}

$user_id = $_SESSION['user_id'];
$post_id = (int)$_POST['post_id'];
$content = trim($_POST['content']);

if (empty($content)) {
    echo json_encode(['success' => false, 'message' => 'Yorum boş olamaz']);
    exit;
}

try {
    $sql = "INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $post_id, $user_id, $content);
    $stmt->execute();

    $sql = "SELECT username, flair FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    echo json_encode([
        'success' => true,
        'comment' => [
            'username' => $user['username'],
            'flair' => $user['flair'],
            'content' => htmlspecialchars($content)
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Yorum gönderilemedi: ' . $e->getMessage()]);
}
$conn->close();
?>