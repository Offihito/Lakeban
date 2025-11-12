<?php
/* send_server_message.php – Kullanıcı + Bot mesajı her zaman görünür + WebSocket */
session_start();
require 'db_connection.php';
require 'execute_js.php';

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

/* -------------------------------------------------
   EXIF / meta‑veri temizleme fonksiyonu
   ------------------------------------------------- */
function stripExifData(string $src, string $dst): bool
{
    // exif uzantısı yoksa sadece kopyala
    if (!extension_loaded('exif')) {
        return copy($src, $dst);
    }

    $type = exif_imagetype($src);
    // sadece JPEG (ve TIFF) destekleniyor
    if (!$type || !in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM])) {
        return copy($src, $dst);
    }

    $img = false;
    if ($type === IMAGETYPE_JPEG) {
        $img = imagecreatefromjpeg($src);
    }

    if ($img) {
        // %90 kalite ile yeni temizlenmiş JPEG oluştur
        $result = imagejpeg($img, $dst, 90);
        imagedestroy($img);
        return $result;
    }

    return copy($src, $dst);
}

/* --------------------------- WebSocket Bildirim --------------------------- */
function sendWsNotification(array $payload): void
{
    $wsUrl   = 'wss://lakeban.com:8000';
    $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $context = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $fp = @stream_socket_client($wsUrl, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);
    if ($fp === false) {
        error_log("[WS] Bağlantı hatası: $errstr ($errno)");
        return;
    }

    $written = @fwrite($fp, $json . "\n");
    if ($written === false) {
        error_log("[WS] Yazma hatası – payload: " . substr($json, 0, 200));
    } else {
        error_log("[WS] Gönderildi → " . substr($json, 0, 200));
    }
    @fclose($fp);
}

