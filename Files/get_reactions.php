<?php
session_start();
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $db->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
    echo json_encode(['error' => 'Veritabanı bağlantı hatası']);
    exit;
}

// Mesaj ID'lerini al (tek bir ID veya virgülle ayrılmış ID'ler)
$message_ids = isset($_GET['message_ids']) ? $_GET['message_ids'] : (isset($_GET['message_id']) ? $_GET['message_id'] : '');

if (empty($message_ids)) {
    error_log("Geçersiz veya eksik message_ids");
    echo json_encode(['error' => 'Geçersiz veya eksik mesaj ID']);
    exit;
}

// Virgülle ayrılmış ID'leri diziye çevir
$message_id_array = array_filter(array_map('intval', explode(',', $message_ids)));
if (empty($message_id_array)) {
    error_log("Geçersiz message_ids formatı: $message_ids");
    echo json_encode(['error' => 'Geçersiz mesaj ID formatı']);
    exit;
}

try {
    // IN operatörü için placeholder'lar oluştur
    $placeholders = implode(',', array_fill(0, count($message_id_array), '?'));
    $stmt = $db->prepare("
        SELECT message_id, emoji, COUNT(*) as count, GROUP_CONCAT(user_id) as user_ids 
        FROM reactions 
        WHERE message_id IN ($placeholders) 
        GROUP BY message_id, emoji
    ");
    $stmt->execute($message_id_array);
    $reactions = $stmt->fetchAll();

    // Reaksiyonları mesaj ID'sine göre grupla
    $grouped_reactions = [];
    foreach ($reactions as $reaction) {
        $message_id = $reaction['message_id'];
        if (!isset($grouped_reactions[$message_id])) {
            $grouped_reactions[$message_id] = [];
        }
        $grouped_reactions[$message_id][] = [
            'emoji' => $reaction['emoji'],
            'count' => (int)$reaction['count'],
            'user_ids' => $reaction['user_ids'] ?? ''
        ];
    }

    // Tüm mesaj ID'leri için reaksiyonları döndür, reaksiyon yoksa boş dizi
    $response = [];
    foreach ($message_id_array as $message_id) {
        $response[$message_id] = $grouped_reactions[$message_id] ?? [];
    }

    // Eğer tek bir message_id gelirse, doğrudan diziyi döndür
    if (count($message_id_array) === 1 && isset($_GET['message_id'])) {
        $response = $response[$message_id_array[0]] ?? [];
    }

    error_log("get_reactions.php: message_ids=$message_ids, reactions=" . json_encode($response));
    echo json_encode($response);
} catch (Exception $e) {
    error_log("Reaksiyon yükleme hatası: message_ids=$message_ids, hata=" . $e->getMessage());
    echo json_encode(['error' => 'Reaksiyon yükleme hatası: ' . $e->getMessage()]);
}
exit;
?>