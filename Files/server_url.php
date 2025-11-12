<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Veritabanına bağlanılamıyor. Lütfen daha sonra tekrar deneyin.");
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Tema ayarlarını yükle
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

$currentTheme = $defaultTheme;
$currentCustomColor = $defaultCustomColor;
$currentSecondaryColor = $defaultSecondaryColor;

try {
    $userStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($userData) {
        $currentTheme = $userData['theme'] ?? $defaultTheme;
        $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
        $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
    }
} catch (PDOException $e) {
    error_log("Tema ayarları alınırken hata: " . $e->getMessage());
}

// Get server ID from URL
if (!isset($_GET['id'])) {
    die("Sunucu ID'si eksik.");
}

$server_id = $_GET['id'];

// Check if the user is the owner of the server
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND owner_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {
    header("Location: sayfabulunamadı");
    exit();
}

// Fetch server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Initialize variables
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Yeni Davet Oluşturma Mantığı ---
    if (isset($_POST['create_new_invite'])) {
        // Maksimum davet sayısını kontrol et (örneğin 10, kalıcı davet hariç)
        $stmt = $db->prepare("SELECT COUNT(*) FROM server_invites WHERE server_id = ? AND NOT (expires_at IS NULL AND max_uses = 0)");
        $stmt->execute([$server_id]);
        $current_temp_invite_count = $stmt->fetchColumn();

        $is_permanent_custom = isset($_POST['is_permanent_custom']);

        if (!$is_permanent_custom && $current_temp_invite_count >= 10) { // Maksimum 10 geçici davet sınırı
            $error = "Bu sunucu için en fazla 10 geçici davet oluşturabilirsiniz.";
            header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
            exit;
        } else {
            try {
                $custom_invite_code = trim($_POST['custom_invite_code'] ?? ''); 

                $invite_code_to_use = '';
                $expires_at = null;
                $new_invite_max_uses = 0;

                // Kalıcı davet oluşturma durumunda özel kodun boş olup olmadığını kontrol et
                if ($is_permanent_custom) {
                    if (empty($custom_invite_code)) {
                        $error = "Kalıcı davet oluşturmak için özel bir kod belirtmelisiniz.";
                        header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
                        exit;
                    }
                    
                    // Kod formatını kontrol et (sadece harf, rakam ve tire)
                    if (!preg_match('/^[a-zA-Z0-9\-]{3,25}$/', $custom_invite_code)) {
                        $error = "Geçersiz kod formatı. Sadece harf, rakam ve tire (-) kullanabilirsiniz (3-25 karakter).";
                        header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
                        exit;
                    }
                    
                    // Global benzersizlik kontrolü (sadece kalıcı davetler için)
                    $stmt = $db->prepare("SELECT COUNT(*) FROM server_invites WHERE invite_code = ? AND expires_at IS NULL AND max_uses = 0");
                    $stmt->execute([$custom_invite_code]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "Bu özel davet kodu zaten kullanılıyor. Lütfen başka bir kod deneyin.";
                        header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
                        exit;
                    }
                    $invite_code_to_use = $custom_invite_code;

                    // Sunucuya ait mevcut kalıcı daveti sil (eğer varsa)
                    $delete_old_custom_stmt = $db->prepare("DELETE FROM server_invites WHERE server_id = ? AND expires_at IS NULL AND max_uses = 0");
                    $delete_old_custom_stmt->execute([$server_id]);

                } else {
                    // Geçici davet mantığı
                    $collision_check_attempts = 0;
                    do {
                        $invite_code_to_use = bin2hex(random_bytes(8));
                        $stmt = $db->prepare("SELECT COUNT(*) FROM server_invites WHERE invite_code = ?");
                        $stmt->execute([$invite_code_to_use]);
                        if ($stmt->fetchColumn() == 0) {
                            break;
                        }
                        $collision_check_attempts++;
                    } while ($collision_check_attempts < 5);

                    if ($collision_check_attempts >= 5) {
                        $error = "Davet kodu oluşturulurken bir sorun oluştu. Lütfen tekrar deneyin.";
                        header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
                        exit;
                    }

                    $new_invite_expiry_days = isset($_POST['new_invite_expiry_days']) ? (int)$_POST['new_invite_expiry_days'] : 0;
                    $new_invite_expiry_days = max(0, min(7, $new_invite_expiry_days));
                    $expires_at = ($new_invite_expiry_days > 0) ? date('Y-m-d H:i:s', strtotime("+$new_invite_expiry_days days")) : null;

                    $new_invite_max_uses = isset($_POST['new_invite_max_uses']) ? (int)$_POST['new_invite_max_uses'] : 0;
                    $new_invite_max_uses = max(0, $new_invite_max_uses);
                }

                // Yeni daveti veritabanına ekle
                $stmt = $db->prepare("INSERT INTO server_invites
                                    (server_id, invite_code, expires_at, max_uses, created_by)
                                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$server_id, $invite_code_to_use, $expires_at, $new_invite_max_uses, $_SESSION['user_id']]);

                $success = "Yeni davet bağlantısı başarıyla oluşturuldu!";
                header("Location: server_url?id=" . $server_id . "&success=" . urlencode($success));
                exit;

            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    $error = "Oluşturulan davet kodu zaten mevcut. Lütfen başka bir kod deneyin.";
                } else {
                    $error = "Davet oluşturulurken veritabanı hatası: " . $e->getMessage();
                }
                header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
                exit;
            }
        }
    }
    // --- Davet Silme Mantığı ---
 elseif (isset($_POST['delete_invite']) && isset($_POST['invite_id'])) {
    $invite_to_delete_id = (int)$_POST['invite_id'];
    try {
        // Önce davetin bu sunucuya ait olduğunu ve kullanıcının sahibi olduğunu doğrula
        $stmt = $db->prepare("SELECT si.* FROM server_invites si
                            JOIN servers s ON si.server_id = s.id
                            WHERE si.id = ? AND si.server_id = ? AND s.owner_id = ?");
        $stmt->execute([$invite_to_delete_id, $server_id, $_SESSION['user_id']]);
        $invite = $stmt->fetch();

        if ($invite) {
            // Sadece belirtilen ID'ye sahip ve bu sunucuya ait olan daveti sil
            $stmt = $db->prepare("DELETE FROM server_invites WHERE id = ? AND server_id = ?");
            $stmt->execute([$invite_to_delete_id, $server_id]);

            if ($stmt->rowCount() > 0) {
                $success = "Davet başarıyla silindi.";
                header("Location: server_url?id=" . $server_id . "&success=" . urlencode($success));
                exit;
            } else {
                $error = "Davet silinirken bir hata oluştu veya davet zaten silinmiş.";
                header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
                exit;
            }
        } else {
            $error = "Davet bulunamadı veya bu işlem için yetkiniz yok.";
            header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
            exit;
        }
    } catch (PDOException $e) {
        $error = "Davet silinirken bir hata oluştu: " . $e->getMessage();
        header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
        exit;
    }
 }
    // Özel Davet Bağlantısını Kaldırma Mantığı
    elseif (isset($_POST['remove_vanity_url'])) {
        try {
            $stmt = $db->prepare("DELETE FROM server_invites WHERE server_id = ? AND expires_at IS NULL AND max_uses = 0");
            $stmt->execute([$server_id]);

            if ($stmt->rowCount() > 0) {
                $success = "Özel davet bağlantısı başarıyla kaldırıldı.";
            } else {
                $error = "Kaldırılacak bir özel davet bağlantısı bulunamadı.";
            }
            header("Location: server_url?id=" . $server_id . "&" . ($success ? "success=" . urlencode($success) : "error=" . urlencode($error)));
            exit;
        } catch (PDOException $e) {
            $error = "Özel davet bağlantısı kaldırılırken bir hata oluştu: " . $e->getMessage();
            header("Location: server_url?id=" . $server_id . "&error=" . urlencode($error));
            exit;
        }
    }
}

