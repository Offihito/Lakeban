<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json; charset=utf-8');

// Hata gösterimini kapat, sadece logla
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

ob_start(); // ÇIKIŞ TAMPONLAMASINI AÇ → BOŞLUK VE HATA ENGELLER

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Geçersiz istek methodu');
    }

    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        throw new Exception('Oturum açılmamış');
    }

    // === CREATE POLL ===
    if ($action === 'create_poll') {
        $server_id = filter_var($_POST['server_id'], FILTER_VALIDATE_INT);
        $channel_id = filter_var($_POST['channel_id'], FILTER_VALIDATE_INT);
        $question = trim($_POST['question']);
        $options = json_decode($_POST['options'], true);
        $duration = filter_var($_POST['duration'] ?? 0, FILTER_VALIDATE_INT);

        if (!$server_id || !$channel_id || !$question || !is_array($options) || count($options) < 2) {
            throw new Exception('Eksik veya geçersiz parametreler');
        }

        $end_time = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration * 60) : null;

        $poll_data = [
            'type' => 'poll',
            'question' => $question,
            'options' => array_map('trim', $options),
            'votes' => array_fill(0, count($options), 0),
            'end_time' => $end_time,
            'voters' => []
        ];

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO messages1 (server_id, channel_id, sender_id, message_text, poll_end_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$server_id, $channel_id, $user_id, json_encode($poll_data), $end_time]);

        $message_id = $db->lastInsertId();
        $stmt = $db->prepare("
            SELECT m.*, UNIX_TIMESTAMP(m.created_at) AS created_at_unix, 
                   u.username, up.avatar_url,
                   COALESCE((
                       SELECT r.color 
                       FROM user_roles ur 
                       JOIN roles r ON ur.role_id = r.id 
                       WHERE ur.user_id = m.sender_id 
                       AND ur.server_id = m.server_id 
                       ORDER BY r.importance DESC 
                       LIMIT 1
                   ), '#FFFFFF') AS role_color
            FROM messages1 m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE m.id = ?
        ");
        $stmt->execute([$message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->commit();
        echo json_encode(['success' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);

    }

    // === VOTE POLL ===
    elseif ($action === 'vote_poll') {
        $message_id = filter_var($_POST['message_id'], FILTER_VALIDATE_INT);
        $option_index = filter_var($_POST['option_index'], FILTER_VALIDATE_INT);

        if (!$message_id || $option_index === false) {
            throw new Exception('Geçersiz parametreler');
        }

        $stmt = $db->prepare("SELECT message_text, poll_end_time FROM messages1 WHERE id = ?");
        $stmt->execute([$message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$message) {
            throw new Exception('Mesaj bulunamadı');
        }

        $poll_data = json_decode($message['message_text'], true);
        if ($poll_data['type'] !== 'poll' || !isset($poll_data['options'][$option_index])) {
            throw new Exception('Geçersiz anket veya seçenek');
        }

        if ($message['poll_end_time'] && strtotime($message['poll_end_time']) < time()) {
            throw new Exception('Bu anket süresi doldu.');
        }

        $db->beginTransaction();

        $stmt = $db->prepare("SELECT option_index FROM poll_votes WHERE message_id = ? AND user_id = ?");
        $stmt->execute([$message_id, $user_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE poll_votes SET option_index = ?, created_at = NOW() WHERE message_id = ? AND user_id = ?");
            $stmt->execute([$option_index, $message_id, $user_id]);
            $poll_data['votes'][$existing['option_index']]--;
            $poll_data['votes'][$option_index]++;
        } else {
            $stmt = $db->prepare("INSERT INTO poll_votes (message_id, user_id, option_index) VALUES (?, ?, ?)");
            $stmt->execute([$message_id, $user_id, $option_index]);
            $poll_data['votes'][$option_index]++;
        }

        // Kimler oy verdi?
        $stmt = $db->prepare("
            SELECT pv.user_id, u.username, up.avatar_url, pv.option_index, pv.created_at
            FROM poll_votes pv
            JOIN users u ON pv.user_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE pv.message_id = ?
            ORDER BY pv.created_at DESC
        ");
        $stmt->execute([$message_id]);
        $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $poll_data['voters'] = $voters;

        $stmt = $db->prepare("UPDATE messages1 SET message_text = ? WHERE id = ?");
        $stmt->execute([json_encode($poll_data), $message_id]);

        $db->commit();
        echo json_encode(['success' => true, 'poll_data' => $poll_data], JSON_UNESCAPED_UNICODE);
    }

    // === GET POLL VOTERS ===
    elseif ($action === 'get_poll_voters') {
        $message_id = filter_var($_POST['message_id'], FILTER_VALIDATE_INT);
        if (!$message_id) {
            throw new Exception('Geçersiz mesaj ID');
        }

        // Önce server_id'yi al
        $stmt = $db->prepare("SELECT server_id FROM messages1 WHERE id = ?");
        $stmt->execute([$message_id]);
        $server_id = $stmt->fetchColumn();

        if (!$server_id) {
            throw new Exception('Mesaj bulunamadı');
        }

        // Kimler oy verdi + rol rengi
        $stmt = $db->prepare("
            SELECT 
                pv.user_id, 
                u.username, 
                up.avatar_url, 
                pv.option_index, 
                pv.created_at,
                COALESCE((
                    SELECT r.color 
                    FROM user_roles ur 
                    JOIN roles r ON ur.role_id = r.id 
                    WHERE ur.user_id = pv.user_id 
                      AND ur.server_id = ?
                    ORDER BY r.importance DESC 
                    LIMIT 1
                ), '#FFFFFF') AS role_color
            FROM poll_votes pv
            JOIN users u ON pv.user_id = u.id
            LEFT JOIN user_profiles up ON u.id = up.user_id
            WHERE pv.message_id = ?
            ORDER BY pv.created_at DESC
        ");
        $stmt->execute([$server_id, $message_id]);
        $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'voters' => $voters], JSON_UNESCAPED_UNICODE);
    }

    else {
        throw new Exception('Geçersiz işlem');
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('POLL ERROR: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

ob_end_flush(); // Tamponu temizle ve JSON'u gönder
?>