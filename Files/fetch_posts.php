<?php
session_start();
require_once 'config.php'; // Veritabanı bağlantısı için config.php'yi dahil et
define('INCLUDE_CHECK', true);

// Hata raporlamayı etkinleştir
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

// Oturum kontrolü (opsiyonel ama güvenlik için iyi)
if (!isset($_SESSION['user_id'])) {
    echo "<p>Lütfen giriş yapın.</p>";
    exit;
}

// Lakealt ID'si ve sıralama kriteri alın
$lakealt_id = $_GET['lakealt_id'] ?? null;
$sort_by = $_GET['sort_by'] ?? 'new'; // Varsayılan olarak "new"
$lakealt_name = $_GET['lakealt_name'] ?? 'bilinmiyor'; // lakealt adını da alıyoruz

if (!$lakealt_id || !is_numeric($lakealt_id)) {
    echo "<p>Geçersiz lakealt ID'si.</p>";
    exit;
}

// Güvenlik: Kullanıcının bu lakealt'ı görüntüleme izni olduğunu kontrol et (isteğe bağlı ama önerilir)
// Bu kısım lakealt.php'deki ana kontrollerle benzer olabilir.

$order_by_sql = "ORDER BY posts.created_at DESC"; // Varsayılan: Yeni

switch ($sort_by) {
    case 'top':
        // En çok oy alanlar (upvotes - downvotes)
        $order_by_sql = "ORDER BY (posts.upvotes - posts.downvotes) DESC, posts.created_at DESC";
        break;
    case 'hot':
        // Popülerlik için belirli bir süre içindeki oy farkı kullanılabilir
        // Daha gelişmiş bir "hot" algoritması (örneğin Reddit'in algoritması) daha fazla veri ve hesaplama gerektirir.
        // Basit bir örnek olarak: Son 7 gün içindeki oy farkı (veya basitçe oy farkı)
        // Eğer belirli bir zaman aralığı eklemek isterseniz, SQL sorgusuna ek bir WHERE koşulu eklemelisiniz.
        $order_by_sql = "ORDER BY (posts.upvotes - posts.downvotes) DESC, posts.created_at DESC";
        break;
    case 'new':
    default:
        $order_by_sql = "ORDER BY posts.created_at DESC";
        break;
}

// Lakealt bilgilerini çek (is_creator ve is_moderator kontrolü için)
$is_creator = false;
$is_moderator = false;
$lakealt_info_sql = "SELECT creator_id FROM lakealts WHERE id = ?";
$stmt_lakealt_info = $conn->prepare($lakealt_info_sql);
if ($stmt_lakealt_info) {
    $stmt_lakealt_info->bind_param("i", $lakealt_id);
    $stmt_lakealt_info->execute();
    $lakealt_data = $stmt_lakealt_info->get_result()->fetch_assoc();
    $stmt_lakealt_info->close();

    if ($lakealt_data && isset($_SESSION['user_id'])) {
        $is_creator = ($lakealt_data['creator_id'] == $_SESSION['user_id']);
    }
} else {
    error_log("Lakealt info SQL prepare hatası: " . $conn->error);
}

// Kullanıcının moderatör olup olmadığını kontrol et
if (isset($_SESSION['user_id'])) {
    $sql_mod = "SELECT 1 FROM lakealt_moderators WHERE lakealt_id = ? AND user_id = ?";
    $stmt_mod = $conn->prepare($sql_mod);
    if ($stmt_mod) {
        $stmt_mod->bind_param("ii", $lakealt_id, $_SESSION['user_id']);
        $stmt_mod->execute();
        $is_moderator = $stmt_mod->get_result()->fetch_row() !== null;
        $stmt_mod->close();
    } else {
        error_log("Moderatör SQL prepare hatası (fetch_posts): " . $conn->error);
    }
}


