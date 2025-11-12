<?php
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

// Hata raporlamayı etkinleştir
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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


// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '');
// Lakealt ID kontrolü
if (!isset($_GET['lakealt_id']) || !is_numeric($_GET['lakealt_id'])) {
    header("Location: posts.php");
    exit;
}

$lakealt_id = (int)$_GET['lakealt_id'];

// Lakealt bilgilerini çek
$sql = "SELECT id, name, theme_color FROM lakealts WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['create_post']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.");
}
$stmt->bind_param("i", $lakealt_id);
$stmt->execute();
$lakealt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lakealt) {
    die($translations['create_post']['lakealt_not_found'] ?? "Bu lakealt bulunamadı.");
}

// Hata mesajları
$errors = [];
$success = false;

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = $translations['create_post']['invalid_csrf'] ?? "Geçersiz CSRF token.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $lakealt_id_form = (int)($_POST['lakealt_id'] ?? 0); // Formdan gelen lakealt_id

        $category_id = 1; // Varsayılan kategori ID (örneğin, "Genel")

        // Doğrulama
        if (empty($title)) {
            $errors[] = $translations['create_post']['title_required'] ?? "Başlık boş olamaz.";
        }
        if (strlen($title) > 255) {
            $errors[] = $translations['create_post']['title_too_long'] ?? "Başlık 255 karakterden uzun olamaz.";
        }
        if ($lakealt_id_form !== $lakealt['id']) { // URL'den gelen ID ile formdan gelen ID'yi karşılaştır
            $errors[] = $translations['create_post']['invalid_lakealt_id'] ?? "Geçersiz lakealt ID'si.";
        }

        // Çoklu medya yükleme
        $media_paths = [];
        if (!empty($_FILES['media']['name'][0])) { // 'name' dizisinin boş olup olmadığını kontrol et
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg'];
            $upload_dir = 'uploads/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Maksimum 10 dosya kontrolü JavaScript'te de yapılıyor, ama sunucu tarafı doğrulama önemli
            $file_count = count($_FILES['media']['name']);
            if ($file_count > 10) {
                $errors[] = $translations['create_post']['too_many_files'] ?? "En fazla 10 dosya yükleyebilirsiniz.";
            } else {
                // Her dosya için işlem yap
                for ($i = 0; $i < $file_count; $i++) {
                    // Eğer dosya boşsa (örneğin sadece 1 dosya seçilip diğerleri boş bırakılırsa) atla
                    if ($_FILES['media']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    if ($_FILES['media']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['media']['name'][$i];
                        $file_tmp = $_FILES['media']['tmp_name'][$i];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        
                        if (!in_array($file_ext, $allowed_types)) {
                            $errors[] = sprintf($translations['create_post']['unsupported_file_type'] ?? "%s - Desteklenmeyen dosya türü. İzin verilenler: %s", $file_name, implode(', ', $allowed_types));
                            continue;
                        }

                        // Dosya boyutu kontrolü (max 10MB)
                        $max_size = 10 * 1024 * 1024; // 10MB
                        if ($_FILES['media']['size'][$i] > $max_size) {
                            $errors[] = sprintf($translations['create_post']['file_too_large'] ?? "%s - Dosya boyutu çok büyük. Maksimum 10MB olabilir.", $file_name);
                            continue;
                        }

                        // Benzersiz dosya adı oluştur
                        $unique_name = uniqid() . '_' . $i . '.' . $file_ext;
                        $file_path = $upload_dir . $unique_name;

                        if (move_uploaded_file($file_tmp, $file_path)) {
                            $media_paths[] = '/' . $file_path;
                        } else {
                            $errors[] = sprintf($translations['create_post']['file_upload_failed'] ?? "%s - Dosya yüklenemedi.", $file_name);
                        }
                    } else {
                        $errors[] = sprintf($translations['create_post']['upload_error'] ?? "%s - Yükleme hatası: %s", $file_name, $_FILES['media']['error'][$i]);
                    }
                }
            }
        }

        // Hata yoksa kaydedelim
        if (empty($errors)) {
            // Medya yollarını JSON formatında kaydet
            $media_paths_json = !empty($media_paths) ? json_encode($media_paths) : null;
            
            $sql = "INSERT INTO posts (user_id, lakealt_id, category_id, title, content, media_path, created_at, upvotes, downvotes)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("SQL prepare hatası: " . $conn->error);
                $errors[] = $translations['create_post']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
            } else {
                $stmt->bind_param("iiisss", $_SESSION['user_id'], $lakealt_id, $category_id, $title, $content, $media_paths_json);
                if ($stmt->execute()) {
                    $post_id = $conn->insert_id;
                    $success = true;
                    header("Location: post.php?id=" . $post_id);
                    exit;
                } else {
                    error_log("SQL execute hatası: " . $stmt->error);
                    $errors[] = $translations['create_post']['post_creation_failed'] ?? "Gönderi oluşturulamadı. Lütfen tekrar deneyin.";
                }
                $stmt->close();
            }
        }
    }
}

