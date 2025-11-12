<?php
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Oturum açmanız gerekiyor.']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF token geçersiz.']);
    exit;
}

$comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
$vote_type = isset($_POST['vote_type']) ? $_POST['vote_type'] : '';

if ($comment_id <= 0 || !in_array($vote_type, ['upvote', 'downvote'])) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz istek.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// İşlemi transaction içinde yap
$conn->begin_transaction();

try {
    // Mevcut oyu kontrol et
    $stmt = $conn->prepare("SELECT vote_type FROM comment_votes WHERE user_id = ? AND comment_id = ?");
    $stmt->bind_param("ii", $user_id, $comment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_vote = $result->fetch_assoc();
    $stmt->close();

    // Comment bilgilerini al (kilit için)
    $stmt = $conn->prepare("SELECT upvotes, downvotes FROM comments WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $comment_id);
    $stmt->execute();
    $comment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $current_upvotes = $comment['upvotes'];
    $current_downvotes = $comment['downvotes'];

    if ($existing_vote) {
        $existing_type = $existing_vote['vote_type'];
        
        if ($existing_type === $vote_type) {
            // Aynı oy tekrar verilirse kaldır
            $stmt = $conn->prepare("DELETE FROM comment_votes WHERE user_id = ? AND comment_id = ?");
            $stmt->bind_param("ii", $user_id, $comment_id);
            $stmt->execute();
            
            // Count güncelle
            if ($vote_type === 'upvote') {
                $current_upvotes--;
            } else {
                $current_downvotes--;
            }
        } else {
            // Farklı oy verilirse güncelle
            $stmt = $conn->prepare("UPDATE comment_votes SET vote_type = ? WHERE user_id = ? AND comment_id = ?");
            $stmt->bind_param("sii", $vote_type, $user_id, $comment_id);
            $stmt->execute();
            
            // Count güncelle
            if ($existing_type === 'upvote') {
                $current_upvotes--;
                $current_downvotes++;
            } else {
                $current_upvotes++;
                $current_downvotes--;
            }
        }
    } else {
        // Yeni oy ekle
        $stmt = $conn->prepare("INSERT INTO comment_votes (user_id, comment_id, vote_type) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $comment_id, $vote_type);
        $stmt->execute();
        
        // Count güncelle
        if ($vote_type === 'upvote') {
            $current_upvotes++;
        } else {
            $current_downvotes++;
        }
    }

    // Comment counts güncelle
    $stmt = $conn->prepare("UPDATE comments SET upvotes = ?, downvotes = ? WHERE id = ?");
    $stmt->bind_param("iii", $current_upvotes, $current_downvotes, $comment_id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    $conn->rollback();
    error_log("Comment vote error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Bir hata oluştu.']);
}