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
    die("Unable to connect to the database. Please try again later.");
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Fetch user theme settings
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

// Get server ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid server ID.");
}
$server_id = (int)$_GET['id'];

// Check if user is the server owner
$stmt = $db->prepare("SELECT s.*, s.owner_id = :user_id AS is_owner FROM servers s WHERE s.id = :server_id");
$stmt->execute(['server_id' => $server_id, 'user_id' => $_SESSION['user_id']]);
$server_access = $stmt->fetch();

if (!$server_access || !$server_access['is_owner']) {
    header("Location: sayfabulunamadı");
    exit();
}

// Fetch server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Fetch bots created by the current user in the selected server
$bots = [];
try {
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.prefix, u.is_active, u.avatar_url, u.public_access
        FROM users u
        JOIN server_members sm ON u.id = sm.user_id
        WHERE u.is_bot = 1 AND u.created_by = ? AND sm.server_id = ?
        ORDER BY u.username
    ");
    $stmt->execute([$_SESSION['user_id'], $server_id]);
    $bots = $stmt->fetchAll();
    foreach ($bots as &$bot) {
        $bot['avatar_url'] = $bot['avatar_url'] ?? '/Uploads/avatars/default.png';
    }
} catch (PDOException $e) {
    error_log("Error fetching bots: " . $e->getMessage());
    $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Botlar getirilirken bir hata oluştu.'];
}

// Determine selected bot
$bot_id = null;
$bot_username = 'Bot Seçin';
$bot_prefix = '';
$bot_is_active = 1;
$bot_avatar = '/Uploads/avatars/default.png';
$bot_public_access = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bot_id'])) {
    $bot_id = filter_var($_POST['bot_id'], FILTER_VALIDATE_INT);
} elseif (isset($_GET['bot_id'])) {
    $bot_id = filter_var($_GET['bot_id'], FILTER_VALIDATE_INT);
}

if (!$bot_id && !empty($bots)) {
    $bot_id = $bots[0]['id'];
}

if ($bot_id && !empty($bots)) {
    $found_bot = false;
    foreach ($bots as $bot_option) {
        if ($bot_option['id'] == $bot_id) {
            $bot_username = $bot_option['username'];
            $bot_prefix = $bot_option['prefix'];
            $bot_is_active = $bot_option['is_active'];
            $bot_avatar = $bot_option['avatar_url'];
            $bot_public_access = $bot_option['public_access'];
            $found_bot = true;
            break;
        }
    }
    if (!$found_bot) {
        $bot_id = $bots[0]['id'];
        $bot_username = $bots[0]['username'];
        $bot_prefix = $bots[0]['prefix'];
        $bot_is_active = $bots[0]['is_active'];
        $bot_avatar = $bots[0]['avatar_url'];
        $bot_public_access = $bots[0]['public_access'];
        $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Seçilen bot bulunamadı. Varsayılan bota geçildi.'];
    }
}

// Fetch bot commands
$commands = [];
if ($bot_id) {
    try {
        $stmt = $db->prepare("SELECT id, command_name, response, response_file_path FROM bot_commands WHERE bot_id = ? ORDER BY command_name");
        $stmt->execute([$bot_id]);
        $commands = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching commands: " . $e->getMessage());
        $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Komutlar getirilirken bir hata oluştu.'];
    }
}

// Fetch channels and roles
$channels = [];
$server_roles = [];
if ($server_id) {
    try {
        $stmt = $db->prepare("SELECT id, name AS channel_name FROM channels WHERE server_id = ?");
        $stmt->execute([$server_id]);
        $channels = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT id, name FROM roles WHERE server_id = ? ORDER BY name ASC");
        $stmt->execute([$server_id]);
        $server_roles = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching channels/roles: " . $e->getMessage());
        $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Kanal veya roller getirilirken hata oluştu.'];
    }
}

