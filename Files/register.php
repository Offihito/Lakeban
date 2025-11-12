<?php
session_start();
require_once 'db_connect.php';

// Set language
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    // Default to browser language or Turkish
    $default_lang = 'tr';
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        $supported_languages = ['tr', 'en', 'fr', 'de', 'ru'];
        if (in_array($browser_lang, $supported_languages)) {
            $default_lang = $browser_lang;
        }
    }
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

// Load translations
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    $errors = [];
    
    if (empty($username)) {
        $errors[] = $translations['register']['errors']['username_required'];
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $errors[] = $translations['register']['errors']['username_length'];
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = $translations['register']['errors']['username_chars'];
    } elseif (strpos($username, '_') === 0) {
        $errors[] = $translations['register']['errors']['username_start'];
    }
    
    if (empty($email)) {
        $errors[] = $translations['register']['errors']['email_required'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = $translations['register']['errors']['email_invalid'];
    }
    
    if (empty($password)) {
        $errors[] = $translations['register']['errors']['password_required'];
    } elseif (strlen($password) < 6) {
        $errors[] = $translations['register']['errors']['password_length'];
    }
    
    if ($password !== $confirm_password) {
        $errors[] = $translations['register']['errors']['password_mismatch'];
    }
    
    // Check if username or email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->rowCount() > 0) {
        $errors[] = $translations['register']['errors']['user_exists'];
    }
    
    if (!isset($_POST['terms'])) {
        $errors[] = $translations['register']['errors']['terms_required'];
    }
    
