<?php
require 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    $result = $conn->query("SELECT password_hash FROM admin_settings LIMIT 1");
    $admin = $result->fetch_assoc();
    
    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Falsches Passwort!';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Familien-Aufgaben-App</title>
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
        .login-container {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            padding: 48px;
            max-width: 500px;
            width: 100%;
        }
        .logo { text-align: center; margin-bottom: 40px; }
        .logo h1 {
            font-family: 'Nunito', sans-serif;
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
        }
        .logo p { color: #64748b; margin-top: 12px; font-size: 18px; }
        .form-group { margin-bottom: 24px; }
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
        .btn-login {
            width: 100%;
            padding: 24px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 22px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.2s;
            min-height: 70px;
        }
        .btn-login:hover { transform: translateY(-3px); }
        .btn-login:active { transform: scale(0.98); }
        .error {
            background: #fef2f2;
            border: 2px solid #fecaca;
            color: #dc2626;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 16px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            color: #64748b;
            text-decoration: none;
            font-size: 18px;
            padding: 16px;
        }
        .back-link:hover { color: #6366f1; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>🔐 Admin Login</h1>
            <p>Elternbereich</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Passwort</label>
                <input type="password" name="password" required autofocus>
            </div>
            <button type="submit" class="btn-login">Anmelden</button>
        </form>
        <a href="index.php" class="back-link">← Zurück zur Startseite</a>
    </div>
</body>
</html>
