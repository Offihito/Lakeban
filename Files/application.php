<?php
session_start();



require_once 'database/db_connection.php';

// === TÜM BAŞVURULARI ÇEK ===
$stmt = $pdo->query("SELECT * FROM applications ORDER BY created_at DESC");
$applications = $stmt->fetchAll();


?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lakeban | Başvurular</title>
    <link rel="icon" type="image/x-icon" href="LakebanAssets/icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0D0D0D;
            --bg-medium: #1A1A1A;
            --bg-light: #2C2C2C;
            --primary-green: #00F57A;
            --text-light: #E0E0E0;
            --text-medium: #A0A0A0;
            --border-radius: 12px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: var(--bg-dark); color: var(--text-light); font-family: 'Inter', sans-serif; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 20px 0; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 30px; }
        .header h1 { font-size: 1.8rem; background: linear-gradient(90deg, var(--primary-green), #fff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .logout-btn { background: #ff4444; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: 0.3s; }
        .logout-btn:hover { background: #ff6666; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-medium); padding: 20px; border-radius: var(--border-radius); border: 1px solid rgba(255,255,255,0.1); text-align: center; }
        .stat-card h3 { font-size: 2rem; color: var(--primary-green); margin-bottom: 5px; }
        .stat-card p { color: var(--text-medium); font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; background: var(--bg-medium); border-radius: var(--border-radius); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        th { background: rgba(0, 245, 122, 0.1); color: var(--primary-green); padding: 15px; text-align: left; font-weight: 600; }
        td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
        tr:hover { background: rgba(0, 245, 122, 0.05); }
        .github-link { color: var(--primary-green); text-decoration: none; }
        .github-link:hover { text-decoration: underline; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-medium); }
        .empty-state i { font-size: 3rem; color: var(--primary-green); margin-bottom: 15px; }
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; }
        .badge-frontend { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .badge-backend { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .badge-fullstack { background: rgba(168, 85, 247, 0.2); color: #c084fc; }
        .badge-other { background: rgba(251, 146, 60, 0.2); color: #fdba74; }

        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 15px; }
            td { border: none; position: relative; padding-left: 50%; }
            td::before { content: attr(data-label); position: absolute; left: 15px; width: 45%; font-weight: 600; color: var(--primary-green); }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Gelen Başvurular</h1>
        <a href="logout.php"><button class="logout-btn">Çıkış Yap</button></a>
    </div>

    <div class="stats">
        <div class="stat-card"><h3><?php echo $total; ?></h3><p>Toplam</p></div>
        <div class="stat-card"><h3><?php echo $frontend; ?></h3><p>Frontend</p></div>
        <div class="stat-card"><h3><?php echo $backend; ?></h3><p>Backend</p></div>
        <div class="stat-card"><h3><?php echo $fullstack; ?></h3><p>Fullstack</p></div>
    </div>

    <?php if (empty($applications)): ?>
        <div class="empty-state">
            <h3>Henüz başvuru yok</h3>
            <p>İlk başvuru geldiğinde burada görünecek.</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Ad Soyad</th>
                    <th>E-posta</th>
                    <th>Tür</th>
                    <th>Diller</th>
                    <th>GitHub</th>
                    <th>Projeler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                <tr>
                    <td data-label="Tarih"><?php echo date('d.m.Y H:i', strtotime($app['created_at'])); ?></td>
                    <td data-label="Ad"><?php echo htmlspecialchars($app['name']); ?></td>
                    <td data-label="E-posta"><a href="mailto:<?php echo htmlspecialchars($app['email']); ?>" style="color:var(--primary-green);"><?php echo htmlspecialchars($app['email']); ?></a></td>
                    <td data-label="Tür">
                        <span class="badge badge-<?php echo $app['dev_type']; ?>">
                            <?php
                            $types = ['frontend' => 'Frontend', 'backend' => 'Backend', 'fullstack' => 'Fullstack', 'other' => 'Diğer'];
                            echo $types[$app['dev_type']] ?? ucfirst($app['dev_type']);
                            ?>
                        </span>
                    </td>
                    <td data-label="Diller"><?php echo nl2br(htmlspecialchars($app['languages'])); ?></td>
                    <td data-label="GitHub">
                        <?php if (!empty($app['github'])): ?>
                            <a href="https://github.com/<?php echo htmlspecialchars($app['github']); ?>" target="_blank" class="github-link">
                                @<?php echo htmlspecialchars($app['github']); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--text-medium);">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Projeler"><?php echo nl2br(htmlspecialchars($app['projects'] ?: '—')); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>