<?php
require 'config.php';

$child_id = $_GET['child'] ?? 1;
$children = $conn->query("SELECT * FROM children ORDER BY name");

$stats = $conn->query("
    SELECT 
        SUM(tc.minutes_earned) as total_earned,
        COUNT(tc.id) as total_completed
    FROM task_completions tc
    WHERE tc.child_id = $child_id
")->fetch_assoc();

$timer_stats = $conn->query("
    SELECT 
        SUM(duration_minutes) as total_used
    FROM timer_logs
    WHERE child_id = $child_id
")->fetch_assoc();

$recent_completions = $conn->query("
    SELECT tc.*, t.title, t.category
    FROM task_completions tc
    JOIN tasks t ON tc.task_id = t.id
    WHERE tc.child_id = $child_id
    ORDER BY tc.completed_at DESC
    LIMIT 10
");

$recent_timer = $conn->query("
    SELECT * FROM timer_logs
    WHERE child_id = $child_id
    ORDER BY ended_at DESC
    LIMIT 10
");

$category_stats = $conn->query("
    SELECT t.category, SUM(tc.minutes_earned) as minutes, COUNT(*) as count
    FROM task_completions tc
    JOIN tasks t ON tc.task_id = t.id
    WHERE tc.child_id = $child_id
    GROUP BY t.category
");

$credits = getTimeCredits($child_id);

$child_name = '';
$children->data_seek(0);
while ($c = $children->fetch_assoc()) {
    if ($c['id'] == $child_id) {
        $child_name = $c['name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistik - Familien-Aufgaben-App</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { touch-action: manipulation; }
        :root {
            --primary: #6366f1;
            --secondary: #8b5cf6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
        }
        body {
            font-family: 'Nunito', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            font-size: 18px;
        }
        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 20px 24px;
            color: white;
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .logo {
            font-family: 'Nunito', sans-serif;
            font-size: 28px;
            font-weight: 800;
        }
        .child-selector {
            display: flex;
            gap: 16px;
        }
        .child-selector a {
            padding: 14px 28px;
            border-radius: 24px;
            text-decoration: none;
            color: white;
            font-weight: 700;
            font-size: 20px;
            transition: all 0.2s;
            opacity: 0.7;
            min-height: 56px;
            display: flex;
            align-items: center;
        }
        .child-selector a.active {
            opacity: 1;
            background: rgba(255,255,255,0.25) !important;
        }
        .nav a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
            font-weight: 700;
            font-size: 18px;
            padding: 12px 20px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
        }
        .nav a:hover { background: rgba(255,255,255,0.25); }
        main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--card);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .stat-card h3 {
            font-family: 'Nunito', sans-serif;
            font-size: 18px;
            color: var(--text);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-family: 'Nunito', sans-serif;
            font-size: 48px;
            font-weight: 800;
        }
        .stat-value.success { color: var(--success); }
        .stat-value.danger { color: var(--danger); }
        .stat-value.warning { color: var(--warning); }
        .stat-value.primary { color: var(--primary); }
        .card {
            background: var(--card);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .card-title {
            font-family: 'Nunito', sans-serif;
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 24px;
        }
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 10px;
        }
        .history-item .info h4 {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .history-item .info p {
            font-size: 13px;
            color: #64748b;
        }
        .history-item .value {
            font-weight: 700;
            font-size: 18px;
        }
        .history-item .value.positive { color: var(--success); }
        .history-item .value.negative { color: var(--danger); }
        .category-bar {
            margin-bottom: 16px;
        }
        .category-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 14px;
        }
        .category-progress {
            height: 24px;
            background: #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }
        .category-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 12px;
            transition: width 0.5s ease;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">📊 Statistik - <?php echo htmlspecialchars($child_name); ?></div>
            <div class="child-selector">
                <?php 
                $children->data_seek(0);
                while ($c = $children->fetch_assoc()): 
                ?>
                    <a href="?child=<?php echo $c['id']; ?>" 
                       class="<?php echo $c['id'] == $child_id ? 'active' : ''; ?>"
                       style="background: <?php echo $c['color']; ?>">
                        <?php echo htmlspecialchars($c['name']); ?>
                    </a>
                <?php endwhile; ?>
            </div>
            <nav class="nav">
                <a href="index.php?child=<?php echo $child_id; ?>">🏠 Startseite</a>
                <a href="calendar.php">📅 Kalender</a>
                <a href="admin.php">⚙️ Admin</a>
            </nav>
        </div>
    </header>
    
    <main>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>⏱️ Gesamt verdient</h3>
                <div class="stat-value primary"><?php echo $stats['total_earned'] ?: 0; ?> Min.</div>
            </div>
            <div class="stat-card">
                <h3>📺 Zeit verbraucht</h3>
                <div class="stat-value danger"><?php echo $timer_stats['total_used'] ?: 0; ?> Min.</div>
            </div>
            <div class="stat-card">
                <h3>💰 Aktuelles Guthaben</h3>
                <div class="stat-value success"><?php echo ($credits['minutes_total'] - $credits['minutes_used']); ?> Min.</div>
            </div>
            <div class="stat-card">
                <h3>✅ Aufgaben erledigt</h3>
                <div class="stat-value warning"><?php echo $stats['total_completed'] ?: 0; ?></div>
            </div>
        </div>
        
        <div class="card">
            <h2 class="card-title">📈 Minuten nach Kategorie</h2>
            <?php if ($category_stats->num_rows > 0): ?>
                <?php 
                $max_minutes = 0;
                $cats = [];
                while ($c = $category_stats->fetch_assoc()) {
                    if ($c['minutes'] > $max_minutes) $max_minutes = $c['minutes'];
                    $cats[] = $c;
                }
                ?>
                <?php foreach ($cats as $cat): ?>
                    <div class="category-bar">
                        <div class="category-label">
                            <span><?php echo htmlspecialchars($cat['category']); ?></span>
                            <span><?php echo $cat['minutes']; ?> Min. (<?php echo $cat['count']; ?> Aufgaben)</span>
                        </div>
                        <div class="category-progress">
                            <div class="category-fill" style="width: <?php echo ($cat['minutes'] / $max_minutes) * 100; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">Noch keine Daten vorhanden</div>
            <?php endif; ?>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="card">
                <h2 class="card-title">✅ Letzte erledigte Aufgaben</h2>
                <?php if ($recent_completions->num_rows > 0): ?>
                    <div class="history-list">
                        <?php while ($c = $recent_completions->fetch_assoc()): ?>
                            <div class="history-item">
                                <div class="info">
                                    <h4><?php echo htmlspecialchars($c['title']); ?></h4>
                                    <p><?php echo date('d.m.Y H:i', strtotime($c['completed_at'])); ?></p>
                                </div>
                                <div class="value positive">+<?php echo $c['minutes_earned']; ?> Min.</div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">Noch keine Aufgaben erledigt</div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2 class="card-title">📺 Letzte Timer-Nutzung</h2>
                <?php if ($recent_timer->num_rows > 0): ?>
                    <div class="history-list">
                        <?php while ($t = $recent_timer->fetch_assoc()): ?>
                            <div class="history-item">
                                <div class="info">
                                    <h4><?php echo htmlspecialchars($t['activity_name']); ?></h4>
                                    <p><?php echo date('d.m.Y H:i', strtotime($t['ended_at'])); ?></p>
                                </div>
                                <div class="value negative">-<?php echo $t['duration_minutes']; ?> Min.</div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">Noch kein Timer verwendet</div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
