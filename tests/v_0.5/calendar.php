<?php
require 'config.php';

$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');
$child_id = $_GET['child'] ?? 1;

$children = $conn->query("SELECT * FROM children ORDER BY name");

$scheduled = $conn->query("
    SELECT s.*, t.title, t.minutes_reward, t.category 
    FROM scheduled_tasks s 
    JOIN tasks t ON s.task_id = t.id 
    WHERE MONTH(s.scheduled_date) = $month AND YEAR(s.scheduled_date) = $year
    ORDER BY s.scheduled_date, s.scheduled_time
");

$tasks = $conn->query("SELECT * FROM tasks ORDER BY title");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['schedule_task'])) {
        $task_id = (int)$_POST['task_id'];
        $date = $_POST['date'];
        $time = $_POST['time'];
        $repeat = $_POST['repeat'];
        $repeat_until = $_POST['repeat_until'] ?: null;
        
        $conn->query("INSERT INTO scheduled_tasks (task_id, scheduled_date, scheduled_time, repeat_type, repeat_until) 
            VALUES ($task_id, '$date', '$time', '$repeat', " . ($repeat_until ? "'$repeat_until'" : "NULL") . ")");
        
        if ($repeat !== 'none' && $repeat_until) {
            $current = new DateTime($date);
            $end = new DateTime($repeat_until);
            
            while ($current < $end) {
                if ($repeat === 'daily') $current->modify('+1 day');
                elseif ($repeat === 'weekly') $current->modify('+1 week');
                elseif ($repeat === 'monthly') $current->modify('+1 month');
                
                if ($current <= $end) {
                    $d = $current->format('Y-m-d');
                    $conn->query("INSERT INTO scheduled_tasks (task_id, scheduled_date, scheduled_time, repeat_type, repeat_until) 
                        VALUES ($task_id, '$d', '$time', '$repeat', " . ($repeat_until ? "'$repeat_until'" : "NULL") . ")");
                }
            }
        }
        
        header('Location: calendar.php?month=' . $month . '&year=' . $year);
        exit;
    }
    
    if (isset($_POST['delete_scheduled'])) {
        $id = (int)$_POST['scheduled_id'];
        $conn->query("DELETE FROM scheduled_tasks WHERE id = $id");
        header('Location: calendar.php?month=' . $month . '&year=' . $year);
        exit;
    }
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$dayOfWeek = date('w', $firstDay);
if ($dayOfWeek == 0) $dayOfWeek = 7;

$monthName = date('F', $firstDay);
$monthNames = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalender - Familien-Aufgaben-App</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { touch-action: manipulation; }
        :root {
            --primary: #6366f1;
            --secondary: #8b5cf6;
            --success: #10b981;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-secondary: #64748b;
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
        .card {
            background: var(--card);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .calendar-nav h2 {
            font-family: 'Nunito', sans-serif;
            font-size: 32px;
        }
        .calendar-nav a {
            padding: 16px 28px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 18px;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .calendar-header {
            background: var(--primary);
            color: white;
            padding: 18px;
            text-align: center;
            font-weight: 700;
            font-size: 16px;
            border-radius: 12px 12px 0 0;
        }
        .calendar-day {
            min-height: 120px;
            padding: 12px;
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            position: relative;
        }
        .calendar-day.other-month {
            background: #f1f5f9;
            opacity: 0.5;
        }
        .calendar-day.today {
            background: #fef3c7;
            border-color: #f59e0b;
        }
        .day-number {
            font-weight: 800;
            margin-bottom: 8px;
            font-size: 18px;
        }
        .scheduled-task {
            font-size: 13px;
            padding: 6px 10px;
            background: var(--primary);
            color: white;
            border-radius: 8px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .form-section {
            background: #f8fafc;
            padding: 28px;
            border-radius: 20px;
            margin-top: 24px;
        }
        .form-section h3 {
            font-family: 'Nunito', sans-serif;
            font-size: 24px;
            margin-bottom: 20px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }
        .form-group { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 18px 22px;
            border: 3px solid #e5e7eb;
            border-radius: 14px;
            font-size: 18px;
            min-height: 56px;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn {
            padding: 18px 32px;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 18px;
            cursor: pointer;
            min-height: 56px;
        }
        .btn:active { transform: scale(0.98); }
        .btn-add { background: var(--success); color: white; }
        .btn-delete { background: #ef4444; color: white; padding: 14px 24px; font-size: 16px; }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">📅 Aufgaben-Kalender</div>
            <nav class="nav">
                <a href="index.php">🏠 Startseite</a>
                <a href="admin.php">⚙️ Admin</a>
                <a href="stats.php">📊 Statistik</a>
            </nav>
        </div>
    </header>
    
    <main>
        <div class="card">
            <div class="calendar-nav">
                <?php 
                $prevMonth = $month - 1;
                $prevYear = $year;
                if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
                
                $nextMonth = $month + 1;
                $nextYear = $year;
                if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
                ?>
                <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>">← Zurück</a>
                <h2><?php echo $monthNames[$month-1] . ' ' . $year; ?></h2>
                <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>">Weiter →</a>
            </div>
            
            <div class="calendar-grid">
                <div class="calendar-header">Mo</div>
                <div class="calendar-header">Di</div>
                <div class="calendar-header">Mi</div>
                <div class="calendar-header">Do</div>
                <div class="calendar-header">Fr</div>
                <div class="calendar-header">Sa</div>
                <div class="calendar-header">So</div>
                
                <?php
                $scheduledArray = [];
                while ($s = $scheduled->fetch_assoc()) {
                    $scheduledArray[$s['scheduled_date']][] = $s;
                }
                
                for ($i = 1; $i < $dayOfWeek; $i++) {
                    $prevDays = cal_days_in_month(CAL_GREGORIAN, $prevMonth, $prevYear);
                    echo '<div class="calendar-day other-month"><span class="day-number">' . ($prevDays - $dayOfWeek + $i + 1) . '</span></div>';
                }
                
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $isToday = ($day == date('j') && $month == date('n') && $year == date('Y'));
                    echo '<div class="calendar-day' . ($isToday ? ' today' : '') . '">';
                    echo '<span class="day-number">' . $day . '</span>';
                    
                    if (isset($scheduledArray[$dateStr])) {
                        foreach ($scheduledArray[$dateStr] as $task) {
                            echo '<div class="scheduled-task" title="' . htmlspecialchars($task['title']) . ' (' . substr($task['scheduled_time'], 0, 5) . ')">';
                            echo htmlspecialchars($task['title']) . ' ' . substr($task['scheduled_time'], 0, 5);
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                }
                
                $remaining = 42 - ($dayOfWeek - 1 + $daysInMonth);
                for ($i = 1; $i <= $remaining; $i++) {
                    echo '<div class="calendar-day other-month"><span class="day-number">' . $i . '</span></div>';
                }
                ?>
            </div>
        </div>
        
        <div class="card">
            <div class="form-section">
                <h3>📅 Aufgabe planen</h3>
                <form method="POST" class="form-grid">
                    <div class="form-group">
                        <label>Aufgabe</label>
                        <select name="task_id" required>
                            <?php while ($task = $tasks->fetch_assoc()): ?>
                                <option value="<?php echo $task['id']; ?>"><?php echo htmlspecialchars($task['title']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Datum</label>
                        <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Uhrzeit</label>
                        <input type="time" name="time" required value="09:00">
                    </div>
                    <div class="form-group">
                        <label>Wiederholen</label>
                        <select name="repeat">
                            <option value="none">Keine Wiederholung</option>
                            <option value="daily">Täglich</option>
                            <option value="weekly">Wöchentlich</option>
                            <option value="monthly">Monatlich</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Wiederholen bis</label>
                        <input type="date" name="repeat_until">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" name="schedule_task" class="btn btn-add">✓ Termin planen</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <h3>📋 Geplante Aufgaben</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
                <thead>
                    <tr style="background: #f8fafc;">
                        <th style="padding: 12px; text-align: left;">Datum</th>
                        <th style="padding: 12px; text-align: left;">Uhrzeit</th>
                        <th style="padding: 12px; text-align: left;">Aufgabe</th>
                        <th style="padding: 12px; text-align: left;">Wiederholung</th>
                        <th style="padding: 12px; text-align: left;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $scheduled->data_seek(0);
                    while ($s = $scheduled->fetch_assoc()):
                    ?>
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo date('d.m.Y', strtotime($s['scheduled_date'])); ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo substr($s['scheduled_time'], 0, 5); ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;"><?php echo htmlspecialchars($s['title']); ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                            <?php if ($s['repeat_type'] !== 'none'): ?>
                                <span style="background: #fef3c7; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo $s['repeat_type']; ?>
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="scheduled_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" name="delete_scheduled" class="btn btn-delete">Löschen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
