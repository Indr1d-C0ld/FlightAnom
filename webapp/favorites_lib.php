<?php
// favorites_lib.php - Schema e accesso alla tabella dei preferiti.
// Modello: watchlist PER-AEROMOBILE (chiave hex), una nota libera per hex.
// I preferiti vivono nello stesso db/events.db; la tabella e' creata pigramente
// dai writer (toggle_favorite.php, edit_favorite.php).

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
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        hex TEXT PRIMARY KEY,
        note TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function fav_table_exists(PDO $pdo): bool {
    return (bool)$pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='favorites'"
    )->fetchColumn();
}

/** Set dei preferiti come mappa hex(minuscolo) => true. [] se la tabella non esiste. */
function fav_hex_set(PDO $pdo): array {
    if (!fav_table_exists($pdo)) {
        return [];
    }
    $out = [];
    foreach ($pdo->query("SELECT hex FROM favorites") as $r) {
        $out[strtolower((string)$r['hex'])] = true;
    }
    return $out;
}

function fav_valid_hex(string $hex): bool {
    return (bool)preg_match('/^[0-9a-f]{6,8}$/', $hex);
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
