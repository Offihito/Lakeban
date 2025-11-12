<?php
require_once 'logconfig.php';

// Giriş yapılmamışsa yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: logintoken.php");
    exit();
}

// Kullanıcı bilgilerini al
try {
    $stmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Kullanıcı bilgileri alınamadı: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Hoşgeldiniz</title>
</head>
<body>
    <h2>Hoşgeldiniz, <?php echo htmlspecialchars($user['username']); ?>!</h2>
    <p>E-posta: <?php echo htmlspecialchars($user['email']); ?></p>
    <p><a href="logout.php">Çıkış Yap</a></p>
</body>
</html>