<?php
// Yetkisiz erişim denemelerini veya bulunamayan sayfa isteklerini loglar.
error_log("Yetkisiz/Erişim Dışı sayfa denemesi: " . $_SERVER['REQUEST_URI'] . " IP: " . $_SERVER['REMOTE_ADDR']);

// URL'den hata tipini alarak başlık, mesaj ve HTTP kodunu dinamik olarak ayarlar.
$error_type = isset($_GET['type']) ? $_GET['type'] : 'unauthorized'; // Varsayılan: yetkisiz erişim
$title = '';
$message = '';
$http_code = 403; // Varsayılan HTTP kodu

if ($error_type === 'notfound') {
    $title = 'Sayfa Bulunamadı';
    $message = 'Aradığınız sayfa bulunamadı. Lütfen URL\'yi kontrol edin veya ana sayfaya geri dönün.';
    $http_code = 404; // 404 Not Found
} else {
    $title = 'Yetkisiz Erişim';
    $message = 'Üzgünüz, bu sayfaya erişim yetkiniz yok. Lütfen ana sayfaya geri dönün.';
    $http_code = 403; // 403 Forbidden
}

// HTTP durum kodunu ayarlar.
http_response_code($http_code);

// Kullanıcının geldiği URL'yi (HTTP_REFERER) kontrol eder.
// Referer'ı doğrudan kullanmadan önce temizlemek ve geçerli bir URL olduğundan emin olmak önemlidir.
// Basit bir örnek olarak, sadece sitenizin ana dizinine yönlendireceğiz,
// çünkü referer dışarıdan gelen URL'leri de içerebilir ve güvenlik riski oluşturabilir.
// Veya sadece göreceli bir yol kullanabiliriz.
$back_url = '/index.php'; // Her zaman ana sayfaya yönlendir

