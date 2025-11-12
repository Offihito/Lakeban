<?php
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

// Hata raporlamayı etkinleştir
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// UTF-8 başlıklarını ayarla
header('Content-Type: text/html; charset=UTF-8');
$conn->set_charset("utf8mb4");

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
    header("Location: /login.php");
    exit;
}

// *** BAŞLIK İÇİN KULLANICI BİLGİLERİNİ ÇEKME BAŞLANGICI ***
$profilePicture = null;
$username = null;
$userFlair = null; // No default flair
$isLoggedIn = isset($_SESSION['user_id']);
if ($isLoggedIn) {
    try {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("SET NAMES utf8mb4");

        // Performans İyileştirmesi: Kullanıcı bilgilerini tek bir sorguda çekme
        $stmt_user_data = $db->prepare("
            SELECT u.username, up.avatar_url, luf.flair 
            FROM users u 
            LEFT JOIN user_profiles up ON u.id = up.user_id 
            LEFT JOIN lakealt_user_flairs luf ON u.id = luf.user_id AND luf.lakealt_id = ?
            WHERE u.id = ?
        ");
        if ($stmt_user_data) {
            $stmt_user_data->execute([$_GET['lakealt_id'], $_SESSION['user_id']]);
            $result_user_data = $stmt_user_data->fetch(PDO::FETCH_ASSOC);
            if ($result_user_data) {
                $username = htmlspecialchars($result_user_data['username'] ?? '', ENT_QUOTES, 'UTF-8');
                $profilePicture = htmlspecialchars($result_user_data['avatar_url'] ?? '', ENT_QUOTES, 'UTF-8');
                $userFlair = $result_user_data['flair'] ? htmlspecialchars($result_user_data['flair'], ENT_QUOTES, 'UTF-8') : null;
            }
            $_SESSION['username'] = $username;
        } else {
            error_log("PDO prepare hatası: SELECT username, avatar_url, flair");
        }
    } catch (PDOException $e) {
        error_log("Veritabanı bağlantı hatası (kullanıcı bilgileri): " . $e->getMessage());
    }
}
// *** BAŞLIK İÇİN KULLANICI BİLGİLERİNİ ÇEKME SONU ***

$defaultProfilePicture = "https://styles.redditmedia.com/t5_5qd327/styles/profileIcon_snooe2e65a47-7832-46ff-84b6-47f4bf4d8301-headshot.png";

$lakealt_id = isset($_GET['lakealt_id']) ? (int)$_GET['lakealt_id'] : 0;

// Lakealt bilgilerini çek
$cache_file = 'cache/lakealt_' . $lakealt_id . '.json';
$cache_lifetime = 3600; // 1 saat

$lakealt = false;
if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
    $lakealt = json_decode(file_get_contents($cache_file), true);
}

if (!$lakealt) {
    $sql = "SELECT name, description, banner_url, avatar_url, theme_color, rules, creator_id, tags FROM lakealts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL prepare hatası: " . $conn->error);
        die($translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.");
    }
    $stmt->bind_param("i", $lakealt_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lakealt = $result->fetch_assoc();
    $stmt->close();

    if ($lakealt) {
        if (!is_dir('cache')) {
            mkdir('cache', 0755, true);
        }
        file_put_contents($cache_file, json_encode($lakealt));
    }
}

if (!$lakealt) {
    header("Location: /");
    exit;
}

// Moderatörleri çek
$sql = "SELECT u.id, u.username FROM lakealt_moderators lm JOIN users u ON lm.user_id = u.id WHERE lm.lakealt_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die($translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.");
}
$stmt->bind_param("i", $lakealt_id);
$stmt->execute();
$result = $stmt->get_result();
$moderators = [];
while ($row = $result->fetch_assoc()) {
    $moderators[] = $row;
}
$stmt->close();

// Kullanıcı yetkilendirme kontrolü
$isModerator = false;
foreach ($moderators as $mod) {
    if ($mod['id'] == $_SESSION['user_id']) {
        $isModerator = true;
        break;
    }
}

if ($lakealt['creator_id'] != $_SESSION['user_id'] && !$isModerator) {
    header("Location: /");
    exit;
}

