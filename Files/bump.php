<?php
session_start();
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'])) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz istek.']);
        exit;
    }

    $server_id = $_POST['server_id'];
    $user_id = $_SESSION['user_id'];

    // Sunucunun varlığını ve kullanıcının yetkisini kontrol et
    $stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ? AND show_in_community = 1");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch();

    if (!$server) {
        echo json_encode(['success' => false, 'message' => 'Sunucu bulunamadı veya toplulukta gösterilemez.']);
        exit;
    }

    // Kullanıcının sunucu sahibi veya yetkili olup olmadığını kontrol et
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM user_roles ur 
        JOIN roles r ON ur.role_id = r.id 
        WHERE ur.user_id = ? AND ur.server_id = ? AND (r.permissions LIKE '%manage_server%' OR ? = ?)
    ");
    $stmt->execute([$user_id, $server_id, $user_id, $server['owner_id']]);
    $has_permission = $stmt->fetchColumn() > 0;

    if (!$has_permission) {
        echo json_encode(['success' => false, 'message' => 'Bu sunucuyu bumplama yetkiniz yok.']);
        exit;
    }

    // Son bumplama zamanını kontrol et (2 saat cooldown)
    $stmt = $db->prepare("SELECT last_bumped_at FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $last_bumped = $stmt->fetchColumn();

    $cooldown_hours = 2;
    $current_time = new DateTime();
    if ($last_bumped) {
        $last_bumped_time = new DateTime($last_bumped);
        $interval = $current_time->diff($last_bumped_time);
        $hours_diff = ($interval->days * 24) + $interval->h;

        if ($hours_diff < $cooldown_hours) {
            $remaining_minutes = ($cooldown_hours * 60) - (($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i);
            echo json_encode(['success' => false, 'message' => "Bu sunucuyu tekrar bumplayabilmek için $remaining_minutes dakika beklemelisiniz."]);
            exit;
        }
    }

    // Sunucuyu bumpla
    $stmt = $db->prepare("UPDATE servers SET last_bumped_at = NOW(), bump_count = bump_count + 1 WHERE id = ?");
    $stmt->execute([$server_id]);

    echo json_encode(['success' => true, 'message' => 'Sunucu başarıyla bumplandı!']);
} catch (PDOException $e) {
    error_log("Bump error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu, lütfen tekrar deneyin.']);
}
?>