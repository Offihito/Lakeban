<?php
header("Content-Type: application/xml; charset=utf-8");

// Dizin sorunu olmayan, indexlenmesi gereken sayfalar
$pages = [
  '/',
  '/hakkimizda',
  '/iletisim',
  '/sikca-sorulan-sorular',
  '/kullanim-kosullari',
  '/gizlilik-politikasi',
  '/changelog',
  '/latestupdates',
  '/terms',
  '/privacy',
  '/explore',
  '/topluluklar',
  '/posts',
  '/partnership',
  '/stats'
];

// Kullanıcıya özel, noindex olması gereken sayfaları çıkarıyoruz
$excluded_pages = [
  '/kayit-ol',
  '/giris-yap',
  '/login',
  '/register',
  '/profile',
  '/directmessages',
  '/messages',
  '/server',
  '/support',
  '/settings',
  '/themes',
  '/serverform',
  '/dashboard',
  '/lakebanapp'
];

// Yinelenenleri kaldır ve sırala
$pages = array_unique($pages);
sort($pages);

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $page): ?>
  <url>
    <loc><?= "https://lakeban.com" . $page ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority><?= 
      ($page === '/') ? '1.0' : 
      (in_array($page, ['/hakkimizda', '/odalar', '/iletisim']) ? '0.9' : '0.8') 
    ?></priority>
  </url>
<?php endforeach; ?>
</urlset>