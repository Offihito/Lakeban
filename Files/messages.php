<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'database/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Not logged in']));
}

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

/* -------------------------------------------------
   POST isteklerini işle
   ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---------- MARK AS READ (bireysel) ---------- */
    if ($action === 'mark_as_read') {
        $friend_id = filter_var($_POST['friend_id'], FILTER_SANITIZE_NUMBER_INT);

        $stmt = $db->prepare("UPDATE messages1 SET read_status = TRUE WHERE receiver_id = ? AND sender_id = ? AND read_status = FALSE");
        $stmt->execute([$_SESSION['user_id'], $friend_id]);

        $stmt = $db->prepare("UPDATE friends SET unread_messages = 0 WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $stmt->execute([$_SESSION['user_id'], $friend_id, $friend_id, $_SESSION['user_id']]);

        echo json_encode(['success' => true]);
        exit;
    }

    /* ---------- MARK GROUP READ ---------- */
    if ($action === 'mark_group_read' && isset($_POST['group_id'])) {
        error_reporting(0);
        ob_start();

        $groupId = $_POST['group_id'];
        $userId  = $_SESSION['user_id'];

        try {
            if (!$db) {
                throw new Exception('Database connection failed');
            }

            $stmt = $db->prepare("SELECT COALESCE(MAX(id),0) AS last_message_id FROM messages1 WHERE group_id = ?");
            $stmt->execute([$groupId]);
            $lastMessage = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("
                UPDATE group_members 
                SET last_read_message_id = :message_id
                WHERE user_id = :user_id AND group_id = :group_id
            ");
            $stmt->execute([
                ':message_id' => (int)$lastMessage['last_message_id'],
                ':user_id'    => $userId,
                ':group_id'   => $groupId
            ]);

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>'Database error: '.$e->getMessage()]);
            exit;
        } catch (Exception $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
            exit;
        }
    }

    /* ---------- CREATE POLL ---------- */
    if ($action === 'create_poll') {
        $receiver_id = filter_var($_POST['receiver_id'] ?? null, FILTER_VALIDATE_INT);
        $group_id    = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT);
        $question    = trim($_POST['question']);
        $options     = json_decode($_POST['options'], true);

        if ((!$receiver_id && !$group_id) || !$question || !is_array($options) || count($options) < 2) {
            echo json_encode(['success'=>false,'message'=>'Eksik veya geçersiz parametreler']);
            exit;
        }

        $poll_data = [
            'type'     => 'poll',
            'question' => $question,
            'options'  => array_map('trim', $options),
            'votes'    => array_fill(0, count($options), 0)
        ];

        $db->beginTransaction();
        if ($receiver_id) {
            $stmt = $db->prepare("INSERT INTO messages1 (sender_id, receiver_id, message_text) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $receiver_id, json_encode($poll_data)]);
        } elseif ($group_id) {
            $stmt = $db->prepare("INSERT INTO messages1 (sender_id, group_id, message_text) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $group_id, json_encode($poll_data)]);
        }
        $message_id = $db->lastInsertId();

        $stmt = $db->prepare("
            SELECT 
                m.*,
                UNIX_TIMESTAMP(m.created_at) AS timestamp,
                u.username AS sender_username,
                up.display_username AS sender_display_username,
                up.avatar_url AS sender_avatar,
                up.avatar_frame_url AS sender_avatar_frame
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE m.id = ?
        ");
        $stmt->execute([$message_id]);
        $new_message = $stmt->fetch();

        $db->commit();

        $response_message = [
            'id'                    => $new_message['id'],
            'sender_id'             => $new_message['sender_id'],
            'receiver_id'           => $new_message['receiver_id'],
            'group_id'              => $new_message['group_id'],
            'message_text'          => $new_message['message_text'],
            'created_at'            => $new_message['created_at'],
            'timestamp'             => $new_message['timestamp'],
            'sender_username'       => $new_message['sender_username'],
            'sender_display_username'=> $new_message['sender_display_username'] ?? $new_message['sender_username'],
            'sender_avatar'         => $new_message['sender_avatar']
        ];

        echo json_encode(['success'=>true,'message'=>$response_message]);
        exit;
    }

    /* ---------- SEND MESSAGE (dosya yükleme + EXIF temizleme) ---------- */
    if ($action === 'send_message') {
        $receiver_id = isset($_POST['receiver_id']) ? filter_var($_POST['receiver_id'], FILTER_SANITIZE_NUMBER_INT) : null;
        $group_id    = isset($_POST['group_id'])    ? filter_var($_POST['group_id'],    FILTER_SANITIZE_NUMBER_INT) : null;
        $message     = trim($_POST['message'] ?? '');
        $reply_to_message_id = isset($_POST['reply_to_message_id']) ? filter_var($_POST['reply_to_message_id'], FILTER_SANITIZE_NUMBER_INT) : null;
        $uploaded_files = [];

        /* ---- Premium kontrolü ---- */
        $isPremium = false;
        try {
            $stmt = $db->prepare("SELECT status, end_date FROM lakebium WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$_SESSION['user_id']]);
            $lakebium = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lakebium && ($lakebium['end_date'] === null || $lakebium['end_date'] > date('Y-m-d H:i:s'))) {
                $isPremium = true;
            }
        } catch (PDOException $e) {
            error_log("Lakebium sorgu hatası: " . $e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Premium durumu kontrol edilirken hata oluştu']);
            exit;
        }

        $max_file_size = $isPremium ? 500 * 1024 * 1024 : 75 * 1024 * 1024; // 500 MB / 75 MB

        /* ---- Dosya yükleme ---- */
        if (!empty($_FILES['files']['name'][0])) {
            $uploadDir = 'Uploads/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $max_files = 5;
            $file_count = count($_FILES['files']['name']);
            if ($file_count > $max_files) {
                echo json_encode(['success'=>false,'message'=>"Maksimum $max_files dosya yükleyebilirsiniz"]);
                exit;
            }

            foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                $file_name = $_FILES['files']['name'][$key];
                $file_size = $_FILES['files']['size'][$key];
                $file_error = $_FILES['files']['error'][$key];
                $file_type = $_FILES['files']['type'][$key];

                if ($file_error !== UPLOAD_ERR_OK) {
                    continue;
                }

                if ($file_size > $max_file_size) {
                    echo json_encode(['success'=>false,'message'=>"Dosya boyutu çok büyük. Maksimum ".($max_file_size/(1024*1024))." MB"]);
                    continue;
                }

                // Güvenli dosya adı (uzantı .jpg olarak zorlanabilir)
                $safe_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.\_\-]/', '', $file_name);
                $target_path = $uploadDir . $safe_name;

                // EXIF temizlenmiş geçici dosya
                $tempCleanPath = $uploadDir . 'temp_' . $safe_name;

                if (stripExifData($tmp_name, $tempCleanPath)) {
                    if (rename($tempCleanPath, $target_path)) {
                        $uploaded_files[] = [
                            'url'  => $target_path,
                            'name' => $file_name,
                            'type' => $file_type
                        ];
                    } else {
                        @unlink($tempCleanPath);
                        echo json_encode(['success'=>false,'message'=>"Dosya taşınamadı: $file_name"]);
                        continue;
                    }
                } else {
                    @unlink($tempCleanPath);
                    echo json_encode(['success'=>false,'message'=>"EXIF temizlenemedi: $file_name"]);
                    continue;
                }
            }
        }

        $file_url_value = !empty($uploaded_files) ? json_encode($uploaded_files) : null;

        /* ---- Mesajı veritabanına ekle ---- */
        try {
            $stmt = $db->prepare("
                INSERT INTO messages1 
                (sender_id, receiver_id, group_id, message_text, reply_to_message_id, file_url) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $receiver_id,
                $group_id,
                $message,
                $reply_to_message_id,
                $file_url_value
            ]);
            $message_id = $db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Mesaj ekleme hatası: " . $e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Mesaj gönderilirken hata oluştu']);
            exit;
        }

        /* ---- Mention bildirimi ---- */
        if ($message) {
            preg_match_all('/@([a-zA-Z0-9_]+)/', $message, $matches);
            $mentioned_usernames = $matches[1] ?? [];

            if (!empty($mentioned_usernames)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($mentioned_usernames), '?'));
                    $stmt = $db->prepare("SELECT id, username FROM users WHERE username IN ($placeholders)");
                    $stmt->execute($mentioned_usernames);
                    $mentioned_users = $stmt->fetchAll();

                    $stmt = $db->prepare("
                        INSERT INTO notifications 
                        (user_id, sender_id, type, related_id, server_id, channel_id, is_read, created_at)
                        VALUES (?, ?, 'mention', ?, ?, ?, 0, NOW())
                    ");

                    foreach ($mentioned_users as $user) {
                        if ($user['id'] == $_SESSION['user_id']) continue;
                        $stmt->execute([
                            $user['id'],
                            $_SESSION['user_id'],
                            $message_id,
                            null,
                            null
                        ]);
                    }
                } catch (PDOException $e) {
                    error_log("Bildirim ekleme hatası: " . $e->getMessage());
                }
            }
        }

        /* ---- Yeni mesajın detaylarını döndür ---- */
        try {
            $stmt = $db->prepare("
                SELECT 
                    m.*,
                    UNIX_TIMESTAMP(m.created_at) AS timestamp,
                    u.username AS sender_username,
                    u.status AS sender_status,
                    up.display_username AS sender_display_username,
                    up.avatar_url AS sender_avatar,
                    up.avatar_frame_url AS sender_avatar_frame,
                    rm.message_text AS reply_to_message_text,
                    rm.file_url AS reply_to_file_url,
                    ru.username AS reply_to_username,
                    rup.display_username AS reply_to_display_username,
                    rup.avatar_url AS reply_to_avatar_url,
                    rup.avatar_frame_url AS reply_to_avatar_frame
                FROM messages1 m
                JOIN users u ON m.sender_id = u.id
                LEFT JOIN user_profiles up ON u.id = up.user_id
                LEFT JOIN messages1 rm ON m.reply_to_message_id = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                LEFT JOIN user_profiles rup ON ru.id = rup.user_id
                WHERE m.id = ?
            ");
            $stmt->execute([$message_id]);
            $new_message = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$new_message) {
                echo json_encode(['success'=>false,'message'=>'Mesaj detayları alınamadı']);
                exit;
            }

            $response_message = [
                'id'                     => $new_message['id'],
                'sender_id'              => $new_message['sender_id'],
                'receiver_id'            => $new_message['receiver_id'],
                'group_id'               => $new_message['group_id'],
                'message_text'           => $new_message['message_text'] ?? '',
                'created_at'             => $new_message['created_at'],
                'timestamp'              => $new_message['timestamp'],
                'sender_username'        => $new_message['sender_username'],
                'sender_display_username'=> $new_message['sender_display_username'] ?? $new_message['sender_username'],
                'sender_avatar'          => $new_message['sender_avatar'],
                'sender_avatar_frame'    => $new_message['sender_avatar_frame'] ?? 'avatars/default-frame.png',
                'sender_status'          => $new_message['sender_status'] ?? 'offline',
                'file_url'               => $new_message['file_url'],
                'reply_to_message_id'    => $new_message['reply_to_message_id'],
                'reply_to_message_text'  => $new_message['reply_to_message_text'],
                'reply_to_username'      => $new_message['reply_to_username'] ?? 'Bilinmeyen',
                'reply_to_display_username'=> $new_message['reply_to_display_username'] ?? $new_message['reply_to_username'] ?? 'Bilinmeyen',
                'reply_to_avatar_url'    => $new_message['reply_to_avatar_url']
            ];

            echo json_encode([
                'success'     => true,
                'message'     => $response_message,
                'files'       => $uploaded_files,
                'message_id'  => $message_id,
                'message_ids' => [$message_id]
            ]);
        } catch (PDOException $e) {
            error_log("Mesaj detayları sorgu hatası: " . $e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Mesaj detayları alınamadı']);
        }
        exit;
    }

    /* ---------- CHECK NEW MESSAGES ---------- */
    if ($action === 'check_new_messages') {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS unread_messages
            FROM messages1
            WHERE receiver_id = ? AND read_status = FALSE
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        echo json_encode(['success'=>true,'unread_messages'=>$result['unread_messages']]);
        exit;
    }

    /* ---------- GET MESSAGES (bireysel / grup) ---------- */
    if ($action === 'get_messages') {
        $friend_id = filter_var($_POST['friend_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
        $group_id  = filter_var($_POST['group_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
        $page      = filter_var($_POST['page'] ?? 1, FILTER_SANITIZE_NUMBER_INT);
        $limit     = 40;
        $offset    = ($page - 1) * $limit;

        try {
            /* grup kontrolü + okundu işaretleme */
            if ($group_id) {
                $stmt = $db->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
                $stmt->execute([$group_id, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    die(json_encode(['success'=>false,'message'=>'Grupta değilsiniz']));
                }

                $stmt = $db->prepare("SELECT COALESCE(MAX(id),0) AS last_message_id FROM messages1 WHERE group_id = ?");
                $stmt->execute([$group_id]);
                $lastMessage = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("
                    UPDATE group_members 
                    SET last_read_message_id = :message_id
                    WHERE user_id = :user_id AND group_id = :group_id
                ");
                $stmt->execute([
                    ':message_id'=> (int)$lastMessage['last_message_id'],
                    ':user_id'   => $_SESSION['user_id'],
                    ':group_id'  => $group_id
                ]);
            }

            /* bireysel okundu işaretleme */
            if ($friend_id && !$group_id) {
                $stmt = $db->prepare("UPDATE messages1 SET read_status = TRUE 
                                      WHERE receiver_id = ? AND sender_id = ? AND read_status = FALSE");
                $stmt->execute([$_SESSION['user_id'], $friend_id]);
            }

            $query = "
                SELECT 
                    m.*,
                    UNIX_TIMESTAMP(m.created_at) AS timestamp,
                    u.username AS sender_username,
                    up.display_username AS sender_display_username,
                    up.avatar_url AS sender_avatar,
                    up.avatar_frame_url AS sender_avatar_frame,
                    m.file_url,
                    rm.message_text AS reply_to_message_text,
                    rm.file_url AS reply_to_file_url,
                    ru.username AS reply_to_username,
                    rup.display_username AS reply_to_display_username,
                    rup.avatar_url AS reply_to_avatar_url,
                    rup.avatar_frame_url AS reply_to_avatar_frame
                FROM messages1 m
                JOIN users u ON m.sender_id = u.id
                LEFT JOIN user_profiles up ON u.id = up.user_id
                LEFT JOIN messages1 rm ON m.reply_to_message_id = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                LEFT JOIN user_profiles rup ON ru.id = rup.user_id
                WHERE ";

            $params = [];
            if ($group_id) {
                $query .= "m.group_id = ?";
                $params[] = $group_id;
            } else {
                $query .= "(m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)";
                $params = array_merge($params, [
                    $_SESSION['user_id'], $friend_id,
                    $friend_id, $_SESSION['user_id']
                ]);
            }

            $query .= " ORDER BY m.created_at DESC LIMIT $limit OFFSET $offset";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            foreach ($messages as &$msg) {
                $msg['sender_display_username'] = $msg['sender_display_username'] ?? $msg['sender_username'];
                if (isset($msg['reply_to_username'])) {
                    $msg['max_display_username'] = $msg['reply_to_display_username'] ?? $msg['reply_to_username'];
                }
            }

            $messages = array_reverse($messages);

            echo json_encode([
                'success'   => true,
                'messages'  => $messages,
                'is_group'  => (bool)$group_id
            ]);
            exit;
        } catch (PDOException $e) {
            error_log("Database Error: " . $e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Mesajlar yüklenirken bir hata oluştu']);
            exit;
        }
    }

    /* ---------- DELETE MESSAGE ---------- */
    if ($action === 'delete_message') {
        $message_id = filter_var($_POST['message_id'], FILTER_SANITIZE_NUMBER_INT);

        if (!$message_id || !is_numeric($message_id)) {
            echo json_encode(['success'=>false,'message'=>'Geçersiz mesaj ID’si']);
            exit;
        }

        try {
            $stmt = $db->prepare("DELETE FROM messages1 WHERE id = ? AND sender_id = ?");
            $stmt->execute([$message_id, $_SESSION['user_id']]);
            $affected = $stmt->rowCount();

            echo json_encode($affected > 0
                ? ['success'=>true,'message'=>'Mesaj başarıyla silindi']
                : ['success'=>false,'message'=>'Mesaj bulunamadı veya silme yetkiniz yok']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Veritabanı hatası: '.$e->getMessage()]);
        }
        exit;
    }

    /* ---------- EDIT MESSAGE ---------- */
    if ($action === 'edit_message') {
        $message_id      = filter_var($_POST['message_id'], FILTER_SANITIZE_NUMBER_INT);
        $new_message_text = trim($_POST['new_message_text']);

        if (empty($new_message_text)) {
            echo json_encode(['success'=>false,'message'=>'Message cannot be empty']);
            exit;
        }

        $stmt = $db->prepare("UPDATE messages1 SET message_text = ? WHERE id = ? AND sender_id = ?");
        $stmt->execute([$new_message_text, $message_id, $_SESSION['user_id']]);

        echo json_encode(['success'=>true]);
        exit;
    }
}

/* ---------- Geçersiz action ---------- */
echo json_encode(['success'=>false,'message'=>'Invalid action']);
exit;
?>