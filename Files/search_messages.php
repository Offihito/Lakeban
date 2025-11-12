<?php
// search_messages.php

session_start();
header('Content-Type: application/json');

// --- Güvenlik ve Session Kontrolü ---
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim.']);
    exit;
}

// --- Gerekli Parametreler ---
if (!isset($_GET['server_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sunucu ID eksik.']);
    exit;
}

$server_id = $_GET['server_id'];
$user_id = $_SESSION['user_id'];

// --- Veritabanı Bağlantısı ---
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Veritabanı bağlantı hatası.']);
    exit;
}

try {
    // --- Dinamik Sorgu Oluşturma ---
    $sql = "
        SELECT
            m.id,
            m.channel_id,
            m.message_text,
            m.created_at,
            UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
            u.username,
            up.avatar_url,
            c.name AS channel_name
        FROM messages1 AS m
        JOIN users AS u ON m.sender_id = u.id
        LEFT JOIN user_profiles AS up ON u.id = up.user_id
        JOIN channels AS c ON m.channel_id = c.id
    ";

    $whereClauses = ["c.server_id = :server_id"];
    $params = [':server_id' => $server_id];

    // Metin araması
    if (!empty($_GET['query'])) {
        $whereClauses[] = "m.message_text LIKE :query";
        $params[':query'] = "%" . $_GET['query'] . "%";
    }

    // Kullanıcıya göre filtreleme
    if (!empty($_GET['from_user_id'])) {
        $whereClauses[] = "m.sender_id = :from_user_id";
        $params[':from_user_id'] = $_GET['from_user_id'];
    }

    // Kanala göre filtreleme
    if (!empty($_GET['in_channel_id'])) {
        $whereClauses[] = "m.channel_id = :in_channel_id";
        $params[':in_channel_id'] = $_GET['in_channel_id'];
    }

    // Belirtilen tarihten sonra
    if (!empty($_GET['after_date'])) {
        $whereClauses[] = "m.created_at >= :after_date";
        $params[':after_date'] = $_GET['after_date'];
    }

    // Belirtilen tarihten önce
    if (!empty($_GET['before_date'])) {
        // Tarihe gün sonunu ekleyerek o günü de dahil et
        $whereClauses[] = "m.created_at <= :before_date";
        $params[':before_date'] = $_GET['before_date'] . ' 23:59:59';
    }

    // Eğer WHERE koşulları varsa sorguya ekle
    if (count($whereClauses) > 0) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }
    
    // Sıralama ve Limit
    $sql .= " ORDER BY m.created_at DESC LIMIT 50";

    // Sorguyu hazırla ve çalıştır
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $messages = $stmt->fetchAll();

    echo json_encode(['success' => true, 'messages' => $messages]);

} catch (PDOException $e) {
    error_log("Search query error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Arama sırasında bir hata oluştu.']);
}
?>