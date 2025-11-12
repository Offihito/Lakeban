<?php
// Session settings (already included in main file, but keeping for standalone functionality)
ini_set('session.gc_maxlifetime', 2592000); // 30 days
session_set_cookie_params(2592000); // 30 days
session_start();

// Database connection (minimal version for header needs)
define('DB_HOST', 'localhost');
define('DB_USER', 'lakebanc_Offihito');
define('DB_PASS', 'P4QG(m2jkWXN');
define('DB_NAME', 'lakebanc_Database');

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection error: " . $e->getMessage());
}

// Default language
$default_lang = 'tr'; // Default language Turkish

// Get browser language
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Language selection
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

// Check user login status
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$username = $isLoggedIn ? ($_SESSION['username'] ?? null) : null;
$profilePicture = null;

if ($isLoggedIn) {
    try {
        $stmt = $db->prepare("
            SELECT u.username, p.avatar_url 
            FROM users u 
            LEFT JOIN user_profiles p ON u.id = p.user_id 
            WHERE u.id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            if ($result['username'] !== $_SESSION['username']) {
                $_SESSION['username'] = $result['username'];
                $username = $result['username'];
            }
            $profilePicture = $result['avatar_url'] ?? null;
        } else {
            error_log("User not found: user_id = " . $_SESSION['user_id']);
            session_unset();
            session_destroy();
            $isLoggedIn = false;
            $username = null;
        }
    } catch (PDOException $e) {
        error_log("User profile query error: " . $e->getMessage());
    }
}
?>

<style>
    :root {
        --dark-bg: #0f1114;
        --darker-bg: #0a0c0f;
        --card-bg: #1e2025;
        --card-border: #2c2e33;
        --text-light: #e0e0e0;
        --text-secondary: rgba(255, 255, 255, 0.7);
        --primary: #4CAF50;
        --primary-dark: #388E3C;
        --primary-light: #81C784;
        --transition: all 0.3s ease;
        --shadow-sm: 0 4px 6px rgba(0,0,0,0.1);
        --radius-sm: 8px;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }

    .header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 5%;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: rgba(10, 12, 15, 0.95);
        backdrop-filter: blur(10px);
        z-index: 1000;
        border-bottom: 1px solid var(--card-border);
        transition: var(--transition);
    }

    .header.scrolled {
        background: rgba(10, 12, 15, 0.98);
        box-shadow: var(--shadow-sm);
        padding: 0.7rem 5%;
    }

    .logo-container {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        transition: var(--transition);
    }

    .header.scrolled .logo-container img {
        height: 35px;
    }

    .logo-container img {
        height: 45px;
        width: auto;
        transition: var(--transition);
    }

    .logo-container .brand {
        font-weight: 700;
        font-size: 1.4rem;
        background: linear-gradient(to right, var(--primary), var(--primary-light));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    .header nav {
        display: flex;
        gap: 1.5rem;
    }

    .header nav a {
        color: var(--text-secondary);
        text-decoration: none;
        font-weight: 500;
        position: relative;
        padding: 0.5rem 0;
        transition: var(--transition);
    }

    .header nav a:hover {
        color: white;
    }

    .header nav a::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 0;
        height: 2px;
        background: var(--primary);
        transition: var(--transition);
    }

    .header nav a:hover::after {
        width: 100%;
    }

    .utils {
        display: flex;
        align-items: center;
        gap: 1.5rem;
    }

    .language-selector select {
        background: var(--card-bg);
        color: white;
        border: 1px solid var(--card-border);
        border-radius: var(--radius-sm);
        padding: 0.5rem;
        cursor: pointer;
        transition: var(--transition);
    }

    .language-selector select:hover {
        border-color: var(--primary);
    }

    .auth-buttons {
        display: flex;
        gap: 1rem;
    }

    .login-btn, .register-btn {
        padding: 0.6rem 1.5rem;
        border-radius: var(--radius-sm);
        font-weight: 500;
        transition: var(--transition);
        text-decoration: none;
    }

    .login-btn {
        color: white;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.2);
    }

    .login-btn:hover {
        background: rgba(255,255,255,0.05);
    }

    .register-btn {
        background: var(--primary);
        color: white;
        border: none;
    }

    .register-btn:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .user-profile {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: var(--radius-sm);
        background: var(--card-bg);
        transition: var(--transition);
    }

    .user-profile:hover {
        background: var(--darker-bg);
    }

    .user-profile a {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        color: white;
        text-decoration: none;
    }

    .avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1rem;
        color: white;
        overflow: hidden;
    }

    .avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    @media (max-width: 992px) {
        .header nav {
            display: none;
        }
    }

    @media (max-width: 768px) {
        .user-profile span {
            display: none;
        }
    }

    @media (max-width: 576px) {
        .header {
            padding: 1rem;
        }
        .utils {
            gap: 1rem;
        }
        .language-selector {
            display: none;
        }
    }
</style>

<header class="header">
    <div class="logo-container">
        <img src="https://lakeban.com/icon.ico" alt="LakeBan Logo">
        <span class="brand">LakeBan</span>
    </div>
    <nav>
        <a href="#"><?php echo $translations['header']['nav']['home'] ?? 'Anasayfa'; ?></a>
        <a href="/topluluklar"><?php echo $translations['header']['nav']['communities'] ?? 'Topluluklar'; ?></a>
        <a href="/changelog"><?php echo $translations['header']['nav']['updates'] ?? 'Güncellemeler'; ?></a>
        <a href="/lakebium"> Lakebium</a>
    </nav>
    <div class="utils">
        <div class="language-selector">
            <select onchange="changeLanguage(this.value)">
                <option value="tr" <?php if ($lang == 'tr') echo 'selected'; ?>>Türkçe</option>
                <option value="en" <?php if ($lang == 'en') echo 'selected'; ?>>English</option>
                <option value="fı" <?php if ($lang == 'fı') echo 'selected'; ?>>Suomi</option>
                <option value="ru" <?php if ($lang == 'ru') echo 'selected'; ?>>Русский</option>
                <option value="fr" <?php if ($lang == 'fr') echo 'selected'; ?>>Français</option>
                <option value="de" <?php if ($lang == 'de') echo 'selected'; ?>>Deutsch</option>
            </select>
        </div>

        <?php if ($isLoggedIn): ?>
            <div class="user-profile">
                <a href="/profile-page?username=<?php echo htmlspecialchars($_SESSION['username']); ?>">
                    <div class="avatar">
                        <?php if ($profilePicture): ?>
                            <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="<?php echo htmlspecialchars($username); ?>'s avatar">
                        <?php else: ?>
                            <?php echo strtoupper(substr($username, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <span><?php echo htmlspecialchars($username); ?></span>
                </a>
            </div>
        <?php else: ?>
            <div class="auth-buttons">
                <a href="login" class="login-btn"><?php echo $translations['header']['login'] ?? 'Giriş Yap'; ?></a>
                <a href="register" class="register-btn"><?php echo $translations['header']['register'] ?? 'Kayıt Ol'; ?></a>
            </div>
        <?php endif; ?>
    </div>
</header>

<script>
    // Header scroll effect
    window.addEventListener('scroll', function() {
        const header = document.querySelector('.header');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // Language change function
    function changeLanguage(lang) {
        window.location.href = window.location.pathname + '?lang=' + lang;
    }
</script>