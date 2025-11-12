<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Geçersiz istek methodu');
    }

    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        throw new Exception('Oturum açılmamış');
    }

    if ($action === 'create_poll') {
        $server_id = filter_var($_POST['server_id'], FILTER_VALIDATE_INT);
        $channel_id = filter_var($_POST['channel_id'], FILTER_VALIDATE_INT);
        $question = trim($_POST['question']);
        $options = json_decode($_POST['options'], true);

        if (!$server_id || !$channel_id || !$question || !is_array($options) || count($options) < 2) {
            throw new Exception('Eksik veya geçersiz parametreler');
        }

        $poll_data = [
            'type' => 'poll',
            'question' => $question,
            'options' => array_map('trim', $options),
            'votes' => array_fill(0, count($options), 0)
        ];

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO messages1 (server_id, channel_id, sender_id, message_text) VALUES (?, ?, ?, ?)");
        $stmt->execute([$server_id, $channel_id, $user_id, json_encode($poll_data)]);

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
    } elseif ($action === 'vote_poll') {
        $message_id = filter_var($_POST['message_id'], FILTER_VALIDATE_INT);
        $option_index = filter_var($_POST['option_index'], FILTER_VALIDATE_INT);

        if (!$message_id || $option_index === false) {
            throw new Exception('Geçersiz parametreler');
        }

        $stmt = $db->prepare("SELECT message_text FROM messages1 WHERE id = ?");
        $stmt->execute([$message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$message) {
            throw new Exception('Mesaj bulunamadı');
        }

        $poll_data = json_decode($message['message_text'], true);
        if ($poll_data['type'] !== 'poll' || !isset($poll_data['options'][$option_index])) {
            throw new Exception('Geçersiz anket veya seçenek');
        }

        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM poll_votes WHERE message_id = ? AND user_id = ?");
        $stmt->execute([$message_id, $user_id]);

        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("UPDATE poll_votes SET option_index = ? WHERE message_id = ? AND user_id = ?");
            $stmt->execute([$option_index, $message_id, $user_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO poll_votes (message_id, user_id, option_index) VALUES (?, ?, ?)");
            $stmt->execute([$message_id, $user_id, $option_index]);
        }

        $stmt = $db->prepare("SELECT option_index, COUNT(*) as vote_count FROM poll_votes WHERE message_id = ? GROUP BY option_index");
        $stmt->execute([$message_id]);
        $votes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $poll_data['votes'] = array_fill(0, count($poll_data['options']), 0);
        foreach ($votes as $index => $count) {
            $poll_data['votes'][$index] = (int)$count;
        }

        $stmt = $db->prepare("UPDATE messages1 SET message_text = ? WHERE id = ?");
        $stmt->execute([json_encode($poll_data), $message_id]);

        $db->commit();
        echo json_encode(['success' => true, 'poll_data' => $poll_data], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Geçersiz işlem');
    }
} catch (Exception $e) {
    $db->rollBack();
    error_log('HATA: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>