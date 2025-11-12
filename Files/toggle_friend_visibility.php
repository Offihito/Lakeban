<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Giriş yapmanız gerekiyor']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$friendId = $data['friend_id'] ?? null;
$userId = $_SESSION['user_id'];

if (!$friendId) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz arkadaş ID']);
    exit;
}

try {
    // Arkadaşın zaten gizli olup olmadığını kontrol et
    $stmt = $db->prepare("SELECT COUNT(*) FROM hidden_friends WHERE user_id = ? AND friend_id = ?");
    $stmt->execute([$userId, $friendId]);
    $isHidden = $stmt->fetchColumn() > 0;

    if ($isHidden) {
        // Görünür yap
        $stmt = $db->prepare("DELETE FROM hidden_friends WHERE user_id = ? AND friend_id = ?");
        $stmt->execute([$userId, $friendId]);
        $message = 'Arkadaş görünür yapıldı';
    } else {
        // Gizle
        $stmt = $db->prepare("INSERT INTO hidden_friends (user_id, friend_id) VALUES (?, ?)");
        $stmt->execute([$userId, $friendId]);
        $message = 'Arkadaş gizlendi';
    }

    echo json_encode(['success' => true, 'message' => $message]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>