<?php
require_once 'logconfig.php';

// Kullanıcı zaten giriş yapmışsa yönlendir
if (isset($_SESSION['user_id'])) {
    header("Location: directmessages");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Kullanıcıyı veritabanında ara
    try {
        // GÜNCELLEME 1: SQL sorgusuna 'is_admin' alanı eklendi
        $stmt = $db->prepare("SELECT id, username, password, email, two_factor_enabled, is_admin FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // 2FA etkinse doğrulama kodu gönder
            if ($user['two_factor_enabled']) {
                $token = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // Token'ı veritabanına kaydet
                $stmt = $db->prepare("UPDATE users SET two_factor_token = ?, token2_expiry = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?");
                $stmt->execute([$token, $user['id']]);
                
                // E-posta gönder (Bu kısım aynı kalıyor)
                $to = $user['email'];
                $subject = "Lakeban - İki Aşamalı Doğrulama Kodu";
                $message = "
                ====================================
                Lakeban - İki Aşamalı Doğrulama Kodu
                ====================================

                Merhaba,

                Giriş işleminiz için doğrulama kodunuz: $token

                Bu kod 5 dakika geçerlidir.

                Eğer bu işlemi siz yapmadıysanız, lütfen bu e-postayı dikkate almayın.

                Saygılarımızla,
                Lakeban Ekibi
                ";
                
                $headers = "From: Lakeban Support <lakebansupport@lakeban.com>\r\n";
                $headers .= "Reply-To: lakebansupport@lakeban.com\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
                
                if (mail($to, $subject, $message, $headers)) {
                    $_SESSION['two_factor_user'] = $user['id'];
                    header("Location: verify_2fa.php");
                    exit();
                } else {
                    $error = "Doğrulama kodu gönderilemedi. Lütfen tekrar deneyin.";
                }
            } 
            // 2FA etkin değilse direkt giriş yap
            else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                
                // GÜNCELLEME 2: 'is_admin' durumu oturuma doğru şekilde atanıyor
                $_SESSION['is_admin'] = ($user['is_admin'] == 1);
                
                // "Beni hatırla" seçeneği
                if (isset($_POST['remember'])) {
                    setcookie('remember_user', $user['id'], time() + 2592000, '/');
                }
                
                header("Location: directmessages");
                exit();
            }
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
        // GÜNCELLEME 3: SQL sorgusuna 'is_admin' alanı eklendi
        $stmt = $db->prepare("SELECT id, username, two_factor_enabled, is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // 2FA etkin olsa bile direkt giriş yap
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            // GÜNCELLEME 4: 'is_admin' durumu oturuma doğru şekilde atanıyor
            $_SESSION['is_admin'] = ($user['is_admin'] == 1);

            header("Location: directmessages");
            exit();
        }
    } catch(PDOException $e) {
        error_log("Cookie kontrolü hatası: " . $e->getMessage()); // Hata günlüğe kaydedilir
        // Cookie geçersiz, bir şey yapma
    }
}

// Set default language
$default_lang = 'tr';

// Get browser language
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'f覺', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Check language selection
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

