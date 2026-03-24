<?php
$db_host = 'localhost';
$db_user = 'adminer';
$db_password = 'Superdepor?604219';
$db_name = 'famtask';

$conn = new mysqli($db_host, $db_user, $db_password, $db_name);
if ($conn->connect_error) {
    die('Datenbankverbindung fehlgeschlagen: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
session_start();

function isAdmin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }
}

function getActiveTimer($child_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM timers WHERE child_id = ? AND is_running = 1 ORDER BY started_at DESC LIMIT 1");
    $stmt->bind_param('i', $child_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getTimeCredits($child_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM time_credits WHERE child_id = ?");
    $stmt->bind_param('i', $child_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ?: ['minutes_total' => 0, 'minutes_used' => 0];
}
