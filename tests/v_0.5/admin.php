<?php
require 'config.php';
requireAdmin();

$children = $conn->query("SELECT * FROM children ORDER BY name");
$tasks = $conn->query("SELECT t.*, c.name as child_name FROM tasks t LEFT JOIN children c ON t.child_id = c.id ORDER BY t.category, t.title");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_child'])) {
        $name = $_POST['name'];
        $color = $_POST['color'];
        $conn->query("INSERT INTO children (name, color) VALUES ('$name', '$color')");
        $child_id = $conn->insert_id;
        $conn->query("INSERT INTO time_credits (child_id, minutes_total, minutes_used) VALUES ($child_id, 0, 0)");
        header('Location: admin.php');
        exit;
    }
    
    if (isset($_POST['add_task'])) {
        $title = $_POST['title'];
        $description = $_POST['description'];
        $minutes_reward = (int)$_POST['minutes_reward'];
        $category = $_POST['category'];
        $image = $_POST['image'] ?? '';
        $custom_image = $_POST['custom_image'] ?? '';
        if (!empty($custom_image)) {
            $image = $custom_image;
        }
        $child_id = $_POST['child_id'] ?: 'NULL';
        
        $conn->query("INSERT INTO tasks (title, description, minutes_reward, category, image, child_id) VALUES ('$title', '$description', $minutes_reward, '$category', '$image', $child_id)");
        header('Location: admin.php');
        exit;
    }
    
    if (isset($_POST['delete_task'])) {
        $task_id = (int)$_POST['task_id'];
        $conn->query("DELETE FROM tasks WHERE id = $task_id");
        header('Location: admin.php');
        exit;
    }
    
    if (isset($_POST['delete_child'])) {
        $child_id = (int)$_POST['child_id'];
        $conn->query("DELETE FROM children WHERE id = $child_id");
        header('Location: admin.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Familien-Aufgaben-App</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { touch-action: manipulation; }
        :root {
            --primary: #6366f1;
            --secondary: #8b5cf6;
            --danger: #ef4444;
            --success: #10b981;
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
        .nav a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
            font-weight: 700;
            font-size: 18px;
            padding: 12px 20px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            display: inline-block;
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
        .card-title {
            font-family: 'Nunito', sans-serif;
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            padding: 16px 20px;
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
            transition: all 0.2s;
            min-height: 56px;
        }
        .btn:active { transform: scale(0.98); }
        .btn-add { background: var(--success); color: white; }
        .btn-add:hover { background: #059669; }
        .btn-delete { background: var(--danger); color: white; }
        .btn-delete:hover { background: #dc2626; }
        .image-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .image-selector input[type="radio"] {
            display: none;
        }
        .img-option {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            background: #f1f5f9;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border: 3px solid transparent;
        }
        .img-option:hover {
            background: #e2e8f0;
            transform: scale(1.1);
        }
        .image-selector input[type="radio"]:checked + .img-option {
            background: var(--primary);
            border-color: var(--secondary);
            box-shadow: 0 4px 12px rgba(99,102,241,0.4);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
            font-size: 16px;
        }
        .data-table th {
            font-weight: 700;
            background: #f8fafc;
            color: var(--text);
            background: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-category {
            background: var(--primary);
            color: white;
        }
        .badge-reward {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">⚙️ Admin-Bereich</div>
            <nav class="nav">
                <a href="index.php">🏠 Startseite</a>
                <a href="calendar.php">📅 Kalender</a>
                <a href="stats.php">📊 Statistik</a>
            </nav>
        </div>
    </header>
    
    <main>
        <div class="card">
            <h2 class="card-title">👶 Kind hinzufügen</h2>
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Farbe</label>
                    <input type="color" name="color" value="#6366f1">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" name="add_child" class="btn btn-add">+ Kind hinzufügen</button>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2 class="card-title">👶 Kinder</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Farbe</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($child = $children->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($child['name']); ?></td>
                        <td><span class="badge" style="background: <?php echo $child['color']; ?>; color: white;">●</span></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="child_id" value="<?php echo $child['id']; ?>">
                                <button type="submit" name="delete_child" class="btn btn-delete" onclick="return confirm('Kind wirklich löschen?')">Löschen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h2 class="card-title">➕ Neue Aufgabe</h2>
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <label>Titel</label>
                    <input type="text" name="title" required>
                </div>
                <div class="form-group">
                    <label>Beschreibung</label>
                    <input type="text" name="description">
                </div>
                <div class="form-group">
                    <label>Minuten奖励</label>
                    <input type="number" name="minutes_reward" value="15" min="1">
                </div>
                <div class="form-group">
                    <label>Kategorie</label>
                    <select name="category">
                        <option>Hausaufgaben</option>
                        <option>Haushalt</option>
                        <option>Haustier</option>
                        <option>Sonstiges</option>
                    </select>
                </div>
                <div class="form-group">
                    <label> Bild / Icon auswählen</label>
                    <div class="image-selector">
                        <input type="radio" name="image" id="img-none" value="" checked>
                        <label for="img-none" class="img-option">🚫</label>
                        
                        <input type="radio" name="image" id="img-math" value="📐">
                        <label for="img-math" class="img-option">📐</label>
                        
                        <input type="radio" name="image" id="img-book" value="📚">
                        <label for="img-book" class="img-option">📚</label>
                        
                        <input type="radio" name="image" id="img-clean" value="🧹">
                        <label for="img-clean" class="img-option">🧹</label>
                        
                        <input type="radio" name="image" id="img-pet" value="🐕">
                        <label for="img-pet" class="img-option">🐕</label>
                        
                        <input type="radio" name="image" id="img-food" value="🍽️">
                        <label for="img-food" class="img-option">🍽️</label>
                        
                        <input type="radio" name="image" id="img-bed" value="🛏️">
                        <label for="img-bed" class="img-option">🛏️</label>
                        
                        <input type="radio" name="image" id="img-trash" value="🗑️">
                        <label for="img-trash" class="img-option">🗑️</label>
                        
                        <input type="radio" name="image" id="img-clothes" value="👕">
                        <label for="img-clothes" class="img-option">👕</label>
                        
                        <input type="radio" name="image" id="img-dog" value="🐶">
                        <label for="img-dog" class="img-option">🐶</label>
                        
                        <input type="radio" name="image" id="img-cat" value="🐱">
                        <label for="img-cat" class="img-option">🐱</label>
                        
                        <input type="radio" name="image" id="img-star" value="⭐">
                        <label for="img-star" class="img-option">⭐</label>
                        
                        <input type="radio" name="image" id="img-trophy" value="🏆">
                        <label for="img-trophy" class="img-option">🏆</label>
                        
                        <input type="radio" name="image" id="img-heart" value="❤️">
                        <label for="img-heart" class="img-option">❤️</label>
                        
                        <input type="radio" name="image" id="img-music" value="🎵">
                        <label for="img-music" class="img-option">🎵</label>
                        
                        <input type="radio" name="image" id="img-sport" value="⚽">
                        <label for="img-sport" class="img-option">⚽</label>
                        
                        <input type="radio" name="image" id="img-game" value="🎮">
                        <label for="img-game" class="img-option">🎮</label>
                        
                        <input type="radio" name="image" id="img-phone" value="📱">
                        <label for="img-phone" class="img-option">📱</label>
                    </div>
                    <input type="text" name="custom_image" placeholder="Oder eigene URL eingeben" style="margin-top: 10px;">
                </div>
                <div class="form-group">
                    <label>Für Kind (leer = alle)</label>
                    <select name="child_id">
                        <option value="">Alle Kinder</option>
                        <?php 
                        $children->data_seek(0);
                        while ($child = $children->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $child['id']; ?>"><?php echo htmlspecialchars($child['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" name="add_task" class="btn btn-add">+ Aufgabe hinzufügen</button>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2 class="card-title">📋 Aufgaben</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Bild</th>
                        <th>Titel</th>
                        <th>Kategorie</th>
                        <th>Minuten</th>
                        <th>Zugewiesen an</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($task = $tasks->fetch_assoc()): ?>
                    <tr>
                        <td><span style="font-size: 32px;"><?php echo $task['image'] ?: '📋'; ?></span></td>
                        <td><?php echo htmlspecialchars($task['title']); ?></td>
                        <td><span class="badge badge-category"><?php echo htmlspecialchars($task['category']); ?></span></td>
                        <td><span class="badge badge-reward">+<?php echo $task['minutes_reward']; ?> Min.</span></td>
                        <td><?php echo $task['child_name'] ? htmlspecialchars($task['child_name']) : 'Alle'; ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                <button type="submit" name="delete_task" class="btn btn-delete" onclick="return confirm('Aufgabe wirklich löschen?')">Löschen</button>
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
