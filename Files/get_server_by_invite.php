<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

$invite_code = $_GET['code'] ?? '';
$response = ['success' => false];

if ($invite_code) {
    try {
        $stmt = $db->prepare("
            SELECT s.*, COUNT(sm.user_id) AS member_count
            FROM servers s
            JOIN server_invites si ON s.id = si.server_id
            LEFT JOIN server_members sm ON s.id = sm.server_id
            WHERE si.invite_code = ? AND s.show_in_community = 1
            GROUP BY s.id
        ");
        $stmt->execute([$invite_code]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($server) {
            $response = [
                'success' => true,
                'server' => [
                    'id' => $server['id'],
                    'name' => $server['name'],
                    'banner' => $server['banner'],
                    'profile_picture' => $server['profile_picture'],
                    'description' => $server['description'],
                    'member_count' => (int)$server['member_count'],
                    'created_at' => $server['created_at'],
                    'last_bump' => $server['last_bump'],
                    'verified' => $server['verified'] ? true : false
                ]
            ];
        } else {
            $response['error'] = 'Server not found or invalid invite code';
        }
    } catch (PDOException $e) {
        $response['error'] = 'Database error: ' . $e->getMessage();
    }
} else {
    $response['error'] = 'No invite code provided';
}

echo json_encode($response);
?>