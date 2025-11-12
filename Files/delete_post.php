<?php
// delete_post.php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// CSRF ve oturum kontrolü
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF token geçersiz']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Oturum açılmamış']);
    exit;
}

// Post ID kontrolü
if (!isset($_POST['post_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Post ID eksik']);
    exit;
}

$post_id = (int)$_POST['post_id'];
$user_id = $_SESSION['user_id'];

// Gönderinin sahibini ve lakealt ID'sini al
$sql = "SELECT user_id, lakealt_id FROM posts WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'SQL prepare hatası: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $post_id);
$stmt->execute();
$result = $stmt->get_result();
$post_info = $result->fetch_assoc();
$stmt->close();

if (!$post_info) {
    echo json_encode(['status' => 'error', 'message' => 'Gönderi bulunamadı']);
    exit;
}

$post_owner_id = $post_info['user_id'];
$lakealt_id = $post_info['lakealt_id'];

// Yetki kontrolü: Kullanıcı gönderinin sahibi mi, yoksa lakealt moderatörü/kurucusu mu?
$has_permission = false;

// 1. Gönderinin sahibi mi?
if ($user_id == $post_owner_id) {
    $has_permission = true;
}

// 2. Lakealt moderatörü mü?
if (!$has_permission) {
    $sql_mod = "SELECT 1 FROM lakealt_moderators WHERE lakealt_id = ? AND user_id = ?";
    $stmt_mod = $conn->prepare($sql_mod);
    if ($stmt_mod) {
        $stmt_mod->bind_param("ii", $lakealt_id, $user_id);
        $stmt_mod->execute();
        $is_moderator = $stmt_mod->get_result()->num_rows > 0;
        $stmt_mod->close();
        if ($is_moderator) {
            $has_permission = true;
        }
    }
}

// 3. Lakealt kurucusu mu?
if (!$has_permission) {
    $sql_creator = "SELECT 1 FROM lakealts WHERE id = ? AND creator_id = ?";
    $stmt_creator = $conn->prepare($sql_creator);
    if ($stmt_creator) {
        $stmt_creator->bind_param("ii", $lakealt_id, $user_id);
        $stmt_creator->execute();
        $is_creator = $stmt_creator->get_result()->num_rows > 0;
        $stmt_creator->close();
        if ($is_creator) {
            $has_permission = true;
        }
    }
}

if (!$has_permission) {
    echo json_encode(['status' => 'error', 'message' => 'Bu gönderiyi silmek için yetkiniz yok.']);
    exit;
}

// Gönderiyi ve ilişkili verileri sil
$conn->begin_transaction();
try {
    // Yorum oylarını sil (yorumlar silinmeden önce)
    $stmt = $conn->prepare("DELETE FROM comment_votes WHERE comment_id IN (SELECT id FROM comments WHERE post_id = ?)");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();

    // Yorumları sil
    $stmt = $conn->prepare("DELETE FROM comments WHERE post_id = ?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    
    // Gönderi oylarını sil
    $stmt = $conn->prepare("DELETE FROM post_votes WHERE post_id = ?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    
    // Gönderiyi sil
    $stmt = $conn->prepare("DELETE FROM posts WHERE id = ?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    
    $conn->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    $conn->rollback();
    error_log("Gönderi silme hatası: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Silme işlemi sırasında bir hata oluştu: ' . $e->getMessage()]);
}
?>