// --- Tüm aktif davet kodlarını ve bilgilerini çekiyoruz ---
$server_invites_list = [];
$permanent_invite = null; // Özel kalıcı davet için değişken
try {
    // Sunucuya ait tüm aktif davetleri çek
    $stmt = $db->prepare("
        SELECT si.id, si.invite_code, si.expires_at, si.max_uses, si.uses_count,
               u.username as creator_username, up.avatar_url as creator_avatar_url
        FROM server_invites si
        JOIN users u ON si.created_by = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE si.server_id = ? ORDER BY si.created_at DESC
    ");
    $stmt->execute([$server_id]);
    $all_invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Özel kalıcı daveti ayır ve diğerlerini server_invites_list'e ekle
    foreach ($all_invites as $invite) {
        if ($invite['expires_at'] === null && $invite['max_uses'] == 0) {
            $permanent_invite = $invite;
        } else {
            $server_invites_list[] = $invite;
        }
    }

} catch (PDOException $e) {
    error_log("Failed to fetch server invites: " . $e->getMessage());
    $error = "Davetler çekilirken bir hata oluştu: " . $e->getMessage();
}

// --- Eğer POST ile başarılı bir işlem olduysa veya URL'de success varsa, göster ---
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8');
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
}

// Fetch URL stats - Adjusted to use server_members
$stats = ['total_joins' => 0, 'last_join' => null];
try {
    // Toplam katılımı server_members tablosundan say
    $stmt = $db->prepare("SELECT COUNT(*) as total_joins, MAX(join_day) as last_join FROM server_members WHERE server_id = ?");
    $stmt->execute([$server_id]);
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Stats query error (server_members): " . $e->getMessage());
}

