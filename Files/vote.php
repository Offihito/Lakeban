<?php
session_start();
require_once 'config.php';

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Oturum açmanız gerekiyor!']);
    exit;
}

// Gerekli POST verilerinin kontrolü
if (!isset($_POST['post_id']) || !isset($_POST['vote_type'])) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz istek!']);
    exit;
}

// CSRF token kontrolü (isteğe bağlı ama önerilir)
// Eğer bu kontrolü kullanıyorsanız, posts.php'den de token göndermeniz gerekir.
/*
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF doğrulama hatası!']);
    exit;
}
*/

$userId = $_SESSION['user_id'];
$postId = (int)$_POST['post_id'];
$voteType = $_POST['vote_type']; // 'upvote' veya 'downvote'

// Geçerli oy tipi kontrolü
if (!in_array($voteType, ['upvote', 'downvote'])) {
    echo json_encode(['status' => 'error', 'message' => 'Geçersiz oy tipi!']);
    exit;
}

// Mevcut oyu kontrol et
$stmt = $conn->prepare("SELECT vote_type FROM post_votes WHERE user_id = ? AND post_id = ?");
if (!$stmt) {
    error_log("Veritabanı hatası (vote.php prepare 1): " . $conn->error);
    echo json_encode(['status' => 'error', 'message' => 'Sorgu hazırlanamadı.']);
    exit;
}
$stmt->bind_param("ii", $userId, $postId);
$stmt->execute();
$result = $stmt->get_result();
$existingVote = $result->fetch_assoc();
$stmt->close();

// İşlemleri gerçekleştir
if ($existingVote) {
    if ($existingVote['vote_type'] === $voteType) {
        // Aynı oy tekrar verilirse: Oyu kaldır
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM post_votes WHERE user_id = ? AND post_id = ?");
            if (!$stmt) {
                throw new Exception("DELETE sorgusu hazırlanamadı: " . $conn->error);
            }
            $stmt->bind_param("ii", $userId, $postId);
            $stmt->execute();
            $stmt->close();
            
            $updateField = $voteType === 'upvote' ? 'upvotes' : 'downvotes';
            $updateSql = "UPDATE posts SET $updateField = $updateField - 1 WHERE id = ?";
            $stmt = $conn->prepare($updateSql);
            if (!$stmt) {
                throw new Exception("UPDATE sorgusu hazırlanamadı (oy kaldırma): " . $conn->error);
            }
            $stmt->bind_param("i", $postId);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            echo json_encode([
                'status' => 'success',
                'action' => 'removed',
                'new_score' => getNewScore($conn, $postId)
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Oy kaldırma hatası: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Oy kaldırma sırasında hata!']);
        }
    } else {
        // Farklı bir oy verilirse: Oyu değiştir
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE post_votes SET vote_type = ? WHERE user_id = ? AND post_id = ?");
            if (!$stmt) {
                throw new Exception("UPDATE sorgusu hazırlanamadı (oy değiştirme 1): " . $conn->error);
            }
            $stmt->bind_param("sii", $voteType, $userId, $postId);
            $stmt->execute();
            $stmt->close();
            
            $oldField = $voteType === 'upvote' ? 'downvotes' : 'upvotes';
            $newField = $voteType === 'upvote' ? 'upvotes' : 'downvotes';
            $updateSql = "UPDATE posts SET $oldField = $oldField - 1, $newField = $newField + 1 WHERE id = ?";
            $stmt = $conn->prepare($updateSql);
            if (!$stmt) {
                throw new Exception("UPDATE sorgusu hazırlanamadı (oy değiştirme 2): " . $conn->error);
            }
            $stmt->bind_param("i", $postId);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            echo json_encode([
                'status' => 'success',
                'action' => 'changed',
                'new_score' => getNewScore($conn, $postId)
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Oy değiştirme hatası: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Oy değiştirme sırasında hata!']);
        }
    }
} else {
    // Daha önce oy yoksa: Yeni oy ekle
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO post_votes (user_id, post_id, vote_type) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("INSERT sorgusu hazırlanamadı: " . $conn->error);
        }
        $stmt->bind_param("iis", $userId, $postId, $voteType);
        $stmt->execute();
        $stmt->close();
        
        $updateField = $voteType === 'upvote' ? 'upvotes' : 'downvotes';
        $updateSql = "UPDATE posts SET $updateField = $updateField + 1 WHERE id = ?";
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            throw new Exception("UPDATE sorgusu hazırlanamadı (yeni oy): " . $conn->error);
        }
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        echo json_encode([
            'status' => 'success',
            'action' => 'added',
            'new_score' => getNewScore($conn, $postId)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Yeni oy ekleme hatası: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Yeni oy ekleme sırasında hata!']);
    }
}

// Bir gönderinin mevcut skorunu (upvotes - downvotes) döndüren yardımcı fonksiyon
function getNewScore($conn, $postId) {
    $stmt = $conn->prepare("SELECT upvotes, downvotes FROM posts WHERE id = ?");
    if (!$stmt) {
        error_log("getNewScore sorgusu hazırlanamadı: " . $conn->error);
        return 0; // Hata durumunda varsayılan değer
    }
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? ($row['upvotes'] - $row['downvotes']) : 0;
}
?>