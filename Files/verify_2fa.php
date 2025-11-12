<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['two_factor_user'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['two_factor_user'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = trim($_POST['code']);
    
    try {
        // Bu sorgu zaten '*' ile tüm sütunları çektiği için sorguyu değiştirmemize gerek yok.
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND two_factor_token = ? AND token2_expiry > NOW()");
        $stmt->execute([$user_id, $code]);
        
        if ($stmt->rowCount() > 0) {
            // GÜNCELLEME 1: Kullanıcı verilerini bir değişkene alalım
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Doğrulama başarılı, oturumları ayarlayalım
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            // GÜNCELLEME 2: 'is_admin' durumunu oturuma doğru şekilde atayalım
            $_SESSION['is_admin'] = ($user['is_admin'] == 1);
            
            // Artık kullanılmayacağı için 2FA oturumunu temizle
            unset($_SESSION['two_factor_user']);
            
            // Veritabanından token'ı temizle
            $db->prepare("UPDATE users SET two_factor_token = NULL, token2_expiry = NULL WHERE id = ?")
               ->execute([$user_id]);
            
            // "Beni hatırla" seçeneği (bu kısım aynı kalıyor)
            if (isset($_POST['remember'])) {
                setcookie('remember_user', $user_id, time() + 2592000, '/');
            }
            
            // Yönlendirme
            header("Location: directmessages");
            exit();
        } else {
            $error = "Geçersiz veya süresi dolmuş kod!";
        }
    } catch(PDOException $e) {
        $error = "Doğrulama sırasında hata oluştu: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İki Aşamalı Doğrulama - LakeBan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --accent-color: #3CB371;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --danger-color: #ed4245;
            --success-color: #3ba55c;
        }

        body {
            background: 
                linear-gradient(135deg, rgba(26, 27, 30, 0.8), rgba(45, 47, 52, 0.8)),
                url('background.jpeg') center/cover no-repeat fixed;
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }

        .form-input {
            background-color: var(--secondary-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2E8B57;
            transform: translateY(-1px);
        }

        .error {
            color: var(--danger-color);
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .container {
            background-color: rgba(45, 47, 52, 0.9);
            border-radius: 0.75rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="flex min-h-screen items-center justify-center">
        <div class="container w-full max-w-md">
            <h2 class="text-2xl font-bold mb-6">İki Aşamalı Doğrulama</h2>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p class="mb-4">E-posta adresinize gönderilen 6 haneli kodu girin:</p>
            <form method="post">
                <div class="mb-6">
                    <input type="text" name="code" maxlength="6" pattern="\d{6}" 
                           class="form-input w-full text-center text-xl tracking-widest" 
                           placeholder="••••••" required autofocus>
                </div>
                <div class="mb-4">
                    <input type="checkbox" name="remember" id="remember">
                    <label for="remember" class="text-sm">Beni bu cihazda hatırla (30 gün)</label>
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-check-circle"></i>
                    Doğrula ve Giriş Yap
                </button>
            </form>
            <p class="mt-4 text-sm text-gray-400">
                Kod gelmedi mi? <a href="login.php" class="text-accent-color hover:underline">Yeniden gönder</a>
            </p>
        </div>
    </div>
</body>
</html>