<?php
class DBSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName) {
        return true;
    }

    public function close() {
        return true;
    }

    public function read($sessionId) {
        $stmt = $this->pdo->prepare("SELECT session_data FROM sessions WHERE session_id = ? AND expires > ?");
        $stmt->execute([$sessionId, time()]);
        return ($row = $stmt->fetch()) ? $row['session_data'] : '';
    }

 public function write($sessionId, $sessionData) {
    $expires = time() + (60 * 60 * 24 * 60); // 60 gün (5184000 saniye)
    $stmt = $this->pdo->prepare("REPLACE INTO sessions (session_id, session_data, expires) VALUES (?, ?, ?)");
    return $stmt->execute([$sessionId, $sessionData, $expires]);
}

    public function destroy($sessionId) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE session_id = ?");
        return $stmt->execute([$sessionId]);
    }

    public function gc($maxlifetime) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE expires < ?");
        return $stmt->execute([time()]);
    }
}