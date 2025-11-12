<?php
session_start();
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/error.log'); // Burayı sunucunuzdaki uygun bir yolla değiştirin

// --- YENİ: WebSocket'e bildirim göndermek için fonksiyon ---
function send_websocket_notification($message_id) {
    // WebSocket sunucunuzun çalıştığı adresi ve portu ayarlayın
    $host = '127.0.0.1'; // Genellikle localhost
    $port = 8000;       // WebSocket sunucunuzun portu

    try {
        // TCP soketi üzerinden basit bir bağlantı kuruyoruz.
        // @ işareti, bağlantı hatası durumunda PHP'nin kendi hatasını basmasını engeller.
        $socket = @fsockopen($host, $port, $errno, $errstr, 1); // 1 saniye timeout

        if ($socket) {
            // Mesaj formatı: 'event_adı:veri'
            $payload = "reaction_update:" . $message_id;
            fwrite($socket, $payload);
            fclose($socket);
            error_log("WebSocket notification sent for message_id: $message_id");
        } else {
            error_log("WebSocket connection failed: $errstr ($errno)");
        }
    } catch (Exception $e) {
        error_log("WebSocket exception: " . $e->getMessage());
    }
}

// Veritabanı bağlantısı
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
    echo json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası']);
    exit;
}

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit;
}

// Gelen verileri al
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$emoji = isset($_POST['emoji']) ? $_POST['emoji'] : '';
$user_id = $_SESSION['user_id']; // Oturumdaki kullanıcı ID'si

if ($message_id <= 0 || empty($emoji) || empty($user_id)) {
    error_log("Geçersiz veri: message_id=$message_id, emoji=$emoji, user_id=$user_id");
    echo json_encode(['success' => false, 'message' => 'Geçersiz veri']);
    exit;
}

// Reaksiyonu kontrol et veya ekle
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reactions WHERE message_id = ? AND user_id = ? AND emoji = ?");
    $stmt->execute([$message_id, $user_id, $emoji]);
    $reaction_exists = $stmt->fetchColumn();

    if ($reaction_exists) {
        $stmt = $db->prepare("DELETE FROM reactions WHERE message_id = ? AND user_id = ? AND emoji = ?");
        $stmt->execute([$message_id, $user_id, $emoji]);
        
        // --- YENİ: Değişikliği herkese bildir ---
        send_websocket_notification($message_id);

        echo json_encode(['success' => true, 'action' => 'removed', 'message' => 'Reaksiyon kaldırıldı']);
    } else {
        $stmt = $db->prepare("INSERT INTO reactions (message_id, user_id, emoji) VALUES (?, ?, ?)");
        $stmt->execute([$message_id, $user_id, $emoji]);

        // --- YENİ: Değişikliği herkese bildir ---
        send_websocket_notification($message_id);

        echo json_encode(['success' => true, 'action' => 'added', 'message' => 'Reaksiyon eklendi']);
    }
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
?>