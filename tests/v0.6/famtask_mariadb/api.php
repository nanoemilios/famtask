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
define('APP_VERSION', '1.0.0');
define('CFG',         __DIR__ . '/.famtask_cfg.php');

$MIGRATIONS = [
    // Format: 'ziel_version' => 'SQL-Statement(s)'
    // Beispiel für spätere Versionen:
    // '1.1.0' => "ALTER TABLE famtask_data ADD COLUMN locked TINYINT(1) DEFAULT 0;",
    // '2.0.0' => "CREATE TABLE famtask_log (id INT AUTO_INCREMENT PRIMARY KEY, ...);",
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
       || ($_SERVER['REQUEST_METHOD'] === 'POST');

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
    .tab{flex:1;padding:9px;background:rgba(255,255,255,0.05);border:1.5px solid rgba(255,255,255,0.08);border-radius:10px;color:rgba(255,255,255,0.4);font-size:13px;font-weight:700;cursor:pointer;text-align:center;transition:all .15s}
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

  <div class="tabs">
    <?php if (!$status['installed']): ?>
      <div class="tab active" onclick="showTab('install')">🔧 Installation</div>
    <?php else: ?>
      <div class="tab active" onclick="showTab('status')">📊 Status</div>
      <div class="tab" onclick="showTab('update')">🔄 Update</div>
      <div class="tab" onclick="showTab('reinstall')">⚙️ Neu konfigurieren</div>
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
  <?php endif; ?>

</div>

<script>
  const ST = <?= $statusJson ?>;

  function showTab(name) {
    document.querySelectorAll('.tab').forEach((t,i) => {
      const id = t.getAttribute('onclick').match(/'(\w+)'/)[1];
      t.classList.toggle('active', id === name);
    });
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    const el = document.getElementById('tab-' + name);
    if (el) el.classList.add('active');
  }

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

  async function doInstall() {
    const btn = event.currentTarget;
    const data = {
      host: document.getElementById('i-host').value,
      port: parseInt(document.getElementById('i-port').value) || 3306,
      db:   document.getElementById('i-db').value,
      user: document.getElementById('i-user').value,
      pass: document.getElementById('i-pass').value
    };
    setLoading(btn, true);
    try {
      const r = await fetch('api.php?action=install', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data)
      });
      const d = await r.json();
      showMsg('install-msg', d.ok, d.msg);
      if (d.ok) setTimeout(() => location.reload(), 1500);
    } catch(e) { showMsg('install-msg', false, 'Netzwerkfehler: ' + e.message); }
    setLoading(btn, false);
  }

  async function doUpdate() {
    const btn = event.currentTarget;
    setLoading(btn, true);
    try {
      const r = await fetch('api.php?action=update', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: '{}'
      });
      const d = await r.json();
      showMsg('update-msg', d.ok, d.msg);
      if (d.ok) setTimeout(() => location.reload(), 2000);
    } catch(e) { showMsg('update-msg', false, 'Netzwerkfehler: ' + e.message); }
    setLoading(btn, false);
  }

  async function doReinstall() {
    const btn = event.currentTarget;
    const data = {
      host: document.getElementById('r-host').value,
      port: parseInt(document.getElementById('r-port').value) || 3306,
      db:   document.getElementById('r-db').value,
      user: document.getElementById('r-user').value,
      pass: document.getElementById('r-pass').value
    };
    setLoading(btn, true);
    try {
      const r = await fetch('api.php?action=install', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data)
      });
      const d = await r.json();
      showMsg('reinstall-msg', d.ok, d.msg);
      if (d.ok) setTimeout(() => location.reload(), 1500);
    } catch(e) { showMsg('reinstall-msg', false, 'Netzwerkfehler: ' + e.message); }
    setLoading(btn, false);
  }
</script>
</body>
</html>
    <?php
}
