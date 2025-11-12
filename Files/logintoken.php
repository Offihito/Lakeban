<?php
require_once 'logconfig.php';

// Kullanıcı zaten giriş yapmışsa yönlendir
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Kullanıcıyı veritabanında ara
    try {
        $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Giriş başarılı, session oluştur
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            // "Beni hatırla" seçeneği
            if (isset($_POST['remember'])) {
                setcookie('remember_user', $user['id'], time() + 2592000, '/');
            }
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Geçersiz kullanıcı adı veya şifre!";
        }
    } catch(PDOException $e) {
        $error = "Giriş sırasında hata oluştu: " . $e->getMessage();
    }
}

// "Beni hatırla" cookie kontrolü
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_user'])) {
    $user_id = $_COOKIE['remember_user'];
    
    try {
        $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: dashboard.php");
            exit();
        }
    } catch(PDOException $e) {
        // Cookie geçersiz, bir şey yapma
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Giriş Yap</title>
</head>
<body>
    <h2>Giriş Yap</h2>
    <?php 
    if (isset($_SESSION['message'])) {
        echo "<p style='color:green;'>".$_SESSION['message']."</p>";
        unset($_SESSION['message']);
    }
    if (isset($error)) echo "<p style='color:red;'>$error</p>"; 
    ?>
    <form method="post">
        <label>Kullanıcı Adı:</label>
        <input type="text" name="username" required><br>
        <label>Şifre:</label>
        <input type="password" name="password" required><br>
        <label>
            <input type="checkbox" name="remember"> Beni hatırla (30 gün)
        </label><br>
        <button type="submit">Giriş Yap</button>
    </form>
    <p>Hesabınız yok mu? <a href="register.php">Kayıt olun</a></p>
</body>
</html>