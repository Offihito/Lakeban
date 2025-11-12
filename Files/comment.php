<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Giriş yapmalısınız.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek.']);
    exit;
}

// CSRF kontrolü
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'CSRF hatası.']);
    exit;
}

$post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$content = trim($_POST['content'] ?? '');

if ($post_id <= 0 || empty($content)) {
    echo json_encode(['success' => false, 'message' => 'Eksik veya geçersiz veri.']);
    exit;
}

try {
    $sql = "INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $post_id, $_SESSION['user_id'], $content);
    $stmt->execute();
    $stmt->close();

    // Kullanıcı bilgilerini al
    $sql = "SELECT username, flair FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'comment' => [
            'id' => $conn->insert_id,
            'content' => htmlspecialchars($content),
            'username' => htmlspecialchars($user['username']),
            'flair' => htmlspecialchars($user['flair'] ?: 'Üye'),
            'created_at' => date('d.m.Y H:i')
        ]
    ]);
} catch (Exception $e) {
    error_log("Yorum ekleme hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Yorum eklenemedi.']);
}
?>