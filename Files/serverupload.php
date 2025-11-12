<?php
session_start();

// Database connection
try {
    $db = new PDO("mysql:host=localhost;dbname=lakebanc_Database", "lakebanc_Offihito", "P4QG(m2jkWXN");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}



// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Get the server ID, channel ID, and message text
$server_id = filter_input(INPUT_POST, 'server_id', FILTER_SANITIZE_NUMBER_INT);
$channel_id = filter_input(INPUT_POST, 'channel_id', FILTER_SANITIZE_NUMBER_INT);
$message_text = filter_input(INPUT_POST, 'message_text', FILTER_SANITIZE_STRING) ?? ''; // Default to empty string if null
$sender_id = $_SESSION['user_id'];

// Validate server_id and channel_id
if (!$server_id || !$channel_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid server or channel ID']);
    exit;
}

try {
    // Check if this is a file upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] == UPLOAD_ERR_OK) {
        // File upload handling
        $file = $_FILES['file'];

        // Extended file validation
        $allowed_types = [
            // Images
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            // Documents
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            // Videos
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov'
        ];

        // Increased max size for videos (250MB)
        $max_size = 250 * 1024 * 1024; 

        // Get the actual MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($file_mime_type, $allowed_types)) {
            throw new Exception('Invalid file type. Allowed types: JPEG, PNG, GIF, PDF, DOC, MP4, WEBM, MOV');
        }

        if ($file['size'] > $max_size) {
            throw new Exception('File size exceeds limit of 100MB');
        }

        // Define the upload directory with separate folders for different file types
        $base_upload_dir = 'uploads/';
        $type_dir = '';
        
        // Determine subdirectory based on file type
        if (strpos($file_mime_type, 'image/') === 0) {
            $type_dir = 'images/';
        } elseif (strpos($file_mime_type, 'video/') === 0) {
            $type_dir = 'videos/';
        } else {
            $type_dir = 'documents/';
        }
        
        $upload_dir = $base_upload_dir . $type_dir;

        // Create directories if they don't exist
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        // Generate safe filename
        $file_extension = $allowed_types[$file_mime_type];
        $file_name = uniqid() . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;

        // Move the uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new Exception('Failed to move uploaded file');
        }

        // Set proper permissions
        chmod($file_path, 0644);

        // Insert file message into database
        $stmt = $db->prepare("INSERT INTO messages1 (sender_id, server_id, channel_id, message_text, file_url, file_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$sender_id, $server_id, $channel_id, $message_text, $file_path, $file_mime_type]);

        // Send a success response
        echo json_encode([
            'success' => true, 
            'file_url' => $file_path,
            'file_type' => $file_mime_type,
            'message' => 'File uploaded successfully'
        ]);

    } else {
        // Handle text-only message
        // Check if at least a message is provided
        if (empty($message_text)) {
            echo json_encode(['success' => true, 'message' => 'Message not sent (empty)']);
            exit;
        }

        // Insert text message into database
        $stmt = $db->prepare("INSERT INTO messages1 (sender_id, server_id, channel_id, message_text, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$sender_id, $server_id, $channel_id, $message_text]);

        // Increment unread message count for the server members
        $stmt = $db->prepare("
            UPDATE server_members 
            SET unread_messages = unread_messages + 1 
            WHERE server_id = ? AND user_id != ?
        ");
        $stmt->execute([$server_id, $sender_id]);

        // Send a success response
        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully'
        ]);
    }

} catch (Exception $e) {
    error_log("Message/Upload Error: " . $e->getMessage());
    // Send an error response
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>