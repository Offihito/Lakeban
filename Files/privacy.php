<?php /* privacy.php */ ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gizlilik Politikası</title>
    <!-- Kayıt sayfasıyla aynı stil ve scriptler -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <style>
               :root {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --accent-color: #3CB371;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --danger-color: #ed4245;
            --success-color: #3ba55c;
        }

        body {
            background: linear-gradient(135deg, #1a1b1e, #2d2f34);
            color: var(--text-primary);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            animation: gradientAnimation 10s ease infinite;
        }

        @keyframes gradientAnimation {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        .form-input {
            background-color: var(--secondary-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2E8B57;
            transform: translateY(-1px);
        }

        .error {
            color: var(--danger-color);
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        a {
            color: var(--accent-color);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .container {
            background-color: rgba(45, 47, 52, 0.9);
            border-radius: 0.75rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
          .particles {
        position: absolute;
        width: 100%;
        height: 100%;
        z-index: -1;
    }

    .particle {
        position: absolute;
        background: rgba(60, 179, 113, 0.15);
        border-radius: 50%;
        animation: float 20s infinite linear;
    }

    @keyframes float {
        0% {
            transform: translateY(0) translateX(0);
            opacity: 0;
        }
        25% {
            transform: translateY(-100vh) translateX(50vw);
            opacity: 0.4;
        }
        50% {
            transform: translateY(-50vh) translateX(-30vw);
            opacity: 0.2;
        }
        75% {
            transform: translateY(-75vh) translateX(70vw);
            opacity: 0.3;
        }
        100% {
            transform: translateY(-100vh) translateX(-100vw);
            opacity: 0;
        }
    }

    /* Mevcut gradient animasyonunu güncelle */
    @keyframes gradientAnimation {
        0% {
            background: linear-gradient(135deg, #1a1b1e, #2d2f34, #3a3d42);
            background-size: 400% 400%;
        }
        50% {
            background: linear-gradient(225deg, #2d2f34, #3a3d42, #1a1b1e);
            background-size: 400% 400%;
        }
        100% {
            background: linear-gradient(315deg, #3a3d42, #1a1b1e, #2d2f34);
            background-size: 400% 400%;
        }
    }
.form-checkbox {
    border-radius: 0.25rem;
    transition: all 0.2s ease;
}

.form-checkbox:checked {
    background-color: var(--accent-color);
    border-color: var(--accent-color);
}

.form-checkbox:focus {
    ring: 2px rgba(60, 179, 113, 0.5);
}
    .container {
        position: relative;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.1);
    }
      html {
        overflow: hidden;
        width: 100%;
        height: 100%;
    }
    
    body {
        overflow: hidden; /* Çift koruma */
        min-height: 100vh;
        width: 100vw;
        position: fixed; /* Kaydırma davranışını engelle */
    }
              ::-webkit-scrollbar {
    width: 10px;
    background: var(--secondary-bg);
}

::-webkit-scrollbar-thumb {
    background: var(--accent-color);
    border-radius: 5px;
    border: 2px solid var(--secondary-bg);
}

::-webkit-scrollbar-thumb:hover {
    background: #2E8B57;
}

/* Firefox için */
html {
    scrollbar-width: thin;
    scrollbar-color: var(--accent-color) var(--secondary-bg);
}

/* İçerik konteynırına scroll özelliği ekleyin */
.container {
    overflow-y: auto;
    max-height: 100vh; /* Ekran yüksekliğinin %70'i */
    padding-right: 15px; /* Scrollbar için boşluk */
} 
    </style>
</head>
<body>
    <div class="flex min-h-screen items-center justify-center">
        <div class="container w-full max-w-4xl"> <!-- Geniş içerik için max-width arttırıldı -->
            <h1 class="text-3xl font-bold mb-6">Gizlilik Politikası</h1>
            
            <div class="space-y-4 text-gray-300">
                <section>
                    <h2 class="text-xl font-semibold mb-2">ÖNEMLİ: Verileriniz ve kişisel anonimliğiniz bizim için herşeyden daha önemli.</h2>
                    <p>Son Değişiklik: 27.05.2025</p>
                    <br>
                    <p>Lakeban INC olarak size sunduğumuz bu Gizlilik Sözleşmesinde sizden hangi verileri aldığımızı, ne için kullandığımızı ve hangi verilerinizi almadığımızı açıkca belirteceğiz.</p>
                    <br>
                    <p>HANGİ VERİLERİNİZİ ALIYORUZ, İŞLİYORUZ:</p>
                    <ul class="list-disc ml-6 mt-2">
                        <li>İstatikler için sadece üye sayısını, sunucu sayısını, mesaj sayısını vb. yi alıyoruz içiniz rahat olsun.</li>
                    </ul>
                    
                  
                </section>
<p>Yukarıda belirttiğiniz verilerinizi alıyoruz ve gerektiğinde işliyoruz. Bu veriler güvenlik ve moderasyon işleri için kullanılacaktır. </p>
                <section>
                    <h2 class="text-xl font-semibold mb-2">HANGİ DURUMLARDA VERİLERİNİZİ İŞLERİZ:</h2>
                    <ul class="list-disc ml-6 mt-2">
                        <li>Bir kullanıcı başka bir kullanıcıyı veya sunucuyu şikayet ettiğinde.</li>
                        <li>Bir kullanıcı Gizlilik Politikamızı ve Kullanım Sözleşmemizi ciddi derecede ihlal ettiğinde.</li>
                    </ul>
                </section>
<p>ÖNEMLİ: Verileriniz 3. Taraflara asla paylaşılmaz ve satılmaz. Sadece hizmet devamlılığı, güvenlik ve moderasyon işleri gereğince Lakeban.com, Ortakları, Lakeban hizmet ağı ve diğer Lakeban hizmetleri için işlenir ve saklanır.</p>
            <div class="mt-8">
                <a href="register.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i>
                    Kayıt Sayfasına Dön
                </a>
            </div>
        </div>
    </div>
</body>
</html>