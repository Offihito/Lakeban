<?php
session_start();
require_once 'db_connect.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $stmt = $pdo->prepare("SELECT user_id FROM password_resets WHERE token = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$token]);
    
    if ($reset = $stmt->fetch()) {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            
            if (empty($password) || empty($confirm_password)) {
                $errors[] = "Please enter both password fields";
            } elseif ($password !== $confirm_password) {
                $errors[] = "Passwords do not match";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $reset['user_id']]);
                
                $stmt = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
                $stmt->execute([$token]);
                
                $success = "Your password has been reset successfully. You can now <a href='login.php'>login</a>.";
            }
        }
    } else {
        $errors[] = "Invalid or expired token";
    }
} else {
    $errors[] = "No token provided";
}
?>


<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
        <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
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
            background: linear-gradient(135deg, #1a1b1e, #2d2f34);
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            animation: gradientAnimation 10s ease infinite;
        }

        @keyframes gradientAnimation {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
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

        .success {
            color: var(--success-color);
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
            <h2 class="text-2xl font-bold mb-6">Reset Your Password</h2>
            <?php
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    echo "<div class='error mb-4'>$error</div>";
                }
            }
            if (isset($success)) {
                echo "<div class='success mb-4'>$success</div>";
            }
            ?>
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"])."?token=".htmlspecialchars($token); ?>">
                <div class="mb-6">
                    <label for="password" class="block text-sm font-medium mb-2">New Password</label>
                    <input type="password" name="password" id="password" class="form-input w-full" required>
                </div>
                <div class="mb-6">
                    <label for="confirm_password" class="block text-sm font-medium mb-2">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input w-full" required>
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-redo-alt"></i>
                    Reset Password
                </button>
            </form>
            <p class="mt-4 text-sm text-gray-400">
                Remembered your password? 
                <a href="login.php" class="text-accent-color hover:underline">Login here</a>
            </p>
        </div>
    </div>
</body>
</html>