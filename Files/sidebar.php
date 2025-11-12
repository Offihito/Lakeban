<?php
// Doğrudan erişimi engelle
if (!defined('INCLUDE_CHECK')) {
    http_response_code(403);
    exit('Forbidden');
}

// $moderated_lakealts ve $joined_lakealts değişkenlerinin bu dosya dahil edilmeden önce
// ana betikte (örneğin posts.php) tanımlanmış olması gerekmektedir.
// Eğer tanımlanmamışlarsa boş dizi olarak ayarlanır, bu da hataları önler.
$moderated_lakealts = $moderated_lakealts ?? [];
$joined_lakealts = $joined_lakealts ?? [];

?>

<div class="sidebar-left">
    <nav class="sidebar-nav">
        <a href="/explore" class="<?php echo basename($_SERVER['PHP_SELF']) === 'explore' ? 'active' : ''; ?>">
            <svg fill="currentColor" height="20" viewBox="0 0 20 20" width="20" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 20a10 10 0 0 1-10-10 10 10 0 0 1 10-10 10 10 0 0 1 10 10 10 10 0 0 1-10 10Zm0-18.75a8.75 8.75 0 0 0-8.75 8.75A8.75 8.75 0 0 0 10 18.75a8.75 8.75 0 0 0 8.75-8.75A8.75 8.75 0 0 0 10 1.25Zm3.125 7.5a2.5 2.5 0 0 1-3.75 2.165l-3.75-3.75A2.5 2.5 0 0 1 8.79 5.375a2.5 2.5 0 0 1 2.165 3.75l3.75 3.75a2.5 2.5 0 0 1-1.58-2.375Z"></path>
            </svg>
            Keşfet
        </a>
        <a href="/posts" class="<?php echo basename($_SERVER['PHP_SELF']) === 'posts' && (!isset($_GET['sort']) || $_GET['sort'] === 'hot') ? 'active' : ''; ?>">
            <svg fill="currentColor" height="20" viewBox="0 0 20 20" width="20" xmlns="http://www.w3.org/2000/svg">
                <path d="M17.125 5.5H2.875A1.627 1.627 0 0 0 1.25 7.125v8.75A1.627 1.627 0 0 0 2.875 17.5h14.25a1.627 1.627 0 0 0 1.625-1.625v-8.75A1.627 1.627 0 0 0 17.125 5.5Zm-14.25.75h14.25a.375.375 0 0 1 .375.375v.875H2.5v-.875a.375.375 0 0 1 .375-.375Zm14.25 9.5H2.875a.375.375 0 0 1-.375-.375V8.75h14.75v6.625a.375.375 0 0 1-.375.375ZM4.375 2.5h-.75v1.75h.75A1.627 1.627 0 0 0 6 2.625v-.75A1.627 1.627 0 0 0 4.375 2.5Zm3 0h-.75v1.75h.75A1.627 1.627 0 0 0 9 2.625v-.75A1.627 1.627 0 0 0 7.375 2.5Zm3 0h-.75v1.75h.75A1.627 1.627 0 0 0 12 2.625v-.75A1.627 1.627 0 0 0 10.375 2.5Z"></path>
            </svg>
            Anasayfa
        </a>
        <a href="/posts?sort=following" class="<?php echo isset($_GET['sort']) && $_GET['sort'] === 'following' ? 'active' : ''; ?>">
            <svg fill="currentColor" height="20" viewBox="0 0 20 20" width="20" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 20a10 10 0 0 1-10-10 10 10 0 0 1 10-10 10 10 0 0 1 10 10 10 10 0 0 1-10 10Zm0-18.75a8.75 8.75 0 0 0-8.75 8.75A8.75 8.75 0 0 0 10 18.75a8.75 8.75 0 0 0 8.75-8.75A8.75 8.75 0 0 0 10 1.25Zm4.375 7.5h-3.125V3.75h-1.25v5h-3.75v1.25h3.75v5h1.25v-5h3.125v-1.25Z"></path>
            </svg>
            Takip Edilen
        </a>
        <a href="/posts?sort=popular" class="<?php echo isset($_GET['sort']) && $_GET['sort'] === 'popular' ? 'active' : ''; ?>">
            <svg fill="currentColor" height="20" viewBox="0 0 20 20" width="20" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 20a10 10 0 0 1-10-10 10 10 0 0 1 10-10 10 10 0 0 1 10 10 10 10 0 0 1-10 10Zm0-18.75a8.75 8.75 0 0 0-8.75 8.75A8.75 8.75 0 0 0 10 18.75a8.75 8.75 0 0 0 8.75-8.75A8.75 8.75 0 0 0 10 1.25Zm-.625 5h3.75L10 2.375 6.875 6.25h3.75Zm0 7.5h3.75L10 17.625 6.875 13.75h3.75Z"></path>
            </svg>
            Popüler
        </a>
        <a href="/posts?sort=top" class="<?php echo isset($_GET['sort']) && $_GET['sort'] === 'top' ? 'active' : ''; ?>">
            <svg fill="currentColor" height="20" viewBox="0 0 20 20" width="20" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 20a10 10 0 0 1-10-10 10 10 0 0 1 10-10 10 10 0 0 1 10 10 10 10 0 0 1-10 10Zm0-18.75a8.75 8.75 0 0 0-8.75 8.75A8.75 8.75 0 0 0 10 18.75a8.75 8.75 0 0 0 8.75-8.75A8.75 8.75 0 0 0 10 1.25Zm-.625 5h3.75L10 2.375 6.875 6.25h3.75Zm0 7.5h3.75L10 17.625 6.875 13.75h3.75Z"></path>
            </svg>
            En İyi
        </a>
        
        <?php if (!empty($moderated_lakealts)): // Moderasyon bölümünü sadece varsa göster ?>
            <div class="sidebar-section">
                <h3>MODERATION</h3>
                <?php foreach ($moderated_lakealts as $mod_lakealt): ?>
                    <a href="/lakealt?name=<?php echo urlencode($mod_lakealt); ?>" class="sub-item <?php echo (isset($lakealt['name']) && $mod_lakealt === $lakealt['name']) ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        l/<?php echo htmlspecialchars($mod_lakealt); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="sidebar-section">
            <h3>KEŞFET</h3>
            <a href="/create_lakealt" class="sub-item <?php echo basename($_SERVER['PHP_SELF']) === 'create_lakealt' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i>
                Lakealt Oluştur
            </a>
            <?php if (!empty($joined_lakealts)): // Üye olunan lakealtları sadece varsa göster ?>
                <?php foreach ($joined_lakealts as $joined_lakealt): ?>
                    <a href="/lakealt?name=<?php echo urlencode($joined_lakealt); ?>" class="sub-item <?php echo (isset($lakealt['name']) && $joined_lakealt === $lakealt['name']) ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        l/<?php echo htmlspecialchars($joined_lakealt); ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="sidebar-section">
            <h3>RESOURCES</h3>
            <a href="/hakkimizda" class="sub-item <?php echo basename($_SERVER['PHP_SELF']) === 'hakkimizda' ? 'active' : ''; ?>">
                <i class="fas fa-info-circle"></i>
                Hakkında
            </a>
        </div>
    </nav>
</div>