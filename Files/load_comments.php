<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'config.php';
define('INCLUDE_CHECK', true);

if (!isset($_SESSION['user_id']) || !isset($_POST['post_id'])) {
    exit;
}

$post_id = (int)$_POST['post_id'];
$sort = $_POST['sort'] ?? 'newest';
$order = match($sort) {
    'best' => 'ORDER BY (upvotes - downvotes) DESC',
    'controversial' => 'ORDER BY (upvotes + downvotes) DESC',
    default => 'ORDER BY created_at DESC'
};

$sql = "
    SELECT
        comments.id,
        comments.post_id,
        comments.content,
        comments.created_at,
        comments.upvotes,
        comments.downvotes,
        comments.parent_id,
        comments.is_deleted,
        users.username,
        users.flair,
        IFNULL(cv.vote_type, '') as user_vote
    FROM comments
    LEFT JOIN users ON comments.user_id = users.id
    LEFT JOIN comment_votes cv ON comments.id = cv.comment_id AND cv.user_id = ?
    WHERE comments.post_id = ?
    $order";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası: " . $conn->error);
    die("Bir sorun oluştu, lütfen tekrar deneyin.");
}
$stmt->bind_param("ii", $_SESSION['user_id'], $post_id);
$stmt->execute();
$result = $stmt->get_result();
$comments = [];
$comment_count = 0;
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
    $comment_count++;
}
$stmt->close();

function displayComments($comments, $parent_id = null, $level = 0, $current_user, $post_id) {
    foreach ($comments as $comment) {
        if ($comment['parent_id'] == $parent_id) {
            $is_deleted = $comment['is_deleted'] == 1;
            $username = $is_deleted ? '[deleted]' : ($comment['username'] ?? '[deleted]');
            echo '<div class="comment" style="margin-left: ' . ($level * 20) . 'px;">';
            echo '<div class="comment-vote">';
            echo '<button class="vote-btn upvote ' . ($comment['user_vote'] === 'upvote' ? 'active' : '') . '" data-comment-id="' . $comment['id'] . '" data-vote-type="upvote">';
            echo '<i class="fas fa-arrow-up"></i>';
            echo '</button>';
            echo '<span class="vote-count">' . ($comment['upvotes'] - $comment['downvotes']) . '</span>';
            echo '<button class="vote-btn downvote ' . ($comment['user_vote'] === 'downvote' ? 'active' : '') . '" data-comment-id="' . $comment['id'] . '" data-vote-type="downvote">';
            echo '<i class="fas fa-arrow-down"></i>';
            echo '</button>';
            echo '</div>';
            echo '<div class="comment-content">';
            echo '<div class="comment-header">';
            echo '<a href="/profile-page.php?username=' . urlencode($username) . '">' . htmlspecialchars($username) . '</a>';
            echo '<span>•</span>';
            echo '<span>' . date('d.m.Y H:i', strtotime($comment['created_at'])) . '</span>';
            if (!$is_deleted && $username === $current_user) {
                echo '<button class="edit-comment ml-2 text-sm text-gray-500" data-comment-id="' . $comment['id'] . '">Düzenle</button>';
                echo '<button class="delete-comment ml-2 text-sm text-gray-500" data-comment-id="' . $comment['id'] . '">Sil</button>';
            }
            echo '</div>';
            echo '<div class="comment-body">' . htmlspecialchars($comment['content'] ?? '') . '</div>';
            echo '<form class="reply-form mt-2 hidden" data-comment-id="' . $comment['id'] . '">';
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">';
            echo '<input type="hidden" name="post_id" value="' . $post_id . '">';
            echo '<input type="hidden" name="parent_id" value="' . $comment['id'] . '">';
            echo '<textarea name="content" placeholder="Yanıt yazın..." required></textarea>';
            echo '<button type="submit" class="submit-btn">Yanıtla</button>';
            echo '</form>';
            echo '<button class="reply-toggle text-sm text-gray-500 mt-1">Yanıtla</button>';
            echo '</div>';
            echo '</div>';
            displayComments($comments, $comment['id'], $level + 1, $current_user, $post_id);
        }
    }
}

if (!empty($comments)) {
    displayComments($comments, null, 0, $_SESSION['username'] ?? '', $post_id);
} else {
    echo '<p>Henüz yorum yok. İlk yorumu siz yapın!</p>';
}
?>