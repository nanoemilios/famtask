<?php
require 'config.php';

$children = $conn->query("SELECT * FROM children ORDER BY name");
$child_id = $_GET['child'] ?? $children->fetch_assoc()['id'] ?? 0;

$timer = getActiveTimer($child_id);
$credits = getTimeCredits($child_id);
$tasks = $conn->query("SELECT * FROM tasks WHERE child_id IS NULL OR child_id = $child_id ORDER BY category, title");

$active_child = null;
$children->data_seek(0);
while ($c = $children->fetch_assoc()) {
    if ($c['id'] == $child_id) {
        $active_child = $c;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_task'])) {
    $task_id = (int)$_POST['task_id'];
    $task = $conn->query("SELECT * FROM tasks WHERE id = $task_id")->fetch_assoc();
    
    $conn->query("INSERT INTO task_completions (task_id, child_id, minutes_earned) VALUES ($task_id, $child_id, {$task['minutes_reward']})");
    $conn->query("UPDATE time_credits SET minutes_total = minutes_total + {$task['minutes_reward']} WHERE child_id = $child_id");
    
    $credits = getTimeCredits($child_id);
    $tasks = $conn->query("SELECT * FROM tasks WHERE child_id IS NULL OR child_id = $child_id ORDER BY category, title");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_timer'])) {
    $activity = $_POST['activity'] ?? 'Freizeit';
    $conn->query("UPDATE timers SET is_running = 0 WHERE child_id = $child_id AND is_running = 1");
    $stmt = $conn->prepare("INSERT INTO timers (child_id, activity_name) VALUES (?, ?)");
    $stmt->bind_param('is', $child_id, $activity);
    $stmt->execute();
    $timer = getActiveTimer($child_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stop_timer'])) {
    if ($timer) {
        $started = new DateTime($timer['started_at']);
        $now = new DateTime();
        $diff = $now->diff($started);
        $minutes = $diff->h * 60 + $diff->i;
        
        if ($minutes < 1) $minutes = 1;
        
        $stmt = $conn->prepare("INSERT INTO timer_logs (child_id, activity_name, duration_minutes, started_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isis', $child_id, $timer['activity_name'], $minutes, $timer['started_at']);
        $stmt->execute();
        
        $conn->query("UPDATE time_credits SET minutes_used = minutes_used + $minutes WHERE child_id = $child_id");
        $conn->query("DELETE FROM timers WHERE id = {$timer['id']}");
        
        $timer = null;
        $credits = getTimeCredits($child_id);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Familien-Aufgaben-App</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #6366f1;
            --secondary: #8b5cf6;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-secondary: #64748b;
        }
        html, body {
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
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
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(99,102,241,0.3);
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
            align-items: center;
        }
        .child-selector a {
            padding: 16px 32px;
            border-radius: 24px;
            text-decoration: none;
            color: white;
            font-weight: 700;
            font-size: 22px;
            transition: all 0.2s;
            opacity: 0.7;
            min-height: 64px;
            display: flex;
            align-items: center;
        }
        .child-selector a.active {
            opacity: 1;
            background: rgba(255,255,255,0.25) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            transform: scale(1.05);
        }
        .timer-display {
            background: rgba(255,255,255,0.2);
            padding: 20px 28px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-weight: 700;
            font-size: 20px;
        }
        .timer-display .time {
            font-family: 'Nunito', sans-serif;
            font-size: 32px;
            font-weight: 800;
        }
        .timer-display.running {
            background: var(--accent);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.5); }
            50% { box-shadow: 0 0 0 20px rgba(245,158,11,0); }
        }
        .credits-display {
            background: var(--success);
            padding: 16px 28px;
            border-radius: 24px;
            font-weight: 800;
            font-size: 22px;
        }
        .credits-display .available {
            color: white;
        }
        main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        @media (min-width: 768px) {
            .grid { grid-template-columns: 2fr 1fr; }
        }
        .card {
            background: var(--card);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .card-title {
            font-family: 'Nunito', sans-serif;
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .task-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 20px;
        }
        .task-card {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border-radius: 24px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: all 0.2s;
        }
        .task-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }
        .task-image-form {
            width: 100%;
        }
        .task-image-btn {
            width: 120px;
            height: 120px;
            border-radius: 24px;
            border: none;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(99,102,241,0.3);
        }
        .task-image-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 30px rgba(99,102,241,0.5);
        }
        .task-image-btn:active {
            transform: scale(0.95);
        }
        .task-emoji {
            font-size: 56px;
        }
        .task-emoji-large {
            font-size: 56px;
            margin-bottom: 16px;
        }
        .task-details h4 {
            font-weight: 700;
            margin: 16px 0 8px;
            font-size: 18px;
        }
        .task-details p {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .task-item {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            padding: 24px;
            border-radius: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            min-height: 100px;
        }
        .task-item:active {
            transform: scale(0.98);
        }
        .task-info h4 {
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 20px;
        }
        .task-info p {
            font-size: 16px;
            color: var(--text-secondary);
        }
        .task-reward {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .reward-badge {
            background: var(--accent);
            color: white;
            padding: 12px 20px;
            border-radius: 20px;
            font-weight: 800;
            font-size: 20px;
        }
        .btn {
            padding: 20px 36px;
            border: none;
            border-radius: 16px;
            font-weight: 800;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            min-height: 64px;
            min-width: 140px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .btn:active {
            transform: scale(0.95);
        }
        .btn-complete {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        .btn-complete:hover { background: #059669; }
        .btn-timer {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        .btn-timer:hover { background: #d97706; }
        .btn-stop {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        .btn-stop:hover { background: #dc2626; }
        .btn-admin {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .nav-links {
            display: flex;
            gap: 16px;
            margin-top: 12px;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 14px 24px;
            border-radius: 14px;
            background: rgba(255,255,255,0.15);
            font-size: 18px;
            font-weight: 600;
            min-height: 52px;
            display: flex;
            align-items: center;
        }
        .nav-links a:hover { background: rgba(255,255,255,0.25); }
        .timer-form {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .timer-form input {
            flex: 1;
            min-width: 200px;
            padding: 20px 24px;
            border: 3px solid #e5e7eb;
            border-radius: 16px;
            font-size: 18px;
            min-height: 64px;
        }
        .timer-form input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .empty-state {
            text-align: center;
            padding: 60px;
            color: var(--text-secondary);
            font-size: 20px;
        }
        .category-badge {
            font-size: 16px;
            padding: 8px 16px;
            border-radius: 12px;
            background: var(--primary);
            color: white;
            margin-right: 12px;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div>
                <div class="logo">🏠 Familien-Aufgaben-App</div>
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
            </div>
            
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <div class="credits-display" style="font-size: 24px;">
                    <span class="available">⏱️ <?php echo ($credits['minutes_total'] - $credits['minutes_used']); ?> Min.</span>
                </div>
                
                <?php if ($timer): ?>
                    <div class="timer-display running">
                        <span>🎮 <?php echo htmlspecialchars($timer['activity_name']); ?></span>
                        <span class="time" id="timer">00:00</span>
                    </div>
                <?php else: ?>
                    <div class="timer-display">
                        <span>⏸️ Kein aktiver Timer</span>
                    </div>
                <?php endif; ?>
                
                <div class="nav-links">
                    <a href="stats.php?child=<?php echo $child_id; ?>">📊 Statistik</a>
                    <a href="calendar.php">📅 Kalender</a>
                    <a href="admin.php">⚙️ Admin</a>
                </div>
            </div>
        </div>
    </header>
    
    <main>
        <div class="grid">
            <div class="card">
                <h2 class="card-title">📋 Aufgaben für heute</h2>
                
                <?php if ($tasks->num_rows === 0): ?>
                    <div class="empty-state">
                        <p>Keine Aufgaben vorhanden.</p>
                    </div>
                <?php else: ?>
                    <div class="task-grid">
                        <?php while ($task = $tasks->fetch_assoc()): ?>
                            <div class="task-card">
                                <?php if (!empty($task['image'])): ?>
                                    <form method="POST" class="task-image-form">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" name="complete_task" class="task-image-btn">
                                            <span class="task-emoji"><?php echo htmlspecialchars($task['image']); ?></span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="task-emoji-large">📋</div>
                                <?php endif; ?>
                                <div class="task-details">
                                    <h4><?php echo htmlspecialchars($task['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($task['description']); ?></p>
                                    <span class="reward-badge">+<?php echo $task['minutes_reward']; ?> Min.</span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2 class="card-title">🎮 Freizeit-Timer</h2>
                
                <?php if ($timer): ?>
                    <p style="margin-bottom: 20px; color: var(--text-secondary); font-size: 20px;">
                        🎮 <strong><?php echo htmlspecialchars($timer['activity_name']); ?></strong>
                    </p>
                    <form method="POST">
                        <button type="submit" name="stop_timer" class="btn btn-stop" style="width: 100%; font-size: 24px; padding: 28px;">
                            🛑 STOPP - Zeit verbrauchen
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="timer-form">
                        <input type="text" name="activity" placeholder="Was machst du?" value="Freizeit" style="font-size: 20px;">
                        <button type="submit" name="start_timer" class="btn btn-timer" style="font-size: 24px;">▶️ START</button>
                    </form>
                    <p style="margin-top: 16px; font-size: 14px; color: var(--text-secondary);">
                        Tipp: Jede Minute wird von deinem Zeitguthaben abgezogen!
                    </p>
                <?php endif; ?>
                
                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <h3 style="margin-bottom: 16px; font-size: 24px;">💰 Zeitguthaben</h3>
                <div style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); padding: 24px; border-radius: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 18px;">
                        <span>✅ Verdient:</span>
                        <strong><?php echo $credits['minutes_total']; ?> Min.</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 18px;">
                        <span>📺 Verbraucht:</span>
                        <strong style="color: var(--danger);">-<?php echo $credits['minutes_used']; ?> Min.</strong>
                    </div>
                    <hr style="border: none; border-top: 2px solid #cbd5e1; margin: 12px 0;">
                    <div style="display: flex; justify-content: space-between; font-size: 24px; font-weight: 800;">
                        <span>💚 Verfügbar:</span>
                        <strong style="color: var(--success);"><?php echo ($credits['minutes_total'] - $credits['minutes_used']); ?> Min.</strong>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <?php if ($timer): ?>
    <script>
        const startTime = new Date('<?php echo $timer['started_at']; ?>').getTime();
        
        function updateTimer() {
            const now = new Date().getTime();
            const elapsed = now - startTime;
            const minutes = Math.floor(elapsed / 60000);
            const seconds = Math.floor((elapsed % 60000) / 1000);
            document.getElementById('timer').textContent = 
                String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        }
        
        updateTimer();
        setInterval(updateTimer, 1000);
    </script>
    <?php endif; ?>
</body>
</html>