// Alternatif (Daha güvenli ve basit bir yaklaşım):
// $back_url = 'index.php'; // Mevcut dizindeki index.php'ye yönlendir
// Eğer ana sayfa her zaman kök dizindeyse (örn: domain.com/), o zaman '/' kullanmak en iyisidir.
// Eğer ana sayfa 'index.php' ise ve diğer sayfalar da aynı dizinde ise 'index.php' kullanın.

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        /* index (1).php dosyasındaki renk paleti değişkenleri */
        :root {
            --neon-green: #3CB371;
            --dark-bg: #0a0a0a;
            --card-bg: #1a1a1a;
            --text-primary: #f5f5f5;
            --text-secondary: #b0b0b0;
            --gradient: linear-gradient(135deg, #3CB371 0%, #2E8B57 100%);
        }

        body {
            font-family: 'Whitney', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: var(--dark-bg); /* Koyu arka plan */
            color: var(--text-primary); /* Birincil yazı rengi */
            margin: 0;
            padding: 0;
            display: flex; /* Flexbox kullan */
            justify-content: center; /* Yatayda ortala */
            align-items: center; /* Dikeyde ortala */
            min-height: 100vh; /* Ekran yüksekliğinin tamamını kapla */
            overflow-x: hidden; /* Yatay kaydırmayı engeller */
        }
        
        .container {
            background-color: var(--card-bg); /* Kart arka plan rengi */
            padding: 40px;
            border-radius: 12px; /* Daha yuvarlak köşeler */
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.4); /* Hafif gölge */
            text-align: center;
            border: 1px solid rgba(60, 179, 113, 0.3); /* Neon yeşili kenarlık */
            transition: box-shadow 0.5s ease; /* Gölge geçişi */
            max-width: 90%; /* Çok büyük ekranlarda aşırı yayılmasını önle */
            width: 500px; /* Belirli bir genişlik ver, içeriğe göre ayarlanabilir */
            box-sizing: border-box; /* Padding ve border genişliğe dahil */
        }

        .container:hover {
            box-shadow: 0 0 40px rgba(60, 179, 113, 0.4), 0 0 60px rgba(60, 179, 113, 0.2); /* Hover'da parlama efekti */
        }
        
        h1 {
            font-size: 2.5rem; /* Daha büyük başlık */
            font-weight: bold;
            margin-bottom: 20px;
            background: var(--gradient); /* Neon yeşili gradient */
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 15px rgba(60, 179, 113, 0.5); /* Hafif parlama */
            animation: float 3s ease-in-out infinite; /* index.php'deki float animasyonu */
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); } /* Biraz daha az yukarı çıksın */
        }
        
        p {
            font-size: 1.1rem; /* Hafif büyük paragraf yazısı */
            margin-bottom: 30px;
            color: var(--text-secondary); /* İkincil yazı rengi */
        }
        
        .back-button {
            background: var(--gradient); /* Neon yeşili gradient */
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 30px; /* Daha yuvarlak buton */
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease; /* Yumuşak geçişler */
            box-shadow: 0 4px 15px rgba(60, 179, 113, 0.3); /* Neon gölge */
        }
        
        .back-button:hover {
            transform: translateY(-3px); /* Hover'da hafif kalkma */
            box-shadow: 0 8px 25px rgba(60, 179, 113, 0.4); /* Daha belirgin gölge */
        }

        /* Hata ikon stilleri */
        .error-icon {
            font-size: 4rem; /* Daha büyük ikon */
            color: var(--neon-green); /* Neon yeşili renk */
            margin-bottom: 20px;
            text-shadow: 0 0 20px rgba(60, 179, 113, 0.7); /* Parlak ikon */
        }
        /* Modal Stilleri (bu kısımlar sayfa bulunamadı için geçerli değildir, ancak diğer modal stillerini korumak adına bırakılmıştır) */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }

        .modal-content {
            position: relative;
            background: #111;
            margin: 10% auto;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            border-radius: 16px;
            border: 1px solid #00ff8830;
            box-shadow: 0 0 40px #00ff8820;
            animation: modalSlideIn 0.3s ease-out;
        }

        .close-modal {
            position: absolute;
            right: 1.5rem;
            top: 1rem;
            font-size: 2rem;
            color: #fff;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close-modal:hover {
            color: #00ff88;
        }

        .modal-title {
            margin-bottom: 1.5rem;
            color: #00ff88;
            font-size: 1.8rem;
            text-align: center;
            text-shadow: 0 0 15px #00ff8830;
        }

        .download-options {
            display: flex;
            flex-direction: column;
            gap: 1.2rem;
        }

        .download-btn {
            display: flex;
            align-items: center;
            padding: 1.2rem;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
        }

        .download-btn:hover {
            transform: translateY(-3px);
            background: #1f1f1f;
            border-color: #00ff88;
            box-shadow: 0 8px 25px #00ff8820;
        }

        .download-btn i {
            font-size: 1.8rem;
            margin-right: 1.2rem;
            width: 35px;
            color: #00ff88;
        }

        .btn-text span {
            font-weight: 600;
            color: #fff;
            letter-spacing: 0.5px;
        }

        .btn-text small {
            display: block;
            color: #888;
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }

        .download-btn.windows {
            border-left: 4px solid #00ff88;
        }

        /* Animations */
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 480px) {
            .modal-content {
                margin: 20% auto;
                padding: 1.5rem;
            }
            
            .download-btn {
                padding: 1rem;
            }
        }
    </style>
    <script>
        // Yönlendirme URL'sini JavaScript değişkenine ata
        // HTML'de doğrudan basmak yerine, daha güvenli ve okunaklı bir yol
        const redirectUrl = '<?php echo htmlspecialchars($back_url); ?>';

        setTimeout(function() {
            window.location.href = redirectUrl; // 5 saniye sonra yönlendir
        }, 5000); 
    </script>
</head>
<body>
    <div class="container">
        <div class="error-icon">
            <?php 
                if ($error_type === 'notfound') {
                    echo '🔍'; // Sayfa bulunamadı ikonu
                } else {
                    echo '⛔'; // Yetkisiz erişim ikonu
                }
            ?>
        </div> 
        <h1><?php echo $title; ?></h1>
        <p><?php echo $message; ?></p>
        <p>Hata Kodu: <?php echo $http_code; ?></p>
        <p>Ana sayfaya <span id="countdown">5</span> saniye içinde yönlendirileceksiniz...</p>
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="back-button">Geri dön veya Ana sayfaya git.</a>

        <script>
            var countdown = 5;
            var countdownElement = document.getElementById('countdown');
            var interval = setInterval(function() {
                countdown--;
                countdownElement.textContent = countdown;
                if (countdown <= 0) {
                    clearInterval(interval);
                }
            }, 1000);
        </script>
    </div>
</body>
</html>