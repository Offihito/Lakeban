
<?php
$host = 'localhost'; // Your cPanel MySQL hostname
$dbname = 'lakebanc_Database'; // Replace with your database name
$username = 'lakebanc_Offihito'; // Replace with your cPanel MySQL username
$password = 'P4QG(m2jkWXN'; // Replace with your cPanel MySQL password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$sessionLifetime = 2592000; // 30 gün

// Özel oturum dizini
$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}
ini_set('session.save_path', $sessionPath);

// Oturum ayarları
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'], // Gerekirse '.example.com' kullanın
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Oturum çöp toplayıcı ayarları
ini_set('session.gc_maxlifetime', $sessionLifetime);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

// Oturum çerezini yenile
if (isset($_SESSION['user_id'])) {
    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => time() + $sessionLifetime,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
    $_SESSION['last_activity'] = time(); // Son etkinliği güncelle
}
?>