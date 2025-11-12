<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açılmamış']);
    exit;
}

$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Okunmamış DM'leri sayan sorgu
    // Not: Veritabanı şemasına göre ayarlanmalı
    $stmt = $db->prepare("
        SELECT COUNT(*) as unread_count
        FROM messages1 m
        LEFT JOIN message_reads mr ON m.id = mr.message_id AND mr.user_id = ?
        WHERE m.channel_id IN (
            SELECT channel_id FROM direct_message_channels WHERE user1_id = ? OR user2_id = ?
        )
        AND mr.message_id IS NULL
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $result = $stmt->fetch();
    
    echo json_encode(['success' => true, 'unread_count' => $result['unread_count']]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>