// --- Günlük katılım verilerini grafik için çekiyoruz - server_members tablosundan ---
$daily_joins_data = ['labels' => [], 'data' => []]; // Boş başlatıyoruz
try {
    // Son 30 günlük katılım verisini server_members tablosundan al
    $stmt = $db->prepare("
        SELECT DATE(join_day) as join_day_date, COUNT(*) as daily_count
        FROM server_members
        WHERE server_id = ? AND join_day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY join_day_date
        ORDER BY join_day_date ASC
    ");
    $stmt->execute([$server_id]);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Son 30 gün için tüm tarihleri içeren bir dizi oluştur (katılım olmasa bile 0 olarak gösterilmesi için)
    $date_range = [];
    $today = new DateTime();
    for ($i = 29; $i >= 0; $i--) { // 29 gün öncesinden bugüne doğru döngü
        $date = (clone $today)->modify("-$i day");
        $date_range[$date->format('Y-m-d')] = 0;
    }

    foreach ($raw_data as $row) {
        // Tarih formatının dizi anahtarı ile eşleştiğinden emin ol
        $formatted_date = (new DateTime($row['join_day_date']))->format('Y-m-d');
        if (isset($date_range[$formatted_date])) {
            $date_range[$formatted_date] = (int)$row['daily_count'];
        }
    }

    foreach ($date_range as $date => $count) {
        $daily_joins_data['labels'][] = (new DateTime($date))->format('d/m'); // Gösterim formatı: GG/AA
        $daily_joins_data['data'][] = $count;
    }

} catch (PDOException $e) {
    error_log("Daily joins query error (server_members): " . $e->getMessage());
    // Hata durumunda $daily_joins_data boş kalır
}

// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı dilini al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    // Desteklenen dilleri kontrol et
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Dil seçeneğini kontrol et
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang; // Tarayıcı dilini varsayılan olarak ayarla
}

$lang = $_SESSION['lang'];

