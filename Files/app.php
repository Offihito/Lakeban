<?php
session_start();
require 'db_connection.php'; // Veritabanı bağlantınızı ekleyin

// Geçici olarak kullanıcı oturum bilgileri (Normalde giriş işleminden gelir)
$_SESSION['user_id'] = 1; 
$_SESSION['username'] = 'kullanici_adi';

// Dil ve Tema ayarlarını veritabanından çekme
$lang = $_SESSION['lang'] ?? 'tr';
$translations = json_decode(file_get_contents(__DIR__ . '/languages/' . $lang . '.json'), true) ?? [];

$stmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_theme = $stmt->fetch(PDO::FETCH_ASSOC);

$currentTheme = $user_theme['theme'] ?? 'dark';
$currentCustomColor = $user_theme['custom_color'] ?? '#663399';
$currentSecondaryColor = $user_theme['secondary_color'] ?? '#3CB371';
?>
<!DOCTYPE html>
<html lang="tr" class="<?php echo htmlspecialchars($currentTheme); ?>-theme" style="--custom-background-color: <?php echo htmlspecialchars($currentCustomColor); ?>; --custom-secondary-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;">
<head>
    <meta charset="UTF-8">
    <title>Lakeban - Ayarlar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <style>
        /* BİLDİRİMSES.PHP'DEN GELEN TÜM STİLLER BURAYA TAŞINDI */
        :root, .root {
            --hover: #3CB371;
            --gradient: #423d3c;
            --scrollback: #0d3b22;
            --error: #ed5151;
            --font-size: 16px;
            --accent-color: #3CB371;
            --custom-background-color: <?php echo htmlspecialchars($currentCustomColor); ?>;
            --custom-secondary-color: <?php echo htmlspecialchars($currentSecondaryColor); ?>;
        }

        /* === AYDINLIK TEMA === */
        .light-theme { background-color: #F2F3F5; color: #2E3338; }
        .light-theme .sidebar, .light-theme .content-container { background-color: #FFFFFF; }
        .light-theme .sidebar a { color: #4F5660; }
        .light-theme .sidebar a:hover, .light-theme .sidebar a.active { background-color: #e3e5e8; color: #060607; }
        .light-theme .content-container h1 { color: #060607; }
        .light-theme .category, .light-theme .sound-info .description { color: #4F5660; }
        .light-theme .sound-option { background-color: #F8F9FA; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        .light-theme .sound-option:hover { background-color: #e3e5e8; }
        .light-theme .preview-btn { background-color: #D1D5DB; }
        .light-theme .preview-btn:hover { background-color: #B0B7C0; }
        .light-theme .edit-profile-btn { background-color: var(--accent-color); }
        .light-theme .tip { background-color: #F8F9FA; }
        
        /* === KOYU TEMA === */
        .dark-theme { background-color: #1E1E1E; color: #ffffff; }
        .dark-theme .sidebar, .dark-theme .content-container { background-color: #242424; }
        .dark-theme .sidebar a { color: #b9bbbe; }
        .dark-theme .sidebar a:hover, .dark-theme .sidebar a.active { background-color: #2f3136; color: #ffffff; }
        .dark-theme .content-container h1 { color: #ffffff; }
        .dark-theme .category, .dark-theme .sound-info .description { color: #b9bbbe; }
        .dark-theme .sound-option { background-color: #2f3136; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); }
        .dark-theme .sound-option:hover { background-color: #35383e; }
        .dark-theme .preview-btn { background-color: #4F545C; }
        .dark-theme .preview-btn:hover { background-color: #5A6069; }
        .dark-theme .edit-profile-btn { background-color: var(--accent-color); }
        .dark-theme .tip { background-color: #2f3136; }

        /* === ÖZEL TEMA === */
        .custom-theme { background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%); color: #ffffff; }
        .custom-theme .sidebar, .custom-theme .content-container { background-color: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%); }
        .custom-theme .sidebar a { color: color-mix(in srgb, var(--custom-background-color) 40%, white); }
        .custom-theme .sidebar a:hover, .custom-theme .sidebar a.active { background-color: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%); color: #ffffff; }
        .custom-theme .sound-option { background-color: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%); }
        .custom-theme .preview-btn { background-color: color-mix(in srgb, var(--custom-secondary-color) 70%, black); }
        .custom-theme .edit-profile-btn { background-color: var(--custom-secondary-color); }
        .custom-theme input[type="radio"]:checked { border-color: var(--custom-secondary-color); }
        .custom-theme input[type="radio"]:checked::before { background-color: var(--custom-secondary-color); }

        /* GENEL YAPI */
        body { font-family: Arial, sans-serif; margin: 0; display: flex; height: 100vh; }
        .sidebar { width: 260px; flex-shrink: 0; background-color: #242424; padding: 20px; box-sizing: border-box; display: flex; flex-direction: column; }
        .sidebar .category { color: #b9bbbe; font-size: 12px; font-weight: 600; text-transform: uppercase; padding: 8px 0; margin-top: 16px; }
        .sidebar a { color: #b9bbbe; text-decoration: none; padding: 10px 16px; border-radius: 5px; margin-bottom: 5px; display: flex; align-items: center; transition: background-color 0.2s, color 0.2s; }
        .sidebar a i { margin-right: 10px; }
        #content { flex-grow: 1; padding: 30px; overflow-y: auto; }
        
        /* BİLDİRİM SAYFASI İÇİN ÖZEL STİLLER */
        .sound-option { display: flex; align-items: center; justify-content: space-between; padding: 12px; margin-bottom: 8px; border-radius: 4px; }
        .sound-info { display: flex; align-items: center; flex-grow: 1; }
        .sound-info .icon { margin-right: 12px; }
        .sound-info .title { font-size: 16px; font-weight: 600; }
        .sound-controls { display: flex; align-items: center; gap: 15px; }
        .preview-btn { color: #ffffff; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; }
        .edit-profile-btn { color: #ffffff; border: none; padding: 10px 16px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; margin-top: 20px;}
        .tip { display: flex; align-items: center; padding: 12px; border-radius: 4px; font-size: 14px; margin-top: 16px; margin-bottom: 16px; }
        .tip i { margin-right: 8px; }
        input[type="radio"] { appearance: none; -webkit-appearance: none; width: 18px; height: 18px; border: 2px solid #b9bbbe; border-radius: 50%; outline: none; cursor: pointer; position: relative; flex-shrink: 0; }
        input[type="radio"]:checked { border-color: var(--hover); }
        input[type="radio"]:checked::before { content: ''; display: block; width: 10px; height: 10px; background-color: var(--hover); border-radius: 50%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

    </style>
</head>
<body>
    <div class="sidebar">
        <div class="category">Kullanıcı Ayarları</div>
        <a href="#settings" id="nav-settings" class="nav-link active">
            <i data-lucide="user"></i> Hesabım
        </a>
        <div class="category">Özelleştirme</div>
        <a href="#bildirimses" id="nav-bildirimses" class="nav-link">
            <i data-lucide="bell"></i> Bildirimler
        </a>
        </div>

    <main id="content">
        </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const contentDiv = document.getElementById('content');
            const navLinks = document.querySelectorAll('.nav-link');

            const routes = {
                '#settings': 'settings-content.php',
                '#bildirimses': 'bildirimses-content.php'
            };

            // Yüklenen içerikteki script'leri çalıştırmak için yardımcı fonksiyon
            const executeScripts = (element) => {
                const scripts = element.querySelectorAll('script');
                scripts.forEach(script => {
                    const newScript = document.createElement('script');
                    newScript.textContent = script.textContent;
                    document.body.appendChild(newScript).remove();
                });
            };

            async function loadContent(hash) {
                const page = routes[hash] || 'settings-content.php';
                
                try {
                    const response = await fetch(page);
                    if (!response.ok) {
                        throw new Error(`Sayfa yüklenemedi: ${response.statusText}`);
                    }
                    const html = await response.text();
                    contentDiv.innerHTML = html;
                    lucide.createIcons(); // Yeni ikonları render et
                    executeScripts(contentDiv); // İçerikle gelen scriptleri çalıştır
                } catch (error) {
                    contentDiv.innerHTML = `<p>İçerik yüklenirken bir hata oluştu: ${error.message}</p>`;
                }
            }

            function updateActiveLink(hash) {
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === hash) {
                        link.classList.add('active');
                    }
                });
            }

            function handleRouteChange() {
                const hash = window.location.hash || '#settings';
                loadContent(hash);
                updateActiveLink(hash);
            }

            navLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const hash = link.getAttribute('href');
                    if (window.location.hash !== hash) {
                        history.pushState(null, '', hash);
                        handleRouteChange();
                    }
                });
            });

            window.addEventListener('popstate', handleRouteChange);

            // İlk yükleme
            handleRouteChange();
        });
    </script>
</body>
</html>