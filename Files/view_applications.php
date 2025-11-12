<?php
// Oturumu başlat
session_start();

// Veritabanı bağlantısını dahil et
require_once 'database/db_connection.php';

// Başvuruları al
try {
    $sql = "SELECT id, name, email, github_username, dev_type, languages, projects, created_at 
            FROM applications 
            ORDER BY created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Başvurular yüklenirken hata oluştu: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Başvuruları Görüntüle</title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet"/>
    <style>
        :root {
            --bg-dark: #0D0D0D;
            --bg-medium: #1A1A1A;
            --bg-light: #2C2C2C;
            --primary-green: #00F57A;
            --primary-glow: rgba(0, 245, 122, 0.2);
            --text-light: #E0E0E0;
            --text-medium: #A0A0A0;
            --border-radius: 12px;
            --transition-speed: 0.4s;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-light);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        .main-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 20px;
        }

        h1, h2 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        h2 {
            font-size: 2.5rem;
            margin-bottom: 2rem;
            background: linear-gradient(90deg, var(--primary-green), #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 20px var(--primary-glow);
            text-align: center;
        }

        p {
            line-height: 1.7;
            color: var(--text-medium);
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }

        .header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 40px;
            background: rgba(13, 13, 13, 0.8);
            backdrop-filter: blur(10px);
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .header-logo {
            height: 40px;
            width: auto;
        }

        .header-nav a {
            color: var(--text-light);
            text-decoration: none;
            margin: 0 20px;
            font-weight: 500;
            position: relative;
            transition: color var(--transition-speed) ease;
        }

        .header-nav a:hover {
            color: var(--primary-green);
        }

        .header-nav a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--primary-green);
            transition: width var(--transition-speed) ease;
        }

        .header-nav a:hover::after {
            width: 100%;
        }

        .menu-toggle {
            display: none;
            font-size: 1.8rem;
            color: var(--text-light);
            cursor: pointer;
            z-index: 1002;
        }

        .mobile-nav {
            display: none;
            position: fixed;
            top: 0;
            right: -250px;
            width: 250px;
            height: 100%;
            background-color: var(--bg-medium);
            backdrop-filter: blur(10px);
            border-left: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 80px;
            transition: right 0.3s ease-in-out;
            z-index: 1001;
            flex-direction: column;
            align-items: center;
            box-shadow: -5px 0 15px rgba(0,0,0,0.3);
        }

        .mobile-nav.open {
            right: 0;
            display: flex;
        }

        .mobile-nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 15px 0;
            display: block;
            width: 80%;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .mobile-nav a:hover {
            background-color: rgba(0, 245, 122, 0.1);
            color: var(--primary-green);
        }

        section {
            padding: 100px 0;
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }

        section.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .applications-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-medium);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .applications-table th,
        .applications-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-medium);
            font-size: 1rem;
        }

        .applications-table th {
            background: var(--bg-light);
            color: var(--text-light);
            font-weight: 600;
        }

        .applications-table tr:hover {
            background: rgba(0, 245, 122, 0.1);
        }

        .applications-table td a {
            color: var(--primary-green);
            text-decoration: none;
        }

        .applications-table td a:hover {
            text-decoration: underline;
        }

        .error-message {
            background-color: rgba(255, 0, 0, 0.2);
            color: #ff4d4d;
            border: 1px solid #ff4d4d;
            padding: 15px;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 2rem;
        }

        .no-applications {
            text-align: center;
            color: var(--text-medium);
            font-size: 1.2rem;
        }

        @media (max-width: 768px) {
            .header-nav {
                display: none;
            }
            .menu-toggle {
                display: block;
            }
            h2 {
                font-size: 2rem;
            }
            .applications-table {
                font-size: 0.9rem;
            }
            .applications-table th,
            .applications-table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
   

    <main class="main-container">
        <section class="visible">
            <h2>Başvuruları Görüntüle</h2>
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif (empty($applications)): ?>
                <p class="no-applications">Henüz başvuru bulunmamaktadır.</p>
            <?php else: ?>
                <table class="applications-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ad Soyad</th>
                            <th>E-posta</th>
                            <th>GitHub Kullanıcı Adı</th>
                            <th>Geliştirici Türü</th>
                            <th>Bildiği Diller</th>
                            <th>Projeler</th>
                            <th>Başvuru Tarihi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($app['id']); ?></td>
                                <td><?php echo htmlspecialchars($app['name']); ?></td>
                                <td><a href="mailto:<?php echo htmlspecialchars($app['email']); ?>"><?php echo htmlspecialchars($app['email']); ?></a></td>
                                <td>
                                    <?php if (!empty($app['github_username'])): ?>
                                        <a href="https://github.com/<?php echo htmlspecialchars($app['github_username']); ?>" target="_blank"><?php echo htmlspecialchars($app['github_username']); ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($app['dev_type'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($app['languages'] ?: '-'); ?></td>
                                <td><?php echo nl2br(htmlspecialchars($app['projects'] ?: '-')); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($app['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>

  

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Mobil menü işlevselliği
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileNav = document.getElementById('mobileNav');
            const mobileNavLinks = mobileNav.querySelectorAll('a');

            mobileMenuToggle.addEventListener('click', () => {
                mobileNav.classList.toggle('open');
            });

            mobileNavLinks.forEach(link => {
                link.addEventListener('click', () => {
                    mobileNav.classList.remove('open');
                });
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth > 768) {
                    mobileNav.classList.remove('open');
                }
            });
        });
    </script>
</body>
</html>