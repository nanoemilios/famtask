<?php
/**
 * FamTask – api.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Eine einzige Datei für:
 *   • Installer  → api.php im Browser öffnen (erster Aufruf)
 *   • REST-API   → GET (Daten laden) / POST (Daten speichern)
 *   • Updater    → api.php?action=update im Browser
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ══════════════════════════════════════════════════════════════════════════════
//  VERSIONSVERWALTUNG
//  Neue Version hinzufügen: APP_VERSION erhöhen + neuen Eintrag in $MIGRATIONS
// ══════════════════════════════════════════════════════════════════════════════
define('APP_VERSION', '1.1.0');
define('CFG',         __DIR__ . '/.famtask_cfg.php');

$MIGRATIONS = [
    '1.1.0' => "CREATE TABLE IF NOT EXISTS famtask_stats (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        day         DATE        NOT NULL,
        child_id    VARCHAR(20) NOT NULL,
        child_name  VARCHAR(80) NOT NULL,
        event_type  ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id      VARCHAR(80) NOT NULL,
        ref_label   VARCHAR(120),
        minutes     INT DEFAULT 0,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day),
        INDEX idx_child (child_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

// ══════════════════════════════════════════════════════════════════════════════
//  ROUTING
// ══════════════════════════════════════════════════════════════════════════════
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$action = strtolower($_GET['action'] ?? '');
$isJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
       || ($_SERVER['REQUEST_METHOD'] === 'POST')
       || isset($_SERVER['HTTP_X_REQUESTED_WITH']);

// ── Browser-Aufruf ohne Action → Admin-Seite ──────────────────────────────
if (!$isJson && $action === '') {
    showAdminPage();
    exit;
}

// ── Installer ────────────────────────────────────────────────────────────────
if ($action === 'install') {
    handleInstall();
    exit;
}

// ── Status (JSON) ────────────────────────────────────────────────────────────
if ($action === 'status') {
    handleStatus();
    exit;
}

// ── Updater ──────────────────────────────────────────────────────────────────
if ($action === 'update') {
    if (!$isJson && $_SERVER['REQUEST_METHOD'] === 'GET') { showAdminPage('update'); exit; }
    handleUpdate($MIGRATIONS);
    exit;
}

// ── Track Event ───────────────────────────────────────────────────────────────
if ($action === 'track') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleTrack(getDB(getCfg()));
    exit;
}

// ── Stats (JSON) ──────────────────────────────────────────────────────────────
if ($action === 'stats') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    if (!$isJson) { showAdminPage('stats'); exit; }
    handleStats(getDB(getCfg()));
    exit;
}

// ── Daten-API (kein action = Daten GET/POST) ─────────────────────────────────
if (!file_exists(CFG)) {
    http_response_code(503);
    jsonOut(['error' => 'not_configured', 'msg' => 'Öffne api.php im Browser zur Einrichtung.']);
    exit;
}
$pdo = getDB(getCfg());
if ($_SERVER['REQUEST_METHOD'] === 'GET')  { handleGet($pdo);  exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') { handlePost($pdo); exit; }

http_response_code(405); echo 'Method Not Allowed';


// ══════════════════════════════════════════════════════════════════════════════
//  INSTALLER
// ══════════════════════════════════════════════════════════════════════════════
function handleInstall() {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $host = trim($data['host'] ?? '');
    $port = intval($data['port'] ?? 3306);
    $db   = trim($data['db']   ?? '');
    $user = trim($data['user'] ?? '');
    $pass = $data['pass'] ?? '';

    if (!$host || !$db || !$user) {
        jsonOut(['ok' => false, 'msg' => 'Host, Datenbank und Benutzer sind pflicht.']);
        return;
    }

    // Verbindung testen
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
                       $user, $pass,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5]);
    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'msg' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()]);
        return;
    }

    // Tabellen erstellen
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS famtask_data (
                data_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
                data_value LONGTEXT,
                updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS famtask_meta (
                meta_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
                meta_value VARCHAR(255)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS famtask_stats (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                day         DATE        NOT NULL,
                child_id    VARCHAR(20) NOT NULL,
                child_name  VARCHAR(80) NOT NULL,
                event_type  ENUM('task_done','task_missed','media_used') NOT NULL,
                ref_id      VARCHAR(80) NOT NULL,
                ref_label   VARCHAR(120),
                minutes     INT DEFAULT 0,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_day (day),
                INDEX idx_child (child_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Version speichern
        $pdo->prepare("INSERT INTO famtask_meta (meta_key, meta_value)
                       VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([APP_VERSION]);

    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'msg' => 'Tabellen konnten nicht erstellt werden: ' . $e->getMessage()]);
        return;
    }

    // Konfiguration speichern (direkt-PHP, nicht web-zugänglich dank .htaccess)
    $cfg = "<?php if(!defined('FAMTASK'))die('403'); return " .
           var_export(['host'=>$host,'port'=>$port,'db'=>$db,'user'=>$user,'pass'=>$pass], true) .
           "; ?>";
    if (!file_put_contents(CFG, $cfg)) {
        jsonOut(['ok' => false, 'msg' => 'Konfigurationsdatei konnte nicht geschrieben werden. Schreibrechte prüfen.']);
        return;
    }

    // .htaccess schützen (Apache)
    $ht = __DIR__ . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "<Files \".famtask_cfg.php\">\n  Order allow,deny\n  Deny from all\n</Files>\n");
    }

    jsonOut(['ok' => true, 'msg' => 'Installation erfolgreich! FamTask ist einsatzbereit.', 'version' => APP_VERSION]);
}


// ══════════════════════════════════════════════════════════════════════════════
//  UPDATER
// ══════════════════════════════════════════════════════════════════════════════
function handleUpdate(array $migrations) {
    if (!file_exists(CFG)) {
        jsonOut(['ok' => false, 'msg' => 'Noch nicht installiert.']);
        return;
    }
    $pdo      = getDB(getCfg());
    $dbVer    = getDbVersion($pdo);
    $ran      = [];
    $errors   = [];

    foreach ($migrations as $targetVer => $sql) {
        if (version_compare($dbVer, $targetVer, '<')) {
            try {
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt) $pdo->exec($stmt);
                }
                // Version nach jedem Schritt aktualisieren
                $pdo->prepare("INSERT INTO famtask_meta (meta_key, meta_value)
                               VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
                    ->execute([$targetVer]);
                $dbVer = $targetVer;
                $ran[] = $targetVer;
            } catch (PDOException $e) {
                $errors[] = "v$targetVer: " . $e->getMessage();
                break; // Abbruch bei Fehler, kein Teil-Update
            }
        }
    }

    // Immer auf APP_VERSION aktualisieren wenn alle Migrationen durch
    if (empty($errors)) {
        $pdo->prepare("INSERT INTO famtask_meta (meta_key, meta_value)
                       VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([APP_VERSION]);
    }

    if ($errors) {
        jsonOut(['ok' => false, 'msg' => 'Fehler bei Update: ' . implode(', ', $errors), 'ran' => $ran]);
    } elseif (empty($ran)) {
        jsonOut(['ok' => true,  'msg' => 'Bereits aktuell – keine Migrationen nötig.',
                 'db_version' => APP_VERSION, 'app_version' => APP_VERSION]);
    } else {
        jsonOut(['ok' => true,  'msg' => 'Update erfolgreich! Migrationen: ' . implode(', ', $ran),
                 'db_version' => APP_VERSION, 'app_version' => APP_VERSION]);
    }
}


// ══════════════════════════════════════════════════════════════════════════════
//  STATUS
// ══════════════════════════════════════════════════════════════════════════════
function handleStatus() {
    if (!file_exists(CFG)) {
        jsonOut(['installed' => false, 'app_version' => APP_VERSION]);
        return;
    }
    try {
        $pdo    = getDB(getCfg());
        $dbVer  = getDbVersion($pdo);
        $stmt   = $pdo->query("SELECT COUNT(*) FROM famtask_data");
        $rows   = $stmt->fetchColumn();
        jsonOut(['installed' => true, 'app_version' => APP_VERSION,
                 'db_version' => $dbVer, 'data_rows' => (int)$rows,
                 'update_available' => version_compare($dbVer, APP_VERSION, '<')]);
    } catch (PDOException $e) {
        jsonOut(['installed' => true, 'error' => $e->getMessage()]);
    }
}


// ══════════════════════════════════════════════════════════════════════════════
//  DATEN GET / POST
// ══════════════════════════════════════════════════════════════════════════════
function handleGet(PDO $pdo) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT data_key, data_value FROM famtask_data");
        $out  = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['data_key']] = $row['data_value'];
        }
        echo json_encode($out);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handlePost(PDO $pdo) {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges JSON']);
        return;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO famtask_data (data_key, data_value)
                               VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE data_value = VALUES(data_value),
                                                       updated_at = CURRENT_TIMESTAMP");
        $pdo->beginTransaction();
        foreach ($body as $k => $v) {
            $stmt->execute([(string)$k, is_string($v) ? $v : json_encode($v)]);
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}


// ══════════════════════════════════════════════════════════════════════════════
//  TRACK EVENT
// ══════════════════════════════════════════════════════════════════════════════
function handleTrack(PDO $pdo) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); jsonOut(['ok'=>false]); return; }

    // Tabelle ggf. anlegen (für bestehende Installationen ohne Update)
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        day DATE NOT NULL, child_id VARCHAR(20) NOT NULL, child_name VARCHAR(80) NOT NULL,
        event_type ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id VARCHAR(80) NOT NULL, ref_label VARCHAR(120), minutes INT DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day), INDEX idx_child (child_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("INSERT INTO famtask_stats (day, child_id, child_name, event_type, ref_id, ref_label, minutes)
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $body['day']        ?? date('Y-m-d'),
        $body['child_id']   ?? '',
        $body['child_name'] ?? '',
        $body['event_type'] ?? 'task_done',
        $body['ref_id']     ?? '',
        $body['ref_label']  ?? '',
        intval($body['minutes'] ?? 0)
    ]);
    jsonOut(['ok' => true]);
}

// ══════════════════════════════════════════════════════════════════════════════
//  STATS DATA (JSON)
// ══════════════════════════════════════════════════════════════════════════════
function handleStats(PDO $pdo) {
    $days  = max(1, min(90, intval($_GET['days'] ?? 30)));
    $child = $_GET['child'] ?? '';

    // Sicherstellen dass Tabelle existiert
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        day DATE NOT NULL, child_id VARCHAR(20) NOT NULL, child_name VARCHAR(80) NOT NULL,
        event_type ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id VARCHAR(80) NOT NULL, ref_label VARCHAR(120), minutes INT DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day), INDEX idx_child (child_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $where = "day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params = [$days];
    if ($child) { $where .= " AND child_id = ?"; $params[] = $child; }

    // Tages-Zusammenfassung pro Kind
    $daily = $pdo->prepare("SELECT day, child_id, child_name,
        SUM(CASE WHEN event_type='task_done'   THEN 1 ELSE 0 END) AS tasks_done,
        SUM(CASE WHEN event_type='task_missed' THEN 1 ELSE 0 END) AS tasks_missed,
        SUM(CASE WHEN event_type='media_used'  THEN minutes ELSE 0 END) AS media_mins
        FROM famtask_stats WHERE $where
        GROUP BY day, child_id, child_name ORDER BY day DESC, child_name");
    $daily->execute($params);

    // Top-Aufgaben
    $topTasks = $pdo->prepare("SELECT ref_label, child_name,
        SUM(CASE WHEN event_type='task_done'   THEN 1 ELSE 0 END) AS done,
        SUM(CASE WHEN event_type='task_missed' THEN 1 ELSE 0 END) AS missed
        FROM famtask_stats WHERE $where AND event_type IN ('task_done','task_missed')
        GROUP BY ref_id, ref_label, child_id, child_name ORDER BY done DESC LIMIT 20");
    $topTasks->execute($params);

    // Medienzeit pro Typ
    $mediaBreakdown = $pdo->prepare("SELECT ref_label, child_name, SUM(minutes) AS total_mins
        FROM famtask_stats WHERE $where AND event_type='media_used'
        GROUP BY ref_id, ref_label, child_id, child_name ORDER BY total_mins DESC");
    $mediaBreakdown->execute($params);

    // Gesamtzahlen
    $totals = $pdo->prepare("SELECT child_name,
        SUM(CASE WHEN event_type='task_done'   THEN 1 ELSE 0 END) AS tasks_done,
        SUM(CASE WHEN event_type='task_missed' THEN 1 ELSE 0 END) AS tasks_missed,
        SUM(CASE WHEN event_type='media_used'  THEN minutes ELSE 0 END) AS media_mins
        FROM famtask_stats WHERE $where GROUP BY child_id, child_name ORDER BY child_name");
    $totals->execute($params);

    jsonOut([
        'ok'             => true,
        'days'           => $days,
        'daily'          => $daily->fetchAll(),
        'top_tasks'      => $topTasks->fetchAll(),
        'media_breakdown'=> $mediaBreakdown->fetchAll(),
        'totals'         => $totals->fetchAll(),
    ]);
}


// ══════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════════════════
function getCfg(): array {
    define('FAMTASK', true);
    return require CFG;
}

function getDB(array $c): PDO {
    return new PDO(
        "mysql:host={$c['host']};port={$c['port']};dbname={$c['db']};charset=utf8mb4",
        $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function getDbVersion(PDO $pdo): string {
    try {
        $s = $pdo->query("SELECT meta_value FROM famtask_meta WHERE meta_key='db_version'");
        return $s->fetchColumn() ?: '0.0.0';
    } catch (PDOException $e) {
        return '0.0.0';
    }
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
}


// ══════════════════════════════════════════════════════════════════════════════
//  ADMIN-SEITE (Installer + Status + Updater UI)
// ══════════════════════════════════════════════════════════════════════════════
function showAdminPage(string $tab = ''): void {
    $configured = file_exists(CFG);
    $status     = ['installed' => false, 'app_version' => APP_VERSION, 'db_version' => '–', 'data_rows' => 0, 'update_available' => false];

    if ($configured) {
        try {
            define('FAMTASK', true);
            $cfg    = require CFG;
            $pdo    = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
                              $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $dbVer  = getDbVersion($pdo);
            $rows   = $pdo->query("SELECT COUNT(*) FROM famtask_data")->fetchColumn();
            $status = ['installed' => true, 'app_version' => APP_VERSION,
                       'db_version' => $dbVer, 'data_rows' => (int)$rows,
                       'update_available' => version_compare($dbVer, APP_VERSION, '<'),
                       'db_host' => $cfg['host'], 'db_name' => $cfg['db']];
        } catch (PDOException $e) {
            $status = ['installed' => true, 'error' => $e->getMessage(), 'app_version' => APP_VERSION];
        }
    }

    $statusJson = json_encode($status);
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>FamTask – Admin</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',Arial,sans-serif;background:#0D0E1A;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:20px}
    .card{background:#161728;border:1.5px solid rgba(255,255,255,0.08);border-radius:18px;padding:28px 32px;width:100%;max-width:520px;margin-top:28px}
    .logo{font-size:36px;font-weight:900;background:linear-gradient(135deg,#FF6B6B,#FFE66D);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-align:center;margin-bottom:4px}
    .sub{text-align:center;color:rgba(255,255,255,0.35);font-size:13px;margin-bottom:24px}
    .tabs{display:flex;gap:6px;margin-bottom:22px}
    .tab{flex:1;padding:9px;background:rgba(255,255,255,0.05);border:1.5px solid rgba(255,255,255,0.08);border-radius:10px;color:rgba(255,255,255,0.4);font-size:11px;font-weight:700;cursor:pointer;text-align:center;transition:all .15s}
    .tab.active{background:rgba(255,107,107,0.12);border-color:rgba(255,107,107,0.4);color:#FF6B6B}
    .section{display:none}.section.active{display:block}
    label{display:block;font-size:11px;font-weight:800;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;margin-top:14px}
    input{width:100%;background:#0D0E1A;border:1.5px solid rgba(255,255,255,0.1);border-radius:10px;color:#fff;font-size:14px;padding:11px 14px;outline:none;transition:border-color .15s}
    input:focus{border-color:#FF6B6B}
    .row{display:flex;gap:10px}
    .row .field{flex:1}
    .btn{width:100%;padding:13px;border:none;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;margin-top:18px;transition:all .15s}
    .btn-pri{background:linear-gradient(135deg,#FF6B6B,#FF8E53);color:#fff}
    .btn-sec{background:rgba(78,205,196,0.12);border:1.5px solid rgba(78,205,196,0.3);color:#4ECDC4}
    .btn:active{transform:scale(.97)}
    .msg{margin-top:14px;padding:12px 16px;border-radius:11px;font-size:13px;font-weight:700;display:none}
    .msg.ok {background:rgba(107,203,119,0.12);border:1.5px solid rgba(107,203,119,0.3);color:#6BCB77}
    .msg.err{background:rgba(255,107,107,0.12);border:1.5px solid rgba(255,107,107,0.3);color:#FF6B6B}
    .stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px}
    .stat{background:#0D0E1A;border-radius:11px;padding:14px;text-align:center}
    .stat-val{font-size:22px;font-weight:900;color:#fff;margin-bottom:3px}
    .stat-lbl{font-size:11px;color:rgba(255,255,255,0.35);font-weight:700;text-transform:uppercase;letter-spacing:.4px}
    .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle}
    .dot-green{background:#6BCB77;box-shadow:0 0 6px #6BCB77}
    .dot-red  {background:#FF6B6B;box-shadow:0 0 6px #FF6B6B}
    .dot-orange{background:#FFB347;box-shadow:0 0 6px #FFB347}
    .info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px}
    .info-row:last-child{border:none}
    .info-key{color:rgba(255,255,255,0.4);font-weight:700}
    .info-val{color:#fff;font-weight:800}
    .warn-box{background:rgba(255,179,71,0.08);border:1.5px solid rgba(255,179,71,0.3);border-radius:11px;padding:12px 16px;font-size:13px;color:#FFB347;margin-top:14px}
    h3{font-size:15px;font-weight:800;color:rgba(255,255,255,0.6);margin-bottom:14px}
    .spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
<div class="card">
  <div class="logo">FamTask ✨</div>
  <div class="sub">Admin-Panel · Version <?= APP_VERSION ?></div>
  <a href="index.html" style="display:block;text-align:center;margin:-8px 0 16px;padding:8px 16px;background:rgba(78,205,196,0.1);border:1.5px solid rgba(78,205,196,0.25);border-radius:10px;color:#4ECDC4;font-size:12px;font-weight:800;text-decoration:none;">← Zurück zur App (index.html)</a>

  <div class="tabs">
    <?php if (!$status['installed']): ?>
      <div class="tab active" data-tab="install">🔧 Installation</div>
    <?php else: ?>
      <div class="tab <?= $tab===''||$tab==='status'?'active':'' ?>" data-tab="status">📊 Status</div>
      <div class="tab <?= $tab==='stats'?'active':'' ?>"   data-tab="stats">📈 Statistiken</div>
      <div class="tab <?= $tab==='update'?'active':'' ?>"  data-tab="update">🔄 Update</div>
      <div class="tab <?= $tab==='reinstall'?'active':'' ?>" data-tab="reinstall">⚙️ Konfig</div>
    <?php endif; ?>
  </div>

  <?php /* ── INSTALLER ── */ if (!$status['installed']): ?>
  <div class="section active" id="tab-install">
    <h3>Datenbankverbindung einrichten</h3>
    <div class="row">
      <div class="field"><label>Host</label><input id="i-host" value="localhost" placeholder="localhost oder IP"/></div>
      <div class="field"><label>Port</label><input id="i-port" value="3306" type="number"/></div>
    </div>
    <label>Datenbank-Name</label><input id="i-db" placeholder="famtask"/>
    <label>Benutzer</label><input id="i-user" placeholder="famtask_user"/>
    <label>Passwort</label><input id="i-pass" type="password" placeholder="••••••••"/>
    <button class="btn btn-pri" onclick="doInstall()">✅ Jetzt installieren</button>
    <div class="msg" id="install-msg"></div>
  </div>

  <?php /* ── STATUS ── */ else: ?>
  <div class="section active" id="tab-status">
    <?php if (isset($status['error'])): ?>
      <div class="msg err" style="display:block">⚠️ Datenbankfehler: <?= htmlspecialchars($status['error']) ?></div>
    <?php else: ?>
      <div class="stat-grid">
        <div class="stat"><div class="stat-val"><span class="dot dot-green"></span>Aktiv</div><div class="stat-lbl">Verbindung</div></div>
        <div class="stat"><div class="stat-val"><?= $status['data_rows'] ?></div><div class="stat-lbl">Datensätze</div></div>
        <div class="stat"><div class="stat-val"><?= htmlspecialchars($status['app_version']) ?></div><div class="stat-lbl">App-Version</div></div>
        <div class="stat"><div class="stat-val"><?= htmlspecialchars($status['db_version']) ?></div><div class="stat-lbl">DB-Version</div></div>
      </div>
      <div style="margin-top:16px">
        <div class="info-row"><span class="info-key">Host</span><span class="info-val"><?= htmlspecialchars($status['db_host'] ?? '–') ?></span></div>
        <div class="info-row"><span class="info-key">Datenbank</span><span class="info-val"><?= htmlspecialchars($status['db_name'] ?? '–') ?></span></div>
        <div class="info-row"><span class="info-key">Konfigurationsdatei</span><span class="info-val">.famtask_cfg.php</span></div>
        <div class="info-row"><span class="info-key">Update verfügbar</span>
          <span class="info-val"><?= $status['update_available']
            ? '<span class="dot dot-orange"></span>Ja – bitte updaten'
            : '<span class="dot dot-green"></span>Aktuell' ?></span>
        </div>
      </div>
      <?php if ($status['update_available']): ?>
        <div class="warn-box">⚠️ DB-Version (<?= $status['db_version'] ?>) ist veraltet. Bitte zum Tab <strong>Update</strong> wechseln.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section" id="tab-update">
    <h3>Datenbank-Update</h3>
    <div class="info-row"><span class="info-key">App-Version</span><span class="info-val"><?= APP_VERSION ?></span></div>
    <div class="info-row"><span class="info-key">DB-Version</span><span class="info-val"><?= htmlspecialchars($status['db_version'] ?? '–') ?></span></div>
    <?php if (!$status['update_available']): ?>
      <div class="msg ok" style="display:block;margin-top:14px">✅ Datenbank ist bereits auf dem neuesten Stand.</div>
    <?php else: ?>
      <div class="warn-box" style="margin-bottom:0">Update von <strong><?= $status['db_version'] ?></strong> auf <strong><?= APP_VERSION ?></strong> verfügbar.</div>
    <?php endif; ?>
    <button class="btn btn-sec" onclick="doUpdate()">🔄 Update jetzt ausführen</button>
    <div class="msg" id="update-msg"></div>
  </div>

  <div class="section" id="tab-reinstall">
    <h3>Datenbankverbindung ändern</h3>
    <p style="font-size:13px;color:rgba(255,255,255,0.4);margin-bottom:4px">Bestehende Daten bleiben erhalten – nur die Verbindungsdaten werden überschrieben.</p>
    <div class="row">
      <div class="field"><label>Host</label><input id="r-host" value="<?= htmlspecialchars($status['db_host'] ?? 'localhost') ?>"/></div>
      <div class="field"><label>Port</label><input id="r-port" value="3306" type="number"/></div>
    </div>
    <label>Datenbank-Name</label><input id="r-db" value="<?= htmlspecialchars($status['db_name'] ?? '') ?>"/>
    <label>Benutzer</label><input id="r-user" placeholder="Benutzer"/>
    <label>Passwort</label><input id="r-pass" type="password" placeholder="Neues Passwort"/>
    <button class="btn btn-pri" onclick="doReinstall()">💾 Verbindung speichern</button>
    <div class="msg" id="reinstall-msg"></div>
  </div>

  <div class="section" id="tab-stats">
    <h3>Zeitraum</h3>
    <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
      <?php foreach([7,14,30,60,90] as $d): ?>
        <button onclick="loadStats(<?=$d?>)" id="days-btn-<?=$d?>" class="btn btn-sec" style="flex:1;padding:8px 4px;font-size:12px"><?=$d?> Tage</button>
      <?php endforeach; ?>
    </div>
    <div id="stats-loading" style="text-align:center;padding:20px;color:rgba(255,255,255,0.35);display:none">⏳ Lade…</div>
    <div id="stats-content"></div>
  </div>

  <?php endif; ?>

