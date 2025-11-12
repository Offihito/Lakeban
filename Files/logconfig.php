<?php
// Veritabanı bağlantısı
define('DB_HOST', 'localhost');
define('DB_USER', 'lakebanc_Offihito');
define('DB_PASS', 'P4QG(m2jkWXN');
define('DB_NAME', 'lakebanc_Database');

// Session ayarları
ini_set('session.gc_maxlifetime', 2592000); // 30 gün
session_set_cookie_params(2592000); // 30 gün

// Oturumu başlat
session_start();

// Veritabanına bağlan
try {
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// Hata raporlama
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>