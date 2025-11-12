<?php
header('Content-Type: application/json');

require_once 'config.php'; // Veritabanı bağlantısı için

if (!isset($_GET['user_id'])) {
    echo json_encode(['error' => 'User ID is required']);
    exit;
}

$user_id = $_GET['user_id'];

// Veritabanından kullanıcı bilgilerini çek
$stmt = $conn->prepare("
    SELECT u.username, u.created_at, u.post_count, up.avatar_url, up.bio, up.background_url
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE u.id = ?
");
if (!$stmt) {
    echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    echo json_encode(['error' => 'SQL execute failed: ' . $stmt->error]);
    exit;
}

$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

if ($user_data) {
    // Arkadaş listesini çek (sadece ilk 3 arkadaş)
    $stmt = $conn->prepare("
        SELECT u.id, u.username, up.avatar_url, u.status
        FROM friends f
        JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE (f.user_id = ? OR f.friend_id = ?) AND u.id != ?
        LIMIT 3
    ");
    if (!$stmt) {
        echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
    if (!$stmt->execute()) {
        echo json_encode(['error' => 'SQL execute failed: ' . $stmt->error]);
        exit;
    }

    $friends_result = $stmt->get_result();
    if (!$friends_result) {
        echo json_encode(['error' => 'SQL get_result failed: ' . $stmt->error]);
        exit;
    }

    $friends = [];
    while ($row = $friends_result->fetch_assoc()) {
        $friends[] = $row;
    }

    // Toplam arkadaş sayısını çek
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_friends
        FROM friends
        WHERE user_id = ? OR friend_id = ?
    ");
    if (!$stmt) {
        echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("ii", $user_id, $user_id);
    if (!$stmt->execute()) {
        echo json_encode(['error' => 'SQL execute failed: ' . $stmt->error]);
        exit;
    }

    $total_friends_result = $stmt->get_result();
    $total_friends = $total_friends_result->fetch_assoc()['total_friends'];

    $user_data['friends'] = $friends;
    $user_data['total_friends'] = $total_friends; // Toplam arkadaş sayısını ekle
    echo json_encode($user_data);
} else {
    echo json_encode(['error' => 'User not found']);
}
?>