// Load language file
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['login']['title'] ?? 'Giriş Yap'; ?> - LakeBan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: hsl(220, 15%, 8%);
            --secondary-bg: hsl(220, 12%, 14%);
            --accent-color: hsl(140, 68%, 48%);
            --accent-color-light: hsl(140, 70%, 58%);
            --text-primary: hsl(210, 25%, 98%);
            --text-secondary: hsl(210, 10%, 65%);
            --danger-color: hsl(0, 70%, 55%);
            --success-color: hsl(140, 65%, 45%);
            --border-color: rgba(255, 255, 255, 0.08);
            --input-focus-shadow: hsla(140, 70%, 50%, 0.4);
            --button-hover-bg: hsl(140, 70%, 35%);
            --container-shadow: rgba(0, 0, 0, 0.9);
            --glass-blur: 45px;
            --glass-brightness: 1.1;
            --discord-gray: hsl(220, 10%, 10%);
            --discord-light-gray: hsl(220, 12%, 16%);
            --discord-button-gray: hsl(220, 10%, 25%);
            --discord-button-hover-gray: hsl(220, 10%, 35%);
            --google-blue: #4285F4;
            --google-blue-hover: #357AE8;
        }

        body {
            background:
                linear-gradient(135deg, var(--primary-bg) 0%, var(--discord-gray) 100%),
                linear-gradient(45deg, rgba(76, 175, 80, 0.04) 0%, rgba(66, 133, 244, 0.04) 100%),
                url('LakebanAssets/background.jpeg') center/cover no-repeat fixed;
            background-blend-mode: multiply, overlay, normal;
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            -webkit-tap-highlight-color: transparent;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            animation: backgroundFadeIn 1.2s ease-out forwards;
        }

        @keyframes backgroundFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .form-input {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 0.8rem 1rem;
            color: var(--text-primary);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            width: 100%;
            font-size: 0.9rem;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.4), 0 1px 2px rgba(0,0,0,0.1);
        }

        .form-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.7;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 3px var(--input-focus-shadow),
                                    inset 0 1px 4px rgba(0,0,0,0.5),
                                    0 1px 6px rgba(0,0,0,0.2);
            background-color: hsl(220, 18%, 10%);
            transform: translateY(-1px) scale(1.005);
        }

        .btn {
            padding: 0.75rem 1.4rem;
            border-radius: 0.75rem;
            font-weight: 700;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            cursor: pointer;
            border: none;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
            z-index: 1;
            transform: translateZ(0);
        }

        .btn-primary {
            background: linear-gradient(145deg, var(--accent-color), var(--button-hover-bg));
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 85, 0.3),
                                    inset 0 1px 0 rgba(255,255,255,0.1);
        }

        .btn-primary:hover {
            background: linear-gradient(145deg, var(--button-hover-bg), var(--accent-color));
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(76, 175, 85, 0.4),
                                    inset 0 1px 0 rgba(255,255,255,0.15);
        }

        .btn-google {
            background: white;
            color: black;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2),
                                    inset 0 1px 0 rgba(255,255,255,0.1);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .btn-google:hover {
            background: #f0f0f0;
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(0, 0, 0, 0.3),
                                    inset 0 1px 0 rgba(255,255,255,0.15);
        }

        .btn-google .google-icon {
            width: 1.2em;
            height: 1.2em;
            vertical-align: middle;
        }

        .btn:after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 6px;
            height: 6px;
            background: rgba(0, 0, 0, 0.1);
            opacity: 0;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: all 0.5s ease-out;
            z-index: 0;
        }

        .btn:active:after {
            width: 200%;
            height: 200%;
            opacity: 1;
            transition: 0s;
        }

        .error-message {
            color: var(--danger-color);
            margin-top: 0.7rem;
            margin-bottom: 1.6rem;
            font-size: 0.9rem;
            text-align: center;
            font-weight: 700;
            animation: fadeIn 0.5s ease-out;
        }

        a {
            color: var(--accent-color);
            text-decoration: none;
            transition: color 0.2s ease, transform 0.1s ease;
        }

        a:hover {
            text-decoration: underline;
            color: var(--accent-color-light);
            transform: translateY(-0.5px);
        }

        .login-container {
            display: flex;
            background-color: rgba(30, 32, 36, 0.85);
            border-radius: 1rem;
            box-shadow: 0 15px 40px var(--container-shadow), inset 0 1px 0 rgba(255,255,255,0.06);
            backdrop-filter: blur(var(--glass-blur)) brightness(var(--glass-brightness));
            -webkit-backdrop-filter: blur(var(--glass-blur)) brightness(var(--glass-brightness));
            border: 1px solid var(--border-color);
            max-width: 850px;
            width: 100%;
            position: relative;
            z-index: 10;
            animation: slideInUp 1s cubic-bezier(0.23, 1, 0.32, 1) forwards;
            overflow: hidden;
            min-height: 480px;
        }

        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(60px) scale(0.92); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .login-form-side {
            padding: 2.5rem 2.8rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            text-align: left;
            position: relative;
            z-index: 2;
        }

        .login-form-side:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 15% 25%, rgba(76, 175, 80, 0.02) 1px, transparent 1px),
                radial-gradient(circle at 85% 75%, rgba(76, 175, 80, 0.02) 1px, transparent 1px);
            background-size: 35px 35px;
            opacity: 0.7;
            z-index: -1;
        }

        .qr-code-side {
            background-color: rgba(20, 22, 25, 0.98);
            padding: 2.5rem 2.2rem;
            flex: 0 0 320px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-left: 1px solid rgba(255, 255, 255, 0.07);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: background-color 0.3s ease;
        }

        .qr-code-side:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 10% 10%, rgba(76, 175, 80, 0.03) 1px, transparent 1px),
                radial-gradient(circle at 90% 90%, rgba(76, 175, 80, 0.03) 1px, transparent 1px);
            background-size: 30px 30px;
            opacity: 0.5;
            z-index: -1;
        }

        .qr-code-box {
            background-color: var(--secondary-bg);
            padding: 1.2rem;
            border-radius: 0.75rem;
            box-shadow: 0 8px 20px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.04);
            margin-bottom: 1.4rem;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .qr-code-box img {
            width: 170px;
            height: 170px;
            display: block;
            margin: 0 auto;
            border-radius: 0.3rem;
        }

        .h2-discord {
            font-size: 1.9rem;
            font-weight: 800;
            margin-bottom: 0.6rem;
            line-height: 1.2;
            text-shadow: 0 2px 5px rgba(0,0,0,0.25);
        }

        .qr-text {
            font-size: 0.88rem;
            color: var(--text-secondary);
            margin-bottom: 1.2rem;
            line-height: 1.5;
        }

        .social-login-separator {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.0rem 0;
            color: var(--text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.6em;
            font-weight: 700;
        }

        .social-login-separator::before,
        .social-login-separator::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin: 0 1rem;
        }

        @media (max-width: 990px) {
            .login-container {
                flex-direction: column;
                max-width: 480px;
                min-height: auto;
                border-radius: 1rem;
            }
            .qr-code-side {
                flex: none;
                width: 100%;
                border-left: none;
                border-top: 1px solid rgba(255, 255, 255, 0.07);
                padding: 2.2rem;
                border-bottom-left-radius: 1rem;
                border-bottom-right-radius: 1rem;
                border-top-left-radius: 0;
                border-top-right-radius: 0;
            }
            .login-form-side {
                padding: 2.2rem;
                border-bottom-left-radius: 0;
                border-bottom-right-radius: 0;
                align-items: center;
                text-align: center;
            }
            .qr-code-box img {
                width: 140px;
                height: 140px;
            }
        }

        @media (max-width: 550px) {
            body {
                padding: 1rem;
            }
            .login-container {
                padding: 0;
                border-radius: 0.8rem;
            }
            .login-form-side, .qr-code-side {
                padding: 1.8rem;
            }
            .h2-discord {
                font-size: 1.7rem;
                margin-bottom: 0.4rem;
            }
            .btn {
                font-size: 0.8rem;
                padding: 0.6rem 1rem;
                gap: 0.4rem;
            }
            .text-sm {
                font-size: 0.8rem;
            }
            .social-login-separator {
                margin: 1.2rem 0;
                font-size: 0.7rem;
            }
            .qr-code-box img {
                width: 110px;
                height: 110px;
            }
        }

        .php-error {
            background-color: rgba(239, 83, 80, 0.2);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
            padding: 0.9rem 1.2rem;
            border-radius: 0.7rem;
            margin-bottom: 1.8rem;
            text-align: center;
            font-weight: 700;
            font-size: 0.9rem;
            animation: shake 0.6s cubic-bezier(0.4, 0, 0.6, 1);
            box-shadow: 0 4px 15px rgba(239, 83, 80, 0.25);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }

        .input-group label {
            transition: all 0.2s ease;
            transform-origin: left;
        }

        .input-group:focus-within label {
            color: var(--accent-color-light);
            transform: translateY(-6px) scale(0.9);
            letter-spacing: 0.04em;
        }

        .form-checkbox {
            appearance: none;
            -webkit-appearance: none;
            width: 1.2rem;
            height: 1.2rem;
            border: 1px solid var(--border-color);
            background-color: var(--secondary-bg);
            border-radius: 0.3rem;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease-in-out;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .form-checkbox:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px var(--input-focus-shadow);
        }

        .form-checkbox:checked:after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: 0.75rem;
            line-height: 1;
        }

        .form-checkbox:focus {
            outline: none;
            box-shadow: 0 0 0 4px var(--input-focus-shadow);
        }
    </style>
