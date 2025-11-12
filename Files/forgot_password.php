<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $errors[] = "Please enter your email address";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($user = $stmt->fetch()) {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token) VALUES (?, ?)");
            $stmt->execute([$user['id'], $token]);
            
            // Send email with reset link using mail() function
            $resetLink = "https://lakeban.com/reset_password.php?token=" . $token;
            $to = $email;
            $subject = "Lakeban Support - Password Reset Request";
            
            // Improved email content
            $message = "
            ====================================
            Lakeban Support - Password Reset Request
            ====================================

            Hello,

            You have requested to reset your password. Please click the link below to proceed:

            " . $resetLink . "

            If you did not request this, please ignore this email.

            Best regards,
            Lakeban Support Team
            ";
            
            // Improved email headers
            $headers = "From: Lakeban Support <lakebansupport@lakeban.com>\r\n";
            $headers .= "Reply-To: lakebansupport@lakeban.com\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "X-Priority: 3\r\n";
            $headers .= "X-MSMail-Priority: Normal\r\n";
            $headers .= "Return-Path: lakebansupport@lakeban.com\r\n";
            
            // Send email
            if (mail($to, $subject, $message, $headers)) {
                $success = "A password reset link has been sent to your email address.";
            } else {
                $errors[] = "Failed to send email. Please try again later.";
            }
        } else {
            $errors[] = "No user found with that email address";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
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

        a {
            color: var(--accent-color);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
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
                    echo "<div class='error'>$error</div>";
                }
            }
            if (isset($success)) {
                echo "<div class='success'>$success</div>";
            }
            ?>
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="mb-6">
                    <label for="email" class="block text-sm font-medium mb-2">Email</label>
                    <input type="email" name="email" id="email" class="form-input w-full" required>
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-paper-plane"></i>
                    Send Reset Link
                </button>
            </form>
            <p class="mt-4 text-sm text-gray-400">Remembered your password? <a href="login.php" class="text-accent-color hover:underline">Login here</a></p>
        </div>
    </div>
</body>
</html>