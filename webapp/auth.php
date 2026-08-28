<?php
// auth.php - Autenticazione e ruoli per Flight Anomaly Monitor.
//
// Il portale e' PUBBLICO in lettura (elenco eventi, dettaglio, statistiche, API).
// Le azioni "di piattaforma" (preferiti: aggiungere/rimuovere/annotare) sono
// riservate a utenti con ruolo >= collaboratore.
//
//   pubblico       chiunque, senza login
//   collaboratore  puo' gestire i preferiti
//   admin          + gestione utenti
//
// Utenti in db/auth.db (separato da events.db). Creare il primo con:
//   php auth_useradd.php <username> <admin|collaboratore>
//
// Fornisce anche i token CSRF: csrf.php e' un semplice alias di questo file.

require_once __DIR__ . '/favorites_lib.php';   // per fa_config()

const ROLE_RANK = ['pubblico' => 0, 'collaboratore' => 1, 'admin' => 2];

function auth_db_path(): string {
    $cfg = fa_config();
    return $cfg['auth_db_path'] ?? (dirname($cfg['db_path']) . '/auth.db');
}

function get_auth_db(): PDO {
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $db = new PDO('sqlite:' . auth_db_path());
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role          TEXT NOT NULL CHECK (role IN ('collaboratore','admin')),
        created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
        last_login_at TEXT,
        last_login_ip TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        username   TEXT,
        ip         TEXT,
        success    INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_la_user ON login_attempts(username, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_la_ip   ON login_attempts(ip, created_at)");
    return $db;
}

function auth_bootstrap(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $sp = __DIR__ . '/sessions';
        if (!is_dir($sp)) {
            @mkdir($sp, 0770, true);
        }
        if (is_dir($sp) && is_writable($sp)) {
            session_save_path($sp);
        }
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool   { return current_user() !== null; }
function current_role(): string { return current_user()['role'] ?? 'pubblico'; }

function log_login_attempt(string $u, string $ip, bool $ok): void {
    $s = get_auth_db()->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?,?,?)");
    $s->execute([$u, $ip, $ok ? 1 : 0]);
}

/** ['ok'=>bool, 'error'=>?string]. Errore sempre generico. */
function attempt_login(string $username, string $password): array {
    $db = get_auth_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $username = trim($username);
    $err = ['ok' => false, 'error' => 'Credenziali non valide.'];

    $byUser = (int) $db->query(
        "SELECT COUNT(*) FROM login_attempts WHERE username = " . $db->quote($username) .
        " AND success = 0 AND created_at > datetime('now','-15 minutes')")->fetchColumn();
    $byIp = (int) $db->query(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = " . $db->quote($ip) .
        " AND success = 0 AND created_at > datetime('now','-15 minutes')")->fetchColumn();
    if ($byUser >= 5 || $byIp >= 20) {
        log_login_attempt($username, $ip, false);
        return $err;
    }

    $st = $db->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$username]);
    $row = $st->fetch();

    static $dummy = null;
    if ($dummy === null) {
        $dummy = password_hash('tempo-costante', PASSWORD_DEFAULT);
    }
    $pwOk = password_verify($password, $row['password_hash'] ?? $dummy);

    if (!$row || !$pwOk) {
        log_login_attempt($username, $ip, false);
        return $err;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP, last_login_ip = ? WHERE id = ?")
       ->execute([$ip, $row['id']]);
    log_login_attempt($username, $ip, true);
    return ['ok' => true];
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Impone il ruolo minimo. Non loggato -> redirect a login; loggato ma
 *  insufficiente -> 403. `json:true` per gli endpoint AJAX (risposta JSON). */
function require_role(string $minRole, bool $json = false): void {
    $need = ROLE_RANK[$minRole] ?? 99;
    if ((ROLE_RANK[current_role()] ?? 0) >= $need) {
        return;
    }
    if ($json) {
        http_response_code(is_logged_in() ? 403 : 401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => is_logged_in() ? 'Permessi insufficienti' : 'Accesso richiesto']);
        exit;
    }
    if (!is_logged_in()) {
        header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Accesso negato</title>'
       . '<link rel="stylesheet" href="assets/style.css"></head><body><div class="container">'
       . '<h1>🚫 Accesso negato</h1><p>Non hai i permessi per questa azione.</p>'
       . '<p><a href="index.php">← Torna agli eventi</a></p></div></body></html>';
    exit;
}

// --- CSRF -----------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}
function csrf_check(): bool {
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

/** Barra di stato accesso per la nav (link Accedi / Esci). */
function auth_nav_html(): string {
    if (is_logged_in()) {
        $u = htmlspecialchars(current_user()['username']);
        $r = htmlspecialchars(current_role());
        return "<span class=\"muted\">$u ($r)</span> <a href=\"logout.php\">Esci</a>";
    }
    return '<a href="login.php">Accedi</a>';
}

// auth.php non fa il bootstrap da solo: lo chiamano le pagine (auth_bootstrap()).
if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    auth_bootstrap();
}