// Fetch special commands
$special_commands = [];
if ($bot_id) {
    try {
        $stmt = $db->prepare("
            SELECT welcome_channel, welcome_message, goodbye_channel, goodbye_message, auto_role_id
            FROM bot_special_commands
            WHERE bot_id = ?
        ");
        $stmt->execute([$bot_id]);
        $special_commands = $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching special commands: " . $e->getMessage());
    }
}

// Fetch moderation settings
$moderation_settings = [];
if ($bot_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM bot_moderation_commands WHERE bot_id = ?");
        $stmt->execute([$bot_id]);
        $results = $stmt->fetchAll();
        foreach ($results as $row) {
            $moderation_settings[$row['command_name']] = $row;
            if (!empty($row['settings'])) {
                $moderation_settings[$row['command_name']]['settings'] = json_decode($row['settings'], true);
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching moderation settings: " . $e->getMessage());
        $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Moderasyon ayarları getirilirken hata oluştu.'];
    }
}

// Fetch bad word filter settings
$bad_word_settings = [];
if ($bot_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM bot_bad_word_filter WHERE bot_id = ?");
        $stmt->execute([$bot_id]);
        $bad_word_settings = $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching bad word settings: " . $e->getMessage());
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $server_access['is_owner']) {
    try {
        $db->beginTransaction();
        $audit_action = '';
        $audit_details = [];
        $redirect_section = $_POST['redirect_section'] ?? 'bot-overview';

        switch ($_POST['action']) {
            case 'add_command':
                $command_name = trim($_POST['command_name']);
                $response = trim($_POST['response']);
                if (empty($command_name)) throw new Exception('Komut adı zorunludur.');
                if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $command_name)) {
                    throw new Exception('Komut adı yalnızca harf, rakam ve alt çizgi içerebilir.');
                }

                $stmt = $db->prepare("SELECT id FROM bot_commands WHERE bot_id = ? AND command_name = ?");
                $stmt->execute([$bot_id, $command_name]);
                if ($stmt->fetch()) throw new Exception('Bu komut zaten mevcut.');

                $response_file_path = null;
                if (isset($_FILES['response_file']) && $_FILES['response_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['response_file'];
                    $upload_dir = 'Uploads/command_files/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm', 'application/pdf', 'text/plain'];
                    $max_size = 10 * 1024 * 1024;
                    $file_type = mime_content_type($file['tmp_name']);
                    if (!in_array($file_type, $allowed_types)) throw new Exception('İzin verilmeyen dosya türü.');
                    if ($file['size'] > $max_size) throw new Exception('Dosya boyutu çok büyük.');

                    $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $new_file_name = uniqid('cmd_file_', true) . '.' . $file_ext;
                    $target_file = $upload_dir . $new_file_name;
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $response_file_path = $target_file;
                    } else {
                        throw new Exception('Dosya yüklenirken hata oluştu.');
                    }
                }

                $stmt = $db->prepare("INSERT INTO bot_commands (bot_id, command_name, response, response_file_path) VALUES (?, ?, ?, ?)");
                $stmt->execute([$bot_id, $command_name, $response, $response_file_path]);
                $audit_action = 'add_bot_command';
                $audit_details = ['bot_id' => $bot_id, 'command_name' => $command_name];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Komut başarıyla eklendi.'];
                break;

            case 'edit_command':
                $command_id = filter_var($_POST['command_id'], FILTER_VALIDATE_INT);
                $command_name = trim($_POST['command_name']);
                $response = trim($_POST['response']);
                if (!$command_id || empty($command_name)) throw new Exception('Geçersiz komut ID veya eksik alanlar.');
                if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $command_name)) {
                    throw new Exception('Komut adı yalnızca harf, rakam ve alt çizgi içerebilir.');
                }

                $old_command_stmt = $db->prepare("SELECT response_file_path FROM bot_commands WHERE id = ? AND bot_id = ?");
                $old_command_stmt->execute([$command_id, $bot_id]);
                $old_command = $old_command_stmt->fetch();
                if (!$old_command) throw new Exception('Komut bulunamadı.');

                $response_file_path = $old_command['response_file_path'];
                if (isset($_POST['remove_file']) && $_POST['remove_file'] == 1) {
                    if ($response_file_path && file_exists($response_file_path)) unlink($response_file_path);
                    $response_file_path = null;
                } elseif (isset($_FILES['response_file']) && $_FILES['response_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['response_file'];
                    $upload_dir = 'Uploads/command_files/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm', 'application/pdf', 'text/plain'];
                    $max_size = 10 * 1024 * 1024;
                    $file_type = mime_content_type($file['tmp_name']);
                    if (!in_array($file_type, $allowed_types)) throw new Exception('İzin verilmeyen dosya türü.');
                    if ($file['size'] > $max_size) throw new Exception('Dosya boyutu çok büyük.');

                    if ($response_file_path && file_exists($response_file_path)) unlink($response_file_path);
                    $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $new_file_name = uniqid('cmd_file_', true) . '.' . $file_ext;
                    $target_file = $upload_dir . $new_file_name;
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $response_file_path = $target_file;
                    } else {
                        throw new Exception('Dosya yüklenirken hata oluştu.');
                    }
                }

                $stmt = $db->prepare("UPDATE bot_commands SET command_name = ?, response = ?, response_file_path = ? WHERE id = ? AND bot_id = ?");
                $stmt->execute([$command_name, $response, $response_file_path, $command_id, $bot_id]);
                $audit_action = 'edit_bot_command';
                $audit_details = ['bot_id' => $bot_id, 'command_id' => $command_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Komut başarıyla güncellendi.'];
                break;

            case 'delete_command':
                $command_id = filter_var($_POST['command_id'], FILTER_VALIDATE_INT);
                if (!$command_id) throw new Exception('Geçersiz komut ID.');

                $deleted_command_stmt = $db->prepare("SELECT response_file_path FROM bot_commands WHERE id = ? AND bot_id = ?");
                $deleted_command_stmt->execute([$command_id, $bot_id]);
                $deleted_command = $deleted_command_stmt->fetch();
                if (!$deleted_command) throw new Exception('Komut bulunamadı.');

                if ($deleted_command['response_file_path'] && file_exists($deleted_command['response_file_path'])) {
                    unlink($deleted_command['response_file_path']);
                }

                $stmt = $db->prepare("DELETE FROM bot_commands WHERE id = ? AND bot_id = ?");
                $stmt->execute([$command_id, $bot_id]);
                $audit_action = 'delete_bot_command';
                $audit_details = ['bot_id' => $bot_id, 'command_id' => $command_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Komut başarıyla silindi.'];
                break;

            case 'update_bot_settings':
                $new_prefix = trim($_POST['bot_new_prefix']);
                $new_is_active = isset($_POST['bot_is_active']) ? 1 : 0;
                $new_username = trim($_POST['bot_new_username']);
                $new_public_access = isset($_POST['bot_public_access']) ? 1 : 0;
                if (empty($new_prefix) || empty($new_username)) throw new Exception('Bot öneki ve adı zorunludur.');

                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$new_username, $bot_id]);
                if ($stmt->fetch()) throw new Exception('Bu kullanıcı adı zaten alınmış.');

                $old_settings_stmt = $db->prepare("SELECT avatar_url FROM users WHERE id = ?");
                $old_settings_stmt->execute([$bot_id]);
                $old_settings = $old_settings_stmt->fetch();
                $new_avatar_url = $old_settings['avatar_url'];

                if (isset($_FILES['bot_new_avatar_file']) && $_FILES['bot_new_avatar_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['bot_new_avatar_file'];
                    $upload_dir = 'Uploads/avatars/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 2 * 1024 * 1024;
                    $file_type = mime_content_type($file['tmp_name']);
                    if (!in_array($file_type, $allowed_types)) throw new Exception('Yalnızca JPG, PNG veya GIF yüklenebilir.');
                    if ($file['size'] > $max_size) throw new Exception('Dosya boyutu 2MB\'tan küçük olmalı.');

                    $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $new_file_name = uniqid('avatar_', true) . '.' . $file_ext;
                    $target_file = $upload_dir . $new_file_name;
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $new_avatar_url = '/' . $target_file;
                        if ($old_settings['avatar_url'] && $old_settings['avatar_url'] !== '/Uploads/avatars/default.png' && file_exists(ltrim($old_settings['avatar_url'], '/'))) {
                            unlink(ltrim($old_settings['avatar_url'], '/'));
                        }
                    } else {
                        throw new Exception('Avatar yüklenirken hata oluştu.');
                    }
                }

                $stmt = $db->prepare("UPDATE users SET prefix = ?, is_active = ?, username = ?, avatar_url = ?, public_access = ? WHERE id = ? AND created_by = ?");
                $stmt->execute([$new_prefix, $new_is_active, $new_username, $new_avatar_url, $new_public_access, $bot_id, $_SESSION['user_id']]);
                $audit_action = 'update_bot_settings';
                $audit_details = ['bot_id' => $bot_id, 'new_username' => $new_username, 'public_access' => $new_public_access];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Bot ayarları güncellendi.'];
                break;

            case 'update_special_commands':
                $special_command_channel_id = filter_var($_POST['special_command_channel'], FILTER_VALIDATE_INT);
                $welcome_message = trim($_POST['welcome_message']);
                $goodbye_message = trim($_POST['goodbye_message']);

                $stmt_check = $db->prepare("SELECT bot_id FROM bot_special_commands WHERE bot_id = ?");
                $stmt_check->execute([$bot_id]);
                if ($stmt_check->fetch()) {
                    $stmt = $db->prepare("UPDATE bot_special_commands SET welcome_channel = ?, welcome_message = ?, goodbye_channel = ?, goodbye_message = ? WHERE bot_id = ?");
                    $stmt->execute([$special_command_channel_id, $welcome_message, $special_command_channel_id, $goodbye_message, $bot_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO bot_special_commands (bot_id, welcome_channel, welcome_message, goodbye_channel, goodbye_message) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$bot_id, $special_command_channel_id, $welcome_message, $special_command_channel_id, $goodbye_message]);
                }
                $audit_action = 'update_special_commands';
                $audit_details = ['bot_id' => $bot_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Özel komutlar güncellendi.'];
                break;

            case 'update_auto_role':
                $post_auto_role_id = !empty($_POST['auto_role_id']) ? filter_var($_POST['auto_role_id'], FILTER_VALIDATE_INT) : null;
                $stmt_check = $db->prepare("SELECT bot_id FROM bot_special_commands WHERE bot_id = ?");
                $stmt_check->execute([$bot_id]);
                if ($stmt_check->fetch()) {
                    $stmt = $db->prepare("UPDATE bot_special_commands SET auto_role_id = ? WHERE bot_id = ?");
                    $stmt->execute([$post_auto_role_id, $bot_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO bot_special_commands (bot_id, auto_role_id) VALUES (?, ?)");
                    $stmt->execute([$bot_id, $post_auto_role_id]);
                }
                $audit_action = 'update_auto_role';
                $audit_details = ['bot_id' => $bot_id, 'auto_role_id' => $post_auto_role_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Otomatik rol güncellendi.'];
                break;

            case 'update_moderation_settings':
                $commands_data = $_POST['mod_commands'] ?? [];
                foreach ($commands_data as $command_name => $settings) {
                    $is_enabled = isset($settings['is_enabled']) ? 1 : 0;
                    $aliases = trim($settings['aliases'] ?? '');
                    $enabled_roles = isset($settings['enabled_roles']) ? implode(',', $settings['enabled_roles']) : null;
                    $disabled_roles = isset($settings['disabled_roles']) ? implode(',', $settings['disabled_roles']) : null;
                    $enabled_channels = isset($settings['enabled_channels']) ? implode(',', $settings['enabled_channels']) : null;
                    $disabled_channels = isset($settings['disabled_channels']) ? implode(',', $settings['disabled_channels']) : null;
                    $delete_command = isset($settings['delete_command_message']) ? 1 : 0;
                    $delete_response = isset($settings['delete_bot_response']) ? 1 : 0;
                    $response_delay = filter_var($settings['response_delete_delay'] ?? 5, FILTER_VALIDATE_INT);
                    $command_specific_settings = [];
                    if ($command_name === 'ban') {
                        $command_specific_settings['message_history_delete'] = $settings['settings']['message_history_delete'] ?? 'none';
                    }
                    if ($command_name === 'clear') {
                        $command_specific_settings['max_messages'] = filter_var($settings['settings']['max_messages'] ?? 100, FILTER_VALIDATE_INT);
                    }
                    $settings_json = json_encode($command_specific_settings);

                    $stmt = $db->prepare("
                        INSERT INTO bot_moderation_commands 
                        (bot_id, command_name, is_enabled, aliases, enabled_roles, disabled_roles, enabled_channels, disabled_channels, delete_command_message, delete_bot_response, response_delete_delay, settings) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        is_enabled = VALUES(is_enabled), 
                        aliases = VALUES(aliases),
                        enabled_roles = VALUES(enabled_roles),
                        disabled_roles = VALUES(disabled_roles),
                        enabled_channels = VALUES(enabled_channels),
                        disabled_channels = VALUES(disabled_channels),
                        delete_command_message = VALUES(delete_command_message),
                        delete_bot_response = VALUES(delete_bot_response),
                        response_delete_delay = VALUES(response_delete_delay),
                        settings = VALUES(settings)
                    ");
                    $stmt->execute([
                        $bot_id, $command_name, $is_enabled, $aliases, $enabled_roles, $disabled_roles,
                        $enabled_channels, $disabled_channels, $delete_command, $delete_response, $response_delay, $settings_json
                    ]);
                }
                $audit_action = 'update_moderation_settings';
                $audit_details = ['bot_id' => $bot_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Moderasyon ayarları güncellendi.'];
                break;

            case 'update_bad_word_filter':
                $bad_words = trim(str_replace("\n", ',', $_POST['bad_words']));
                $enabled = isset($_POST['bad_word_enabled']) ? 1 : 0;
                $delete_message = isset($_POST['delete_message']) ? 1 : 0;
                $warn_user = isset($_POST['warn_user']) ? 1 : 0;
                $mute_user = isset($_POST['mute_user']) ? 1 : 0;
                $mute_duration = filter_var($_POST['mute_duration'] ?? 10, FILTER_VALIDATE_INT);
                $warn_message = trim($_POST['warn_message']);

                $stmt_check = $db->prepare("SELECT bot_id FROM bot_bad_word_filter WHERE bot_id = ?");
                $stmt_check->execute([$bot_id]);
                if ($stmt_check->fetch()) {
                    $stmt = $db->prepare("UPDATE bot_bad_word_filter SET enabled = ?, bad_words = ?, delete_message = ?, warn_user = ?, warn_message = ?, mute_user = ?, mute_duration = ? WHERE bot_id = ?");
                    $stmt->execute([$enabled, $bad_words, $delete_message, $warn_user, $warn_message, $mute_user, $mute_duration, $bot_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO bot_bad_word_filter (bot_id, enabled, bad_words, delete_message, warn_user, warn_message, mute_user, mute_duration) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$bot_id, $enabled, $bad_words, $delete_message, $warn_user, $warn_message, $mute_user, $mute_duration]);
                }
                $audit_action = 'update_bad_word_filter';
                $audit_details = ['bot_id' => $bot_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Küfür filtresi ayarları güncellendi.'];
                break;

            case 'delete_bot':
                $confirm_username = trim($_POST['confirm_username']);
                $stmt = $db->prepare("SELECT username FROM users WHERE id = ? AND created_by = ?");
                $stmt->execute([$bot_id, $_SESSION['user_id']]);
                $bot_data = $stmt->fetch();
                if (!$bot_data || $bot_data['username'] !== $confirm_username) {
                    throw new Exception('Bot kullanıcı adı eşleşmedi.');
                }

                // Delete associated data
                $stmt = $db->prepare("DELETE FROM bot_commands WHERE bot_id = ?");
                $stmt->execute([$bot_id]);
                $stmt = $db->prepare("DELETE FROM bot_special_commands WHERE bot_id = ?");
                $stmt->execute([$bot_id]);
                $stmt = $db->prepare("DELETE FROM bot_moderation_commands WHERE bot_id = ?");
                $stmt->execute([$bot_id]);
                $stmt = $db->prepare("DELETE FROM bot_bad_word_filter WHERE bot_id = ?");
                $stmt->execute([$bot_id]);
                $stmt = $db->prepare("DELETE FROM server_members WHERE user_id = ? AND server_id = ?");
                $stmt->execute([$bot_id, $server_id]);
                $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND created_by = ?");
                $stmt->execute([$bot_id, $_SESSION['user_id']]);
                $audit_action = 'delete_bot';
                $audit_details = ['bot_id' => $bot_id];
                $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Bot başarıyla silindi.'];
                $bot_id = null; // Reset bot_id after deletion
                break;
        }

        if ($audit_action) {
            $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, details, server_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $audit_action, json_encode($audit_details), $server_id]);
        }

        $db->commit();
        header("Location: manage_bots.php?id=$server_id" . ($bot_id ? "&bot_id=$bot_id" : "") . "&section=$redirect_section");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in POST action: " . $e->getMessage());
        $_SESSION['feedback'] = ['type' => 'error', 'message' => $e->getMessage()];
        header("Location: manage_bots.php?id=$server_id" . ($bot_id ? "&bot_id=$bot_id" : "") . "&section=$redirect_section");
        exit;
    }
}

// Display feedback
$feedback = '';
if (isset($_SESSION['feedback'])) {
    $feedback_type = $_SESSION['feedback']['type'];
    $feedback_message = htmlspecialchars($_SESSION['feedback']['message'], ENT_QUOTES, 'UTF-8');
    $feedback = "<div class='feedback-message bg-$feedback_type'>$feedback_message</div>";
    unset($_SESSION['feedback']);
}

// Language settings
$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fr', 'de', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (!file_exists($langFile)) {
        error_log("Language file not found: $langFile");
        return [];
    }
    $content = file_get_contents($langFile);
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parse error in $langFile: " . json_last_error_msg());
        return [];
    }
    return $data;
}

