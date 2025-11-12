<?php
session_start();
require_once 'config.php';

// Varsayılan dil
$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fi', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);

    // Validate
    if (empty($name)) {
        $errors[] = $translations['create_lakealt']['error_name_required'] ?? "Lakealt adı gereklidir.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $name)) {
        $errors[] = $translations['create_lakealt']['error_name_invalid'] ?? "Lakealt adı 3-50 karakter olmalı ve sadece harf, rakam veya alt çizgi içermeli.";
    }
    if (empty($description)) {
        $errors[] = $translations['create_lakealt']['error_description_required'] ?? "Açıklama gereklidir.";
    }

    // Check if name exists
    $sql = "SELECT 1 FROM lakealts WHERE name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = $translations['create_lakealt']['error_name_taken'] ?? "Bu lakealt adı zaten kullanılıyor.";
    }
    $stmt->close();

    if (empty($errors)) {
        // Insert lakealt
        $sql = "INSERT INTO lakealts (name, description, creator_id) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $name, $description, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $lakealt_id = $conn->insert_id;

            // Add user as member
            $sql = "INSERT INTO lakealt_members (user_id, lakealt_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $_SESSION['user_id'], $lakealt_id);
            $stmt->execute();

            // Add user as moderator
            $sql = "INSERT INTO lakealt_moderators (user_id, lakealt_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $_SESSION['user_id'], $lakealt_id);
            $stmt->execute();

            $stmt->close();
            header("Location: lakealt?name=" . urlencode($name));
            exit;
        } else {
            $errors[] = $translations['create_lakealt']['error_creation_failed'] ?? "Lakealt oluşturulurken hata oluştu.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['create_lakealt']['title'] ?? 'Lakealt Oluştur - Lakeban'; ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #202020;
            --secondary-bg: #181818;
            --accent-color: #3CB371;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --border-color: #101010;
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Arial', sans-serif;
            margin: 0;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.5rem;
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .error {
            color: #ED4245;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .submit-btn {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }

        .submit-btn:hover {
            background-color: #2e8b57;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo $translations['create_lakealt']['heading'] ?? 'Lakealt Oluştur'; ?></h1>
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="name"><?php echo $translations['create_lakealt']['name_label'] ?? 'Lakealt Adı'; ?></label>
                <input type="text" id="name" name="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="description"><?php echo $translations['create_lakealt']['description_label'] ?? 'Açıklama'; ?></label>
                <textarea id="description" name="description" required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            </div>
            <button type="submit" class="submit-btn"><?php echo $translations['create_lakealt']['submit_button'] ?? 'Oluştur'; ?></button>
        </form>
    </div>
</body>
</html>