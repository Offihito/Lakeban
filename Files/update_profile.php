<?php
session_start();
require 'db_connection.php'; // Use PDO connection from profile (8).php

// Prevent PHP notices/warnings from corrupting JSON output
ob_start(); // Start output buffering to capture any unintended output

// Initialize response
$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        ob_end_clean(); // Clear any output
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor.']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$update_data = [];
$errors = [];

// All updatable fields
$allowed_fields = [
    'bio',
    'display_username', // Added display_username
    'profile_header_color',
    'profile_text_color',
    'profile_button_color',
    'country',
    'avatar_url',
    'background_url',
    'profile_music',
    'video_url'
];

// Sanitize and validate form data
foreach ($allowed_fields as $field) {
    if (isset($_POST[$field])) {
        $value = trim($_POST[$field]);
        if ($field === 'display_username') {
            if (empty($value)) {
                $errors[] = 'Görünen ad boş olamaz.';
            } elseif (strlen($value) > 50) {
                $errors[] = 'Görünen ad 50 karakterden uzun olamaz.';
            } else {
                $update_data[$field] = $value;
            }
        } else {
            $update_data[$field] = $value;
        }
    }
}

// File upload handling
$file_types = [
    'avatar' => [
        'target_dir' => 'avatars/',
        'db_field' => 'avatar_url',
        'allowed' => ['image/webp', 'image/jpeg', 'image/png', 'image/gif'],
        'max_size' => 4 * 1024 * 1024 // 4MB
    ],
    'background' => [
        'target_dir' => 'uploads/backgrounds/',
        'db_field' => 'background_url',
        'allowed' => ['image/webp', 'image/jpeg', 'image/png', 'image/gif'],
        'max_size' => 6 * 1024 * 1024 // 6MB
    ],
    'music' => [
        'target_dir' => 'uploads/music/',
        'db_field' => 'profile_music',
        'allowed' => ['audio/mpeg', 'audio/ogg', 'audio/wav'],
        'max_size' => 10 * 1024 * 1024 // 10MB
    ],
    'video' => [
        'target_dir' => 'uploads/videos/',
        'db_field' => 'video_url',
        'allowed' => ['video/mp4', 'video/webm', 'video/ogg', 'video/mov'],
        'max_size' => 20 * 1024 * 1024 // 20MB
    ]
];

foreach ($file_types as $file_key => $config) {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$file_key];
        $file_type = mime_content_type($file['tmp_name']);

        if (!in_array($file_type, $config['allowed'])) {
            $errors[] = "$file_key formatı geçersiz.";
            continue;
        }

        if ($file['size'] > $config['max_size']) {
            $errors[] = "$file_key boyut limiti aşıldı.";
            continue;
        }

        // Delete old file
        try {
            $stmt = $db->prepare("SELECT {$config['db_field']} FROM user_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $old_file = $stmt->fetchColumn();
            if ($old_file && file_exists($old_file)) {
                unlink($old_file);
            }
        } catch (PDOException $e) {
            $errors[] = "Eski $file_key silinirken hata: " . $e->getMessage();
            continue;
        }

        // Upload new file
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_filename = uniqid() . '.' . $ext;
        $target_path = $config['target_dir'] . $new_filename;

        if (!is_dir($config['target_dir'])) {
            mkdir($config['target_dir'], 0777, true);
        }

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $update_data[$config['db_field']] = $target_path;
        } else {
            $errors[] = "$file_key yükleme hatası.";
        }
    }
}

// Database update
if (empty($errors) && !empty($update_data)) {
    try {
        $sql = "INSERT INTO user_profiles (user_id, " . implode(', ', array_keys($update_data)) . ") 
                VALUES (?" . str_repeat(', ?', count($update_data)) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', array_map(function($key) {
                    return "$key = VALUES($key)";
                }, array_keys($update_data)));

        $stmt = $db->prepare($sql);
        $params = array_merge([$user_id], array_values($update_data));
        $stmt->execute($params);

        // Update session data for display_username
        if (isset($update_data['display_username'])) {
            $_SESSION['display_username'] = $update_data['display_username'];
        }

        $response = ['success' => true, 'message' => 'Profil başarıyla güncellendi.'];
    } catch (PDOException $e) {
        $errors[] = "Veritabanı hatası: " . $e->getMessage();
        $response = ['success' => false, 'message' => implode(', ', $errors)];
    }
} else {
    $response = ['success' => false, 'message' => implode(', ', $errors) ?: 'Güncellenecek veri yok.'];
}

// Clear any unintended output and send JSON response
ob_end_clean();
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>