<?php
session_start();
require_once 'config.php';

// Kullanıcı giriş yapmamışsa login sayfasına yönlendir
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$message = '';

// Form gönderildiğinde işlemleri yap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hangi işlemin seçildiğini belirle (renk mi, dosya mı)
    $action = $_POST['action'] ?? null;

    if ($action === 'set_color') {
        // Renk ayarlama işlemi
        $customColor = $_POST['custom_color'] ?? '#FFFFFF';
        
        $stmt = $conn->prepare("
            UPDATE user_profiles 
            SET avatar_frame_url = NULL, 
                avatar_frame_color = ?
            WHERE user_id = ?
        ");
        $stmt->bind_param("si", $customColor, $user_id);
        if ($stmt->execute()) {
            $message = "Renk başarıyla ayarlandı!";
        } else {
            $message = "Hata: Renk ayarlanırken bir sorun oluştu.";
        }
        $stmt->close();

    } elseif ($action === 'upload_frame' && isset($_FILES['frame_file']) && $_FILES['frame_file']['error'] == 0) {
        // Dosya yükleme işlemi
        $file = $_FILES['frame_file'];
        $uploadDir = 'frames/';
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        
        // Dosya türü kontrolü
        if (in_array($file['type'], $allowedTypes)) {
            // Benzersiz bir dosya adı oluştur
            $fileName = uniqid() . '-' . basename($file['name']);
            $targetPath = $uploadDir . $fileName;

            // Dosyayı belirtilen klasöre taşı
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Veritabanını yeni dosya yolu ile güncelle
                $stmt = $conn->prepare("
                    UPDATE user_profiles 
                    SET avatar_frame_url = ?, 
                        avatar_frame_color = NULL
                    WHERE user_id = ?
                ");
                $stmt->bind_param("si", $targetPath, $user_id);
                if ($stmt->execute()) {
                    $message = "Çerçeve başarıyla yüklendi ve ayarlandı!";
                } else {
                    $message = "Hata: Veritabanı güncellenirken bir sorun oluştu.";
                }
                $stmt->close();
            } else {
                $message = "Hata: Dosya yüklenirken bir sorun oluştu.";
            }
        } else {
            $message = "Hata: Yalnızca PNG, JPG, GIF veya WEBP formatında dosyalar yükleyebilirsiniz.";
        }
    }
    
    // İşlem sonrası profil sayfasına yönlendirme (isteğe bağlı, mesaj göstermek için kaldırılabilir)
    $_SESSION['success_message'] = $message;
    header("Location: profile-page.php?username=" . $username);
    exit;
}

$frame_class = '';
if (!empty($user_data['avatar_frame_url'])) {
    $frame_class = 'frame-image';
} elseif (!empty($user_data['avatar_frame_color'])) {
    $frame_class = 'frame-color';
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Avatar Çerçevesini Düzenle</title>
    <style>
        body { font-family: sans-serif; background-color: #1a1a1a; color: #fff; padding: 20px; }
        .container { max-width: 600px; margin: auto; background-color: #2a2a2a; padding: 30px; border-radius: 8px; }
        h1 { text-align: center; color: #bb00ff; }
        .form-section { margin-bottom: 30px; padding: 20px; border: 1px solid #444; border-radius: 5px; }
        h3 { border-bottom: 2px solid #bb00ff; padding-bottom: 10px; margin-top: 0; }
        label { display: block; margin-bottom: 10px; font-weight: bold; }
        input[type="color"], input[type="file"] { margin-bottom: 15px; }
        .action-button {
            display: block; width: 100%; padding: 12px; font-size: 16px;
            background: linear-gradient(135deg, #2bff00, #bb00ff); color: white;
            border: none; border-radius: 5px; cursor: pointer; transition: all 0.3s ease;
        }
        .action-button:hover { filter: brightness(1.2); }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="avatar-container <?php echo $frame_class; ?>" style="<?php echo $frame_style; ?>">

        <h1>Avatar Çerçevesini Düzenle</h1>

        <?php if (!empty($message)): ?>
            <p style="background-color: #333; padding: 10px; border-radius: 5px; text-align: center;"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <div class="form-section">
            <h3>Özel Renk Çerçevesi</h3>
            <form action="avatar_frames.php" method="POST">
                <input type="hidden" name="action" value="set_color">
                <label for="custom-color">Bir renk seçin:</label>
                <input type="color" id="custom-color" name="custom_color" value="#FFFFFF">
                <button type="submit" class="action-button">Rengi Uygula</button>
            </form>
        </div>

        <div class="form-section">
            <h3>Çerçeve Resmi Yükle</h3>
            <form action="avatar_frames.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_frame">
                <label for="frame-file">Bir resim dosyası seçin (PNG, JPG, GIF):</label>
                <input type="file" id="frame-file" name="frame_file" accept="image/png, image/jpeg, image/gif, image/webp">
                <button type="submit" class="action-button">Resmi Yükle ve Uygula</button>
            </form>
        </div>
    </div>
</body>
</html>