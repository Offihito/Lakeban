<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hataların çıktıya basılmasını engeller
ini_set('log_errors', 1);    // Hataların log dosyasına yazılmasını sağlar
ini_set('error_log', '/path/to/your/php-error.log'); // Hata log dosyanızın yolu

session_start();
require_once 'config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Bir hata oluştu.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reporter_user_id = $_SESSION['user_id'] ?? null;
    
    // Güvenli veri temizleme
    $reported_username = filter_input(INPUT_POST, 'reported_username', FILTER_SANITIZE_STRING);
    $report_reason = filter_input(INPUT_POST, 'report_reason', FILTER_SANITIZE_STRING);
    $report_description = filter_input(INPUT_POST, 'report_description', FILTER_SANITIZE_STRING);

    if (empty($reported_username) || empty($report_reason)) {
        $response['message'] = 'Kullanıcı adı ve şikayet nedeni boş bırakılamaz.';
        echo json_encode($response);
        exit;
    }

    // Şikayet edilen kullanıcıyı bul
    $stmt_user = $conn->prepare("SELECT id FROM users WHERE username = ?");
    if (!$stmt_user) {
        $response['message'] = 'Kullanıcı sorgusu hatası: ' . $conn->error;
        echo json_encode($response);
        exit;
    }
    
    $stmt_user->bind_param("s", $reported_username);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    
    if ($result_user->num_rows === 0) {
        $response['message'] = 'Şikayet edilen kullanıcı bulunamadı.';
        echo json_encode($response);
        exit;
    }

    $reported_user = $result_user->fetch_assoc();
    $reported_user_id = $reported_user['id'];
    $stmt_user->close();

    // Aynı şikayetin tekrarını önleme
    $check_stmt = $conn->prepare("SELECT id FROM user_reports 
                                WHERE reported_user_id = ? 
                                AND reporter_user_id = ? 
                                AND report_reason = ?
                                AND created_at > NOW() - INTERVAL 1 DAY");
    $check_stmt->bind_param("iis", $reported_user_id, $reporter_user_id, $report_reason);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        $response['message'] = 'Aynı nedenle zaten son 24 saat içinde şikayet ettiniz.';
        echo json_encode($response);
        exit;
    }

    // Şikayeti kaydet
    $stmt = $conn->prepare("INSERT INTO user_reports 
                          (reported_user_id, reporter_user_id, report_reason, report_description) 
                          VALUES (?, ?, ?, ?)");
    
    if ($reporter_user_id) {
        $stmt->bind_param("iiss", $reported_user_id, $reporter_user_id, $report_reason, $report_description);
    } else {
        // Anonim şikayet
        $stmt->bind_param("iiss", $reported_user_id, null, $report_reason, $report_description);
    }

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Şikayet başarıyla gönderildi. Yöneticiler inceleyecektir.';
    } else {
        $response['message'] = 'Şikayet kaydedilemedi: ' . $stmt->error;
    }
    
    $stmt->close();
} else {
    $response['message'] = 'Geçersiz istek metodu.';
}

echo json_encode($response);
?>