// Profil resmi çek
$profilePicture = null;
$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe2e65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";
try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("SET NAMES utf8mb4");

    $stmt = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
    if ($stmt) {
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && isset($result['avatar_url'])) {
            $profilePicture = $result['avatar_url'];
        }
    }
} catch (PDOException $e) {
    error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
}
$profilePicture = $profilePicture ?: $defaultProfilePicture;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sprintf($translations['create_post']['title'] ?? 'Gönderi Oluştur - l/%s - Lakeban', htmlspecialchars($lakealt['name'])); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-bg: #202020;
            --secondary-bg: #181818;
            --accent-color: <?php echo htmlspecialchars($lakealt['theme_color'] ?? '#3CB371'); ?>;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --border-color: #101010;
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Arial', sans-serif;
            margin: 0;
        }

        .header {
            background-color: var(--secondary-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-nav {
            display: flex;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            height: 48px;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--text-primary);
        }

        .header-logo img {
            width: 32px;
            height: 32px;
        }

        .header-logo span {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .header-search {
            flex: 1;
            margin: 0 1rem;
        }

        .search-form {
            display: flex;
            align-items: center;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
        }

        .search-input {
            background: none;
            border: none;
            color: var(--text-primary);
            width: 100%;
            outline: none;
            font-size: 0.9rem;
        }

        .search-input::placeholder {
            color: var(--text-secondary);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-btn {
            background: none;
            border: none;
            color: var(--text-primary);
            padding: 0.5rem;
            cursor: pointer;
            font-size: 1.25rem;
        }

        .header-btn:hover {
            color: var(--accent-color);
        }

        .profile-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            padding: 0;
        }

        .profile-btn img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .hamburger-btn {
            display: none;
        }

        .container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
            gap: 1rem;
        }

        .content {
            flex: 1;
            max-width: 800px;
        }

        .form-container {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
        }

        .form-container h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 0.5rem;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group input[type="file"] {
            padding: 0.25rem;
        }

        .submit-btn {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .submit-btn:hover {
            background-color: #2e8b57;
        }

        .error {
            color: #ED4245;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        .success {
            color: var(--accent-color);
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        /* Çoklu dosya yükleme stilleri */
        .file-upload-container {
            margin-bottom: 1rem;
        }

        .file-upload-label {
            display: block;
            padding: 1rem;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .file-upload-label:hover {
            border-color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
        }

        .file-upload-label i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--accent-color);
        }

        .file-upload-label span {
            display: block;
            font-size: 0.9rem;
        }

        .file-upload-input {
            display: none;
        }

        .file-preview-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .file-preview {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 4px;
            overflow: hidden;
            flex-shrink: 0; /* Önizlemelerin küçülmesini engeller */
        }

        .file-preview img,
        .file-preview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .file-preview .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: rgba(237, 66, 69, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.7rem;
            line-height: 1; /* Metin dikey hizalamasını düzeltir */
            padding: 0;
        }

        .file-info {
            font-size: 0.8rem;
            margin-top: 0.5rem;
            color: var(--text-secondary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            width: 100%; /* Dosya adının taşmasını engeller */
            text-align: center;
        }

        @media (max-width: 768px) {
            .hamburger-btn {
                display: block;
            }
            .header-search,
            .header-actions .header-btn:not(.hamburger-btn) {
                display: none;
            }
            .container {
                flex-direction: column;
            }
            .content {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="content">
            <div class="form-container">
                <h2><?php echo sprintf($translations['create_post']['heading'] ?? 'Gönderi Oluştur - l/%s', htmlspecialchars($lakealt['name'])); ?></h2>
                <?php if (!empty($errors)): ?>
                    <div class="error">
                        <?php foreach ($errors as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form action="create_post.php?lakealt_id=<?php echo $lakealt_id; ?>" method="POST" enctype="multipart/form-data" id="post-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="lakealt_id" value="<?php echo $lakealt_id; ?>">
                    <div class="form-group">
                        <label for="title"><?php echo $translations['create_post']['title_label'] ?? 'Başlık'; ?></label>
                        <input type="text" id="title" name="title" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="content"><?php echo $translations['create_post']['content_label'] ?? 'İçerik (Opsiyonel)'; ?></label>
                        <textarea id="content" name="content"><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
                    </div>
                    <div class="form-group">
                        <label><?php echo $translations['create_post']['media_label'] ?? 'Medya (Opsiyonel - En fazla 10 dosya)'; ?></label>
                        <div class="file-upload-container">
                            <label for="media" class="file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span><?php echo $translations['create_post']['upload_prompt'] ?? 'Dosyaları sürükleyip bırakın veya tıklayarak seçin'; ?></span>
                                <span class="file-info"><?php echo $translations['create_post']['file_info'] ?? 'Desteklenen formatlar: JPG, PNG, GIF, WEBP, MP4, WEBM, OGG (Max 10MB/dosya, Toplam 50MB)'; ?></span>
                            </label>
                            <input type="file" id="media" name="media[]" class="file-upload-input" multiple accept="image/*,video/*">
                        </div>
                        <div class="file-preview-container" id="file-preview-container"></div>
                    </div>
                    <button type="submit" class="submit-btn"><?php echo $translations['create_post']['submit_button'] ?? 'Paylaş'; ?></button>
                </form>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Seçilen dosyaları saklamak için bir global dizi
            let selectedFiles = [];

            // Dosya önizleme işlevselliği
            function updateFilePreviews() {
                const previewContainer = $('#file-preview-container');
                previewContainer.empty(); // Mevcut önizlemeleri temizle

                selectedFiles.forEach((file, index) => {
                    const fileType = file.type.split('/')[0];
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        const previewDiv = $('<div class="file-preview"></div>');
                        // Her önizleme öğesi için benzersiz bir veri dizini ekliyoruz
                        const removeBtn = $('<button class="remove-btn" data-index="' + index + '">&times;</button>');
                        
                        if (fileType === 'image') {
                            previewDiv.append($('<img src="' + e.target.result + '" alt="' + file.name + '">'));
                        } else if (fileType === 'video') {
                            previewDiv.append($('<video controls><source src="' + e.target.result + '" type="' + file.type + '"></video>'));
                        } else {
                            // Desteklenmeyen veya bilinmeyen dosya türleri için genel simge
                            previewDiv.append($('<div style="background:#333;height:100px;width:100px;display:flex;align-items:center;justify-content:center;color:#fff;"><i class="fas fa-file" style="font-size:2rem;"></i></div>'));
                        }
                        
                        previewDiv.append(removeBtn);

                        let fileSize = file.size;
                        let sizeText = '';
                        if (fileSize < 1024) {
                            sizeText = fileSize + ' B';
                        } else if (fileSize < 1024 * 1024) {
                            sizeText = (fileSize / 1024).toFixed(1) + ' KB';
                        } else {
                            sizeText = (fileSize / (1024 * 1024)).toFixed(1) + ' MB';
                        }
                        
                        previewDiv.append($('<div class="file-info">' + file.name + ' (' + sizeText + ')</div>'));
                        previewContainer.append(previewDiv);
                    };
                    reader.readAsDataURL(file);
                });
            }

            // Dosya seçildiğinde
            $('#media').on('change', function() {
                const newFiles = Array.from(this.files); // Yeni seçilen dosyalar
                const currentFileNames = new Set(selectedFiles.map(file => file.name)); // Mevcut dosya adları

                // Yeni dosyaları selectedFiles'a ekle (kopya veya sınır kontrolü yaparak)
                for (const file of newFiles) {
                    if (!currentFileNames.has(file.name)) { // Dosya zaten eklenmemişse
                        if (selectedFiles.length < 10) { // Toplam dosya sayısı 10'dan azsa
                            selectedFiles.push(file);
                        } else {
                            alert('<?php echo $translations['create_post']['js_too_many_files'] ?? "En fazla 10 dosya yükleyebilirsiniz. "; ?>' + file.name + '" eklenemedi.');
                            break; // Limite ulaşıldı, döngüden çık
                        }
                    } else {
                        alert('"' + file.name + '<?php echo $translations['create_post']['js_file_already_selected'] ?? " dosyası zaten seçildi."; ?>');
                    }
                }

                // input elementinin dosyalarını DataTransfer ile güncelle
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(file => dataTransfer.items.add(file));
                this.files = dataTransfer.files; // input elementini güncelledik

                updateFilePreviews(); // Önizlemeleri güncelle
            });
            
            // Dosya kaldırma butonu
            $(document).on('click', '.remove-btn', function() {
                const indexToRemove = $(this).data('index');
                
                selectedFiles.splice(indexToRemove, 1); // Diziden dosyayı kaldır

                // input elementinin dosyalarını DataTransfer ile yeniden oluştur
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(file => dataTransfer.items.add(file));
                $('#media')[0].files = dataTransfer.files;

                updateFilePreviews(); // Önizlemeleri güncelle
            });

            // Sürükle bırak desteği
            const uploadLabel = $('.file-upload-label')[0];
            
            uploadLabel.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadLabel.style.borderColor = 'var(--accent-color)';
                uploadLabel.style.backgroundColor = 'rgba(60, 179, 113, 0.1)';
            });
            
            uploadLabel.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadLabel.style.borderColor = 'var(--border-color)';
                uploadLabel.style.backgroundColor = '';
            });
            
            uploadLabel.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadLabel.style.borderColor = 'var(--border-color)';
                uploadLabel.style.backgroundColor = '';
                
                if (e.dataTransfer.files.length) {
                    const droppedFiles = Array.from(e.dataTransfer.files);
                    const currentFileNames = new Set(selectedFiles.map(file => file.name));

                    for (const file of droppedFiles) {
                        if (!currentFileNames.has(file.name)) {
                            if (selectedFiles.length < 10) {
                                selectedFiles.push(file);
                            } else {
                                alert('<?php echo $translations['create_post']['js_too_many_files'] ?? "En fazla 10 dosya yükleyebilirsiniz. "; ?>' + file.name + '" eklenemedi.');
                                break;
                            }
                        } else {
                            alert('"' + file.name + '<?php echo $translations['create_post']['js_file_already_selected'] ?? " dosyası zaten seçildi."; ?>');
                        }
                    }

                    // input elementinin dosyalarını DataTransfer ile güncelle
                    const dataTransfer = new DataTransfer();
                    selectedFiles.forEach(file => dataTransfer.items.add(file));
                    $('#media')[0].files = dataTransfer.files;

                    updateFilePreviews(); // Önizlemeleri güncelle
                }
            });
            
            // Form gönderiminde dosya boyutu kontrolü
            $('#post-form').on('submit', function() {
                let totalSize = 0;
                const maxTotalSize = 50 * 1024 * 1024; // 50MB toplam boyut
                
                for (let i = 0; i < selectedFiles.length; i++) {
                    totalSize += selectedFiles[i].size;
                    
                    // Tek dosya boyutu kontrolü (10MB)
                    if (selectedFiles[i].size > 10 * 1024 * 1024) {
                        alert(selectedFiles[i].name + '<?php echo $translations['create_post']['js_file_too_large'] ?? " dosyası çok büyük. Maksimum dosya boyutu 10MB olabilir."; ?>');
                        return false;
                    }
                }
                
                // Toplam boyut kontrolü (50MB)
                if (totalSize > maxTotalSize) {
                    alert('<?php echo $translations['create_post']['js_total_size_exceeded'] ?? "Toplam dosya boyutu 50MB\'ı geçemez. Lütfen daha küçük dosyalar seçin."; ?>');
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>