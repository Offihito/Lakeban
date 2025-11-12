<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['order']) || !is_array($data['order'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

try {
    $db->beginTransaction();

    // Update positions in server_members table
    $stmt = $db->prepare("UPDATE server_members SET position = ? WHERE user_id = ? AND server_id = ?");
    foreach ($data['order'] as $item) {
        $stmt->execute([(int)$item['position'], $userId, (int)$item['server_id']]);
    }

    $db->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Error updating server order: " . $e->getMessage());
    echo json_encode(['succes
s' => false, 'error' => 'Database error']);
}