$translations = loadLanguage($lang);
?>
<!DOCTYPE html>
<html lang="tr" class="<?php echo $currentTheme; ?>-theme" style="--custom-background-color: <?php echo $currentCustomColor; ?>; --custom-secondary-color: <?php echo $currentSecondaryColor; ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($translations['bot_management']['title']) ? htmlspecialchars($translations['bot_management']['title'], ENT_QUOTES, 'UTF-8') : 'Bot Yönetimi'; ?> - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
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

        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
            --scrollbar-thumb: #c1c3c7;
            --scrollbar-track: #F2F3F5;
        }

        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 85%, var(--custom-secondary-color) 15%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 75%, var(--custom-secondary-color) 25%);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 30%, white);
            --scrollbar-thumb: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            --scrollbar-track: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            --accent-color: var(--custom-secondary-color);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

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

        #movesidebar {
            position: relative;
            width: clamp(200px, 20%, 300px);
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            padding: 20px 10px;
        }

        #main-content {
            flex: 1;
            background-color: var(--primary-bg);
            padding: 20px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
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
            cursor: pointer;
        }

        .nav-item:hover {
            background-color: rgba(79, 84, 92, 0.4);
            color: var(--text-primary);
        }

        .nav-item.active {
            background-color: rgba(79, 84, 92, 0.6);
            color: var(--text-primary);
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-section.compact {
            margin-bottom: 0;
        }
        .form-section.compact + .form-section {
            margin-top: 16px;
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

        .form-input:disabled, .form-textarea:disabled {
            background-color: rgba(0, 0, 0, 0.3);
            cursor: not-allowed;
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

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

        .btn:disabled {
            background-color: rgba(79, 84, 92, 0.3);
            cursor: not-allowed;
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

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch input:disabled + .slider {
            background-color: rgba(79, 84, 92, 0.3);
            cursor: not-allowed;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(79, 84, 92, 0.6);
            transition: .15s;
            border-radius: 12px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .15s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent-color);
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

        .container__68f37 {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(79, 84, 92, 0.3);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding-left: 20px;
            padding-right: 20px;
        }

        .container__68f37:last-of-type {
            border-bottom: none;
        }

        .column__68f37 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .column__68f37.left-column {
            border-right: 1px solid rgba(79, 84, 92, 0.3);
            padding-right: 20px;
        }

        .h5_b717a1 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .eyebrow_b717a1 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
        }

        .title__68f37 {
            color: var(--text-primary);
        }

        .text__68f37 {
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .anchor_edefb8 {
            color: var(--accent-color);
            text-decoration: none;
        }

        .anchor_edefb8:hover {
            text-decoration: underline;
        }

        .profile-picture-upload-area {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: var(--secondary-bg);
            border: 1px dashed rgba(79, 84, 92, 0.6);
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .profile-picture-upload-area:hover {
            border-color: var(--accent-color);
        }

        .profile-picture-upload-area img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .select2-container .select2-selection--multiple {
            background-color: rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.3);
            border-radius: 3px;
            color: var(--text-primary);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--accent-color);
            color: white;
        }

        .feedback-message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .feedback-message.bg-success {
            background-color: var(--success-color);
        }

        .feedback-message.bg-error {
            background-color: var(--danger-color);
        }

        .bot-item {
            padding: 8px 12px;
            margin: 4px 0;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .bot-item:hover {
            background-color: rgba(79, 84, 92, 0.4);
        }

        .bot-item.active {
            background-color: rgba(79, 84, 92, 0.6);
        }

        .bot-item img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
    </style>
</head>
<body>
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-lg"><?php echo isset($translations['server_settings']['server_setting']) ? htmlspecialchars($translations['server_settings']['server_setting'], ENT_QUOTES, 'UTF-8') : 'Sunucu Ayarları'; ?></h1>
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
        <a href="server_category?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-users w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['community']; ?></span>
        </a>
        <h3 class="text-xs font-bold text-gray-500 uppercase px-4 mt-4 mb-2">Bot Yönetimi</h3>
        <a href="create_bot?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-robot w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['create_bot']; ?></span>
        </a>
        <a href="manage_bots?id=<?php echo $server_id; ?>" class="nav-item active">
            <i class="fas fa-cogs w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['manage_bots']; ?></span>
        </a>
        <a href="add_bot_to_server?id=<?php echo $server_id; ?>" class="nav-item">
            <i class="fas fa-robot w-5 text-center"></i>
            <span><?php echo $translations['server_settings']['add_bot_to_server'] ?? 'Bot Ekle'; ?></span>
        </a>
    </div>
</nav>
    </div>
    
    <div id="main-content">
        <?php echo $feedback; ?>

        <div id="bot-list" class="container__68f37">
            <div class="column__68f37 left-column">
                <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Listesi</h2>
                <div class="mt-4">
                    <?php if (empty($bots)): ?>
                        <p class="text__68f37">Bu sunucuda bot bulunmuyor.</p>
                    <?php else: ?>
                        <?php foreach ($bots as $bot): ?>
                            <div class="bot-item <?php echo $bot['id'] == $bot_id ? 'active' : ''; ?>" data-bot-id="<?php echo $bot['id']; ?>">
                                <img src="<?php echo htmlspecialchars($bot['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?>">
                                <span><?php echo htmlspecialchars($bot['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="column__68f37">
                <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Yeni Bot Ekle</h2>
                <form method="POST" action="create_bot.php" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="server_id" value="<?php echo $server_id; ?>">
                    <div class="form-section">
                        <label class="form-label" for="bot_username">Bot Kullanıcı Adı</label>
                        <input type="text" name="bot_username" id="bot_username" class="form-input" placeholder="Bot kullanıcı adı" required>
                    </div>
                    <div class="form-section">
                        <label class="form-label" for="bot_prefix">Bot Öneki</label>
                        <input type="text" name="bot_prefix" id="bot_prefix" class="form-input" placeholder="Örn: !" required>
                    </div>
                    <div class="form-section">
                        <label class="form-label" for="bot_avatar">Bot Avatarı</label>
                        <input type="file" name="bot_avatar" id="bot_avatar" class="form-input" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Bot Oluştur</button>
                </form>
            </div>
        </div>

        <?php if ($bot_id): ?>
            <div id="bot-overview" class="container__68f37 hidden">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Seçimi</h2>
                    <div class="bot-item active">
                        <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="column__68f37">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Genel Ayarlar</h2>
                    <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="update_bot_settings">
                        <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_section" value="bot-overview">
                        <div class="form-section">
                            <label class="form-label" for="bot_new_username">Kullanıcı Adı</label>
                            <input type="text" name="bot_new_username" id="bot_new_username" class="form-input" value="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="bot_new_prefix">Önek</label>
                            <input type="text" name="bot_new_prefix" id="bot_new_prefix" class="form-input" value="<?php echo htmlspecialchars($bot_prefix, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="bot_is_active">Bot Aktif</label>
                            <label class="switch">
                                <input type="checkbox" name="bot_is_active" id="bot_is_active" <?php echo $bot_is_active ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="bot_public_access">Diğer Sunucularda Kullanılabilir</label>
                            <label class="switch">
                                <input type="checkbox" name="bot_public_access" id="bot_public_access" <?php echo $bot_public_access ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="text__68f37">Bu seçenek etkinleştirilirse, diğer sunucu sahipleri bu botu kendi sunucularına ekleyebilir.</p>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="bot_new_avatar_file">Avatar</label>
                            <div class="profile-picture-upload-area">
                                <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="Bot Avatar">
                                <input type="file" name="bot_new_avatar_file" id="bot_new_avatar_file" class="hidden" accept="image/*">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                    </form>
                </div>
            </div>

            <div id="special-commands" class="container__68f37 hidden">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Seçimi</h2>
                    <div class="bot-item active">
                        <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="column__68f37">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Özel Komutlar</h2>
                    <form method="POST" action="" class="space-y-4">
                        <input type="hidden" name="action" value="update_special_commands">
                        <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_section" value="special-commands">
                        <div class="form-section">
                            <label class="form-label" for="special_command_channel">Hoş Geldin ve Güle Güle Kanalı</label>
                            <select name="special_command_channel" id="special_command_channel" class="form-input select2">
                                <option value="">Kanal seçin...</option>
                                <?php foreach ($channels as $channel): ?>
                                    <option value="<?php echo $channel['id']; ?>" <?php echo ($special_commands['welcome_channel'] ?? '') == $channel['id'] ? 'selected' : ''; ?>>
                                        #<?php echo htmlspecialchars($channel['channel_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="welcome_message">Hoş Geldin Mesajı</label>
                            <textarea name="welcome_message" id="welcome_message" class="form-input form-textarea"><?php echo htmlspecialchars($special_commands['welcome_message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="goodbye_message">Güle Güle Mesajı</label>
                            <textarea name="goodbye_message" id="goodbye_message" class="form-input form-textarea"><?php echo htmlspecialchars($special_commands['goodbye_message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                    </form>
                    <form method="POST" action="" class="space-y-4 mt-6">
                        <input type="hidden" name="action" value="update_auto_role">
                        <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_section" value="special-commands">
                        <div class="form-section">
                            <label class="form-label" for="auto_role_id">Otomatik Rol</label>
                            <select name="auto_role_id" id="auto_role_id" class="form-input select2">
                                <option value="">Rol seçin...</option>
                                <?php foreach ($server_roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php echo ($special_commands['auto_role_id'] ?? '') == $role['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Rolü Kaydet</button>
                    </form>
                </div>
            </div>

            <div id="bot-commands" class="container__68f37 hidden">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Seçimi</h2>
                    <div class="bot-item active">
                        <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="column__68f37">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Yanıtlar</h2>
                    <div class="form-section">
                        <h3 class="h5_b717a1">Yeni Yanıt Ekle</h3>
                        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="action" value="add_command">
                            <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_section" value="bot-commands">
                            <div class="form-section compact">
                                <label class="form-label" for="command_name">Komut Adı</label>
                                <input type="text" name="command_name" id="command_name" class="form-input" placeholder="Örn: Merhaba" maxlength="32" required>
                            </div>
                            <div class="form-section compact">
                                <label class="form-label" for="response">Yanıt Metni</label>
                                <textarea name="response" id="response" class="form-input form-textarea" placeholder="Botunuzun vereceği yanıt"></textarea>
                            </div>
                            <div class="form-section compact">
                                <label class="form-label" for="response_file">Dosya Yanıtı</label>
                                <input type="file" name="response_file" id="response_file" class="form-input" accept="image/*,video/mp4,video/webm,application/pdf,text/plain">
                                <p class="text__68f37">Maks. 10MB (resim, video, PDF, metin)</p>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Yanıt Ekle</button>
                        </form>
                    </div>
                    <div class="form-section">
                        <h3 class="h5_b717a1">Mevcut Yanıtlar</h3>
                        <?php if (empty($commands)): ?>
                            <p class="text__68f37">Bu bot için otomatik yanıt bulunmuyor.</p>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($commands as $command): ?>
                                    <div class="p-4 bg-gray-700/50 rounded-lg">
                                        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                                            <input type="hidden" name="action" value="edit_command">
                                            <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="command_id" value="<?php echo htmlspecialchars($command['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="redirect_section" value="bot-commands">
                                            <div class="form-section compact">
                                                <label class="form-label">Komut Adı</label>
                                                <input type="text" name="command_name" class="form-input" value="<?php echo htmlspecialchars($command['command_name'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="32" required>
                                            </div>
                                            <div class="form-section compact">
                                                <label class="form-label">Yanıt Metni</label>
                                                <textarea name="response" class="form-input form-textarea"><?php echo htmlspecialchars($command['response'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </div>
                                            <div class="form-section compact">
                                                <label class="form-label">Dosya Yanıtı</label>
                                                <?php if (!empty($command['response_file_path'])): ?>
                                                    <div class="flex items-center mb-2">
                                                        <i class="fas fa-paperclip mr-2"></i>
                                                        <span class="text__68f37"><?php echo basename($command['response_file_path']); ?></span>
                                                        <label class="ml-auto flex items-center text__68f37">
                                                            <input type="checkbox" name="remove_file" value="1" class="mr-1">
                                                            Dosyayı Sil
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                                <input type="file" name="response_file" class="form-input" accept="image/*,video/mp4,video/webm,application/pdf,text/plain">
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                                                <button type="submit" name="action" value="delete_command" class="btn btn-danger" onclick="return confirm('Bu komutu silmek istediğinize emin misiniz?');">
                                                    <i class="fas fa-trash-alt"></i> Sil
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="moderation-commands" class="container__68f37 hidden">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Seçimi</h2>
                    <div class="bot-item active">
                        <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="column__68f37">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Moderasyon Komutları</h2>
                    <p class="text__68f37 mb-4">Botunuzun moderasyon komutlarını yapılandırın.</p>
                    <form method="POST" action="" class="space-y-4">
                        <input type="hidden" name="action" value="update_moderation_settings">
                        <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_section" value="moderation-commands">
                        <?php
                        $mod_commands = ['ban', 'unban', 'kick', 'clear'];
                        $mod_command_names = [
                            'ban' => 'Ban (Yasakla)', 
                            'unban' => 'Unban (Yasağı Kaldır)', 
                            'kick' => 'Kick (At)', 
                            'clear' => 'Clear (Mesaj Temizle)'
                        ];
                        foreach ($mod_commands as $cmd):
                            $settings = $moderation_settings[$cmd] ?? [];
                            $is_enabled = $settings['is_enabled'] ?? false;
                            $aliases = $settings['aliases'] ?? '';
                            $enabled_roles = !empty($settings['enabled_roles']) ? explode(',', $settings['enabled_roles']) : [];
                            $disabled_roles = !empty($settings['disabled_roles']) ? explode(',', $settings['disabled_roles']) : [];
                            $enabled_channels = !empty($settings['enabled_channels']) ? explode(',', $settings['enabled_channels']) : [];
                            $disabled_channels = !empty($settings['disabled_channels']) ? explode(',', $settings['disabled_channels']) : [];
                            $delete_command_msg = $settings['delete_command_message'] ?? false;
                            $delete_bot_resp = $settings['delete_bot_response'] ?? false;
                            $resp_delay = $settings['response_delete_delay'] ?? 5;
                            $specific_settings = $settings['settings'] ?? [];
                        ?>
                            <div class="border-t border-gray-700/50 pt-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="h5_b717a1"><?php echo $mod_command_names[$cmd]; ?> Komutu</h3>
                                    <label class="switch">
                                        <input type="checkbox" name="mod_commands[<?php echo $cmd; ?>][is_enabled]" <?php echo $is_enabled ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="form-section compact">
                                        <label class="form-label" for="aliases_<?php echo $cmd; ?>">Takma Adlar</label>
                                        <input type="text" name="mod_commands[<?php echo $cmd; ?>][aliases]" id="aliases_<?php echo $cmd; ?>" class="form-input" value="<?php echo htmlspecialchars($aliases, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Örn: yasakla,uzaklaştır">
                                    </div>
                                    <?php if ($cmd === 'clear'): ?>
                                        <div class="form-section compact">
                                            <label class="form-label" for="max_messages_<?php echo $cmd; ?>">Maksimum Mesaj</label>
                                            <input type="number" name="mod_commands[<?php echo $cmd; ?>][settings][max_messages]" id="max_messages_<?php echo $cmd; ?>" class="form-input" value="<?php echo htmlspecialchars($specific_settings['max_messages'] ?? 100, ENT_QUOTES, 'UTF-8'); ?>" min="1" max="500">
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($cmd === 'ban'): ?>
                                        <div class="form-section compact">
                                            <label class="form-label" for="msg_history_<?php echo $cmd; ?>">Mesaj Geçmişini Sil</label>
                                            <select name="mod_commands[<?php echo $cmd; ?>][settings][message_history_delete]" class="form-input">
                                                <option value="none" <?php echo ($specific_settings['message_history_delete'] ?? 'none') == 'none' ? 'selected' : ''; ?>>Silme</option>
                                                <option value="1h" <?php echo ($specific_settings['message_history_delete'] ?? '') == '1h' ? 'selected' : ''; ?>>Son 1 Saat</option>
                                                <option value="24h" <?php echo ($specific_settings['message_history_delete'] ?? '') == '24h' ? 'selected' : ''; ?>>Son 24 Saat</option>
                                                <option value="7d" <?php echo ($specific_settings['message_history_delete'] ?? '') == '7d' ? 'selected' : ''; ?>>Son 7 Gün</option>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                    <div class="form-section compact">
                                        <label class="form-label">Yetkili Roller</label>
                                        <select name="mod_commands[<?php echo $cmd; ?>][enabled_roles][]" class="form-input select2" multiple>
                                            <?php foreach ($server_roles as $role): ?>
                                                <option value="<?php echo $role['id']; ?>" <?php echo in_array($role['id'], $enabled_roles) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-section compact">
                                        <label class="form-label">Devre Dışı Roller</label>
                                        <select name="mod_commands[<?php echo $cmd; ?>][disabled_roles][]" class="form-input select2" multiple>
                                            <?php foreach ($server_roles as $role): ?>
                                                <option value="<?php echo $role['id']; ?>" <?php echo in_array($role['id'], $disabled_roles) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-section compact">
                                        <label class="form-label">Yetkili Kanallar</label>
                                        <select name="mod_commands[<?php echo $cmd; ?>][enabled_channels][]" class="form-input select2" multiple>
                                            <?php foreach ($channels as $channel): ?>
                                                <option value="<?php echo $channel['id']; ?>" <?php echo in_array($channel['id'], $enabled_channels) ? 'selected' : ''; ?>>
                                                    #<?php echo htmlspecialchars($channel['channel_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-section compact">
                                        <label class="form-label">Devre Dışı Kanallar</label>
                                        <select name="mod_commands[<?php echo $cmd; ?>][disabled_channels][]" class="form-input select2" multiple>
                                            <?php foreach ($channels as $channel): ?>
                                                <option value="<?php echo $channel['id']; ?>" <?php echo in_array($channel['id'], $disabled_channels) ? 'selected' : ''; ?>>
                                                    #<?php echo htmlspecialchars($channel['channel_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-section compact">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="mod_commands[<?php echo $cmd; ?>][delete_command_message]" class="mr-2" <?php echo $delete_command_msg ? 'checked' : ''; ?>>
                                        <span class="text__68f37">Komut mesajını sil</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="mod_commands[<?php echo $cmd; ?>][delete_bot_response]" class="mr-2" <?php echo $delete_bot_resp ? 'checked' : ''; ?>>
                                        <span class="text__68f37">Bot yanıtını sil</span>
                                    </label>
                                    <div class="flex items-center">
                                        <label class="form-label mr-2">Yanıt silme gecikmesi (saniye):</label>
                                        <input type="number" name="mod_commands[<?php echo $cmd; ?>][response_delete_delay]" class="form-input w-24" value="<?php echo htmlspecialchars($resp_delay, ENT_QUOTES, 'UTF-8'); ?>" min="1" max="60">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Moderasyon Ayarlarını Kaydet</button>
                    </form>
                </div>
            </div>

            <div id="bad-word-filter" class="container__68f37 hidden">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Seçimi</h2>
                    <div class="bot-item active">
                        <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                                </div>
                <div class="column__68f37">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Küfür Filtresi</h2>
                    <form method="POST" action="" class="space-y-4">
                        <input type="hidden" name="action" value="update_bad_word_filter">
                        <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_section" value="bad-word-filter">
                        <div class="form-section">
                            <label class="form-label" for="bad_word_enabled">Küfür Filtresini Etkinleştir</label>
                            <label class="switch">
                                <input type="checkbox" name="bad_word_enabled" id="bad_word_enabled" <?php echo ($bad_word_settings['enabled'] ?? 0) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-section">
                            <label class="form-label" for="bad_words">Yasaklı Kelimeler (her satıra bir kelime)</label>
                            <textarea name="bad_words" id="bad_words" class="form-input form-textarea" placeholder="Her satıra bir kelime yazın"><?php echo htmlspecialchars(str_replace(',', "\n", $bad_word_settings['bad_words'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="form-section">
                            <label class="form-label">Eylemler</label>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="delete_message" class="mr-2" <?php echo ($bad_word_settings['delete_message'] ?? 0) ? 'checked' : ''; ?>>
                                    <span class="text__68f37">Mesajı Sil</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="warn_user" class="mr-2" <?php echo ($bad_word_settings['warn_user'] ?? 0) ? 'checked' : ''; ?>>
                                    <span class="text__68f37">Kullanıcıyı Uyar</span>
                                </label>
                                <div class="form-section compact pl-6">
                                    <label class="form-label" for="warn_message">Uyarı Mesajı</label>
                                    <textarea name="warn_message" id="warn_message" class="form-input form-textarea" placeholder="Kullanıcıya gönderilecek uyarı mesajı"><?php echo htmlspecialchars($bad_word_settings['warn_message'] ?? 'Lütfen küfür kullanmaktan kaçının.', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="mute_user" class="mr-2" <?php echo ($bad_word_settings['mute_user'] ?? 0) ? 'checked' : ''; ?>>
                                    <span class="text__68f37">Kullanıcıyı Sustur</span>
                                </label>
                                <div class="form-section compact pl-6">
                                    <label class="form-label" for="mute_duration">Susturma Süresi (dakika)</label>
                                    <input type="number" name="mute_duration" id="mute_duration" class="form-input w-24" value="<?php echo htmlspecialchars($bad_word_settings['mute_duration'] ?? 10, ENT_QUOTES, 'UTF-8'); ?>" min="1" max="1440">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                    </form>
                </div>
            </div>

            <div id="delete-bot" class="container__68f37 hidden">
                <div class="column__68f37 left-column">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Bot Seçimi</h2>
                    <div class="bot-item active">
                        <img src="<?php echo htmlspecialchars($bot_avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($bot_username, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="column__68f37">
                    <h2 class="h5_b717a1 eyebrow_b717a1 title__68f37">Botu Sil</h2>
                    <p class="text__68f37 mb-4">Botu silmek, tüm komutlarını ve ayarlarını kalıcı olarak kaldırır. Bu işlem geri alınamaz.</p>
                    <form method="POST" action="" class="space-y-4">
                        <input type="hidden" name="action" value="delete_bot">
                        <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_section" value="bot-list">
                        <div class="form-section">
                            <label class="form-label" for="confirm_username">Bot Kullanıcı Adını Onaylayın</label>
                            <input type="text" name="confirm_username" id="confirm_username" class="form-input" placeholder="Bot kullanıcı adını girin" required>
                        </div>
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Bu botu silmek istediğinize emin misiniz?');"><i class="fas fa-trash-alt"></i> Botu Sil</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                theme: 'default',
                width: '100%',
                placeholder: 'Seçin...',
                allowClear: true
            });

            $('.bot-item').click(function() {
                var botId = $(this).data('bot-id');
                window.location.href = 'manage_bots.php?id=<?php echo $server_id; ?>&bot_id=' + botId + '&section=bot-overview';
            });

            function showSection(sectionId) {
                $('.container__68f37').addClass('hidden');
                $('#' + sectionId).removeClass('hidden');
            }

            var currentSection = '<?php echo isset($_GET['section']) ? htmlspecialchars($_GET['section'], ENT_QUOTES, 'UTF-8') : 'bot-list'; ?>';
            showSection(currentSection);

            $('.profile-picture-upload-area').click(function() {
                $(this).find('input[type="file"]').click();
            });

            $('.profile-picture-upload-area input[type="file"]').change(function() {
                if (this.files && this.files[0]) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        $(this).prev('img').attr('src', e.target.result);
                    }.bind(this);
                    reader.readAsDataURL(this.files[0]);
                }
            });
        });
    </script>
</body>
</html>