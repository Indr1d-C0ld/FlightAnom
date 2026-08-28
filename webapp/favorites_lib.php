<?php
// favorites_lib.php - Schema e accesso alla tabella dei preferiti.
// Modello: preferiti PER-EVENTO (chiave event_id -> events.id), con nota
// annotabile per evento. I preferiti vivono nello stesso db/events.db; la
// tabella e' creata pigramente dai writer (toggle_favorite.php, edit_favorite.php).

/**
 * Configurazione condivisa da tutte le pagine PHP.
 * Legge config.php (non versionato) se presente, altrimenti usa i default:
 * questo mantiene il funzionamento del deployment flat senza config.php.
 */
function fa_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $defaults = [
            // Percorso assoluto del database SQLite (events + favorites).
            'db_path'         => __DIR__ . '/db/events.db',
            // Base URL di un'istanza MilAir ITA per il link 🔍 nella lista eventi.
            // Vuoto = link non mostrato.
            'milair_base_url' => '',
        ];
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? array_merge($defaults, (array) require $file) : $defaults;
    }
    return $cfg;
}

function fav_db_path(): string {
    return fa_config()['db_path'];
}

function fav_open(bool $writable = false): PDO {
    $path = fav_db_path();
    if (!$writable && !file_exists($path)) {
        throw new RuntimeException("database non inizializzato: $path");
    }
    $pdo = new PDO("sqlite:$path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=5000');
    return $pdo;
}

function fav_ensure_schema(PDO $pdo): void {
    // Migrazione dalla vecchia tabella per-aeromobile (chiave hex): messa da
    // parte, non cancellata. La si puo' rimuovere a mano con DROP quando serve.
    $legacy = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='favorites'"
    )->fetchColumn();
    if ($legacy) {
        $cols = $pdo->query("PRAGMA table_info(favorites)")->fetchAll();
        $names = array_column($cols, 'name');
        if (in_array('hex', $names, true) && !in_array('event_id', $names, true)) {
            $pdo->exec("ALTER TABLE favorites RENAME TO favorites_legacy_hex");
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        event_id   INTEGER PRIMARY KEY,
        note       TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function fav_table_exists(PDO $pdo): bool {
    $cols = $pdo->query("PRAGMA table_info(favorites)")->fetchAll();
    return in_array('event_id', array_column($cols, 'name'), true);
}

/** Set dei preferiti come mappa event_id(int) => true. [] se la tabella non esiste. */
function fav_id_set(PDO $pdo): array {
    if (!fav_table_exists($pdo)) {
        return [];
    }
    $out = [];
    foreach ($pdo->query("SELECT event_id FROM favorites") as $r) {
        $out[(int) $r['event_id']] = true;
    }
    return $out;
}

function fav_valid_id($id): bool {
    return ctype_digit((string) $id) && (int) $id > 0;
}

/**
 * Percorso web (relativo alla webapp) della silhouette del tipo velivolo, se
 * presente in silhouettes/. Le silhouette (formato VRS/VirtualRadar, ~85x20)
 * sono popolate da download_silhouettes.php. Ritorna null se assente.
 */
function fa_silhouette_path(?string $type): ?string {
    static $memo = [];
    if (empty($type)) {
        return null;
    }
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($type)));
    if ($safe === '') {
        return null;
    }
    if (array_key_exists($safe, $memo)) {
        return $memo[$safe];
    }
    // Qualche alias fra designatori ICAO e nomi-file diffusi.
    static $alias = [
        'M345' => ['M345', 'M-345'],
        'M346' => ['M346', 'M-346'],
        'E390' => ['E390', 'C390', 'KC390'],
        'FA6X' => ['FA6X', 'F6X'],
        'GA6C' => ['GA6C', 'G600'],
        'HRON' => ['HRON', 'HERON'],
    ];
    $cands = $alias[$safe] ?? [$safe];
    $found = null;
    foreach ($cands as $c) {
        foreach (['bmp', 'png', 'svg', 'gif'] as $ext) {
            $f = __DIR__ . '/silhouettes/' . $c . '.' . $ext;
            if (is_file($f) && filesize($f) > 0) {
                $found = 'silhouettes/' . $c . '.' . $ext;
                break 2;
            }
        }
    }
    return $memo[$safe] = $found;
}

/**
 * HTML della bandiera nazione: <img flags/XX.svg> se disponibile, altrimenti
 * emoji bandiera dai Regional Indicator Symbols, altrimenti 🏳️ per ZZ/ignoto.
 */
function fa_country_flag_html(?string $code): string {
    $c = strtoupper(trim((string) $code));
    if ($c === '' || $c === 'ZZ') {
        return '<span title="Nazionalità non determinata">🏳️</span>';
    }
    if (preg_match('/^[A-Z]{2}$/', $c)) {
        $svg = __DIR__ . '/flags/' . $c . '.svg';
        if (is_file($svg)) {
            return '<img src="flags/' . $c . '.svg" class="flag-icon" alt="' . $c . '" title="' . $c . '">';
        }
        $o = 0x1F1E6 - 65;
        return '<span title="' . $c . '">' . mb_chr(ord($c[0]) + $o) . mb_chr(ord($c[1]) + $o) . '</span>';
    }
    return htmlspecialchars($c);
}

/**
 * Percorso web del logo compagnia (VRS OperatorFlags) da opflags/<CODICE>.*,
 * o null se assente. Popolato da download_opflags.php.
 */
function fa_operator_logo(?string $code): ?string {
    static $memo = [];
    $c = strtoupper(trim((string) $code));
    if ($c === '' || !preg_match('/^[A-Z0-9]{2,4}$/', $c)) {
        return null;
    }
    if (array_key_exists($c, $memo)) {
        return $memo[$c];
    }
    foreach (['bmp', 'png', 'svg', 'gif'] as $ext) {
        $f = __DIR__ . '/opflags/' . $c . '.' . $ext;
        if (is_file($f) && filesize($f) > 0) {
            return $memo[$c] = 'opflags/' . $c . '.' . $ext;
        }
    }
    return $memo[$c] = null;
}

/** Data UTC memorizzata ("Y-m-d H:i:s UTC") -> "d/m/Y H:i:s" ora italiana. */
function fav_format_it(?string $utc_str): string {
    $utc_str = (string)$utc_str;
    if ($utc_str === '') {
        return '';
    }
    try {
        if (substr($utc_str, -4) === ' UTC') {
            $utc_str = substr($utc_str, 0, -4);
        }
        $dt = new DateTime($utc_str, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Rome'));
        return $dt->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return $utc_str;
    }
}