// Dil dosyalarını yükleme fonksiyonu
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
<html lang="tr" class="<?php echo $currentTheme; ?>-theme" style="--custom-background-color: <?php echo $currentCustomColor; ?>; --custom-secondary-color: <?php echo $currentSecondaryColor; ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['server_settings']['title_three']; ?> - <?php echo htmlspecialchars($server_id, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --scrollbar-thumb: #202225;
            --scrollbar-track: #2e3338;
        }

        /* === AYDINLIK TEMA === */
        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
            --scrollbar-thumb: #c1c3c7;
            --scrollbar-track: #e3e5e8;
        }

        /* === KOYU TEMA === */
        .dark-theme {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --scrollbar-thumb: #202225;
            --scrollbar-track: #2e3338;
        }

        /* === ÖZEL TEMA === */
        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 40%, white);
            --scrollbar-thumb: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            --scrollbar-track: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar-thumb);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-track {
            background-color: var(--scrollbar-track);
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

        /* Stats card */
        .stats-card {
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            padding: 16px;
            margin-bottom: 16px;
        }

        /* QR code container */
        .qrcode-container {
            display: none;
            text-align: center;
            margin-top: 20px;
        }

        .qrcode-container img {
            width: 192px;
            height: 192px;
            margin: 0 auto;
            border: 1px solid rgba(79, 84, 92, 0.6);
            border-radius: 4px;
        }

        /* Notification styles */
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 4px;
            z-index: 1000;
            animation: slideIn 0.3s, fadeOut 0.5s 2.5s forwards;
        }

        .notification-success {
            background-color: var(--success-color);
            color: white;
        }

        .notification-error {
            background-color: var(--danger-color);
            color: white;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
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
        /* Yeni Eklenen Stiller */
        .h5_b717a1 {
            font-size: 0.75rem;
            line-height: 1rem;
        }
        .eyebrow_b717a1 {
            color: var(--text-secondary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .025em;
        }
        .title_a2e6f9 {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        .clickable__40463 {
            cursor: pointer;
            margin-left: 8px;
        }
        .availabilityIndicator__40463 {
            display: flex;
            align-items: center;
            background-color: rgba(0,0,0,0.2);
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .guildFeatureAvailabilityIndicator__956b8 {
            background-color: var(--secondary-bg); /* Benzer bir arka plan rengi */
        }
        .icon__40463 {
            margin-right: 4px;
        }
        .text-sm\/medium_cf4812 {
            font-size: 0.875rem;
            line-height: 1.25rem;
        }
        .description_a2e6f9 {
            margin-top: 4px;
            margin-bottom: 16px;
        }
        .anchor_edefb8 {
            color: var(--accent-color);
        }
        .anchorUnderlineOnHover_edefb8:hover {
            text-decoration: underline;
        }
        .editVanityUrlCard__5abaa {
            background-color: rgba(0,0,0,0.1);
            border: 1px solid rgba(0,0,0,0.3);
            border-radius: 4px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .cardPrimary__73069 {
            box-shadow: 0 1px 0 rgba(4,4,5,0.2), 0 1.5px 0 rgba(6,6,7,0.05), 0 2px 0 rgba(4,4,5,0.05);
        }
        .editable__73069 {
            border: 1px solid transparent;
            transition: border-color .15s ease-in-out;
        }
        .formTitleField__5abaa {
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .heading-sm\/semibold_cf4812 {
            font-size: 1rem;
            line-height: 1.5rem;
            font-weight: 600;
        }
        .defaultColor__5345c {
            color: var(--text-primary);
        }
        .flex__7c0ba {
            display: flex;
        }
        .horizontal__7c0ba {
            flex-direction: row;
        }
        .justifyStart_abf706 {
            justify-content: flex-start;
        }
        .alignCenter_abf706 {
            align-items: center;
        }
        .noWrap_abf706 {
            flex-wrap: nowrap;
        }
        .prefixInput__9d137 {
            display: flex;
            align-items: center;
            background-color: rgba(0,0,0,0.1);
            border: 1px solid rgba(0,0,0,0.3);
            border-radius: 3px;
            padding: 8px 10px;
            width: 100%;
            color: var(--text-primary);
        }
        .prefixInputPrefix__9d137 {
            padding-right: 4px;
            color: var(--text-secondary);
        }
        .prefixInputInput__9d137 {
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 14px;
            width: 100%;
        }
        .marginReset_fd297e {
            margin: 0;
            padding: 0;
        }
        .removeVanityUrlButton__5abaa {
            margin-top: 16px;
            background: none;
            border: none;
            color: var(--danger-color);
            text-decoration: underline;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        .vanityInfo__5abaa {
            margin-top: 16px;
        }
        /* Yeni eklenen stil: Hata mesajı */
        #invite-error {
            background-color: rgba(237, 66, 69, 0.1);
            border: 1px solid var(--danger-color);
            border-radius: 4px;
            padding: 8px;
            margin-bottom: 12px;
            display: none;
        }
    </style>
</head>
<body class="flex h-screen">
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
                <a href="server_url?id=<?php echo $server_id; ?>" class="nav-item active">
                    <i class="fas fa-link w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['server_url']; ?></span>
                </a>
                <a href="unban_users?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-shield-alt w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['moderation']; ?></span>
                </a>
                <a href="server_category?id=<?php echo $server_id; ?>" class="nav-item">
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

    <div id="main-content" class="flex-1 flex flex-col overflow-hidden">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-3xl mx-auto">
                <?php if (!empty($error)): ?>
                    <div class="bg-red-900/50 text-red-200 p-3 rounded mb-4 text-sm">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php elseif (!empty($success)): ?>
                    <div class="bg-green-900/50 text-green-200 p-3 rounded mb-4 text-sm">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <h2 class="text-xl font-semibold mb-6">Sunucu URL Yönetimi</h2>

                <div class="form-section">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title_a2e6f9">Özel Davet Bağlantısı
                        <div class="clickable__40463" role="button" tabindex="0">
                            <span style="display: none;"></span>
                        </div>
                    </h2>
                    <div class="text-sm/medium_cf4812 description_a2e6f9" data-text-variant="text-sm/medium" style="color: var(--text-secondary);">Kendi özel davet bağlantınla başkalarını sunucuna kolayca getir. Ancak, bağlantıya sahip herkes katılabilir ve tüm sunucu üyelerine açık en az bir metin kanalına ihtiyacın olacak.</div>
                    <div class="editVanityUrlCard__5abaa cardPrimary__73069 card__73069 editable__73069">
                        <div class="formTitleField__5abaa">
                            <h2 class="heading-sm/semibold_cf4812 defaultColor__5345c" data-text-variant="heading-sm/semibold" style="color: var(--text-default);">Davet URL'si</h2>
                            <div class="text-sm/medium_cf4812" data-text-variant="text-sm/medium" style="color: var(--text-secondary);">
                                <?php echo htmlspecialchars($permanent_invite['uses_count'] ?? 0, ENT_QUOTES, 'UTF-8'); ?> kullanım
                            </div>
                        </div>
                        <div class="flex__7c0ba horizontal__7c0ba justifyStart_abf706 alignCenter_abf706 noWrap_abf706 prefixInput__9d137" style="flex: 1 1 auto;">
                            <div class="prefixInputPrefix__9d137" style="flex: 0 1 auto;">https://lakeban.com/join_server?code=</div>
                            <!-- DÜZELTME: 'disabled' özelliğini kaldırıyoruz -->
                            <input class="prefixInputInput__9d137 marginReset_fd297e" maxlength="25" 
                                   value="<?php echo htmlspecialchars($permanent_invite['invite_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                   style="flex: 1 1 auto;">
                        </div>
                        <?php if ($permanent_invite): ?>
                            <form method="POST" action="server_url?id=<?php echo $server_id; ?>" onsubmit="return confirm('Özel Davet Bağlantısını kaldırmak istediğinizden emin misiniz?');">
                                <button type="submit" name="remove_vanity_url" class="removeVanityUrlButton__5abaa button__201d5 lookLink__201d5 lowSaturationUnderline__41f68 colorRed__201d5 sizeMin__201d5 grow__201d5">
                                    <div class="contents__201d5">Özel Davet Bağlantısını kaldır</div>
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="text-sm text-gray-400 mt-4">Henüz bir özel davet bağlantınız yok. Aşağıdan oluşturabilirsiniz.</p>
                        <?php endif; ?>
                    </div>
                    <div class="text-sm/medium_cf4812 vanityInfo__5abaa" data-text-variant="text-sm/medium" style="color: var(--text-secondary);">
                        <?php if ($permanent_invite): ?>
                            Artık herkes bu sunucuya <a class="anchor_edefb8 anchorUnderlineOnHover_edefb8" href="https://lakeban.com/join_server?code=<?php echo htmlspecialchars($permanent_invite['invite_code'], ENT_QUOTES, 'UTF-8'); ?>" rel="noreferrer noopener" target="_blank" role="button" tabindex="0">https://lakeban.com/join_server?code=<?php echo htmlspecialchars($permanent_invite['invite_code'], ENT_QUOTES, 'UTF-8'); ?></a> adresinden ulaşabilir
                        <?php else: ?>
                            Özel davet bağlantısı oluşturulduğunda burada görünecektir.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stats-card">
                    <h3 class="font-medium text-sm mb-3">URL İSTATİSTİKLERİ</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-400">Toplam Katılım</p>
                            <p class="text-lg font-bold"><?php echo htmlspecialchars($stats['total_joins'] ?? 0, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Son Katılım</p>
                            <p class="text-lg font-bold">
                                <?php
                                if (!empty($stats['last_join'])) {
                                    $lastJoin = new DateTime($stats['last_join']);
                                    $now = new DateTime();
                                    $interval = $now->diff($lastJoin); // Calculate difference from last join to now

                                    if ($interval->y > 0) {
                                        echo htmlspecialchars($interval->y . ' yıl önce', ENT_QUOTES, 'UTF-8');
                                    } elseif ($interval->m > 0) {
                                        echo htmlspecialchars($interval->m . ' ay önce', ENT_QUOTES, 'UTF-8');
                                    } elseif ($interval->d > 0) {
                                        echo htmlspecialchars($interval->d . ' gün önce', ENT_QUOTES, 'UTF-8');
                                    } elseif ($interval->h > 0) {
                                        echo htmlspecialchars($interval->h . ' saat önce', ENT_QUOTES, 'UTF-8');
                                    } elseif ($interval->i > 0) {
                                        echo htmlspecialchars($interval->i . ' dakika önce', ENT_QUOTES, 'UTF-8');
                                    } else {
                                        echo "Şimdi";
                                    }
                                } else {
                                    echo "Hiç katılım yok";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="stats-card mt-6">
                    <h3 class="font-medium text-sm mb-3">GÜNLÜK KATILIM GRAFİĞİ (Son 30 Gün)</h3>
                    <div class="chart-container" style="position: relative; height:300px; width:100%">
                        <canvas id="dailyJoinsChart"></canvas>
                    </div>
                </div>

                <h3 class="font-medium text-sm mb-3">AKTİF DAVETLER</h3>
                <div id="invite-list" class="space-y-4">
                    <?php if (empty($server_invites_list)): ?>
                        <p class="text-gray-400">Henüz aktif bir davetiniz yok.</p>
                    <?php else: ?>
                        <?php foreach ($server_invites_list as $invite): ?>
                            <div class="stats-card flex flex-col md:flex-row items-start md:items-center justify-between p-4">
                                <div class="mb-4 md:mb-0 md:mr-4">
                                    <p class="text-lg font-bold flex items-center">
                                        <span id="invite-code-<?php echo $invite['id']; ?>"><?php echo htmlspecialchars($invite['invite_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <button type="button" onclick="copyInvite('<?php echo htmlspecialchars($invite['invite_code'], ENT_QUOTES, 'UTF-8'); ?>')" class="ml-2 text-gray-400 hover:text-white" title="Daveti kopyala">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </p>
                                    <p class="text-sm text-gray-400 mt-1">
                                        Oluşturan:
                                        <?php if (!empty($invite['creator_avatar_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($invite['creator_avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" class="inline-block w-6 h-6 rounded-full mr-1">
                                        <?php else: ?>
                                            <i class="fas fa-user-circle inline-block w-6 h-6 text-gray-400 mr-1 align-middle"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($invite['creator_username'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="text-sm text-gray-400 mt-1">
                                        Kullanım: <?php echo htmlspecialchars($invite['uses_count'], ENT_QUOTES, 'UTF-8'); ?> /
                                        <?php echo ($invite['max_uses'] == 0) ? 'Sınırsız' : htmlspecialchars($invite['max_uses'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="text-sm text-gray-400" id="countdown-<?php echo $invite['id']; ?>">
                                        <?php
                                        if ($invite['expires_at']) {
                                            $expiryTime = new DateTime($invite['expires_at']);
                                            $now = new DateTime();
                                            if ($expiryTime > $now) {
                                                $interval = $now->diff($expiryTime);
                                                echo "Kalan Süre: ";
                                                if ($interval->d > 0) echo $interval->d . " gün ";
                                                if ($interval->h > 0) echo $interval->h . " saat ";
                                                if ($interval->i > 0) echo $interval->i . " dakika ";
                                                echo $interval->s . " saniye";
                                            } else {
                                                echo "<span class='text-red-400'>Süresi doldu</span>";
                                            }
                                        } else {
                                            echo "Süresiz";
                                        }
                                        ?>
                                    </p>
                                </div>
                                <div>
                                    <form method="POST" action="server_url?id=<?php echo $server_id; ?>" class="inline-block" onsubmit="return confirm('Bu daveti silmek istediğinizden emin misiniz?');">
                                        <input type="hidden" name="invite_id" value="<?php echo $invite['id']; ?>">
                                        <button type="submit" name="delete_invite" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash-alt"></i> Sil
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="form-section mt-6">
                    <h3 class="font-medium text-sm mb-3">YENİ DAVET OLUŞTUR</h3>
                    
                    <!-- Hata mesajı için yeni alan -->
                    <div id="invite-error" class="text-red-400 text-sm mb-3 hidden"></div>
                    
                    <form method="POST" action="server_url?id=<?php echo $server_id; ?>" 
                          class="space-y-4" id="invite-form">
                        <!-- Kalıcı Davet Seçeneği -->
                        <div class="flex items-center">
                            <input type="checkbox" id="is-permanent-custom" name="is_permanent_custom" 
                                   class="mr-2 h-4 w-4 text-accent-color focus:ring-accent-color border-gray-600 rounded">
                            <label for="is-permanent-custom" class="text-sm text-gray-400">
                                Kalıcı Davet Oluştur (Özel URL, süresiz)
                            </label>
                        </div>

                        <!-- Özel Davet Kodu (Sadece kalıcı davet seçilince görünecek) -->
                        <div id="custom-invite-container" class="hidden">
                            <label for="custom-invite-code" class="form-label">ÖZEL DAVET KODU</label>
                            <input type="text" id="custom-invite-code" name="custom_invite_code" 
                                   class="form-input" placeholder="ornek-kod"
                                   pattern="[a-zA-Z0-9\-]{3,25}" 
                                   title="Sadece harf, rakam ve tire (-) kullanabilirsiniz">
                            <p class="text-xs text-gray-400 mt-1">
                                Özel kodunuz sadece harf, rakam ve tire (-) içerebilir (3-25 karakter)
                            </p>
                        </div>

                        <!-- Geçici Davet Seçenekleri -->
                        <div id="temporary-invite-options">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="new-invite-expiry-days" class="form-label">
                                        SÜRE (GÜN) <span class="text-gray-500">(Max 7)</span>
                                    </label>
                                    <select id="new-invite-expiry-days" name="new_invite_expiry_days" 
                                            class="form-input">
                                        <option value="1">1 Gün</option>
                                        <option value="3">3 Gün</option>
                                        <option value="7" selected>7 Gün</option>
                                        <option value="0">Süresiz</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="new-invite-max-uses" class="form-label">
                                        MAKS. KULLANIM
                                    </label>
                                    <select id="new-invite-max-uses" name="new_invite_max_uses" 
                                            class="form-input">
                                        <option value="5">5 Kullanım</option>
                                        <option value="10">10 Kullanım</option>
                                        <option value="25">25 Kullanım</option>
                                        <option value="50">50 Kullanım</option>
                                        <option value="0" selected>Sınırsız</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="create_new_invite" class="btn btn-primary w-full md:w-auto">
                            <i class="fas fa-plus"></i> Yeni Davet Oluştur
                        </button>
                    </form>
                </div>

                <!-- QR Kod Bölümü İyileştirmeleri -->
                <div class="form-section mt-6">
                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                        <button class="btn btn-primary flex-1" onclick="generateQRCode()">
                            <i class="fas fa-qrcode"></i>
                            <span>QR Kod Oluştur</span>
                        </button>
                        
                        <div class="flex-1">
                            <select id="qrcode-invite-select" class="form-input">
                                <?php if ($permanent_invite): ?>
                                    <option value="permanent">Kalıcı Davet</option>
                                <?php endif; ?>
                                <?php foreach ($server_invites_list as $invite): ?>
                                    <option value="<?php echo $invite['id']; ?>">
                                        <?php echo htmlspecialchars($invite['invite_code'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="qrcode-container" class="qrcode-container mt-6 p-4 bg-gray-800 rounded-lg hidden">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-sm font-medium">QR KOD</h3>
                        <button class="text-gray-400 hover:text-white" onclick="document.getElementById('qrcode-container').classList.add('hidden')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <img id="qrcode" src="" alt="QR Code" class="mx-auto">
                    <button class="btn btn-primary w-full mt-4" onclick="downloadQRCode()">
                        <i class="fas fa-download"></i>
                        <span>QR Kodu İndir</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Hata ayıklama: PHP'den gelen veriyi kontrol etmek için
        console.log("Günlük Katılım Etiketleri:", <?php echo json_encode($daily_joins_data['labels']); ?>);
        console.log("Günlük Katılım Verileri:", <?php echo json_encode($daily_joins_data['data']); ?>);
        console.log("Sunucu Davet Listesi:", <?php echo json_encode($server_invites_list); ?>);

        const dailyJoinsLabels = <?php echo json_encode($daily_joins_data['labels']); ?>;
        const dailyJoinsData = <?php echo json_encode($daily_joins_data['data']); ?>;

        // Chart.js'i sadece veriler varsa veya en azından etiketler varsa başlat
        if (dailyJoinsLabels.length > 0) {
            const ctx = document.getElementById('dailyJoinsChart').getContext('2d');
            const dailyJoinsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dailyJoinsLabels,
                    datasets: [{
                        label: 'Günlük Katılım Sayısı',
                        data: dailyJoinsData,
                        borderColor: 'rgb(60, 179, 113)', // Accent color
                        backgroundColor: 'rgba(60, 179, 113, 0.2)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            // X ekseni ızgara çizgilerini kaldır
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: 'var(--text-secondary)'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: Math.max(...dailyJoinsData) + 2, // Add some padding
                            // Y ekseni ızgara çizgilerini kaldır
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: 'var(--text-secondary)',
                                precision: 0 // Ensure integer ticks for counts
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: 'var(--text-primary)'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'var(--secondary-bg)',
                            titleColor: 'var(--text-primary)',
                            bodyColor: 'var(--text-secondary)',
                            borderColor: 'rgba(79, 84, 92, 0.6)',
                            borderWidth: 1
                        }
                    }
                }
            });
        } else {
            // Veri yoksa grafik alanına bir mesaj göster
            const chartContainer = document.getElementById('dailyJoinsChart').parentNode;
            chartContainer.innerHTML = '<p class="text-center text-gray-400 mt-10">Son 30 güne ait katılım verisi bulunamadı.</p>';
        }

        // Davet kodu kopyalama fonksiyonu
        function copyInvite(code) {
            // Davetin tam URL'sini oluştur
            const fullInviteUrl = `https://lakeban.com/join_server?code=${encodeURIComponent(code)}`;
            navigator.clipboard.writeText(fullInviteUrl).then(() => {
                showNotification("Davet bağlantısı kopyalandı!", "success");
            }).catch(err => {
                console.error('Kopyalama hatası:', err);
                showNotification("Davet kopyalanamadı.", "error");
            });
        }

        // QR kodu oluştur
        function generateQRCode() {
            const inviteType = document.getElementById('qrcode-invite-select').value;
            let inviteCode = '';
            
            if (inviteType === 'permanent') {
                inviteCode = "<?php echo $permanent_invite['invite_code'] ?? ''; ?>";
            } else {
                // Seçilen geçici daveti bul
                const invites = <?php echo json_encode($server_invites_list); ?>;
                const selectedInvite = invites.find(invite => invite.id == inviteType);
                inviteCode = selectedInvite ? selectedInvite.invite_code : '';
            }
            
            if (!inviteCode) {
                showNotification("QR kodu oluşturmak için geçerli bir davet seçin", "error");
                return;
            }
            
            const urlToEncode = `https://lakeban.com/join_server?code=${encodeURIComponent(inviteCode)}`;
            const qrImg = document.getElementById("qrcode");
            
            qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${urlToEncode}`;
            document.getElementById("qrcode-container").classList.remove("hidden");
        }

        // QR kodunu indir
        function downloadQRCode() {
            const qrImg = document.getElementById("qrcode");
            if (qrImg.src) {
                const link = document.createElement("a");
                link.href = qrImg.src;
                link.download = "sunucu-davet-qr.png";
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Bildirim göster
        function showNotification(message, type) {
            const notification = document.createElement("div");
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Geri sayım sayacı fonksiyonu
        function updateCountdowns() {
            // PHP'den gelen davet listesini JavaScript'e aktar
            const serverInvites = <?php echo json_encode($server_invites_list); ?>;

            serverInvites.forEach(invite => {
                const countdownElement = document.getElementById(`countdown-${invite.id}`);
                if (!countdownElement) return; // Element yoksa atla

                if (invite.expires_at) {
                    const expiryTime = new Date(invite.expires_at).getTime();
                    const now = new Date().getTime();
                    const distance = expiryTime - now;

                    if (distance < 0) {
                        countdownElement.innerHTML = "<span class='text-red-400'>Süresi doldu</span>";
                        // İsteğe bağlı: Davet kartını grileştir veya kaldır
                    } else {
                        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                        let timeString = "Kalan Süre: ";
                        if (days > 0) timeString += `${days} gün `;
                        if (hours > 0 || days > 0) timeString += `${hours} saat `;
                        if (minutes > 0 || hours > 0 || days > 0) timeString += `${minutes} dakika `;
                        timeString += `${seconds} saniye`;

                        countdownElement.innerHTML = timeString.trim();
                    }
                } else {
                    countdownElement.innerHTML = "Süresiz";
                }
            });
        }

        // Sayfa yüklendiğinde ve her saniye geri sayımı güncelle
        setInterval(updateCountdowns, 1000);
        updateCountdowns();

        // Kalıcı davet onay kutusu ve diğer seçeneklerin etkileşimi
        document.addEventListener('DOMContentLoaded', function() {
            const isPermanentCheckbox = document.getElementById('is-permanent-custom');
            const customInviteContainer = document.getElementById('custom-invite-container');
            const tempInviteOptions = document.getElementById('temporary-invite-options');
            const inviteForm = document.getElementById('invite-form');
            const errorDisplay = document.getElementById('invite-error');

            function toggleInviteOptions() {
                if (isPermanentCheckbox.checked) {
                    customInviteContainer.classList.remove('hidden');
                    tempInviteOptions.classList.add('hidden');
                } else {
                    customInviteContainer.classList.add('hidden');
                    tempInviteOptions.classList.remove('hidden');
                }
            }

            // Form gönderim kontrolü
            inviteForm.addEventListener('submit', function(e) {
                errorDisplay.classList.add('hidden');
                
                if (isPermanentCheckbox.checked) {
                    const customCode = document.getElementById('custom-invite-code').value.trim();
                    
                    if (!customCode) {
                        e.preventDefault();
                        showError('Kalıcı davet için özel kod gereklidir');
                        return;
                    }
                    
                    if (!/^[a-zA-Z0-9\-]{3,25}$/.test(customCode)) {
                        e.preventDefault();
                        showError('Geçersiz kod formatı. Sadece harf, rakam ve tire kullanabilirsiniz (3-25 karakter)');
                        return;
                    }
                }
            });

            function showError(message) {
                errorDisplay.textContent = message;
                errorDisplay.classList.remove('hidden');
            }

            isPermanentCheckbox.addEventListener('change', toggleInviteOptions);
            toggleInviteOptions(); // İlk yüklemede durumu ayarla
        });

        // Mobil Kaydırma hareketi
        const movesidebar = document.getElementById("movesidebar");

        let startX, endX; // Hareket başlangıç ve bitiş noktaları

        // Ekran genişliği 768px veya daha küçükse kaydırma işlemi etkinleştirilsin
        if (window.innerWidth <= 768) {
          // Dokunma başlangıcını algıla
          document.addEventListener("touchstart", (e) => {
            startX = e.touches[0].clientX;
          });

          // Dokunma bitişini algıla ve hareketi kontrol et
          document.addEventListener("touchend", (e) => {
            endX = e.changedTouches[0].clientX;
            handleSwipe();
          });

          // Hareketi işleyen fonksiyon
          function handleSwipe() {
            const deltaX = startX - endX;

            // Minimum hassasiyet
            if (Math.abs(deltaX) < 100) return; // 100px altında hiçbir işlem yapma

            // Sağdan sola kaydırma: Sidebar kapanıyor
            if (deltaX > 100) {
              closeSidebar();
            }
            // Soldan sağa kaydırma: Sidebar açılıyor
            else if (deltaX < -100) {
              openSidebar();
            }
          }

          // Sidebar'ı açan fonksiyon
          function openSidebar() {
            movesidebar.style.left = "0"; // Sağdan sıfıra hareket
          }

          // Sidebar'ı kapatan fonksiyon
          function closeSidebar() {
            movesidebar.style.left = "-100%"; // Sağdan kaybolma
          }
        }
    </script>
</body>
</html>