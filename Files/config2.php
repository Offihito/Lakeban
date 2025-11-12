<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'lakebanc_Offihito');
define('DB_PASS', 'P4QG(m2jkWXN');
define('DB_NAME', 'lakebanc_Database');
define('PAYONEER_API_USERNAME', 'your_payoneer_api_username');
define('PAYONEER_API_PASSWORD', 'your_payoneer_api_password');
define('PAYONEER_PARTNER_ID', 'your_payoneer_partner_id');
define('PAYONEER_API_URL', 'https://api.payoneer.com/');

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection error: " . $e->getMessage());
}