// Gönderileri çek
$sql = "
    SELECT
        posts.id,
        posts.title,
        posts.content,
        posts.created_at,
        posts.upvotes,
        posts.downvotes,
        posts.media_path,
        users.username,
        users.flair,
        category.name as category_name,
        (SELECT COUNT(*) FROM comments WHERE comments.post_id = posts.id) as comment_count
    FROM posts
    JOIN users ON posts.user_id = users.id
    JOIN category ON posts.category_id = category.id
    WHERE posts.lakealt_id = ?
    {$order_by_sql} LIMIT 20";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("SQL prepare hatası (fetch_posts): " . $conn->error);
    echo "<p>Sunucu hatası. Lütfen daha sonra tekrar deneyin.</p>";
    exit;
}
$stmt->bind_param("i", $lakealt_id);
$stmt->execute();
$result = $stmt->get_result();
$posts = [];
while ($row = $result->fetch_assoc()) {
    // Kullanıcının oy durumunu çek (her gönderi için)
    $row['user_vote'] = ''; // Varsayılan boş
    if (isset($_SESSION['user_id'])) {
        $sql_vote = "SELECT vote_type FROM post_votes WHERE user_id = ? AND post_id = ?";
        $stmt_vote = $conn->prepare($sql_vote);
        if ($stmt_vote) {
            $stmt_vote->bind_param("ii", $_SESSION['user_id'], $row['id']);
            $stmt_vote->execute();
            $vote_result = $stmt_vote->get_result();
            $vote = $vote_result->fetch_assoc();
            $row['user_vote'] = $vote ? $vote['vote_type'] : '';
            $stmt_vote->close();
        } else {
            error_log("Oy SQL prepare hatası (fetch_posts): " . $conn->error);
        }
    }
    $posts[] = $row;
}
$stmt->close();

// Gönderileri HTML olarak döndür
if (!empty($posts)) {
    foreach ($posts as $post) {
        ?>
        <div class="post">
            <div class="post-vote">
                <button class="vote-btn upvote <?php echo $post['user_vote'] === 'upvote' ? 'active' : ''; ?>" data-post-id="<?php echo $post['id']; ?>" data-vote-type="upvote">
                    <i class="fas fa-arrow-up"></i>
                </button>
                <span class="vote-count"><?php echo ($post['upvotes'] - $post['downvotes']); ?></span>
                <button class="vote-btn downvote <?php echo $post['user_vote'] === 'downvote' ? 'active' : ''; ?>" data-post-id="<?php echo $post['id']; ?>" data-vote-type="downvote">
                    <i class="fas fa-arrow-down"></i>
                </button>
            </div>
            <div class="post-content">
                <div class="post-header">
                    <a href="/lakealt.php?name=<?php echo urlencode($lakealt_name); ?>">
                        l/<?php echo htmlspecialchars($lakealt_name); ?>
                    </a>
                    <span>•</span>
                    <a href="/profile-page.php?username=<?php echo urlencode($post['username']); ?>">
                        <?php echo htmlspecialchars($post['username']); ?>
                    </a>
                    <span>•</span>
                    <span><?php echo date('d.m.Y H:i', strtotime($post['created_at'])); ?></span>
                </div>
                <div class="post-title" onclick="window.location.href='/post.php?id=<?php echo $post['id']; ?>'">
                    <?php echo htmlspecialchars($post['title']); ?>
                </div>
                <div class="post-body">
                    <?php echo htmlspecialchars($post['content']); ?>
                </div>
                <?php if ($post['media_path']): ?>
                    <div class="post-media">
                        <?php
                        $file_extension = pathinfo($post['media_path'], PATHINFO_EXTENSION);
                        if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                            echo '<img src="' . htmlspecialchars($post['media_path']) . '" alt="Post Media" loading="lazy">';
                        } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                            echo '<video controls>
                                    <source src="' . htmlspecialchars($post['media_path']) . '" type="video/' . $file_extension . '">
                                    Tarayıcınız video oynatmayı desteklemiyor.
                                  </video>';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <div class="post-footer">
                    <div class="share-btn" onclick="sharePost(<?php echo $post['id']; ?>)">
                        <i class="fas fa-share"></i>
                        <span>Paylaş</span>
                    </div>
                    <a href="/post.php?id=<?php echo $post['id']; ?>" class="comments-btn">
                        <svg class="icon-comment" fill="currentColor" height="16" viewBox="0 0 20 20" width="16" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 19H1.871a.886.886 0 0 1-.798-.52.886.886 0 0 1 .158-.941L3.1 15.771A9 9 0 1 1 10 19Zm-6.549-1.5H10a7.5 7.5 0 1 0-5.323-2.219l.54.545L3.451 17.5Z"></path>
                        </svg>
                        <span><?php echo $post['comment_count']; ?></span>
                        <span class="sr-only">Yorumlara git</span>
                    </a>
                    <?php if ($is_creator || $is_moderator): ?>
                        <div class="delete-btn" data-post-id="<?php echo $post['id']; ?>">
                            <i class="fas fa-trash"></i>
                            <span>Sil</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
} else {
    echo '<p>Bu sıralama kriterine göre henüz gönderi bulunmuyor.</p>';
}
?>