if (empty($errors)) {
    try {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Insert into users table with status and original_status
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, status, original_status) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $username,
            $email,
            $hashed_password,
            'offline', // status
            'online'  // original_status
        ]);
        
        // Get the last inserted user ID
        $user_id = $pdo->lastInsertId();
        
        // Insert into user_profiles table (unchanged from original)
        $stmt = $pdo->prepare("
            INSERT INTO user_profiles (
                user_id, bio, avatar_url, background_url, profile_theme, mood_status, 
                mood_updated_at, theme_color, layout_preference, profile_music_enabled
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            NULL, // bio
            NULL, // avatar_url
            NULL, // background_url
            'default', // profile_theme
            NULL, // mood_status
            '2024-11-30 03:01:00', // mood_updated_at
            'default', // theme_color
            'grid', // layout_preference
            0 // profile_music_enabled
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION['success_message'] = $translations['register']['success'];
        header("Location: login");
        exit();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $errors[] = "Registration failed. Please try again later.";
    }
}
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['register']['title']; ?> - LakeBan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: hsl(220, 15%, 6%);
            --secondary-bg: hsl(220, 12%, 12%);
            --accent-color: hsl(140, 65%, 45%);
            --accent-color-light: hsl(140, 70%, 55%);
            --text-primary: hsl(210, 25%, 98%);
            --text-secondary: hsl(210, 10%, 60%);
            --danger-color: hsl(0, 65%, 50%);
            --success-color: hsl(140, 60%, 40%);
            --border-color: rgba(255, 255, 255, 0.07);
            --input-focus-shadow: hsla(140, 70%, 50%, 0.38);
            --button-hover-bg: hsl(140, 65%, 32%);
            --container-shadow: rgba(0, 0, 0, 0.95);
            --glass-blur: 50px;
            --glass-brightness: 1.05;
            --discord-gray: hsl(220, 10%, 8%);
            --discord-light-gray: hsl(220, 12%, 14%);
            --input-shadow-hover: rgba(76, 175, 80, 0.2);
            --button-shadow-hover: rgba(76, 175, 80, 0.5);
            --glow-color: hsla(140, 70%, 50%, 0.7);
        }

        body {
            background:
                linear-gradient(135deg, var(--primary-bg) 0%, var(--discord-gray) 100%),
                linear-gradient(45deg, rgba(76, 175, 80, 0.03) 0%, rgba(66, 133, 244, 0.03) 100%),
                url('background.jpeg') center/cover no-repeat fixed;
            background-blend-mode: multiply, overlay, normal;
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-tap-highlight-color: transparent;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            animation: backgroundFadeIn 1.5s ease-out forwards;
        }

        @keyframes backgroundFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .form-input {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.8rem;
            padding: 0.9rem 1.1rem;
            color: var(--text-primary);
            transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
            width: 100%;
            font-size: 0.92rem;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.45), 0 1px 3px rgba(0,0,0,0.15);
            -webkit-appearance: none;
        }

        .form-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.75;
            transition: opacity 0.3s ease;
        }

        .form-input:focus::placeholder {
            opacity: 0.4;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 4px var(--input-focus-shadow),
                        inset 0 1px 5px rgba(0,0,0,0.55),
                        0 1px 12px var(--input-shadow-hover);
            background-color: var(--primary-bg);
            transform: translateY(-2px) scale(1.005);
        }
        
        .btn {
            padding: 0.85rem 1.6rem;
            border-radius: 0.8rem;
            font-weight: 700;
            transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
            cursor: pointer;
            border: none;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            font-size: 0.92rem;
            position: relative;
            overflow: hidden;
            z-index: 1;
            transform: translateZ(0);
        }

        .btn-primary {
            background: linear-gradient(145deg, var(--accent-color), var(--button-hover-bg));
            color: white;
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.35),
                        inset 0 1.5px 0 rgba(255,255,255,0.12);
        }

        .btn-primary:hover {
            background: linear-gradient(145deg, var(--button-hover-bg), var(--accent-color));
            transform: translateY(-4px) scale(1.01);
            box-shadow: 0 12px 28px var(--button-shadow-hover),
                        inset 0 1.5px 0 rgba(255,255,255,0.25);
        }

        .btn-google {
            background: white;
            color: black;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15),
                        inset 0 1.5px 0 rgba(0, 0, 0, 0.05);
        }

        .btn-google:hover {
            background: #f0f0f0;
            transform: translateY(-4px) scale(1.01);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.2),
                        inset 0 1.5px 0 rgba(0, 0, 0, 0.08);
        }

        .btn-google img {
            width: 1.25rem;
            height: 1.25rem;
            transition: transform 0.3s ease;
        }

        .btn-google:hover img {
            transform: scale(1.1);
        }

        .btn:after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 8px;
            height: 8px;
            background: rgba(255, 255, 255, 0.7);
            opacity: 0;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: all 0.6s ease-out;
            z-index: 0;
            filter: blur(4px);
        }

        .btn:active:after {
            width: 200%;
            height: 200%;
            opacity: 1;
            transition: 0s;
            background: rgba(255, 255, 255, 0.8);
        }

        .static-error {
            color: var(--danger-color);
            margin-top: 0.8rem;
            margin-bottom: 1.8rem;
            font-size: 0.95rem;
            text-align: left;
            font-weight: 700;
            animation: fadeIn 0.6s ease-out;
            background-color: rgba(239, 83, 80, 0.25);
            border: 1px solid var(--danger-color);
            padding: 1rem 1.4rem;
            border-radius: 0.75rem;
            box-shadow: 0 5px 18px rgba(239, 83, 80, 0.3);
            position: relative;
            overflow: hidden;
        }

        .static-error:before {
            content: '🚨';
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
            opacity: 0.7;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-7px); }
            40%, 80% { transform: translateX(7px); }
        }

        a {
            color: var(--accent-color);
            text-decoration: none;
            transition: color 0.3s ease, transform 0.2s ease;
        }

        a:hover {
            text-decoration: underline;
            color: var(--accent-color-light);
            transform: translateY(-1.5px);
        }

        .register-container {
            display: flex;
            background-color: rgba(30, 32, 36, 0.88);
            border-radius: 1.2rem;
            box-shadow: 0 20px 50px var(--container-shadow), inset 0 1px 0 rgba(255,255,255,0.08);
            backdrop-filter: blur(var(--glass-blur)) brightness(var(--glass-brightness));
            -webkit-backdrop-filter: blur(var(--glass-blur)) brightness(var(--glass-brightness));
            border: 1px solid var(--border-color);
            max-width: 500px;
            width: 100%;
            position: relative;
            z-index: 10;
            animation: slideInUp 1.1s cubic-bezier(0.23, 1, 0.32, 1) forwards;
            overflow: hidden;
            padding: 2.5rem 2rem;
            flex-direction: column;
            align-items: flex-start;
            text-align: left;
            min-height: 580px;
            justify-content: center;
        }

        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(100px) scale(0.9); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .register-container:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 15% 25%, rgba(76, 175, 80, 0.05) 1px, transparent 1px),
                radial-gradient(circle at 85% 75%, rgba(76, 175, 80, 0.05) 1px, transparent 1px);
            background-size: 45px 45px;
            opacity: 0.9;
            z-index: -1;
        }

        .h2-register {
            font-size: 2.4rem;
            font-weight: 800;
            margin-bottom: 0.8rem;
            line-height: 1.2;
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            background-image: linear-gradient(to right, hsl(140, 65%, 45%), hsl(140, 70%, 55%));
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(76, 175, 80, 0.12);
            border-radius: 50%;
            animation: float linear infinite;
            opacity: 0;
            filter: blur(3px);
            will-change: transform, opacity;
            box-shadow: 0 0 10px rgba(76, 175, 80, 0.2);
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) translateX(0vw) scale(0.6);
                opacity: 0;
            }
            10% {
                opacity: 0.25;
            }
            90% {
                opacity: 0.25;
            }
            100% {
                transform: translateY(-30vh) translateX(var(--random-x-end)) scale(var(--random-scale-end));
                opacity: 0;
            }
        }

        .input-group label {
            transition: all 0.3s ease;
            transform-origin: left;
            position: absolute;
            left: 1.1rem;
            top: 1rem;
            font-size: 0.92rem;
            color: var(--text-secondary);
            pointer-events: none;
        }

        .input-group:focus-within label,
        .input-group .form-input:not(:placeholder-shown) + label {
            color: var(--accent-color-light);
            transform: translateY(-1.8rem) scale(0.8);
            letter-spacing: 0.03em;
            background-color: var(--discord-gray);
            padding: 0 0.4rem;
            border-radius: 0.2rem;
            z-index: 1;
        }

        .form-group-compact .input-group label {
            top: 0.8rem;
        }
        .form-group-compact .input-group:focus-within label,
        .form-group-compact .input-group .form-input:not(:placeholder-shown) + label {
            transform: translateY(-1.5rem) scale(0.8);
        }

        .form-checkbox {
            appearance: none;
            -webkit-appearance: none;
            width: 1.4rem;
            height: 1.4rem;
            border: 1px solid var(--border-color);
            background-color: var(--secondary-bg);
            border-radius: 0.4rem;
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
            box-shadow: 0 0 0 3px var(--input-focus-shadow), 0 0 8px rgba(76, 175, 80, 0.4);
        }

        .form-checkbox:checked:after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: 0.9rem;
            line-height: 1;
            animation: checkboxCheck 0.3s ease-out forwards;
        }

        @keyframes checkboxCheck {
            from { transform: scale(0.5); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .form-checkbox:focus {
            outline: none;
            box-shadow: 0 0 0 4px var(--input-focus-shadow), 0 0 10px rgba(76, 175, 80, 0.5);
        }

        .form-group-compact {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group-compact .form-input {
            padding: 1.2rem 1.1rem 0.6rem 1.1rem;
            font-size: 0.92rem;
        }

        .form-group-compact label {
            margin-bottom: 0;
        }

        .social-login-separator {
            margin-top: 2rem;
            margin-bottom: 2rem;
            position: relative;
            width: 100%;
            text-align: center; 
            color: var(--text-secondary);
        }

        .social-login-separator::before,
        .social-login-separator::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 35%;
            height: 1px;
            background-color: var(--border-color);
            transform: translateY(-50%);
        }

        .social-login-separator::before {
            left: 0;
        }

        .social-login-separator::after {
            right: 0;
        }

        @media (max-width: 680px) {
            .register-container {
                padding: 1.8rem 1.2rem;
                max-width: 95%;
                min-height: auto;
            }
            .h2-register {
                font-size: 2rem;
            }
            .btn {
                font-size: 0.85rem;
                padding: 0.75rem 1.2rem;
            }
            .text-sm {
                font-size: 0.8rem;
            }
            .form-group-compact .form-input {
                font-size: 0.88rem;
            }
            .form-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
            }
            .static-error {
                padding: 0.8rem 1.2rem;
                font-size: 0.85rem;
            }
            .static-error:before {
                left: 0.8rem;
            }
            .input-group label {
                font-size: 0.85rem;
                left: 0.8rem;
            }
            .input-group:focus-within label,
            .input-group .form-input:not(:placeholder-shown) + label {
                transform: translateY(-1.5rem) scale(0.75);
                padding: 0 0.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles-container"></div>

    <div class="flex min-h-screen items-center justify-center">
        <div class="register-container">
            <h2 class="h2-register mb-4">
                <?php echo $translations['register']['title']; ?>
            </h2>
            <p class="text-sm text-gray-400 mb-6"><?php echo $translations['register']['subtitle']; ?></p>

            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="static-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="w-full">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 form-grid">
                    <div class="mb-4 input-group form-group-compact">
                        <input type="text" name="username" id="username" class="form-input w-full"
                               placeholder=" " value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required autocomplete="username">
                        <label for="username" class="block font-medium text-gray-300">
                            <?php echo $translations['register']['username']; ?>
                        </label>
                    </div>

                    <div class="mb-4 input-group form-group-compact">
                        <input type="password" name="password" id="password" class="form-input w-full"
                               placeholder=" " required autocomplete="new-password">
                        <label for="password" class="block font-medium text-gray-300">
                            <?php echo $translations['register']['password']; ?>
                        </label>
                    </div>

                    <div class="mb-4 input-group form-group-compact">
                        <input type="email" name="email" id="email" class="form-input w-full"
                               placeholder=" " value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required autocomplete="email">
                        <label for="email" class="block font-medium text-gray-300">
                            <?php echo $translations['register']['email']; ?>
                        </label>
                    </div>

                    <div class="mb-5 input-group form-group-compact">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input w-full"
                               placeholder=" " required autocomplete="new-password">
                        <label for="confirm_password" class="block font-medium text-gray-300">
                            <?php echo $translations['register']['confirm_password']; ?>
                        </label>
                    </div>
                </div>

                <div class="mb-6 flex items-start text-left">
                    <label class="flex items-start text-sm text-gray-400 cursor-pointer select-none">
                        <input type="checkbox" name="terms" required class="form-checkbox mt-0">
                        <span class="ml-2">
                            <a href="terms" class="font-medium hover:text-accent-color-light"><?php echo $translations['register']['terms_link']; ?></a> 
                            <?php echo $translations['register']['terms']; ?> 
                            <a href="privacy" class="font-medium hover:text-accent-color-light"><?php echo $translations['register']['privacy_link']; ?></a>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-user-plus"></i>
                    <?php echo $translations['register']['submit']; ?>
                </button>
            </form>

            <div class="social-login-separator text-gray-400 text-xs uppercase font-bold tracking-wider my-5"><?php echo $translations['register']['or']; ?></div>
            <button type="button" class="btn btn-google w-full">
                <img src="LakebanAssets/g_logo.png" alt="Google Icon" class="inline-block mr-2"> <?php echo $translations['register']['google_signup']; ?>
            </button>

            <p class="mt-5 text-sm text-gray-400">
                <?php echo $translations['register']['already_have_account']; ?>
                <a href="login" class="font-medium hover:text-accent-color-light">
                    <?php echo $translations['register']['login_here']; ?>
                </a>
            </p>
        </div>
    </div>

    <script>
        function changeLanguage(lang) {
            window.location.href = '?lang=' + lang;
        }
    </script>
</body>
</html>