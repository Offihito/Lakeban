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
    error_log("Database connection error: " . $e->getMessage());
    die("Veritabanı bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.");
}

// URL'den davet kodunu al
if (!isset($_GET['code']) || empty($_GET['code'])) {
    die("Davet kodu eksik.");
}

$invite_code = $_GET['code'];

// DEBUG LOG: Davet kodu alındı
error_log("[DEBUG] join_server.php - Alınan davet kodu: " . $invite_code);

// Davet kodunun geçerliliğini kontrol et ve sunucu detaylarını al
$stmt = $db->prepare("SELECT s.id AS server_id, s.name AS server_name, s.description AS server_description, 
                             s.profile_picture, s.banner, s.invite_background,
                             si.id AS invite_id, si.max_uses, si.uses_count, si.expires_at
                      FROM server_invites si 
                      JOIN servers s ON si.server_id = s.id 
                      WHERE si.invite_code = ?");
$stmt->execute([$invite_code]);
$server_details = $stmt->fetch();

if (!$server_details) {
    error_log("[DEBUG] join_server.php - Geçersiz veya süresi dolmuş davet kodu: " . $invite_code);
    die("Geçersiz veya süresi dolmuş davet kodu."); 
}

// DEBUG LOG: Davet detayları çekildi
error_log("[DEBUG] join_server.php - Çekilen davet ID: " . $server_details['invite_id'] . ", Mevcut Kullanım: " . $server_details['uses_count']);

// Davet kodunun süresi dolmuş mu kontrol et
if ($server_details['expires_at'] && strtotime($server_details['expires_at']) < time()) {
    error_log("[DEBUG] join_server.php - Süresi dolmuş davet kodu: " . $invite_code);
    die("Bu davet kodunun süresi dolmuştur.");
}

// Davet kodunun maksimum kullanıma ulaşıp ulaşmadığını kontrol et
if ($server_details['max_uses'] != 0 && $server_details['uses_count'] >= $server_details['max_uses']) {
    error_log("[DEBUG] join_server.php - Maksimum kullanıma ulaşmış davet kodu: " . $invite_code);
    die("Bu davet kodu maksimum kullanım sayısına ulaşmıştır.");
}

$server_id = $server_details['server_id'];
$server_name = htmlspecialchars($server_details['server_name'] ?? '', ENT_QUOTES, 'UTF-8');
$server_description = htmlspecialchars($server_details['server_description'] ?? '', ENT_QUOTES, 'UTF-8');
$server_profile_picture = htmlspecialchars($server_details['profile_picture'] ?? '', ENT_QUOTES, 'UTF-8');
$server_banner = htmlspecialchars($server_details['banner'] ?? '', ENT_QUOTES, 'UTF-8');
$invite_background = htmlspecialchars($server_details['invite_background'] ?? '', ENT_QUOTES, 'UTF-8');

// Davet bilgileri (güncelleme için)
$invite_id = $server_details['invite_id'];
$max_uses = $server_details['max_uses'];
$current_uses = $server_details['uses_count'];

// Sunucudaki üye sayısını al
$stmt = $db->prepare("SELECT COUNT(*) AS member_count FROM server_members WHERE server_id = ?");
$stmt->execute([$server_id]);
$member_count_result = $stmt->fetch();
$member_count = $member_count_result['member_count'];

// Kullanıcının giriş yapıp yapmadığını kontrol et
$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;

$isBanned = false;
$isMember = false;

if ($is_logged_in) {
    // Kullanıcının sunucudan yasaklı olup olmadığını kontrol et
    $stmt = $db->prepare("SELECT * FROM banned_users WHERE user_id = ? AND server_id = ?");
    $stmt->execute([$user_id, $server_id]);
    $isBanned = $stmt->fetch();

    if ($isBanned) {
        die("Bu sunucudan yasaklandınız ve katılamazsınız.");
    }

    // Kullanıcının zaten sunucunun bir üyesi olup olmadığını kontrol et
    $stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
    $stmt->execute([$server_id, $user_id]);
    if ($stmt->rowCount() > 0) {
        $isMember = true;
    }
}

// Katılma işlemini yönet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_server'])) {
    if (!$is_logged_in) {
        header("Location: login.php");
        exit;
    }

    if ($isMember) {
        header("Location: server.php?id=$server_id");
        exit;
    }

    if ($isBanned) {
        die("Bu sunucudan yasaklandınız ve katılamazsınız.");
    }

    // Kullanıcıyı sunucuya ekle
    $db->beginTransaction();
    try {
        // 1. Kullanıcıyı sunucuya ekle
        $stmt = $db->prepare("INSERT INTO server_members (server_id, user_id, join_day) VALUES (?, ?, CURDATE())");
        $stmt->execute([$server_id, $user_id]);

        // 2. SADECE KULLANILAN DAVET KODUNUN uses_count'UNU ARTIR
        if ($max_uses == 0 || $current_uses < $max_uses) {
            $stmt_update = $db->prepare("UPDATE server_invites SET uses_count = uses_count + 1 WHERE id = ? AND invite_code = ?");
            $stmt_update->execute([$invite_id, $invite_code]);

            // Eğer davet kodu maksimum kullanıma ulaştıysa (ve sınırsız değilse), devre dışı bırak
            if ($max_uses != 0 && ($current_uses + 1) >= $max_uses) {
                $stmt_deactivate = $db->prepare("UPDATE server_invites SET expires_at = NOW() WHERE id = ?");
                $stmt_deactivate->execute([$invite_id]);
            }
        }

        $db->commit();
        
        // =================== YENİ KOD BAŞLANGICI ===================
        // BOTLARIN OTOMATİK ROL ATAMA İŞLEMİ
        try {
            // Sunucudaki aktif botların otomatik rol ayarlarını kontrol et
            $stmt_auto_role = $db->prepare("
                SELECT bsc.auto_role_id
                FROM bot_special_commands bsc
                JOIN users u ON bsc.bot_id = u.id
                WHERE bsc.auto_role_id IS NOT NULL 
                AND u.is_active = 1
                AND bsc.bot_id IN (
                    SELECT user_id FROM server_members WHERE server_id = ?
                )
            ");
            $stmt_auto_role->execute([$server_id]);
            $auto_roles = $stmt_auto_role->fetchAll(PDO::FETCH_COLUMN);

            // Eğer atanacak roller varsa, kullanıcıya bu rolleri ver
            if (!empty($auto_roles)) {
                $stmt_assign_role = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, server_id) VALUES (?, ?, ?)");
                foreach ($auto_roles as $auto_role_id) {
                    $stmt_assign_role->execute([$user_id, $auto_role_id, $server_id]);
                }
            }
        } catch (Exception $e) {
            // Rol atama başarısız olursa logla ama işlemi durdurma
            error_log("Otomatik rol atama hatası: " . $e->getMessage());
        }
        // =================== YENİ KOD SONU ===================

        
        // HOŞGELDİN MESAJI GÖNDER
        try {
            // Kullanıcı adını al
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $username = $user['username'] ?? 'Bir kullanıcı';
            
            // Botların hoşgeldin komutlarını kontrol et
            $stmt = $db->prepare("
                SELECT bsc.welcome_channel, bsc.welcome_message, u.username AS bot_username
                FROM bot_special_commands bsc
                JOIN users u ON bsc.bot_id = u.id
                WHERE bsc.welcome_channel IS NOT NULL AND bsc.welcome_message != '' 
                AND u.is_active = 1
                AND bsc.bot_id IN (
                    SELECT user_id FROM server_members WHERE server_id = ?
                )
            ");
            $stmt->execute([$server_id]);
            $welcome_commands = $stmt->fetchAll();

            foreach ($welcome_commands as $command) {
                $message = str_replace(
                    ['{user}', '{server}'],
                    [htmlspecialchars($username), htmlspecialchars($server_name)],
                    $command['welcome_message']
                );
                
                $stmt = $db->prepare("INSERT INTO messages1 
                    (server_id, channel_id, sender_id, message_text) 
                    VALUES (?, ?, (SELECT id FROM users WHERE username = ?), ?)");
                $stmt->execute([
                    $server_id,
                    $command['welcome_channel'],
                    $command['bot_username'],
                    $message
                ]);
            }
        } catch (Exception $e) {
            error_log("Hoşgeldin mesajı gönderilemedi: " . $e->getMessage());
        }

        // Başarılı olduğunda yönlendir
        header("Location: server.php?id=$server_id");
        exit;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error adding user to server: " . $e->getMessage());
        die("Sunucuya katılırken bir hata oluştu. Lütfen tekrar deneyin.");
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sunucuya Katıl: <?php echo $server_name; ?></title>
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

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .invite-card {
            background-color: var(--secondary-bg);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 450px;
            width: 90%;
        }

        .invite-banner {
            height: 120px;
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .invite-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.7), rgba(0,0,0,0.1));
        }

        .server-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid var(--secondary-bg);
            background-color: var(--secondary-bg);
            object-fit: cover;
            position: absolute;
            bottom: -35px;
            left: 20px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s ease;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2E8B57;
        }

        .btn-secondary {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: rgba(79, 84, 92, 0.6);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4" style="<?php 
    if (!empty($invite_background)) {
        echo 'background-image: url(\'' . $invite_background . '\');';
    } elseif (!empty($server_banner)) { 
        echo 'background-image: url(\'' . $server_banner . '\');';
    } else {
        echo 'background-color: var(--primary-bg);'; 
    }
?>">
    <div class="invite-card">
        <div class="invite-banner" style="<?php 
            if (!empty($server_banner)) {
                echo 'background-image: url(\'' . $server_banner . '\');';
            } else {
                echo 'background-color: #4CAF50;';
            }
        ?>">
            <img src="<?php echo !empty($server_profile_picture) ? $server_profile_picture : 'https://via.placeholder.com/70/36393f/FFFFFF?text='; ?>" alt="Sunucu Profil Resmi" class="server-avatar">
        </div>
        <div class="p-8 pt-12">
            <h1 class="text-2xl font-bold mb-2"><?php echo $server_name; ?></h1>
            <p class="text-sm text-gray-400 mb-2"><?php echo $server_description; ?></p>
            <p class="text-sm text-gray-400 mb-6"><i class="fas fa-users mr-2"></i><?php echo $member_count; ?> Üye</p>
            
            <form method="POST" action="join_server.php?code=<?php echo htmlspecialchars($invite_code, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($is_logged_in): ?>
                    <?php if ($isMember): ?>
                        <a href="server.php?id=<?php echo $server_id; ?>" class="btn btn-primary w-full mb-3 text-center block">
                            <i class="fas fa-sign-in-alt mr-2"></i> Sunucuya Git
                        </a>
                    <?php else: ?>
                        <button type="submit" name="join_server" class="btn btn-primary w-full mb-3">
                            <i class="fas fa-plus-circle mr-2"></i> Sunucuya Katıl
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <button type="submit" name="join_server" class="btn btn-primary w-full mb-3">
                        <i class="fas fa-sign-in-alt mr-2"></i> Giriş Yap ve Katıl
                    </button>
                <?php endif; ?>
            </form>
            <a href="directmessages.php" class="btn btn-secondary w-full text-center block">
                <i class="fas fa-times-circle mr-2"></i> İptal
            </a>
        </div>
    </div>
</body>
</html>