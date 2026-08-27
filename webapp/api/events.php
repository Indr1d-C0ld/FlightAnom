<?php
// api/events.php - Eventi in formato JSON.
// Stessi filtri e stessa semantica delle date di index.php:
//   - date_from / date_to sono giorni nel fuso Europe/Rome, convertiti in UTC
//     e confrontati con first_seen_utc (memorizzato come 'Y-m-d H:i:s UTC').
// NON restituisce track_points (array di punti traccia, anche centinaia di
// elementi per riga): per la traccia di un singolo evento usare view.php?id=N.

ini_set('display_errors', '0'); // produzione: nessun dettaglio errore al client
require_once __DIR__ . '/../favorites_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

function fail(int $code, string $msg, int $flags): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], $flags);
    exit;
}

try {
    $tz_italy = new DateTimeZone('Europe/Rome');
    $tz_utc   = new DateTimeZone('UTC');

    $db_path = fa_config()['db_path'];
    $pdo = new PDO("sqlite:$db_path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000'); // attende invece di fallire con "database is locked"

    $where = [];
    $params = [];

    if (!empty($_GET['event_type'])) {
        $where[] = "event_type = ?";
        $params[] = $_GET['event_type'];
    }
    if (!empty($_GET['hex'])) {
        $where[] = "hex LIKE ?";
        $params[] = '%' . $_GET['hex'] . '%';
    }
    if (!empty($_GET['callsign'])) {
        $where[] = "callsign LIKE ?";
        $params[] = '%' . $_GET['callsign'] . '%';
    }

    // Conversione date italiane -> UTC, identica a index.php
    if (!empty($_GET['date_from'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
            fail(400, 'date_from deve essere nel formato YYYY-MM-DD', $JSON_FLAGS);
        }
        $dt = new DateTime($_GET['date_from'] . ' 00:00:00', $tz_italy);
        $dt->setTimezone($tz_utc);
        $where[] = "first_seen_utc >= ?";
        $params[] = $dt->format('Y-m-d H:i:s') . ' UTC';
    }
    if (!empty($_GET['date_to'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
            fail(400, 'date_to deve essere nel formato YYYY-MM-DD', $JSON_FLAGS);
        }
        $dt = new DateTime($_GET['date_to'] . ' 23:59:59', $tz_italy);
        $dt->setTimezone($tz_utc);
        $where[] = "first_seen_utc <= ?";
        $params[] = $dt->format('Y-m-d H:i:s') . ' UTC';
    }

    // Limite risultati: default 200, massimo 1000
    $limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 200;

    // Colonne esplicite: tutta la riga tranne track_points e screenshot_path
    $sql = "SELECT id, first_seen_utc, hex, callsign, reg, model_t,
                   lat, lon, alt_baro, gs, squawk, ground,
                   event_type, note, near_airport, created_at
            FROM events";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY first_seen_utc DESC LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($events, $JSON_FLAGS);

} catch (Throwable $e) {
    error_log('api/events.php: ' . $e->getMessage());
    fail(500, 'Errore interno', $JSON_FLAGS);
}