</head>
<body>
    <div class="particles" id="particles-container">
    <?php 
    // Create 50 particles
    for($i=0; $i<50; $i++) { 
        $size = rand(5, 20);
        $left = rand(0, 95);
        $delay = rand(0, 15);
        echo "<div class='particle' style='
            width:{$size}px;
            height:{$size}px;
            left:{$left}vw;
            animation-delay:-{$delay}s;
            top:".rand(100,150)."%;
        '></div>";
    }
    ?>
    </div>

    <div class="login-container">
        <div class="login-form-side">
            <h2 class="h2-discord text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-green-600 mb-2">
                <?php echo $translations['login']['welcome'] ?? "LakeBan'e Hoş Geldiniz!"; ?>
            </h2>
            <p class="text-sm text-gray-400 mb-6"><?php echo $translations['login']['welcome_message'] ?? "Sizi tekrar görmek ne güzel!"; ?></p>

            <?php if (isset($error)): ?>
                <div class="php-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="w-full">
                <div class="mb-4 input-group">
                    <label for="username" class="block text-sm font-medium mb-2 text-gray-300">
                        <?php echo $translations['login']['username'] ?? "Kullanıcı Adı veya E-posta"; ?>
                    </label>
                    <input type="text" name="username" id="username" class="form-input"
                           placeholder="<?php echo $translations['login']['username_placeholder'] ?? 'Kullanıcı adınızı veya e-postanızı girin'; ?>"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required autocomplete="username">
                </div>
                <div class="mb-4 input-group">
                    <label for="password" class="block text-sm font-medium mb-2 text-gray-300">
                        <?php echo $translations['login']['password'] ?? "Şifre"; ?>
                    </label>
                    <input type="password" name="password" id="password" class="form-input"
                           placeholder="<?php echo $translations['login']['password_placeholder'] ?? 'Şifrenizi girin'; ?>" required autocomplete="current-password">
                </div>

                <div class="mb-5 flex items-center justify-between w-full">
                    <div class="flex items-center">
                        <input type="checkbox" name="remember" id="remember" class="form-checkbox">
                        <label for="remember" class="ml-2 block text-sm text-gray-400 cursor-pointer select-none">
                            <?php echo $translations['login']['remember_me'] ?? "Beni hatırla (30 gün)"; ?>
                        </label>
                    </div>
                    <a href="forgot_password.php" class="text-xs font-medium hover:text-accent-color-light">
                        <?php echo $translations['login']['forgot_password'] ?? "Şifremi unuttum?"; ?>
                    </a>
                </div>

                <button type="submit" class="btn btn-primary w-full mt-1">
                    <?php echo $translations['login']['submit'] ?? "Giriş Yap"; ?>
                </button>
            </form>

            <div class="social-login-separator"><?php echo $translations['login']['or'] ?? "VEYA"; ?></div>

            <button type="button" class="btn btn-google w-full">
                <img src="LakebanAssets/g_logo.png" alt="Google Icon" class="google-icon"> 
                <?php echo $translations['login']['google_login'] ?? "Google ile Giriş Yap"; ?>
            </button>

            <p class="mt-6 text-sm text-gray-400">
                <?php echo $translations['login']['no_account'] ?? "Hesabın yok mu?"; ?>
                <a href="register.php" class="font-medium hover:text-accent-color-light">
                    <?php echo $translations['login']['register_here'] ?? "Buradan kayıt ol."; ?>
                </a>
            </p>
        </div>

        <div class="qr-code-side">
            <h3 class="h2-discord text-primary mb-2"><?php echo $translations['login']['qr_title'] ?? "QR Koduyla Giriş Yap"; ?></h3>
            <p class="qr-text text-center mb-4">
                <?php echo $translations['login']['qr_message'] ?? "Telefonunuzdaki LakeBan uygulamasını kullanarak QR kodunu tarayın."; ?>
            </p>
            <div class="qr-code-box">
                <img src="LakebanAssets/qr.png" alt="QR Code" id="qr-code-image">
            </div>
            <p class="mt-3 text-sm text-gray-400">
                <?php echo $translations['login']['no_app'] ?? "Uygulama yok mu?"; ?>
                <a href="download_app.php" class="font-medium hover:text-accent-color-light">
                    <?php echo $translations['login']['app_coming_soon'] ?? "Yakında geliyor."; ?>
                </a>
            </p>
        </div>
    </div>
</body>
</html>