<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

// Hata raporlamayı etkinleştir
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$response = ['status' => 'error', 'message' => 'Bir hata oluştu.'];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Giriş yapmalısınız.';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $content = isset($_POST['content']) ? trim($_POST['content']) : '';
    $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    // CSRF token kontrolü
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        $response['message'] = 'CSRF hatası: Geçersiz token.';
        echo json_encode($response);
        exit;
    }

    if (empty($post_id) || empty($title) || empty($content)) {
        $response['message'] = 'Tüm alanları doldurmanız gerekmektedir.';
        echo json_encode($response);
        exit;
    }

    if (mb_strlen($title) > 255) {
        $response['message'] = 'Başlık 255 karakterden uzun olamaz.';
        echo json_encode($response);
        exit;
    }
    if (mb_strlen($content) > 10000) { // Örnek bir limit
        $response['message'] = 'İçerik 10000 karakterden uzun olamaz.';
        echo json_encode($response);
        exit;
    }

    try {
        // Gönderinin mevcut kullanıcıya ait olup olmadığını kontrol et
        $sql_check_owner = "SELECT user_id FROM posts WHERE id = ?";
        $stmt_check_owner = $conn->prepare($sql_check_owner);
        if (!$stmt_check_owner) {
            error_log("SQL prepare hatası (owner check): " . $conn->error);
            throw new Exception("Veritabanı hatası.");
        }
        $stmt_check_owner->bind_param("i", $post_id);
        $stmt_check_owner->execute();
        $result_check_owner = $stmt_check_owner->get_result()->fetch_assoc();
        $stmt_check_owner->close();

        if (!$result_check_owner || $result_check_owner['user_id'] != $user_id) {
            $response['message'] = 'Bu gönderiyi düzenleme yetkiniz yok.';
            echo json_encode($response);
            exit;
        }

        // Gönderiyi güncelle ve edited_at sütununu ayarla
        $sql_update = "UPDATE posts SET title = ?, content = ?, edited_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        if (!$stmt_update) {
            error_log("SQL prepare hatası (update): " . $conn->error);
            throw new Exception("Veritabanı hatası.");
        }
        $stmt_update->bind_param("ssi", $title, $content, $post_id);

        if ($stmt_update->execute()) {
            $response['status'] = 'success';
            $response['message'] = 'Gönderi başarıyla güncellendi.';
        } else {
            error_log("SQL execute hatası (update): " . $stmt_update->error);
            $response['message'] = 'Gönderi güncellenirken bir hata oluştu.';
        }
        $stmt_update->close();

    } catch (Exception $e) {
        error_log("Genel hata: " . $e->getMessage());
        $response['message'] = 'Bir sorun oluştu: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'Geçersiz istek metodu.';
}

echo json_encode($response);
?>