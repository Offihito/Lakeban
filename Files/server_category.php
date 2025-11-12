<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Veritabanı bağlantısı
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Veritabanı bağlantı hatası: " . $e->getMessage());
    die("Veritabanına bağlanılamıyor. Lütfen daha sonra tekrar deneyin.");
}

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Kullanıcının tema ayarlarını al
$currentTheme = 'dark';
$currentCustomColor = '#663399';
$currentSecondaryColor = '#3CB371';

try {
    $themeStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $themeStmt->execute([$_SESSION['user_id']]);
    $userTheme = $themeStmt->fetch();
    
    if ($userTheme) {
        $currentTheme = $userTheme['theme'] ?? 'dark';
        $currentCustomColor = $userTheme['custom_color'] ?? '#663399';
        $currentSecondaryColor = $userTheme['secondary_color'] ?? '#3CB371';
    }
} catch (PDOException $e) {
    error_log("Theme settings error: " . $e->getMessage());
}

// URL'den sunucu ID'sini al
if (!isset($_GET['id'])) {
    die("Sunucu ID'si eksik.");
}

$server_id = $_GET['id'];

// Kullanıcının sunucunun sahibi olup olmadığını kontrol et
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND owner_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {  
    header("Location: sayfabulunamadı");
    exit();
}

// Sunucu detaylarını al
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Form gönderimini işle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tags = $_POST['tags'] ?? '';
    
    // Etiketleri doğrula
    $tagArray = explode(',', $tags);
    $validTags = [];
    $tagCount = 0;
    
    foreach ($tagArray as $tag) {
        $cleanTag = trim(substr($tag, 0, 20)); // Maksimum 20 karakter
        if (!empty($cleanTag) && $tagCount < 5) {
            $validTags[] = $cleanTag;
            $tagCount++;
        }
    }
    
    $tags = implode(',', $validTags);

    // Sunucu etiketlerini güncelle
    $stmt = $db->prepare("UPDATE servers SET tags = ? WHERE id = ?");
    $stmt->execute([$tags, $server_id]);

    // Sunucu verilerini yenile
    $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch();
}

// Dil ayarları
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

// Dil dosyalarını yükle
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
<html lang="tr" class="<?php echo $currentTheme; ?>-theme" style="--font: 'Arial'; --monospace-font: 'Arial'; --ligatures: none; --app-height: 100vh; --custom-background-color: <?php echo $currentCustomColor; ?>; --custom-secondary-color: <?php echo $currentSecondaryColor; ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['server_settings']['title_five']; ?> - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
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
            --warning-color: #faa61a;
        }

        /* Aydınlık Tema */
        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
        }

        /* Koyu Tema (varsayılan) */
        .dark-theme {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
        }

        /* Özel Tema */
        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 40%, white);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        
        /* Koyu tema için select stilleri */
        select.form-input {
            color: var(--text-primary);
            background-color: var(--secondary-bg);
        }

        select.form-input option {
            color: var(--text-primary);
            background-color: var(--secondary-bg);
        }

        select.form-input:focus option {
            background-color: var(--primary-bg);
        }
        
        /* Discord-like sidebar */
        #movesidebar {
            position: absolute;
            height: 100vh;
            width: 20%;
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }
        
        #main-content {
            position: absolute;
            height: 100vh;
            width: 80%;
            margin-left: 20%;
            background-color: var(--primary-bg);
        }

        .nav-item {
            padding: 6px 10px;
            margin: 2px 8px;
            border-radius: 4px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            transition: all 0.1s ease;
        }

        .nav-item:hover {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .nav-item.active {
            background-color: rgba(79, 84, 92, 0.6);
            color: var(--text-primary);
        }

        /* Discord-like form elements */
        .form-section {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
        }

        .form-input {
            background-color: rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.3);
            border-radius: 3px;
            padding: 8px 10px;
            width: 100%;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:hover {
            border-color: rgba(0, 0, 0, 0.5);
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
        }

        /* Discord-like buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.1s ease;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2E8B57;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c03537;
        }

        .btn-secondary {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: rgba(79, 84, 92, 0.6);
        }

        /* Etiket stilleri */
        .tag-input-container {
            position: relative;
        }
        
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        
        .tag {
            background-color: rgba(60, 179, 113, 0.2);
            color: #3CB371;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
        }
        
        .tag .remove {
            margin-left: 5px;
            cursor: pointer;
        }
        
        .suggested-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 15px;
        }
        
        .tag-suggestion {
            background-color: rgba(79, 84, 92, 0.4);
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tag-suggestion:hover {
            background-color: rgba(79, 84, 92, 0.6);
        }
        
        @media (max-width: 768px) {
            #movesidebar{
              width: 100%;
              left: 0%;
              height: 100vh;
              z-index: 10;
            }
            #main-content {
                position: absolute;
                height: 100vh;
                left: -20%;
                width: 100%;
             }
        }
    </style>