/* --------------------------- Yardımcı Fonksiyonlar --------------------------- */
function getUserRoleImportance($db, $user_id, $server_id)
{
    $stmt = $db->prepare("
        SELECT MAX(r.importance) 
        FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND ur.server_id = ?
    ");
    $stmt->execute([$user_id, $server_id]);
    return $stmt->fetchColumn() ?: 0;
}

function getUserIdByUsername($db, $username)
{
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    return $user ? $user['id'] : null;
}

/* --------------------------- Ana İşlem --------------------------- */
try {
    $db->beginTransaction();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Geçersiz istek methodu');
    }

    if (!isset($_POST['server_id'], $_POST['channel_id'], $_POST['message_text'])) {
        throw new Exception('Eksik parametreler');
    }

    $server_id   = filter_var($_POST['server_id'], FILTER_VALIDATE_INT);
    $channel_id  = filter_var($_POST['channel_id'], FILTER_VALIDATE_INT);
    $message_text = trim($_POST['message_text']);
    $sender_id   = $_SESSION['user_id'] ?? null;
    $reply_to_message_id = isset($_POST['reply_to_message_id'])
        ? filter_var($_POST['reply_to_message_id'], FILTER_VALIDATE_INT)
        : null;
    $file_path   = null;

    if (!$sender_id) throw new Exception('Oturum açılmamış');
    if ($server_id === false || $channel_id === false) throw new Exception('Geçersiz sunucu veya kanal ID');

    /* ---------- Susturulma kontrolü ---------- */
    $stmt_muted = $db->prepare("
        SELECT 1 FROM muted_users 
        WHERE user_id = ? AND server_id = ? AND unmute_at > NOW()
    ");
    $stmt_muted->execute([$sender_id, $server_id]);
    if ($stmt_muted->fetch()) {
        throw new Exception('Susturulmuş kullanıcılar mesaj gönderemez.');
    }

    /* ---------- Yanıt mesaj kontrolü ---------- */
    if ($reply_to_message_id !== null) {
        $stmt = $db->prepare("SELECT id FROM messages1 WHERE id = ? AND channel_id = ? AND server_id = ?");
        $stmt->execute([$reply_to_message_id, $channel_id, $server_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Yanıtlanan mesaj bulunamadı veya bu kanalda değil');
        }
    }

    /* ---------- Dosya yükleme (EXIF temizleme ile) ---------- */
    if (!empty($_FILES['file']['tmp_name'])) {
        $isPremium = false;
        $stmt = $db->prepare("SELECT status, end_date FROM lakebium WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$sender_id]);
        $lakebium = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lakebium && ($lakebium['end_date'] === null || $lakebium['end_date'] > date('Y-m-d H:i:s'))) {
            $isPremium = true;
        }
        $max_file_size = $isPremium ? 500 * 1024 * 1024 : 75 * 1024 * 1024;
        $upload_dir = 'Uploads/';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            throw new Exception('Dosya dizini oluşturulamadı');
        }

        $file_info = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $file_info->file($_FILES['file']['tmp_name']);
        $file_size = $_FILES['file']['size'];
        if ($file_size > $max_file_size) {
            throw new Exception('Dosya boyutu çok büyük. Maksimum ' . ($max_file_size / (1024 * 1024)) . 'MB dosya yüklenebilir.');
        }

        $extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        if (empty($extension)) {
            $mime_to_extension = [
                'text/plain' => 'txt', 'application/pdf' => 'pdf', 'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/zip' => 'zip', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogg',
                'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
            ];
            $extension = $mime_to_extension[$mime_type] ?? 'bin';
        }

        // Güvenli dosya adı
        $file_name   = bin2hex(random_bytes(16)) . '.' . $extension;
        $target_file = $upload_dir . $file_name;
        $temp_clean  = $upload_dir . 'tmp_' . $file_name;

        // EXIF temizlenmiş geçici dosya
        if (stripExifData($_FILES['file']['tmp_name'], $temp_clean)) {
            if (rename($temp_clean, $target_file)) {
                $file_path = $target_file;
            } else {
                @unlink($temp_clean);
                throw new Exception('Dosya taşınamadı');
            }
        } else {
            @unlink($temp_clean);
            throw new Exception('EXIF verileri temizlenemedi');
        }
    }

    /* ---------- Kullanıcı mesajını veritabanına ekle ---------- */
    $stmt = $db->prepare("
        INSERT INTO messages1 (server_id, channel_id, sender_id, message_text, file_path, reply_to_message_id) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt->execute([$server_id, $channel_id, $sender_id, $message_text, $file_path, $reply_to_message_id])) {
        throw new Exception('Mesaj oluşturulamadı');
    }
    $message_id = $db->lastInsertId(); // Kullanıcının mesaj ID'si

    /* ---------- Küfür filtresi ---------- */
    $bad_word_found = false;
    $bad_word_settings = null;
    $stmt_bad_word = $db->prepare("
        SELECT bw.* 
        FROM bot_bad_word_filter bw
        JOIN server_members sm ON bw.bot_id = sm.user_id
        WHERE sm.server_id = ? AND bw.enabled = 1
    ");
    $stmt_bad_word->execute([$server_id]);
    $all_bad_word_settings = $stmt_bad_word->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_bad_word_settings as $settings) {
        $words = array_filter(array_map('trim', explode(',', $settings['bad_words'])));
        foreach ($words as $word) {
            if (stripos($message_text, $word) !== false) {
                $bad_word_found    = true;
                $bad_word_settings = $settings;
                break 2;
            }
        }
    }

    if ($bad_word_found) {
        if ($bad_word_settings['delete_message']) {
            $db->prepare("DELETE FROM messages1 WHERE id = ?")->execute([$message_id]);
        }
        if ($bad_word_settings['mute_user']) {
            $mute_until = date('Y-m-d H:i:s', time() + $bad_word_settings['mute_duration'] * 60);
            $db->prepare("
                INSERT INTO muted_users (user_id, server_id, muted_by, mute_at, unmute_at)
                VALUES (?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE unmute_at = ?
            ")->execute([$sender_id, $server_id, $bad_word_settings['bot_id'], $mute_until, $mute_until]);
        }
        $db->commit();
        echo json_encode([
            'success' => false,
            'message' => 'Mesajınız uygunsuz içerik içerdiği için kaldırıldı.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Etiket (mention) bildirimi ---------- */
    preg_match_all('/@(\w+)/', $message_text, $matches);
    $mentioned_usernames = $matches[1] ?? [];

    foreach ($mentioned_usernames as $username) {
        $stmt_user = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_user->execute([$username]);
        $mentioned_user = $stmt_user->fetch();
        if ($mentioned_user && $mentioned_user['id'] != $sender_id) {
            $stmt_notif = $db->prepare("
                INSERT INTO
                INSERT INTO notifications (user_id, sender_id, type, related_id, server_id, channel_id)
                VALUES (?, ?, 'mention', ?, ?, ?)
            ");
            $stmt_notif->execute([$mentioned_user['id'], $sender_id, $message_id, $server_id, $channel_id]);
        }
    }

    /* ---------- Yanıt bildirimi ---------- */
    if ($reply_to_message_id !== null) {
        $stmt_reply = $db->prepare("SELECT sender_id FROM messages1 WHERE id = ?");
        $stmt_reply->execute([$reply_to_message_id]);
        $replied_user = $stmt_reply->fetch();
        if ($replied_user && $replied_user['sender_id'] != $sender_id) {
            $stmt_notif = $db->prepare("
                INSERT INTO notifications (user_id, sender_id, type, related_id, server_id, channel_id)
                VALUES (?, ?, 'reply', ?, ?, ?)
            ");
            $stmt_notif->execute([$replied_user['sender_id'], $sender_id, $message_id, $server_id, $channel_id]);
        }
    }

    /* ---------- Bot komutları ---------- */
    $stmt_bots = $db->prepare("
        SELECT u.id, u.prefix 
        FROM users u
        JOIN server_members sm ON u.id = sm.user_id
        WHERE u.is_bot = 1 AND u.is_active = 1 AND sm.server_id = ?
    ");
    $stmt_bots->execute([$server_id]);
    $bots_in_server = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);

    $bot_responded = false;
    $moderation_action_taken = false;
    $bot_message_id = null;
    $bot_sender_id = null;
    $bot_file_path = null;

    foreach ($bots_in_server as $bot) {
        if ($bot_responded) break;

        $bot_id     = $bot['id'];
        $bot_prefix = $bot['prefix'];

        if (strpos($message_text, $bot_prefix) !== 0) continue;

        $command_full_text = substr($message_text, strlen($bot_prefix));
        $parts = explode(' ', $command_full_text, 2);
        $command_name_input = strtolower(trim($parts[0] ?? ''));
        $args = $parts[1] ?? '';

        if (empty($command_name_input)) continue;

        /* ---- JavaScript bot scripti ---- */
        $stmt_script = $db->prepare("SELECT script_content FROM bot_scripts WHERE bot_id = ?");
        $stmt_script->execute([$bot_id]);
        $script = $stmt_script->fetch();
        if ($script && !empty($script['script_content'])) {
            $result = executeBotScript(
                $script['script_content'],
                $command_name_input,
                $args,
                $server_id,
                $channel_id,
                $sender_id,
                $bot_id,
                $db
            );
            if ($result && isset($result['response'])) {
                $response_text = $result['response'];
                $response_file = $result['file_path'] ?? null;

                $ins = $db->prepare("
                    INSERT INTO messages1 
                    (server_id, channel_id, sender_id, message_text, file_path, reply_to_message_id) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$server_id, $channel_id, $bot_id, $response_text, $response_file, $reply_to_message_id]);
                $bot_message_id   = $db->lastInsertId();
                $bot_sender_id    = $bot_id;
                $bot_file_path    = $response_file;
                $bot_responded    = true;
            }
        }

        /* ---- Moderasyon komutları ---- */
        $stmt_mod = $db->prepare("
            SELECT command_name, aliases, enabled_roles, disabled_roles,
                   enabled_channels, disabled_channels, settings
            FROM bot_moderation_commands
            WHERE bot_id = ?
        ");
        $stmt_mod->execute([$bot_id]);
        $mod_commands = $stmt_mod->fetchAll(PDO::FETCH_ASSOC);

        $matched_mod_command = null;
        foreach ($mod_commands as $mod_cmd) {
            $aliases = array_filter(array_map('trim', explode(',', strtolower($mod_cmd['aliases'] ?? ''))));
            if (strtolower($mod_cmd['command_name']) === $command_name_input ||
                in_array($command_name_input, $aliases)) {
                $matched_mod_command = $mod_cmd;
                break;
            }
        }

        if ($matched_mod_command) {
            /* İzin kontrolü */
            $sender_roles_stmt = $db->prepare("SELECT role_id FROM user_roles WHERE user_id = ? AND server_id = ?");
            $sender_roles_stmt->execute([$sender_id, $server_id]);
            $sender_roles = $sender_roles_stmt->fetchAll(PDO::FETCH_COLUMN);

            $is_owner_stmt = $db->prepare("SELECT owner_id FROM servers WHERE id = ?");
            $is_owner_stmt->execute([$server_id]);
            $is_sender_owner = $is_owner_stmt->fetchColumn() == $sender_id;

            $enabled_roles   = array_filter(explode(',', $matched_mod_command['enabled_roles'] ?? ''));
            $disabled_roles  = array_filter(explode(',', $matched_mod_command['disabled_roles'] ?? ''));
            $enabled_channels = array_filter(explode(',', $matched_mod_command['enabled_channels'] ?? ''));
            $disabled_channels = array_filter(explode(',', $matched_mod_command['disabled_channels'] ?? ''));

            $has_permission = $is_sender_owner;
            if (!$has_permission && !empty($enabled_roles)) {
                $has_permission = count(array_intersect($sender_roles, $enabled_roles)) > 0;
            }
            if ($has_permission && !empty($disabled_roles)) {
                $has_permission = count(array_intersect($sender_roles, $disabled_roles)) === 0;
            }
            if ($has_permission && !empty($enabled_channels) && !in_array($channel_id, $enabled_channels)) {
                $has_permission = false;
            }
            if ($has_permission && !empty($disabled_channels) && in_array($channel_id, $disabled_channels)) {
                $has_permission = false;
            }

            if (!$has_permission) continue;

            $command_to_run = $matched_mod_command['command_name'];
            switch ($command_to_run) {
                case 'clear':
                    $delete_count = filter_var($args, FILTER_VALIDATE_INT);
                    $settings = json_decode($matched_mod_command['settings'], true);
                    $max_clear = $settings['max_messages'] ?? 100;

                    if ($delete_count === false || $delete_count <= 0) {
                        throw new Exception("Lütfen silinecek mesaj sayısını girin (Örn: !temizle 10).");
                    }
                    if ($delete_count > $max_clear) {
                        throw new Exception("Tek seferde en fazla {$max_clear} mesaj silebilirsiniz.");
                    }

                    $db->prepare("DELETE FROM messages1 WHERE id = ?")->execute([$message_id]);
                    $stmt_del = $db->prepare("DELETE FROM messages1 WHERE channel_id = :channel_id ORDER BY id DESC LIMIT :limit");
                    $stmt_del->bindValue(':channel_id', $channel_id, PDO::PARAM_INT);
                    $stmt_del->bindValue(':limit', $delete_count, PDO::PARAM_INT);
                    $stmt_del->execute();

                    $bot_responded = true;
                    $moderation_action_taken = true;
                    break;

                case 'ban':
                case 'kick':
                case 'unban':
                    $target_user_id = null;
                    preg_match('/@(\w+)/', $args, $m);
                    if (!empty($m[1])) {
                        $target_user_id = getUserIdByUsername($db, $m[1]);
                    } elseif (filter_var($args, FILTER_VALIDATE_INT)) {
                        $target_user_id = (int)$args;
                    }
                    if (!$target_user_id) throw new Exception("Lütfen geçerli bir kullanıcı etiketi (@kullanici) veya ID girin.");
                    if ($target_user_id == $sender_id) throw new Exception("Kendinize işlem yapamazsınız.");

                    if ($command_to_run !== 'unban') {
                        $sender_imp = getUserRoleImportance($db, $sender_id, $server_id);
                        $target_imp = getUserRoleImportance($db, $target_user_id, $server_id);
                        if (!$is_sender_owner && $sender_imp <= $target_imp) {
                            throw new Exception("Kendinizden daha yüksek veya eşit role sahip birine işlem yapamazsınız.");
                        }
                    }

                    if ($command_to_run === 'ban') {
                        $db->prepare("INSERT IGNORE INTO banned_users (user_id, banned_by, server_id) VALUES (?, ?, ?)")->execute([$target_user_id, $sender_id, $server_id]);
                        $db->prepare("DELETE FROM server_members WHERE user_id = ? AND server_id = ?")->execute([$target_user_id, $server_id]);
                    } elseif ($command_to_run === 'kick') {
                        $db->prepare("DELETE FROM server_members WHERE user_id = ? AND server_id = ?")->execute([$target_user_id, $server_id]);
                    } elseif ($command_to_run === 'unban') {
                        $db->prepare("DELETE FROM banned_users WHERE user_id = ? AND server_id = ?")->execute([$target_user_id, $server_id]);
                    }

                    $db->prepare("DELETE FROM messages1 WHERE id = ?")->execute([$message_id]);
                    $bot_responded = true;
                    $moderation_action_taken = true;
                    break;
            }

            if ($bot_responded) continue;
        }

        /* ---- Otomatik yanıtlayıcı ---- */
        if (!$bot_responded) {
            $stmt_cmd = $db->prepare("
                SELECT response, response_file_path
                FROM bot_commands
                WHERE bot_id = ? AND LOWER(command_name) = LOWER(?)
            ");
            $stmt_cmd->execute([$bot_id, $command_name_input]);
            $command = $stmt_cmd->fetch();
            if ($command) {
                $ins = $db->prepare("
                    INSERT INTO messages1 
                    (server_id, channel_id, sender_id, message_text, file_path, reply_to_message_id) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$server_id, $channel_id, $bot_id, $command['response'], $command['response_file_path'], $reply_to_message_id]);
                $bot_message_id   = $db->lastInsertId();
                $bot_sender_id    = $bot_id;
                $bot_file_path    = $command['response_file_path'];
                $bot_responded    = true;
            }
        }
    }

    $db->commit();

    /* ==============================================================
       1. KULLANICININ MESAJI → HER ZAMAN WebSocket + JSON'a dahil!
       ============================================================== */
    $stmt_last = $db->prepare("
        SELECT 
            m.*,
            UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
            u.username,
            up.display_username,
            up.avatar_url,
            up.avatar_frame_url,
            COALESCE(
                (SELECT r.color FROM user_roles ur JOIN roles r ON ur.role_id = r.id
                 WHERE ur.user_id = m.sender_id AND ur.server_id = m.server_id
                 ORDER BY r.importance DESC LIMIT 1),
                '#FFFFFF'
            ) AS role_color,
            COALESCE(
                (SELECT r.icon FROM user_roles ur JOIN roles r ON ur.role_id = r.id
                 WHERE ur.user_id = m.sender_id AND ur.server_id = m.server_id
                 ORDER BY r.importance DESC LIMIT 1),
                NULL
            ) AS role_icon
        FROM messages1 m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE m.id = ?
    ");
    $stmt_last->execute([$message_id]);
    $user_msg = $stmt_last->fetch(PDO::FETCH_ASSOC);

    // WebSocket'e kullanıcı mesajı
    $user_payload = [
        'type'      => 'message-sent',
        'serverId'  => $server_id,
        'channelId' => $channel_id,
        'senderId'  => $sender_id,
        'message'   => $user_msg,
        'files'     => $file_path ? [['path' => $file_path]] : []
    ];
    sendWsNotification($user_payload);

    /* ==============================================================
       2. BOT YANITI VARSA → WebSocket'e ayrı gönder + JSON'a ekle
       ============================================================== */
    $response_data = ['success' => true, 'message' => $user_msg];

    if ($bot_responded) {
        $stmt_bot_msg = $db->prepare("
            SELECT 
                m.*,
                UNIX_TIMESTAMP(m.created_at) AS created_at_unix,
                u.username,
                up.display_username,
                up.avatar_url,
                up.avatar_frame_url,
                COALESCE(
                    (SELECT r.color FROM user_roles ur JOIN roles r ON ur.role_id = r.id
                     WHERE ur.user_id = m.sender_id AND ur.server_id = m.server_id
                     ORDER BY r.importance DESC LIMIT 1),
                    '#FFFFFF'
                ) AS role_color,
                COALESCE(
                    (SELECT r.icon FROM user_roles ur JOIN roles r ON ur.role_id = r.id
                     WHERE ur.user_id = m.sender_id AND ur.server_id = m.server_id
                     ORDER BY r.importance DESC LIMIT 1),
                    NULL
                ) AS role_icon
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE m.id = ?
        ");
        $stmt_bot_msg->execute([$bot_message_id]);
        $bot_msg = $stmt_bot_msg->fetch(PDO::FETCH_ASSOC);

        if ($bot_msg) {
            $bot_payload = [
                'type'      => 'message-sent',
                'serverId'  => $server_id,
                'channelId' => $channel_id,
                'senderId'  => $bot_sender_id,
                'message'   => $bot_msg,
                'files'     => $bot_file_path ? [['path' => $bot_file_path]] : []
            ];
            sendWsNotification($bot_payload);
            $response_data['bot_message'] = $bot_msg;
        }

        if ($moderation_action_taken) {
            $stmt_bot = $db->prepare("
                SELECT u.username, up.avatar_url
                FROM users u
                LEFT JOIN user_profiles up ON u.id = up.user_id
                WHERE u.id = ? AND u.is_bot = 1
            ");
            $stmt_bot->execute([$bot_sender_id]);
            $bot_info = $stmt_bot->fetch(PDO::FETCH_ASSOC);

            $stmt_role = $db->prepare("
                SELECT r.color 
                FROM user_roles ur 
                JOIN roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ? AND ur.server_id = ? 
                ORDER BY r.importance DESC LIMIT 1
            ");
            $stmt_role->execute([$bot_sender_id, $server_id]);
            $role_color = $stmt_role->fetchColumn() ?: '#FFFFFF';

            $mod_response = [
                'id'                  => $bot_message_id,
                'server_id'           => $server_id,
                'channel_id'          => $channel_id,
                'sender_id'           => $bot_sender_id,
                'message_text'        => "[Moderasyon] Komut başarıyla işlendi: {$command_to_run}",
                'file_path'           => null,
                'reply_to_message_id' => $reply_to_message_id,
                'created_at_unix'     => time(),
                'username'            => $bot_info['username'] ?? 'Bot',
                'avatar_url'          => $bot_info['avatar_url'] ?? null,
                'role_color'          => $role_color
            ];
            $response_data['bot_message'] = $mod_response;
        }
    }

    echo json_encode($response_data, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('HATA: ' . $e->getMessage() . ' - Dosya: ' . $e->getFile() . ' - Satır: ' . $e->getLine());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>