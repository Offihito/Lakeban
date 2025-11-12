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
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die(json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]));
}

// Session check
if (!isset($_SESSION['user_id'])) {
    die(json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]));
}

// Handle server creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $server_name = $_POST['server_name'] ?? '';
        $server_description = $_POST['server_description'] ?? '';
        $target_file = null;

        // Handle file upload
        if (!empty($_FILES["server_pp"]["name"])) {
            $target_dir = "uploads/";
            $target_file = $target_dir . uniqid() . '_' . basename($_FILES["server_pp"]["name"]);
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            // Check if image file is valid
            $check = getimagesize($_FILES["server_pp"]["tmp_name"]);
            if ($check === false) {
                throw new Exception("Dosya geçerli bir resim değil");
            }

            // Check file size
            if ($_FILES["server_pp"]["size"] > 500000) {
                throw new Exception("Dosya boyutu 500KB'ı geçemez");
            }

            // Allow certain file formats
            if (!in_array($imageFileType, ['jpg', 'png', 'jpeg', 'gif'])) {
                throw new Exception("Sadece JPG, JPEG, PNG & GIF dosyaları yüklenebilir");
            }

            // Try to upload file
            if (!move_uploaded_file($_FILES["server_pp"]["tmp_name"], $target_file)) {
                throw new Exception("Dosya yüklenirken hata oluştu");
            }
        }

        // Insert server
        $stmt = $db->prepare("INSERT INTO servers (name, description, owner_id, profile_picture, show_in_community) VALUES (?, ?, ?, ?, 0)");
$stmt->execute([$server_name, $server_description, $_SESSION['user_id'], $target_file]);
$server_id = $db->lastInsertId();

        // Add owner as member
        $stmt = $db->prepare("INSERT INTO server_members (server_id, user_id) VALUES (?, ?)");
        $stmt->execute([$server_id, $_SESSION['user_id']]);

        // Create default channel
        $default_channel_name = "genel";
        $stmt = $db->prepare("INSERT INTO channels (server_id, name) VALUES (?, ?)");
        $stmt->execute([$server_id, $default_channel_name]);

        // Generate invite code
        $invite_code = bin2hex(random_bytes(16));
        $stmt = $db->prepare("INSERT INTO server_invites (server_id, invite_code) VALUES (?, ?)");
        $stmt->execute([$server_id, $invite_code]);

        echo json_encode([
            'success' => true,
            'server_id' => $server_id,
            'invite_link' => "http://lakeban.000.pe/join_server.php?code=$invite_code"
        ]);

    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("Server creation error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}