</head>
<body class="flex h-screen">
    <!-- Yan Menü -->
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-lg"><?php echo $translations['server_settings']['server_setting']; ?></h1>
            <p class="text-xs text-gray-400 mt-1 truncate"><?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        
        <nav class="flex-1 p-2 overflow-y-auto">
            <div class="space-y-1">
                <a href="server_settings?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-cog w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['general']; ?></span>
                </a>
                <a href="server_emojis?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-smile w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['emojis']; ?></span>
                </a>
                <a href="server_stickers?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-sticky-note w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['stickers']; ?></span>
                </a>
                <a href="assign_role?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-user-tag w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['roles']; ?></span>
                </a>
                <a href="audit_log?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-history w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['audit_log']; ?></span>
                </a>
                <a href="server_url?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-link w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['server_url']; ?></span>
                </a>
                <a href="unban_users?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-shield-alt w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['moderation']; ?></span>
                </a>
                <a href="server_category?id=<?php echo $server_id; ?>" class="nav-item active">
                    <i class="fas fa-users w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['community']; ?></span>
                </a>
                <h3 class="text-xs font-bold text-gray-500 uppercase px-4 mt-4 mb-2">Bot Yönetimi</h3>
                <a href="create_bot?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-robot w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['create_bot']; ?></span>
                </a>
                <a href="manage_bots?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-cogs w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['manage_bots']; ?></span>
                </a>
            </div>
        </nav>

        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item">
                <i class="fas fa-arrow-left w-5 text-center"></i>
                <span><?php echo $translations['server_settings']['back_server']; ?></span>
            </a>
        </div>
    </div>

    <!-- Ana İçerik -->
    <div id="main-content" class="flex-1 flex flex-col overflow-hidden">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-xl font-semibold mb-6">Topluluk Ayarları</h2>
                
                <!-- Etiket Yönetimi Formu -->
                <div class="bg-secondary-bg rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold mb-4">Sunucu Etiketleri</h3>
                    <form method="POST" action="server_category?id=<?php echo $server_id; ?>">
                        <div class="form-section">
                            <label for="tags" class="form-label">Etiketler (virgülle ayırın)</label>
                            <div class="tag-input-container">
                                <input 
                                    type="text" 
                                    id="tags" 
                                    name="tags" 
                                    class="form-input w-full" 
                                    value="<?php echo htmlspecialchars($server['tags'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="örnek: oyun, sohbet, topluluk"
                                >
                                <div class="help">En fazla 5 etiket, her etiket en fazla 20 karakter</div>
                                
                                <!-- Mevcut etiketleri göster -->
                                <?php if (!empty($server['tags'])): ?>
                                <div class="tag-list">
                                    <?php 
                                    $tags = explode(',', $server['tags']);
                                    foreach ($tags as $tag): 
                                        $cleanTag = trim($tag);
                                        if (!empty($cleanTag)):
                                    ?>
                                        <div class="tag">
                                            <?php echo htmlspecialchars($cleanTag, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-section mt-6">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <span>Etiketleri Kaydet</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Önerilen Etiketler -->
                <div class="bg-secondary-bg rounded-lg p-6">
                    <h3 class="text-lg font-semibold mb-4">Popüler Etiketler</h3>
                    <div class="suggested-tags">
                        <?php
                        // Popüler etiket listesi
                        $popularTags = ['Oyun', 'Sohbet', 'Eğlence', 'Müzik', 'Sanat', 
                                        'Teknoloji', 'Eğitim', 'Yemek', 'Film', 'Spor'];
                        foreach ($popularTags as $tag): 
                        ?>
                            <div class="tag-suggestion" onclick="addTag('<?php echo $tag; ?>')">
                                <?php echo $tag; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Etiket ekleme fonksiyonu
    function addTag(tag) {
        const input = document.getElementById('tags');
        const currentValue = input.value.trim();
        const tags = currentValue ? currentValue.split(',') : [];
        
        // Etiket zaten ekli mi kontrol et
        const tagExists = tags.some(t => t.trim() === tag);
        
        // Etiket ekle (5 etiket sınırı)
        if (!tagExists && tags.length < 5) {
            tags.push(tag);
            input.value = tags.join(',');
            
            // Etiket listesini güncelle
            const tagList = document.querySelector('.tag-list');
            if (tagList) {
                const newTag = document.createElement('div');
                newTag.className = 'tag';
                newTag.textContent = tag;
                tagList.appendChild(newTag);
            }
        }
    }
    
    // Mobil kaydırma hareketi
    const movesidebar = document.getElementById("movesidebar");
    
    if (window.innerWidth <= 768) {
        let startX, endX;
        
        document.addEventListener("touchstart", (e) => {
            startX = e.touches[0].clientX;
        });
        
        document.addEventListener("touchend", (e) => {
            endX = e.changedTouches[0].clientX;
            handleSwipe();
        });
        
        function handleSwipe() {
            const deltaX = startX - endX;
            
            if (Math.abs(deltaX) < 100) return;
            
            if (deltaX > 100) {
                closeSidebar();
            } else if (deltaX < -100) {
                openSidebar();
            }
        }
        
        function openSidebar() {
            movesidebar.style.left = "0";
        }
        
        function closeSidebar() {
            movesidebar.style.left = "-100%";
        }
    }
    </script>
</body>
</html>