</div>

<script>
  const ST = <?= $statusJson ?>;

  // ── Tab-Navigation ──────────────────────────────────────────────────────────
  function showTab(name) {
    document.querySelectorAll('.tab[data-tab]').forEach(t =>
      t.classList.toggle('active', t.dataset.tab === name)
    );
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    const el = document.getElementById('tab-' + name);
    if (el) el.classList.add('active');
    if (name === 'stats' && !document.getElementById('stats-content').innerHTML.trim()) loadStats(30);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.tab[data-tab]').forEach(tab =>
      tab.addEventListener('click', () => showTab(tab.dataset.tab))
    );
    <?php if ($tab === 'stats'): ?>loadStats(30);<?php endif; ?>
  });

  // ── Helpers ─────────────────────────────────────────────────────────────────
  function showMsg(id, ok, text) {
    const el = document.getElementById(id);
    el.className = 'msg ' + (ok ? 'ok' : 'err');
    el.textContent = text;
    el.style.display = 'block';
  }

  function setLoading(btnEl, loading) {
    if (loading) {
      btnEl._orig = btnEl.innerHTML;
      btnEl.innerHTML = '<span class="spinner"></span>Bitte warten…';
      btnEl.disabled = true;
    } else {
      btnEl.innerHTML = btnEl._orig;
      btnEl.disabled = false;
    }
  }

  // ── Installer / Update / Reinstall ──────────────────────────────────────────
  async function doInstall() {
    const btn = event.currentTarget;
    const data = { host: document.getElementById('i-host').value, port: parseInt(document.getElementById('i-port').value)||3306, db: document.getElementById('i-db').value, user: document.getElementById('i-user').value, pass: document.getElementById('i-pass').value };
    setLoading(btn, true);
    try { const r = await fetch('api.php?action=install', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}); const d = await r.json(); showMsg('install-msg', d.ok, d.msg); if (d.ok) setTimeout(()=>location.reload(),1500); } catch(e) { showMsg('install-msg', false, 'Netzwerkfehler: '+e.message); }
    setLoading(btn, false);
  }

  async function doUpdate() {
    const btn = event.currentTarget;
    setLoading(btn, true);
    try { const r = await fetch('api.php?action=update', {method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}); const d = await r.json(); showMsg('update-msg', d.ok, d.msg); if (d.ok) setTimeout(()=>location.reload(),2000); } catch(e) { showMsg('update-msg', false, 'Netzwerkfehler: '+e.message); }
    setLoading(btn, false);
  }

  async function doReinstall() {
    const btn = event.currentTarget;
    const data = { host: document.getElementById('r-host').value, port: parseInt(document.getElementById('r-port').value)||3306, db: document.getElementById('r-db').value, user: document.getElementById('r-user').value, pass: document.getElementById('r-pass').value };
    setLoading(btn, true);
    try { const r = await fetch('api.php?action=install', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}); const d = await r.json(); showMsg('reinstall-msg', d.ok, d.msg); if (d.ok) setTimeout(()=>location.reload(),1500); } catch(e) { showMsg('reinstall-msg', false, 'Netzwerkfehler: '+e.message); }
    setLoading(btn, false);
  }

  // ── Statistiken ─────────────────────────────────────────────────────────────
  async function loadStats(days) {
    document.querySelectorAll('[id^=days-btn-]').forEach(b => b.style.cssText='');
    const active = document.getElementById('days-btn-'+days);
    if (active) active.style.cssText='background:rgba(255,107,107,0.2);border-color:rgba(255,107,107,0.5);color:#FF6B6B';
    document.getElementById('stats-loading').style.display='block';
    document.getElementById('stats-content').innerHTML='';
    try {
      const r = await fetch('api.php?action=stats&days='+days, {headers:{'Accept':'application/json'}});
      const d = await r.json();
      document.getElementById('stats-loading').style.display='none';
      if (!d.ok) { document.getElementById('stats-content').innerHTML='<p style="color:#FF6B6B">Fehler beim Laden</p>'; return; }
      renderStats(d);
    } catch(e) {
      document.getElementById('stats-loading').style.display='none';
      document.getElementById('stats-content').innerHTML='<p style="color:#FF6B6B">'+e.message+'</p>';
    }
  }

  function bar(pct, color) {
    return `<div style="background:rgba(255,255,255,0.06);border-radius:99px;height:6px;margin-top:4px;overflow:hidden"><div style="width:${Math.round(pct)}%;background:${color};height:100%;border-radius:99px;transition:width .4s"></div></div>`;
  }

  function renderStats(d) {
    const el = document.getElementById('stats-content');
    if (!d.totals.length) { el.innerHTML='<div class="msg ok" style="display:block">Noch keine Daten. Aufgaben erledigen und Medienzeit nutzen um Statistiken zu sehen.</div>'; return; }
    const colors = ['#FF6B6B','#4ECDC4','#A78BFA','#FFB347','#6BCB77'];
    const childColor = {};
    d.totals.forEach((t,i) => childColor[t.child_name] = colors[i % colors.length]);
    let html = '';

    html += '<h3 style="margin-bottom:10px">🏆 Gesamtübersicht</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px">';
    d.totals.forEach(t => {
      const col = childColor[t.child_name];
      const total = parseInt(t.tasks_done)+parseInt(t.tasks_missed);
      const pct = total ? Math.round(parseInt(t.tasks_done)/total*100) : 0;
      html += `<div style="background:#0D0E1A;border:1.5px solid ${col}33;border-radius:12px;padding:12px">
        <div style="font-weight:800;color:${col};font-size:13px;margin-bottom:8px">${t.child_name}</div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,0.5);margin-bottom:2px"><span>✅ Erledigt</span><span style="color:#6BCB77;font-weight:800">${t.tasks_done}</span></div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,0.5);margin-bottom:2px"><span>❌ Verpasst</span><span style="color:#FF6B6B;font-weight:800">${t.tasks_missed}</span></div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,0.5);margin-bottom:4px"><span>🎮 Medien</span><span style="color:#A78BFA;font-weight:800">${t.media_mins} Min</span></div>
        ${bar(pct,'#6BCB77')}<div style="font-size:10px;color:rgba(255,255,255,0.3);text-align:right;margin-top:3px">${pct}% pünktlich</div>
      </div>`;
    });
    html += '</div>';

    html += '<h3 style="margin-bottom:10px">📅 Tagesverlauf</h3><div style="overflow-x:auto;margin-bottom:18px"><table style="width:100%;font-size:11px;border-collapse:collapse"><tr><th style="text-align:left;padding:4px 6px;color:rgba(255,255,255,0.4)">Datum</th><th style="padding:4px 6px;color:rgba(255,255,255,0.4)">Kind</th><th style="padding:4px 6px;color:#6BCB77">✅</th><th style="padding:4px 6px;color:#FF6B6B">❌</th><th style="padding:4px 6px;color:#A78BFA">🎮</th></tr>';
    const dayMap = {};
    d.daily.forEach(row => { if (!dayMap[row.day]) dayMap[row.day]=[]; dayMap[row.day].push(row); });
    Object.keys(dayMap).slice(0,14).forEach(day => {
      dayMap[day].forEach((row,i) => {
        const col = childColor[row.child_name];
        const dateStr = new Date(row.day+'T12:00:00').toLocaleDateString('de-CH',{weekday:'short',day:'numeric',month:'short'});
        html += `<tr>${i===0?`<td rowspan="${dayMap[day].length}" style="color:rgba(255,255,255,0.5);white-space:nowrap;padding:4px 6px">${dateStr}</td>`:''}
          <td style="padding:4px 6px"><span style="color:${col};font-weight:800">${row.child_name}</span></td>
          <td style="color:#6BCB77;font-weight:800;text-align:center;padding:4px 6px">${row.tasks_done}</td>
          <td style="color:#FF6B6B;font-weight:800;text-align:center;padding:4px 6px">${row.tasks_missed}</td>
          <td style="color:#A78BFA;font-weight:800;text-align:center;padding:4px 6px">${row.media_mins}</td></tr>`;
      });
    });
    html += '</table></div>';

    if (d.top_tasks.length) {
      html += '<h3 style="margin-bottom:10px">📋 Aufgaben-Ranking</h3><div style="margin-bottom:18px">';
      const maxDone = Math.max(...d.top_tasks.map(t=>parseInt(t.done)||0),1);
      d.top_tasks.forEach(t => {
        const col=childColor[t.child_name],done=parseInt(t.done)||0,missed=parseInt(t.missed)||0;
        html += `<div style="background:#0D0E1A;border-radius:10px;padding:10px 12px;margin-bottom:6px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;font-weight:800;color:#fff">${t.ref_label||'?'}</span><span style="font-size:11px;color:${col};font-weight:800">${t.child_name}</span></div>
          <div style="display:flex;gap:12px;font-size:11px;color:rgba(255,255,255,0.45);margin-bottom:4px"><span>✅ ${done}×</span><span>❌ ${missed}×</span></div>${bar(done/maxDone*100,col)}</div>`;
      });
      html += '</div>';
    }

    if (d.media_breakdown.length) {
      html += '<h3 style="margin-bottom:10px">🎮 Medienzeit nach Typ</h3><div style="margin-bottom:18px">';
      const maxMins = Math.max(...d.media_breakdown.map(m=>parseInt(m.total_mins)||0),1);
      const icons={'TV':'📺','Tablet':'📱','Nintendo':'🎮'};
      d.media_breakdown.forEach(m => {
        const col=childColor[m.child_name];
        html += `<div style="background:#0D0E1A;border-radius:10px;padding:10px 12px;margin-bottom:6px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;font-weight:800;color:#fff">${icons[m.ref_label]||'🎮'} ${m.ref_label}</span><span style="font-size:11px;color:${col};font-weight:800">${m.child_name}</span></div>
          <div style="font-size:13px;font-weight:900;color:#A78BFA;margin-bottom:4px">${m.total_mins} Min</div>${bar(parseInt(m.total_mins)/maxMins*100,'#A78BFA')}</div>`;
      });
      html += '</div>';
    }

    el.innerHTML = html;
  }
</script>
</body>
</html>
    <?php
}
