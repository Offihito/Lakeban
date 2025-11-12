<?php
require_once 'logconfig.php';

// Oturum kimliğini yenile (güvenlik için)
session_regenerate_id(true);

// Session'ı temizle
$_SESSION = array();

// Session cookie'yi sil
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// "Beni hatırla" cookie'sini sil
if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, '/');
}

// Session'ı tamamen yok et
session_destroy();
session_write_close(); // Oturum verilerinin yazıldığından emin ol

// Tarayıcı önbelleğini devre dışı bırak
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Giriş sayfasına yönlendir
header("Location: index");
exit();
?>