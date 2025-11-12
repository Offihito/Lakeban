<?php
session_start();
require_once 'config.php';
include 'header.php';

// URL'den kullanıcı adını al
$profile_username = isset($_GET['username']) ? $_GET['username'] : null;
if (!$profile_username) { header("Location: index.php"); exit; }

// Kullanıcı ID'sini al
$user_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$user_stmt->bind_param("s", $profile_username);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
if ($user_result->num_rows === 0) { echo "Kullanıcı bulunamadı."; exit; }
$user_data = $user_result->fetch_assoc();
$user_id = $user_data['id'];

// TÜM gönderileri çeken sorgu
$all_posts_stmt = $conn->prepare("SELECT title, content, created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC");
$all_posts_stmt->bind_param("i", $user_id);
$all_posts_stmt->execute();
$all_posts_result = $all_posts_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profile_username); ?> Adlı Kullanıcının Gönderileri</title>
     <link href="https://fonts.googleapis.com/css2?family=Motiva+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #1b2838; font-family: 'Motiva Sans', sans-serif; color: #acb2b8; }
        .page_wrapper { max-width: 900px; margin: 20px auto; }
        .page_header { font-size: 24px; color: white; margin-bottom: 20px; border-bottom: 1px solid #2a475e; padding-bottom: 10px;}
        .post_item { background-color: rgba(23, 26, 33, 0.85); padding: 20px; margin-bottom: 15px; border-radius: 4px; }
        .post_title { font-size: 20px; color: #66c0f4; margin: 0 0 10px 0; }
        .post_date { font-size: 12px; color: #5a5e63; margin-bottom: 15px; }
        .post_content { font-size: 15px; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="page_wrapper">
        <div class="page_header"><?php echo htmlspecialchars($profile_username); ?> Adlı Kullanıcının Gönderileri</div>
        <?php while($post = $all_posts_result->fetch_assoc()): ?>
            <div class="post_item">
                <h2 class="post_title"><?php echo htmlspecialchars($post['title']); ?></h2>
                <div class="post_date"><?php echo date('d F Y, H:i', strtotime($post['created_at'])); ?></div>
                <div class="post_content">
                    <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                </div>
            </div>
        <?php endwhile; ?>
        <?php if($all_posts_result->num_rows === 0): ?>
            <p>Bu kullanıcının hiç gönderisi yok.</p>
        <?php endif; ?>
    </div>
</body>
</html>