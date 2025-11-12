<?php
// Oturumu başlat (Eğer henüz başlatılmadıysa)
// Çoğu durumda, her PHP sayfasında session_start() çağrılır.
// Ancak emin olmak için burada bir kontrol ekleyebiliriz.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Kullanıcının tema ayarlarını oturumdan yükle. Eğer oturumda yoksa, varsayılan değerleri kullan.
$themeSettings = $_SESSION['theme_settings'] ?? [];

// Varsayılan değerler (themes.php dosyasındakiyle aynı olmalı!)
$defaultTheme = 'dark';
$defaultAccentColor = '#3CB371'; // Lütfen themes.php'deki varsayılan ile aynı olduğundan emin olun.

// Oturumdan gelen değerleri veya varsayılanları değişkenlere ata
$currentTheme = $themeSettings['theme'] ?? $defaultTheme;
$currentAccentColor = $themeSettings['accent_color'] ?? $defaultAccentColor;

// Bu dosya HTML çıktısı vermez, sadece değişkenleri ayarlar.
?>