// CSRF token oluşturma
if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time'] > 1800)) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF kontrolü
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'] || (time() - $_SESSION['csrf_token_time'] > 1800)) {
        $errors[] = $translations['customize_lakealt']['errors']['csrf_invalid'] ?? "CSRF token geçersiz veya süresi dolmuş.";
    } else {
        if (isset($_POST['update_general'])) {
            if ($lakealt['creator_id'] != $_SESSION['user_id']) {
                $errors[] = $translations['customize_lakealt']['errors']['no_general_settings_permission'] ?? "Genel ayarları güncelleme yetkiniz yok.";
            } else {
                $description = trim($_POST['description']);
                $theme_color = trim($_POST['theme_color']);
                $tags = isset($_POST['tags']) ? implode(',', array_map('trim', explode(',', $_POST['tags']))) : '';
                $banner_path = $lakealt['banner_url'];
                $avatar_path = $lakealt['avatar_url'];

                // Doğrulama
                if (empty($description)) {
                    $errors[] = $translations['customize_lakealt']['errors']['description_required'] ?? "Açıklama gereklidir.";
                }
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_color)) {
                    $errors[] = $translations['customize_lakealt']['errors']['invalid_hex_color'] ?? "Geçerli bir hex renk kodu seçin.";
                }

                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $errors[] = $translations['customize_lakealt']['errors']['upload_dir_error'] ?? "Uploads dizini mevcut değil veya yazılabilir değil. Lütfen dizin izinlerini kontrol edin.";
                }

                // Banner yükleme
                if (isset($_FILES['banner']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
                    $image_info = getimagesize($_FILES['banner']['tmp_name']);
                    if ($image_info === false) {
                        $errors[] = $translations['customize_lakealt']['errors']['invalid_banner_image'] ?? "Yüklenen banner dosyası geçerli bir görüntü değil.";
                    } else {
                        $file_name = basename($_FILES['banner']['name']);
                        $file_path = $upload_dir . time() . '_' . uniqid() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $file_name);
                        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_types = ['jpg', 'jpeg', 'png'];
                        $mime_type = mime_content_type($_FILES['banner']['tmp_name']);
                        $allowed_mimes = ['image/jpeg', 'image/png'];

                        if (!in_array($file_type, $allowed_types) || !in_array($mime_type, $allowed_mimes)) {
                            $errors[] = $translations['customize_lakealt']['errors']['invalid_banner_type'] ?? "Banner için sadece JPG, JPEG veya PNG dosyaları yüklenebilir.";
                        } elseif ($_FILES['banner']['size'] > 5242880) {
                            $errors[] = $translations['customize_lakealt']['errors']['banner_size_limit'] ?? "Banner dosyası 5MB'dan büyük olamaz.";
                        } else {
                            if (move_uploaded_file($_FILES['banner']['tmp_name'], $file_path)) {
                                if ($banner_path && file_exists($banner_path) && strpos($banner_path, 'placeholder') === false) {
                                    unlink($banner_path);
                                }
                                $banner_path = $file_path;
                            } else {
                                $errors[] = $translations['customize_lakealt']['errors']['banner_upload_failed'] ?? "Banner yüklenemedi. Lütfen tekrar deneyin.";
                            }
                        }
                    }
                }

                // Avatar yükleme
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $image_info = getimagesize($_FILES['avatar']['tmp_name']);
                    if ($image_info === false) {
                        $errors[] = $translations['customize_lakealt']['errors']['invalid_avatar_image'] ?? "Yüklenen avatar dosyası geçerli bir görüntü değil.";
                    } else {
                        $file_name = basename($_FILES['avatar']['name']);
                        $file_path = $upload_dir . time() . '_avatar_' . uniqid() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $file_name);
                        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_types = ['jpg', 'jpeg', 'png'];
                        $mime_type = mime_content_type($_FILES['avatar']['tmp_name']);
                        $allowed_mimes = ['image/jpeg', 'image/png'];

                        if (!in_array($file_type, $allowed_types) || !in_array($mime_type, $allowed_mimes)) {
                            $errors[] = $translations['customize_lakealt']['errors']['invalid_avatar_type'] ?? "Avatar için sadece JPG, JPEG veya PNG dosyaları yüklenebilir.";
                        } elseif ($_FILES['avatar']['size'] > 5242880) {
                            $errors[] = $translations['customize_lakealt']['errors']['avatar_size_limit'] ?? "Avatar dosyası 5MB'dan büyük olamaz.";
                        } else {
                            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $file_path)) {
                                if ($avatar_path && file_exists($avatar_path) && strpos($avatar_path, 'placeholder') === false) {
                                    unlink($avatar_path);
                                }
                                $avatar_path = $file_path;
                            } else {
                                $errors[] = $translations['customize_lakealt']['errors']['avatar_upload_failed'] ?? "Avatar yüklenemedi. Lütfen tekrar deneyin.";
                            }
                        }
                    }
                }

                if (empty($errors)) {
                    $sql = "UPDATE lakealts SET description = ?, banner_url = ?, avatar_url = ?, theme_color = ?, tags = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        error_log("SQL prepare hatası: " . $conn->error);
                        $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                    } else {
                        $stmt->bind_param("sssssi", $description, $banner_path, $avatar_path, $theme_color, $tags, $lakealt_id);
                        if ($stmt->execute()) {
                            $success = $translations['customize_lakealt']['success']['general_settings_updated'] ?? "Genel ayarlar güncellendi.";
                            if (file_exists($cache_file)) {
                                unlink($cache_file);
                            }
                            $sql_re_fetch = "SELECT name, description, banner_url, avatar_url, theme_color, rules, creator_id, tags FROM lakealts WHERE id = ?";
                            $stmt_re_fetch = $conn->prepare($sql_re_fetch);
                            $stmt_re_fetch->bind_param("i", $lakealt_id);
                            $stmt_re_fetch->execute();
                            $result_re_fetch = $stmt_re_fetch->get_result();
                            $lakealt = $result_re_fetch->fetch_assoc();
                            $stmt_re_fetch->close();
                        } else {
                            error_log("SQL execute hatası: " . $stmt->error);
                            $errors[] = $translations['customize_lakealt']['errors']['db_update_error'] ?? "Veritabanı güncelleme hatası.";
                        }
                        $stmt->close();
                    }
                }
            }
        } elseif (isset($_POST['add_rule'])) {
            if ($lakealt['creator_id'] != $_SESSION['user_id'] && !$isModerator) {
                $errors[] = $translations['customize_lakealt']['errors']['no_rule_permission'] ?? "Kural ekleme yetkiniz yok.";
            } else {
                $rule_text = trim($_POST['rule_text']);
                if (empty($rule_text)) {
                    $errors[] = $translations['customize_lakealt']['errors']['rule_text_required'] ?? "Kural metni gereklidir.";
                } else {
                    $current_rules = $lakealt['rules'] ? explode("\n", $lakealt['rules']) : [];
                    $current_rules[] = $rule_text;
                    $new_rules = implode("\n", array_filter($current_rules));
                    $sql = "UPDATE lakealts SET rules = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        error_log("SQL prepare hatası: " . $conn->error);
                        $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                    } else {
                        $stmt->bind_param("si", $new_rules, $lakealt_id);
                        if ($stmt->execute()) {
                            $success = $translations['customize_lakealt']['success']['rule_added'] ?? "Kural eklendi.";
                            if (file_exists($cache_file)) {
                                unlink($cache_file);
                            }
                            header("Location: /customize_lakealt.php?lakealt_id=" . $lakealt_id);
                            exit;
                        } else {
                            error_log("SQL execute hatası: " . $stmt->error);
                            $errors[] = $translations['customize_lakealt']['errors']['rule_add_failed'] ?? "Kural eklenemedi.";
                        }
                        $stmt->close();
                    }
                }
            }
        } elseif (isset($_POST['delete_rule'])) {
            if ($lakealt['creator_id'] != $_SESSION['user_id'] && !$isModerator) {
                $errors[] = $translations['customize_lakealt']['errors']['no_rule_permission'] ?? "Kural silme yetkiniz yok.";
            } else {
                $rule_index = (int)$_POST['rule_index'];
                $current_rules = $lakealt['rules'] ? explode("\n", $lakealt['rules']) : [];
                if (isset($current_rules[$rule_index])) {
                    unset($current_rules[$rule_index]);
                    $new_rules = implode("\n", array_filter($current_rules));
                    $sql = "UPDATE lakealts SET rules = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        error_log("SQL prepare hatası: " . $conn->error);
                        $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                    } else {
                        $stmt->bind_param("si", $new_rules, $lakealt_id);
                        if ($stmt->execute()) {
                            $success = $translations['customize_lakealt']['success']['rule_deleted'] ?? "Kural silindi.";
                            if (file_exists($cache_file)) {
                                unlink($cache_file);
                            }
                            header("Location: /customize_lakealt.php?lakealt_id=" . $lakealt_id);
                            exit;
                        } else {
                            error_log("SQL execute hatası: " . $stmt->error);
                            $errors[] = $translations['customize_lakealt']['errors']['rule_delete_failed'] ?? "Kural silinemedi.";
                        }
                        $stmt->close();
                    }
                } else {
                    $errors[] = $translations['customize_lakealt']['errors']['invalid_rule_index'] ?? "Geçersiz kural indeksi.";
                }
            }
        } elseif (isset($_POST['add_moderator'])) {
            if ($lakealt['creator_id'] != $_SESSION['user_id']) {
                $errors[] = $translations['customize_lakealt']['errors']['no_moderator_add_permission'] ?? "Moderatör ekleme yetkiniz yok.";
            } else {
                $username = trim($_POST['moderator_username']);
                $sql = "SELECT id FROM users WHERE username = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    error_log("SQL prepare hatası: " . $conn->error);
                    $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                } else {
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $user = $result->fetch_assoc();
                        $user_id = $user['id'];
                        if ($user_id == $_SESSION['user_id']) {
                            $errors[] = $translations['customize_lakealt']['errors']['self_moderator_add'] ?? "Kendinizi moderatör olarak ekleyemezsiniz.";
                        } else {
                            $sql = "SELECT 1 FROM lakealt_moderators WHERE user_id = ? AND lakealt_id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("ii", $user_id, $lakealt_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result->num_rows == 0) {
                                $sql = "INSERT INTO lakealt_moderators (user_id, lakealt_id) VALUES (?, ?)";
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param("ii", $user_id, $lakealt_id);
                                if ($stmt->execute()) {
                                    $success = $translations['customize_lakealt']['success']['moderator_added'] ?? "Moderatör eklendi.";
                                    if (file_exists($cache_file)) {
                                        unlink($cache_file);
                                    }
                                    header("Location: /customize_lakealt.php?lakealt_id=" . $lakealt_id);
                                    exit;
                                } else {
                                    error_log("SQL execute hatası: " . $stmt->error);
                                    $errors[] = $translations['customize_lakealt']['errors']['moderator_add_failed'] ?? "Moderatör eklenemedi.";
                                }
                            } else {
                                $errors[] = $translations['customize_lakealt']['errors']['already_moderator'] ?? "Bu kullanıcı zaten moderatör.";
                            }
                        }
                    } else {
                        $errors[] = $translations['customize_lakealt']['errors']['user_not_found'] ?? "Kullanıcı bulunamadı.";
                    }
                    $stmt->close();
                }
            }
        } elseif (isset($_POST['remove_moderator'])) {
            if ($lakealt['creator_id'] != $_SESSION['user_id']) {
                $errors[] = $translations['customize_lakealt']['errors']['no_moderator_remove_permission'] ?? "Moderatör kaldırma yetkiniz yok.";
            } else {
                $user_id = (int)$_POST['user_id'];
                if ($user_id == $_SESSION['user_id']) {
                    $errors[] = $translations['customize_lakealt']['errors']['self_moderator_remove'] ?? "Kendinizi moderatörlükten çıkaramazsınız.";
                } else {
                    $sql = "DELETE FROM lakealt_moderators WHERE user_id = ? AND lakealt_id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        error_log("SQL prepare hatası: " . $conn->error);
                        $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                    } else {
                        $stmt->bind_param("ii", $user_id, $lakealt_id);
                        if ($stmt->execute()) {
                            $success = $translations['customize_lakealt']['success']['moderator_removed'] ?? "Moderatör kaldırıldı.";
                            if (file_exists($cache_file)) {
                                unlink($cache_file);
                            }
                            header("Location: /customize_lakealt.php?lakealt_id=" . $lakealt_id);
                            exit;
                        } else {
                            error_log("SQL execute hatası: " . $stmt->error);
                            $errors[] = $translations['customize_lakealt']['errors']['moderator_remove_failed'] ?? "Moderatör kaldırılamadı.";
                        }
                        $stmt->close();
                    }
                }
            }
        } elseif (isset($_POST['update_flair'])) {
            $sql = "SELECT 1 FROM lakealt_members WHERE lakealt_id = ? AND user_id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("SQL prepare hatası: " . $conn->error);
                $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
            } else {
                $stmt->bind_param("ii", $lakealt_id, $_SESSION['user_id']);
                $stmt->execute();
                $is_member = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();

                if (!$is_member) {
                    $errors[] = $translations['customize_lakealt']['errors']['not_lakealt_member'] ?? "Flair ayarlamak için bu lakealt'a üye olmalısınız.";
                } else {
                    $flair = trim($_POST['user_flair']);
                    if (strlen($flair) > 50) {
                        $errors[] = $translations['customize_lakealt']['errors']['flair_too_long'] ?? "Flair 50 karakterden uzun olamaz.";
                    } elseif ($flair === '') {
                        $sql = "DELETE FROM lakealt_user_flairs WHERE user_id = ? AND lakealt_id = ?";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            error_log("SQL prepare hatası: " . $conn->error);
                            $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                        } else {
                            $stmt->bind_param("ii", $_SESSION['user_id'], $lakealt_id);
                            if ($stmt->execute()) {
                                $success = $translations['customize_lakealt']['success']['flair_removed'] ?? "Flair kaldırıldı.";
                                header("Location: /customize_lakealt.php?lakealt_id=" . $lakealt_id);
                                exit;
                            } else {
                                error_log("SQL execute hatası: " . $stmt->error);
                                $errors[] = $translations['customize_lakealt']['errors']['flair_remove_failed'] ?? "Flair kaldırılamadı.";
                            }
                            $stmt->close();
                        }
                    } else {
                        $sql = "INSERT INTO lakealt_user_flairs (user_id, lakealt_id, flair) VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE flair = ?";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            error_log("SQL prepare hatası: " . $conn->error);
                            $errors[] = $translations['customize_lakealt']['errors']['server_error'] ?? "Sunucu hatası. Lütfen daha sonra tekrar deneyin.";
                        } else {
                            $stmt->bind_param("iiss", $_SESSION['user_id'], $lakealt_id, $flair, $flair);
                            if ($stmt->execute()) {
                                $success = $translations['customize_lakealt']['success']['flair_updated'] ?? "Flair güncellendi.";
                                header("Location: /customize_lakealt.php?lakealt_id=" . $lakealt_id);
                                exit;
                            } else {
                                error_log("SQL execute hatası: " . $stmt->error);
                                $errors[] = $translations['customize_lakealt']['errors']['flair_update_failed'] ?? "Flair güncellenemedi.";
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
}

// Üye olunan lakealtları çek
$joined_lakealts = [];
$sql_joined_lakealts = "SELECT l.name FROM lakealt_members lm JOIN lakealts l ON lm.lakealt_id = l.id WHERE lm.user_id = ?";
$stmt_joined_lakealts = $conn->prepare($sql_joined_lakealts);
if ($stmt_joined_lakealts) {
    $stmt_joined_lakealts->bind_param("i", $_SESSION['user_id']);
    $stmt_joined_lakealts->execute();
    $result_joined_lakealts = $stmt_joined_lakealts->get_result();
    while ($row_joined = $result_joined_lakealts->fetch_assoc()) {
        $joined_lakealts[] = $row_joined['name'];
    }
    $stmt_joined_lakealts->close();
} else {
    error_log("SQL prepare hatası (katılınan lakealtlar): " . $conn->error);
}

// Moderatör olunan lakealtları çek
$moderated_lakealts = [];
$sql_moderated_lakealts = "SELECT l.name FROM lakealt_moderators lm JOIN lakealts l ON lm.lakealt_id = l.id WHERE lm.user_id = ?";
$stmt_moderated_lakealts = $conn->prepare($sql_moderated_lakealts);
if ($stmt_moderated_lakealts) {
    $stmt_moderated_lakealts->bind_param("i", $_SESSION['user_id']);
    $stmt_moderated_lakealts->execute();
    $result_moderated_lakealts = $stmt_moderated_lakealts->get_result();
    while ($row_moderated = $result_moderated_lakealts->fetch_assoc()) {
        $moderated_lakealts[] = $row_moderated['name'];
    }
    $stmt_moderated_lakealts->close();
} else {
    error_log("SQL prepare hatası (modere edilen lakealtlar): " . $conn->error);
}

// Topluluk İstatistikleri
$member_count = 0;
$post_count = 0;
$sql_stats_members = "SELECT COUNT(*) as member_count FROM lakealt_members WHERE lakealt_id = ?";
$stmt_stats_members = $conn->prepare($sql_stats_members);
if ($stmt_stats_members) {
    $stmt_stats_members->bind_param("i", $lakealt_id);
    $stmt_stats_members->execute();
    $member_count = $stmt_stats_members->get_result()->fetch_assoc()['member_count'];
    $stmt_stats_members->close();
} else {
    error_log("SQL prepare hatası (üye sayısı): " . $conn->error);
}

$sql_stats_posts = "SELECT COUNT(*) as post_count FROM posts WHERE lakealt_id = ?";
$stmt_stats_posts = $conn->prepare($sql_stats_posts);
if ($stmt_stats_posts) {
    $stmt_stats_posts->bind_param("i", $lakealt_id);
    $stmt_stats_posts->execute();
    $post_count = $stmt_stats_posts->get_result()->fetch_assoc()['post_count'];
    $stmt_stats_posts->close();
} else {
    error_log("SQL prepare hatası (gönderi sayısı): " . $conn->error);
}

// Yardımcı fonksiyon: Null güvenli htmlspecialchars
function safe_html($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo str_replace('{name}', safe_html($lakealt['name']), $translations['customize_lakealt']['title'] ?? 'Lakealt Özelleştir - l/' . safe_html($lakealt['name'])); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>

    <style>
        :root {
            --primary-bg: #202020;
            --secondary-bg: #181818;
            --accent-color: <?php echo safe_html($lakealt['theme_color'] ?? '#3CB371'); ?>;
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

        .create-post-btn {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .create-post-btn:hover {
            background-color: #2e8b57;
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

        .sidebar-left {
            width: 260px;
            background-color: var(--secondary-bg);
            border-right: 1px solid var(--border-color);
            position: sticky;
            top: 64px;
            height: calc(100vh - 64px);
            overflow-y: auto;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            padding: 1rem;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            border-radius: 6px;
        }

        .sidebar-nav a:hover {
            color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
        }

        .sidebar-nav a.active {
            color: var(--accent-color);
            background-color: rgba(60, 179, 113, 0.1);
        }

        .sidebar-nav svg {
            width: 20px;
            height: 20px;
            margin-right: 0.5rem;
        }

        .sidebar-section {
            margin: 1rem 0;
            padding: 0 1rem;
        }

        .sidebar-section h3 {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .sidebar-nav .sub-item {
            padding-left: 2rem;
        }

        .content {
            flex: 1;
            max-width: 800px;
        }

        .section {
            background-color: var(--secondary-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .section h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            background-color: var(--primary-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.5rem;
            color: var(--text-primary);
        }

        .form-group input[type="file"] {
            padding: 0.25rem;
            background-color: transparent;
            border: none;
        }

        .form-group input[type="file"]::-webkit-file-upload-button {
            background-color: var(--accent-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .form-group input[type="file"]::-webkit-file-upload-button:hover {
            background-color: #2e8b57;
        }

        .form-group input[type="color"] {
            padding: 0;
            height: 40px;
            width: 80px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .submit-btn {
            background-color: var(--accent-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .submit-btn:hover {
            background-color: #2e8b57;
        }

        .delete-btn {
            background-color: #ED4245;
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .delete-btn:hover {
            background-color: #c13535;
        }

        .error {
            color: #ED4245;
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
        }

        .success {
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
        }

        .rule-item,
        .moderator-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .rule-item:last-child,
        .moderator-item:last-child {
            border-bottom: none;
        }

        @media (max-width: 1024px) {
            .container {
                flex-direction: column;
            }
            .sidebar-left {
                width: 100%;
                position: static;
            }
            .content {
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .hamburger-btn {
                display: block;
            }
            .header-search,
            .header-actions .header-btn:not(.hamburger-btn) {
                display: none;
            }
            .sidebar-left {
                position: fixed;
                width: 260px;
                top: 0;
                left: 0;
                height: 100%;
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
            }
            .sidebar-left.active {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <?php include 'sidebar.php'; ?>

        <div class="content">
            <h1 class="text-2xl font-semibold mb-4"><?php echo str_replace('{name}', safe_html($lakealt['name']), $translations['customize_lakealt']['customize_title'] ?? 'Özelleştir'); ?></h1>

            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <p class="error"><?php echo safe_html($error); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($success): ?>
                <p class="success"><?php echo safe_html($success); ?></p>
            <?php endif; ?>

            <div class="section">
                <h2><?php echo $translations['customize_lakealt']['community_stats'] ?? 'Topluluk İstatistikleri'; ?></h2>
                <p><?php echo str_replace('{count}', number_format($member_count), $translations['customize_lakealt']['member_count'] ?? 'Üye Sayısı: ' . number_format($member_count)); ?></p>
                <p><?php echo str_replace('{count}', number_format($post_count), $translations['customize_lakealt']['post_count'] ?? 'Gönderi Sayısı: ' . number_format($post_count)); ?></p>
            </div>

            <div class="section">
                <h2><?php echo $translations['customize_lakealt']['general_settings'] ?? 'Genel Ayarlar'; ?></h2>
                <?php if ($lakealt['creator_id'] == $_SESSION['user_id']): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo safe_html($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="update_general" value="1">
                        <div class="form-group">
                            <label for="description"><?php echo $translations['customize_lakealt']['description_label'] ?? 'Açıklama'; ?></label>
                            <textarea id="description" name="description"><?php echo safe_html($lakealt['description']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="banner"><?php echo $translations['customize_lakealt']['banner_label'] ?? 'Banner Resmi'; ?></label>
                            <input type="file" id="banner" name="banner" accept="image/jpeg,image/png">
                            <?php if ($lakealt['banner_url']): ?>
                                <p><?php echo $translations['customize_lakealt']['current'] ?? 'Mevcut'; ?>: <img src="<?php echo safe_html($lakealt['banner_url']); ?>" alt="Banner" style="max-width: 200px;"></p>
                            <?php else: ?>
                                <p><?php echo $translations['customize_lakealt']['no_file_selected'] ?? 'Seçilen dosya yok'; ?></p>
                            <?php endif; ?>
                            <div class="cropper-container hidden">
                                <img id="banner-preview-cropper" src="" alt="<?php echo $translations['customize_lakealt']['banner_preview'] ?? 'Banner Önizleme'; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="avatar"><?php echo $translations['customize_lakealt']['avatar_label'] ?? 'Avatar Resmi'; ?></label>
                            <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png">
                            <?php if ($lakealt['avatar_url']): ?>
                                <p><?php echo $translations['customize_lakealt']['current'] ?? 'Mevcut'; ?>: <img src="<?php echo safe_html($lakealt['avatar_url']); ?>" alt="Avatar" style="max-width: 80px;"></p>
                            <?php else: ?>
                                <p><?php echo $translations['customize_lakealt']['no_file_selected'] ?? 'Seçilen dosya yok'; ?></p>
                            <?php endif; ?>
                            <div class="cropper-container hidden">
                                <img id="avatar-preview-cropper" src="" alt="<?php echo $translations['customize_lakealt']['avatar_preview'] ?? 'Avatar Önizleme'; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="theme_color"><?php echo $translations['customize_lakealt']['theme_color_label'] ?? 'Tema Rengi'; ?></label>
                            <input type="color" id="theme_color" name="theme_color" value="<?php echo safe_html($lakealt['theme_color'] ?? '#3CB371'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="tags"><?php echo $translations['customize_lakealt']['tags_label'] ?? 'Etiketler (Virgülle Ayırın)'; ?></label>
                            <input type="text" id="tags" name="tags" placeholder="<?php echo $translations['customize_lakealt']['tags_placeholder'] ?? 'Ör: Teknoloji, Oyun, Sanat'; ?>" value="<?php echo safe_html($lakealt['tags'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="submit-btn" aria-label="<?php echo $translations['customize_lakealt']['save_button'] ?? 'Kaydet'; ?>"><?php echo $translations['customize_lakealt']['save_button'] ?? 'Kaydet'; ?></button>
                    </form>
                <?php else: ?>
                    <p class="text-red-400"><?php echo $translations['customize_lakealt']['permission_denied']['general_settings'] ?? 'Genel ayarları düzenlemek için lakealt\'ın yaratıcısı olmanız gerekmektedir.'; ?></p>
                <?php endif; ?>
            </div>

            <div class="section">
                <h2><?php echo $translations['customize_lakealt']['rules'] ?? 'Kurallar'; ?></h2>
                <?php if ($lakealt['creator_id'] == $_SESSION['user_id'] || $isModerator): ?>
                    <div class="form-group">
                        <label for="rule_search"><?php echo $translations['customize_lakealt']['rule_search_label'] ?? 'Kural Ara'; ?></label>
                        <input type="text" id="rule_search" placeholder="<?php echo $translations['customize_lakealt']['rule_search_placeholder'] ?? 'Kural metninde ara...'; ?>">
                    </div>
                    <div id="rules-list">
                        <?php
                        $rules = $lakealt['rules'] ? explode("\n", $lakealt['rules']) : [];
                        if (!empty($rules)):
                        ?>
                            <?php foreach ($rules as $index => $rule): ?>
                                <?php if (trim($rule)): ?>
                                    <div class="rule-item">
                                        <span><?php echo safe_html(trim($rule)); ?></span>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo safe_html($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="rule_index" value="<?php echo $index; ?>">
                                            <input type="hidden" name="delete_rule" value="1">
                                            <button type="submit" class="delete-btn"><?php echo $translations['customize_lakealt']['delete_rule_button'] ?? 'Sil'; ?></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php echo $translations['customize_lakealt']['no_rules'] ?? 'Henüz kural eklenmemiş.'; ?></p>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?php echo safe_html($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="add_rule" value="1">
                        <div class="form-group">
                            <label for="rule_text"><?php echo $translations['customize_lakealt']['new_rule_label'] ?? 'Yeni Kural'; ?></label>
                            <textarea id="rule_text" name="rule_text"></textarea>
                        </div>
                        <button type="submit" class="submit-btn"><?php echo $translations['customize_lakealt']['add_rule_button'] ?? 'Kural Ekle'; ?></button>
                    </form>
                <?php else: ?>
                    <p class="text-red-400"><?php echo $translations['customize_lakealt']['permission_denied']['rules'] ?? 'Kuralları düzenlemek için lakealt\'ın yaratıcısı veya moderatörü olmanız gerekmektedir.'; ?></p>
                <?php endif; ?>
            </div>

            <div class="section">
                <h2><?php echo $translations['customize_lakealt']['moderators'] ?? 'Moderatörler'; ?></h2>
                <?php if ($lakealt['creator_id'] == $_SESSION['user_id']): ?>
                    <?php if (!empty($moderators)): ?>
                        <div class="form-group">
                            <label for="moderator_search"><?php echo $translations['customize_lakealt']['moderator_search_label'] ?? 'Moderatör Ara'; ?></label>
                            <input type="text" id="moderator_search" placeholder="<?php echo $translations['customize_lakealt']['moderator_search_placeholder'] ?? 'Moderatör ara...'; ?>">
                        </div>
                        <div id="moderators-list">
                            <?php foreach ($moderators as $moderator): ?>
                                <div class="moderator-item">
                                    <span><?php echo safe_html($moderator['username']); ?></span>
                                    <?php if ($moderator['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo safe_html($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $moderator['id']; ?>">
                                            <input type="hidden" name="remove_moderator" value="1">
                                            <button type="submit" class="delete-btn"><?php echo $translations['customize_lakealt']['remove_moderator_button'] ?? 'Kaldır'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p><?php echo $translations['customize_lakealt']['no_moderators'] ?? 'Henüz moderatör eklenmemiş.'; ?></p>
                    <?php endif; ?>
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?php echo safe_html($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="add_moderator" value="1">
                        <div class="form-group">
                            <label for="moderator_username"><?php echo $translations['customize_lakealt']['moderator_username_label'] ?? 'Kullanıcı Adı'; ?></label>
                            <input type="text" id="moderator_username" name="moderator_username" aria-label="<?php echo $translations['customize_lakealt']['moderator_username_label'] ?? 'Moderatör kullanıcı adı'; ?>">
                        </div>
                        <button type="submit" class="submit-btn"><?php echo $translations['customize_lakealt']['add_moderator_button'] ?? 'Moderatör Ekle'; ?></button>
                    </form>
                <?php else: ?>
                    <p class="text-red-400"><?php echo $translations['customize_lakealt']['permission_denied']['moderators'] ?? 'Moderatörleri yönetmek için lakealt\'ın yaratıcısı olmanız gerekmektedir.'; ?></p>
                <?php endif; ?>
            </div>

            <div class="section">
                <h2><?php echo $translations['customize_lakealt']['flair_section'] ?? 'Kendi Flair\'inizi Ayarlayın'; ?></h2>
                <?php
                $current_flair = null;
                $sql = "SELECT flair FROM lakealt_user_flairs WHERE user_id = ? AND lakealt_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $_SESSION['user_id'], $lakealt_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $current_flair = $row['flair'];
                    }
                    $stmt->close();
                } else {
                    error_log("SQL prepare hatası (flair): " . $conn->error);
                }
                ?>
                <form method="POST" class="mt-4">
                    <input type="hidden" name="csrf_token" value="<?php echo safe_html($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="update_flair" value="1">
                    <div class="form-group">
                        <label for="user_flair"><?php echo $translations['customize_lakealt']['flair_label'] ?? 'Flair (Boş bırakarak kaldırabilirsiniz)'; ?></label>
                        <input type="text" id="user_flair" name="user_flair" value="<?php echo safe_html($current_flair ?? ''); ?>" placeholder="<?php echo $translations['customize_lakealt']['flair_placeholder'] ?? 'Ör: Uzman, Hayran, Yeni Üye'; ?>" maxlength="50">
                    </div>
                    <button type="submit" class="submit-btn"><?php echo $translations['customize_lakealt']['save_flair_button'] ?? 'Flair\'i Kaydet'; ?></button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.querySelector('.hamburger-btn')?.addEventListener('click', () => {
            document.querySelector('.sidebar-left').classList.toggle('active');
        });

        document.getElementById('theme_color')?.addEventListener('input', function() {
            document.documentElement.style.setProperty('--accent-color', this.value);
        });

        $('#rule_search').on('input', function() {
            const query = $(this).val().toLowerCase();
            $('#rules-list .rule-item').each(function() {
                const ruleText = $(this).find('span').text().toLowerCase();
                $(this).toggle(ruleText.includes(query));
            });
        });

        $('#moderator_search').on('input', function() {
            const query = $(this).val().toLowerCase();
            $('#moderators-list .moderator-item').each(function() {
                const moderatorText = $(this).find('span').text().toLowerCase();
                $(this).toggle(moderatorText.includes(query));
            });
        });

        const bannerInput = document.getElementById('banner');
        const avatarInput = document.getElementById('avatar');
        const bannerCropperPreview = document.getElementById('banner-preview-cropper');
        const avatarCropperPreview = document.getElementById('avatar-preview-cropper');

        let bannerCropper;
        let avatarCropper;

        if (bannerInput && bannerCropperPreview) {
            bannerInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        bannerCropperPreview.src = event.target.result;
                        if (bannerCropper) {
                            bannerCropper.destroy();
                        }
                        bannerCropper = new Cropper(bannerCropperPreview, {
                            aspectRatio: 16 / 9,
                            viewMode: 1,
                            ready: function () {
                                bannerCropperPreview.parentElement.classList.remove('hidden');
                            }
                        });
                    };
                    reader.readAsDataURL(file);
                } else {
                    if (bannerCropper) {
                        bannerCropper.destroy();
                        bannerCropperPreview.parentElement.classList.add('hidden');
                    }
                }
            });
        }

        if (avatarInput && avatarCropperPreview) {
            avatarInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        avatarCropperPreview.src = event.target.result;
                        if (avatarCropper) {
                            avatarCropper.destroy();
                        }
                        avatarCropper = new Cropper(avatarCropperPreview, {
                            aspectRatio: 1 / 1,
                            viewMode: 1,
                            ready: function () {
                                avatarCropperPreview.parentElement.classList.remove('hidden');
                            }
                        });
                    };
                    reader.readAsDataURL(file);
                } else {
                    if (avatarCropper) {
                        avatarCropper.destroy();
                        avatarCropperPreview.parentElement.classList.add('hidden');
                    }
                }
            });
        }
    </script>
</body>
</html>