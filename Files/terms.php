<?php /* privacy.php */ ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanım Koşullarımız</title>
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
            <h1 class="text-3xl font-bold mb-6"> Kullanım Koşullarımız</h1>
            
            <div class="space-y-4 text-gray-300">
                <section>
                    <p>Son Değişiklik: 02.04.2025</p>
                    <br>
                    <h2 class="text-xl font-semibold mb-2">0- BİLMENİZ GEREKENLER:</h2>
                    <p>Biz Lakeban INC ve bir iletişim platformu olan Lakeban.com un sahibiyiz. Bu sayfa/Anlaşma boyunca; Lakeban INC, Lakeban.com, Ortakları ve hizmet ağı hakkında “Biz” , “Bizim” “Bizler” diye konuşacağız. Lakeban INC, Ortakları ve Lakeban yazılım hizmetlerini kullanan kişi hakkında da yani Sizden bahsederkende “Siz” “Sizler” “Sizin” ve “Lakeban kullanıcısı” diye hitap edeceğiz ve bahsedeceğiz. Gizlilik Sözleşmesi ve Kullanım Sözleşmesi Lakeban.com ve Lakeban hizmet ağı var olduğu sürece aktif olucaktır.</p>
                    <br>
                    <p>Kullanım Sözleşmesi ve Gizlilik Sözleşmemiz üzerinde değişiklik yapıldığı zaman sizi Lakeban.com üzerinden bilgilendireceğiz. Hizmetlerimizi, Yazılımlarımızı ve Uygulamalarımızı kullanmaya devam etmeniz durumunda Kullanım Sözleşmesi ve Gizlilik Sözleşmesi üzerinde yapılan değişiklikler okunmuş ve kabul edilmiş sayılacaktır.</p>
                    <br>
                    <h2 class="text-xl font-semibold mb-2">1- BİZ KİMİZ: </h2>
                    <p>Lakeban.com Lakeban INC tarafından verilen bir iletişim hizmetidir. Biz iletişim yazılımları geliştiren bir grubuz ve sizin Lakeban hizmet sağlayıcınız Hizmetlerimizi Türkiye Cumhuriyeti’nden sağlamaktayız.</p>
                     <br>
                     <h2 class="text-xl font-semibold mb-2">1.1- VERİLERİNİZ HAKKINDA:</h2>
                     <p>Lakeban, yürürlükteki Türkiye Cumhuriyeti yasaları çerçevesinde, kullanıcı verilerini yetkili makamlara paylaşabilir.</p>
                     <br>
                     <p>Türkiye Cumhuriyeti yasalarınca suç teşkil eden faaliyetlerde bulunduğunuzun yetkili makamlarca tespit edilmesi halinde, gerekli yasal yükümlülükler kapsamında verilerinizi paylaşabiliriz ve gerekli görürsek Lakeban.com hesabınıza kısıtlamalar veya kalıcı yaptırımlar getirebiliriz.</p>
                        <br>
                        <h2 class="text-xl font-semibold mb-2">2- HİZMETLERİMİZİN İÇERİĞİ:</h2>
                         <p>Hizmetlerimizin içeriği, sunduğumuz hizmetlerle sınırlıdır. Lakeban INC, Lakeban.com ve Ortakları bizim içeriklerimizdir. Bunun dışındaki hiçbir içerikten sorumlu değilizdir. Bizim hizmet ağımızda yaptığınız paylaşımlardan siz sorumlusunuzdur. (Mesaj içeriği, dosya içeriği, sunucu içerikleri, dm içeriği, hesap profil içeriği vb). </p>
                         <br>
                         <h2 class="text-xl font-semibold mb-2"> 2.1- LAKEBAN HESABINIZ:</h2>
                        <p>Kullanım koşullarımızı veya Gizlilik Sözleşmemizi ihlal ettiğiniz zaman veya diğer gerekli durumlarda Lakeban Hizmetlerinize kısıtlama getirme, askıya alma veya tamamen sonlandırma hakkına sahibiz. Bu kısıtlama hesap profilinizin değiştirilmesi ve kısıtlanması veya silinmesi hakkındadır.</p>
                        <br>
                        <p>Hesabınızın güvenliğinden siz sorumlusunuzdur. Eğer hesabınızın başına birşey gelirse biz size destek sunmaya çalışırız, ancak hesabınızı kurtaramazsak bu durumdan Lakeban.com, Ortakları, Lakeban uygulamaları/yazılımları ve Lakeban hizmet ağı sorumlu tutulamaz.</p>
                        <br>
                        <h2 class="text-xl font-semibold mb-2">2.2- HİZMETLERİMİZ VE YAZILIMLARIMIZ HAKKIMDA:</h2>
                         <p>Lakeban.com web sitesi, Lakeban PC uygulaması, Lakeban Mobil uygulaması gibi Lakeban yazılımlarını kullandığınız an Lakeban Hizmet ağını kullanmış sayılırsınız. Bizim Hizmet ağımızda geçerli kuralları bu Kullanım Sözleşmesinde ve Gizlilik Sözleşmesinde kapsamında belirliyoruz.</p>
                         <br>
                         <p>Lakeban.com web sitesi, Lakeban PC uygulaması, Lakeban mobil uygulaması ve diğer Lakeban yazılımları ve hizmetlerini kullanmak için belirli bir yaş kısıtlaması bulunmamaktadır. 18 yaş altındaki bireylerin hizmetlerimizi kullanması durumunda, bu bireylerin tüm sorumluluğu ebevenylerine aittir.</p>
                            <br>
                            <h2 class="text-xl font-semibold mb-2">2.3- ÜCRETLİ HİZMETLERİMİZ:</h2>
                             <p>Hali hazırda ücretli bir hizmetimiz bulunmamaktadır/sunulmamaktadır, ileride eklenmesi durumunda gerekli düzenleme ve bilgilendirme yapılacaktır.</p>
                             <br>
                             <h2 class="text-xl font-semibold mb-2">2.4- FESİH: </h2>
                              <p>Gizlilik Sözleşmesi ve Kullanım Sözleşmemizi fesh etmeniz ve sorumluluğundan çıkmanız için Lakeban.com hesabınızı kalıcı olarak silmeniz gereklidir. Ne zaman olursa olsun tekrar bir Lakeban.com hesabı açtığınız an bizim Gizlilik Sözleşmemizi ve Kullanım Sözleşmemizi kabul etmiş ve okumuş sayılırsınız.</p>
                               <br>
                               <h2 class="text-xl font-semibold mb-2">2.5- 3. PARTİLER:</h2>
                                <p>Lakeban.com, Ortakları ve Lakeban Hizmet ağı içindeki herhangi bir harici linke tıklamanız durumunda, yönlendirildiğiniz web sitesi veya uygulama üzerinde hiçbir sorumluluğumuz yoktur. Gittiğiniz sitede birçok güvenlik ihlali ve tehlikeye maruz kalabilirsiniz. Bilinmeyen veya güvenilir olmayan bağlantılara tıklamamanızı öneririz. Gittiğiniz sitenin Gizlilik Sözleşmesi ve Kullanıcı Sözleşmesine okumanızı öneririz.</p>
                                 <br>
                                 <h2 class="text-xl font-semibold mb-2">2.6- Sorumluluklar ve Yükümlülükler:</h2>
                                 <p>Lakeban.com, Ortakları, Lakeban hizmet ağı ve Lakeban yazılım hizmetlerini kullanırken Kullanım Sözleşmemize ve Gizlilik Sözleşmemize tam olarak uymakla yükümlüsünüzdür.</p>
                                  <br>
                                  <h2 class="text-xl font-semibold mb-2">3- YASAL DURUM:</h2>
                                  <p>Merkezimiz Türkiye Cumhuriyeti’ndedir. Bu yüzden hem biz hem de siz Türkiye Cumhuriyeti yasalarına tabiyiz. Ayrıca kendi ülkenizin yasalarınada uymakla yükümlüsünüz. Eğer ikamet ettiğiniz ve vatandaşı olduğunuz devletin yasalarını ihlal etmeniz ve hakkınızda bir şikayet olması durumunda, devletiniz sizin verilerinizi istediği zaman, sizin yaptığınız ihlalin derecesine göre verilerinizi paylaşabiliriz. (Yasaları ciddi şekilde ihlal ettiğiniz durumlarda).</p>
                
                </section>
<p>ÖNEMLİ: Lakeban.com da bir Lakeban hesabı açtığınız an Gizlilik Politikamızı ve Kullanıcı Sözleşmemizi kabul etmiş ve okumuş sayılırsınız.</p>
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