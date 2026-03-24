<?php
$host = $_POST['db_host'] ?? 'localhost';
$user = $_POST['db_user'] ?? 'adminer';
$password = $_POST['db_password'] ?? '';
$database = $_POST['db_name'] ?? 'famtask';
$admin_password = $_POST['admin_password'] ?? '';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = new mysqli($host, $user, $password);
    
    if ($conn->connect_error) {
        $errors[] = "Verbindung fehlgeschlagen: " . $conn->connect_error;
    } else {
        $conn->query("CREATE DATABASE IF NOT EXISTS `$database`");
        $conn->select_db($database);
        
        $conn->query("CREATE TABLE IF NOT EXISTS children (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            avatar VARCHAR(255) DEFAULT '',
            color VARCHAR(20) DEFAULT '#6366F1',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            minutes_reward INT DEFAULT 15,
            category VARCHAR(50) DEFAULT 'Hausaufgaben',
            image VARCHAR(500) DEFAULT '',
            child_id INT,
            created_by INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE SET NULL
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS task_completions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            child_id INT NOT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            minutes_earned INT NOT NULL,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS time_credits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            child_id INT NOT NULL UNIQUE,
            minutes_total INT DEFAULT 0,
            minutes_used INT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS timers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            child_id INT NOT NULL,
            activity_name VARCHAR(255) DEFAULT 'Freizeit',
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            paused_at TIMESTAMP NULL,
            is_running TINYINT(1) DEFAULT 1,
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS timer_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            child_id INT NOT NULL,
            activity_name VARCHAR(255),
            duration_minutes INT NOT NULL,
            started_at TIMESTAMP,
            ended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS scheduled_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            scheduled_date DATE NOT NULL,
            scheduled_time TIME NOT NULL,
            repeat_type ENUM('none', 'daily', 'weekly', 'monthly') DEFAULT 'none',
            repeat_until DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS admin_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);
        $conn->query("INSERT INTO admin_settings (password_hash) VALUES ('$password_hash')");
        
        $conn->query("INSERT INTO children (name, color) VALUES ('Santiago', '#ff0000')");
        $conn->query("INSERT INTO children (name, color) VALUES ('Samuel', '#0000ff')");
        
        $conn->query("INSERT INTO tasks (title, description, minutes_reward, category) VALUES 
            ('Hausaufgaben', 'Hausaufgaben erledigen', 30, 'Hausaufgaben'),
            ('Betten', 'Bett machen', 15, 'Hausaufgaben'),
            ('Zimmer aufräumen', 'Zimmer sauber machen', 20, 'Haushalt')");
        
        foreach ([1, 2] as $child_id) {
            $conn->query("INSERT INTO time_credits (child_id, minutes_total, minutes_used) VALUES ($child_id, 60, 0)");
        }
        
        $config_content = "<?php\n";
        $config_content .= "\$db_host = '$host';\n";
        $config_content .= "\$db_user = '$user';\n";
        $config_content .= "\$db_password = '$password';\n";
        $config_content .= "\$db_name = '$database';\n";
        $config_content .= "\n";
        $config_content .= "\$conn = new mysqli(\$db_host, \$db_user, \$db_password, \$db_name);\n";
        $config_content .= "if (\$conn->connect_error) {\n";
        $config_content .= "    die('Datenbankverbindung fehlgeschlagen: ' . \$conn->connect_error);\n";
        $config_content .= "}\n";
        $config_content .= "\$conn->set_charset('utf8mb4');\n";
        $config_content .= "session_start();\n";
        
        file_put_contents('config.php', $config_content);
        
        $success = true;
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Familien-Aufgaben-App - Installation</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { touch-action: manipulation; }
        body {
            font-family: 'Nunito', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .install-container {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            padding: 48px;
            max-width: 600px;
            width: 100%;
        }
        .logo {
            text-align: center;
            margin-bottom: 40px;
        }
        .logo h1 {
            font-family: 'Nunito', sans-serif;
            font-size: 36px;
            font-weight: 800;
            color: #1e293b;
        }
        .logo p {
            color: #64748b;
            margin-top: 12px;
            font-size: 20px;
        }
        .form-group {
            margin-bottom: 24px;
        }
        .form-group label {
            display: block;
            font-weight: 700;
            color: #374151;
            margin-bottom: 12px;
            font-size: 18px;
        }
        .form-group input {
            width: 100%;
            padding: 20px 24px;
            border: 3px solid #e5e7eb;
            border-radius: 16px;
            font-size: 20px;
            transition: all 0.2s;
            min-height: 60px;
        }
        .form-group input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
        }
        .btn-install {
            width: 100%;
            padding: 24px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 22px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            min-height: 70px;
        }
        .btn-install:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px -10px rgba(99,102,241,0.5);
        }
        .btn-install:active {
            transform: scale(0.98);
        }
        .error {
            background: #fef2f2;
            border: 2px solid #fecaca;
            color: #dc2626;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 16px;
        }
        .success {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
            color: #16a34a;
            padding: 32px;
            border-radius: 16px;
            text-align: center;
        }
        .success h2 { margin-bottom: 16px; font-size: 28px; }
        .success a {
            display: inline-block;
            margin-top: 20px;
            padding: 20px 40px;
            background: #6366f1;
            color: white;
            text-decoration: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 20px;
        }
        .success a:hover { background: #4f46e5; }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="logo">
            <h1>🏠 Familien-Aufgaben-App</h1>
            <p>Installation</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $e): ?>
                    <p><?php echo htmlspecialchars($e); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <h2>✅ Installation erfolgreich!</h2>
                <p>Die Datenbank wurde erstellt und konfiguriert.</p>
                <a href="index.php">Zur App →</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>Datenbank-Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Datenbank-Benutzer</label>
                    <input type="text" name="db_user" value="adminer" required>
                </div>
                <div class="form-group">
                    <label>Datenbank-Passwort</label>
                    <input type="password" name="db_password" placeholder="Passwort eingeben">
                </div>
                <div class="form-group">
                    <label>Datenbank-Name</label>
                    <input type="text" name="db_name" value="famtask" required>
                </div>
                <div class="form-group">
                    <label>Admin-Passwort</label>
                    <input type="password" name="admin_password" placeholder="Passwort für Admin-Bereich" required minlength="4">
                </div>
                <button type="submit" class="btn-install">Installieren</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
