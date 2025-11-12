<?php
// update_user_id.php

// Hata gösterimi açık
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connection.php';

$eski_id = 1234;
$yeni_id = 1005;

echo "<pre>İşlem başlıyor...\n";

try {
    // PDO var mı?
    if (!isset($pdo) || !$pdo) {
        die("Hata: PDO bağlantısı yok!");
    }
    echo "Veritabanı bağlantısı: BAŞARILI\n";

    // Yeni ID kullanılıyor mu?
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$yeni_id]);
    if ($stmt->fetch()) {
        die("Hata: ID $yeni_id zaten başka bir kullanıcıda!");
    }
    echo "Yeni ID müsait\n";

    // Transaction
    $pdo->beginTransaction();
    echo "Transaction başladı\n";

    // Foreign key kapat
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "Foreign key kontrolleri kapatıldı\n";

    // user_profiles güncelle
    $stmt1 = $pdo->prepare("UPDATE user_profiles SET user_id = ? WHERE user_id = ?");
    $stmt1->execute([$yeni_id, $eski_id]);
    $etkilenen1 = $stmt1->rowCount();
    echo "user_profiles: $etkilenen1 satır güncellendi\n";

    // users güncelle
    $stmt2 = $pdo->prepare("UPDATE users SET id = ? WHERE id = ?");
    $stmt2->execute([$yeni_id, $eski_id]);
    $etkilenen2 = $stmt2->rowCount();
    echo "users: $etkilenen2 satır güncellendi\n";

    // Foreign key aç
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Foreign key kontrolleri açıldı\n";

    // Commit
    $pdo->commit();
    echo "\nTAMAM! ID $eski_id → $yeni_id olarak değiştirildi.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\nHATA: " . $e->getMessage() . "\n";
}
?>