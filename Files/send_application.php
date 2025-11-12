<?php
// Başvuru formunu işleyen PHP dosyası

// Oturumu başlat
session_start();

// Veritabanı bağlantısını dahil et
require_once 'database/db_connection.php';

// CORS için gerekli başlıklar
header('Content-Type: text/plain; charset=UTF-8');
header('Access-Control-Allow-Origin: *'); // Gerekirse CORS kısıtlamalarını ayarlayın

// Hata ayıklama için geçici olarak hataları göster
ini_set('display_errors', 0); // Hataları tarayıcıda göstermemesi için 0, ancak loglara yazılır
error_reporting(E_ALL);

// Form verilerini al
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$github_username = isset($_POST['github_username']) ? trim($_POST['github_username']) : '';
$dev_type = isset($_POST['dev_type']) ? trim($_POST['dev_type']) : '';
$languages = isset($_POST['languages']) ? trim($_POST['languages']) : '';
$projects = isset($_POST['projects']) ? trim($_POST['projects']) : '';

// Girdi doğrulama
if (empty($name) || empty($email) || empty($dev_type) || empty($languages)) {
    http_response_code(400);
    echo 'Lütfen tüm zorunlu alanları doldurun.';
    exit;
}

// E-posta formatını kontrol et
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo 'Geçerli bir e-posta adresi girin.';
    exit;
}

// Veritabanına ekleme
try {
    // SQL sorgusu
    $sql = "INSERT INTO applications (name, email, github_username, dev_type, languages, projects) VALUES (:name, :email, :github_username, :dev_type, :languages, :projects)";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Sorgu hazırlanırken hata oluştu.');
    }
    
    // Parametreleri bağla
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':github_username', $github_username, PDO::PARAM_STR);
    $stmt->bindParam(':dev_type', $dev_type, PDO::PARAM_STR);
    $stmt->bindParam(':languages', $languages, PDO::PARAM_STR);
    $stmt->bindParam(':projects', $projects, PDO::PARAM_STR);
    
    // Sorguyu çalıştır
    if ($stmt->execute()) {
        http_response_code(200);
        echo 'Başvurunuz başarıyla gönderildi! En kısa sürede sizinle iletişime geçeceğiz.';
    } else {
        throw new Exception('Başvuru kaydedilirken hata oluştu.');
    }
    
    $stmt = null; // Statement'ı kapat
} catch (Exception $e) {
    http_response_code(500);
    echo 'Hata: ' . $e->getMessage();
}

// Veritabanı bağlantısını kapat
$db = null;
?>