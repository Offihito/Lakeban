<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : null;

// Etiket renkleri
$tagColors = [
    'new' => '#43b581',
    'improvement' => '#faa61a',
    'bugfix' => '#f04747'
];

// Changelog tarihlerini topla
$contributionData = [
    '2025-06-30' =>8,
    '2025-06-27' =>5,
    '2025-06-23' =>6,
    '2025-06-20' =>9,
    '2025-06-15' =>4,
    '2025-06-09' =>2,
    '2025-06-08' =>6,
    '2025-06-05' =>6,
    '2025-06-04' => 5,
    '2025-06-03' => 5,
    '2025-06-01' => 7,
    '2025-05-24' => 4,
    '2025-05-20' => 7,
    '2025-05-17' => 7,
    '2025-05-10' => 9,
    '2025-05-04' => 27,
    '2025-05-03' => 5,
    '2025-05-01' => 7,
    '2025-04-20' => 5,
    '2025-04-13' => 6,
    '2025-04-07' => 4,
    '2025-04-03' => 1,
    '2025-04-01' => 2,
    '2025-03-31' => 2,
    '2025-03-30' => 5,
    '2025-03-29' => 3,
    '2025-03-28' => 3,
    '2025-03-23' => 2,
    '2025-03-05' => 3,
    '2025-03-03' => 2,
    '2025-02-27' => 3,
    '2025-02-25' => 4,
    '2025-02-22' => 3,
    '2025-02-18' => 3,
    '2025-02-16' => 4,
    '2025-02-11' => 3,
    '2025-02-08' => 6,
    '2025-02-07' => 4,
    '2025-02-05' => 2,
    '2025-02-03' => 4,
    '2025-01-20' => 4,
    '2025-01-12' => 3,
    '2025-01-10' => 6,
    '2024-12-23' => 7,
    '2024-12-20' => 8,
    '2024-12-18' => 2,
    '2024-12-17' => 5,
    '2024-12-08' => 6,
    '2024-12-06' => 2,
    '2024-12-05' => 2,
    '2024-12-02' => 1,
    '2024-12-01' => 2,
    '2024-11-30' => 4,
    '2024-11-29' => 4,
    '2024-11-28' => 3,
    '2024-11-27' => 3,
    '2024-11-26' => 1,
    '2024-11-25' => 6,
    '2024-11-23' => 1,
    '2024-11-18' => 1,
    '2024-11-16' => 2,
    '2024-11-14' => 3,
    '2024-11-11' => 1,
    '2024-11-09' => 3,
    '2024-11-08' => 1,
    '2024-11-07' => 1,
    '2024-11-06' => 2,
    '2024-11-01' => 1,
    '2024-10-31' => 2,
    '2024-10-29' => 2,
    '2024-10-25' => 7,
    '2024-10-22' => 3,
    '2024-10-20' => 2,
    '2024-10-17' => 2,
    '2024-10-13' => 2,
    '2024-10-12' => 2,
    '2024-10-09' => 1,
    '2024-10-08' => 2,
    '2024-10-07' => 1,
    '2024-10-06' => 1,
    '2024-10-05' => 1,
    '2024-10-04' => 1,
    '2024-10-03' => 1,
    '2024-10-02' => 1,
    '2024-10-01' => 1,
];

// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı dilini al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    // Desteklenen dilleri kontrol et
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Dil seçeneğini kontrol et
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang; // Tarayıcı dilini varsayılan olarak ayarla
}

$lang = $_SESSION['lang'];

// Dil dosyalarını yükleme fonksiyonu
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);


?>