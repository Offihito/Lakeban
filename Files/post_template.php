<div class="post" id="post-<?php echo $post['id']; ?>">
    <div class="post-vote">
        <button class="vote-btn upvote <?php echo $post['user_vote'] === 'upvote' ? 'active' : ''; ?>" data-post-id="<?php echo $post['id']; ?>" data-vote-type="upvote" data-csrf-token="<?php echo $csrfToken; ?>">
            <i class="fas fa-arrow-up"></i>
        </button>
        <span class="vote-count"><?php echo ($post['upvotes'] - $post['downvotes']); ?></span>
        <button class="vote-btn downvote <?php echo $post['user_vote'] === 'downvote' ? 'active' : ''; ?>" data-post-id="<?php echo $post['id']; ?>" data-vote-type="downvote" data-csrf-token="<?php echo $csrfToken; ?>">
            <i class="fas fa-arrow-down"></i>
        </button>
    </div>
    <div class="post-content">
        <div class="post-header">
            <img src="<?php echo htmlspecialchars($post['user_avatar_url'] ?: $defaultProfilePicture); ?>" alt="<?php echo $translations['posts']['user_avatar_alt'] ?? 'User Avatar'; ?>" class="user-avatar">
            <a href="/lakealt?name=<?php echo urlencode($post['lakealt_name']); ?>">
                l/<?php echo htmlspecialchars($post['lakealt_name']); ?>
            </a>
            <span>•</span>
            <a href="/profile-page?username=<?php echo urlencode($post['username']); ?>">
                <?php echo htmlspecialchars($post['username']); ?>
            </a>
            <span>•</span>
            <span><?php echo time_ago($post['created_at']); ?></span>
        </div>
        <div class="post-title" onclick="window.location.href='/post?id=<?php echo $post['id']; ?>'">
            <?php echo htmlspecialchars($post['title']); ?>
        </div>
        <div class="post-body">
            <?php echo nl2br(htmlspecialchars($post['content'])); ?>
        </div>
        <?php if (!empty($post['media_path'])): ?>
            <div class="post-media">
                <?php
                $media_paths = json_decode($post['media_path'], true);

                // Check for JSON decode errors or empty array
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($media_paths) || empty($media_paths)) {
                    $media_paths = [$post['media_path']]; // Treat as single path
                }

                if (count($media_paths) > 1) {
                    echo '<div class="image-carousel-container">';
                    echo '<button class="carousel-control prev"><i class="fas fa-chevron-left"></i></button>';
                    echo '<div class="image-carousel">';
                    foreach ($media_paths as $media_url) {
                        $file_extension = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
                        if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                            echo '<img src="' . htmlspecialchars($media_url) . '" alt="' . ($translations['posts']['media_alt'] ?? 'Post Media') . '" loading="lazy">';
                        } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                            echo '<video controls preload="metadata">
                                    <source src="' . htmlspecialchars($media_url) . '" type="video/' . $file_extension . '">
                                    ' . ($translations['posts']['video_unsupported'] ?? 'Your browser does not support video playback.') . '
                                  </video>';
                        }
                    }
                    echo '</div>';
                    echo '<button class="carousel-control next"><i class="fas fa-chevron-right"></i></button>';
                    echo '</div>';
                } else {
                    $media_url = !empty($media_paths) ? $media_paths[0] : null;
                    if ($media_url) {
                        $file_extension = strtolower(pathinfo($media_url, PATHINFO_EXTENSION));
                        if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                            echo '<img src="' . htmlspecialchars($media_url) . '" alt="' . ($translations['posts']['media_alt'] ?? 'Post Media') . '" loading="lazy">';
                        } elseif (in_array($file_extension, ['mp4', 'webm', 'ogg'])) {
                            echo '<video controls preload="metadata">
                                    <source src="' . htmlspecialchars($media_url) . '" type="video/' . $file_extension . '">
                                    ' . ($translations['posts']['video_unsupported'] ?? 'Your browser does not support video playback.') . '
                                  </video>';
                        }
                    }
                }
                ?>
            </div>
        <?php endif; ?>
        <div class="post-footer">
            <div class="share-btn" data-post-id="<?php echo $post['id']; ?>">
                <i class="fas fa-share"></i>
                <span><?php echo $translations['posts']['share'] ?? 'Share'; ?></span>
            </div>
            <a href="/post?id=<?php echo $post['id']; ?>" class="comments-btn">
                <svg class="icon-comment" fill="currentColor" height="16" viewBox="0 0 20 20" width="16" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 19H1.871a.886.886 0 0 1-.798-.52.886.886 0 0 1 .158-.941L3.1 15.771A9 9 0 1 1 10 19Zm-6.549-1.5H10a7.5 7.5 0 1 0-5.323-2.219l.54.545L3.451 17.5Z"></path>
                </svg>
                <span><?php echo $post['comment_count']; ?></span>
                <span class="sr-only"><?php echo $translations['posts']['comments_alt'] ?? 'Go to comments'; ?></span>
            </a>
            <?php if ($post['is_creator'] || $is_moderator): ?>
                <div class="delete-btn" data-post-id="<?php echo $post['id']; ?>" data-csrf-token="<?php echo $csrfToken; ?>">
                    <i class="fas fa-trash"></i>
                    <span><?php echo $translations['posts']['delete'] ?? 'Delete'; ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>