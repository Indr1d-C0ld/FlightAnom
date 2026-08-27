<?php
// view.php - Dettaglio evento con mappa interattiva e layout bilanciato
ini_set('display_errors', '0'); // produzione: nessun dettaglio errore al client
require_once __DIR__ . '/favorites_lib.php';

$id = (int)($_GET['id'] ?? 0);
$db_path = fa_config()['db_path'];

try {
    $pdo = new PDO("sqlite:$db_path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000'); // attende invece di fallire con "database is locked"

    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('view.php: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Servizio non disponibile</title></head>'
       . '<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
       . '<h1>Servizio temporaneamente non disponibile</h1><p>Riprova tra qualche minuto.</p></body></html>';
    exit;
}

if (!$event) {
    http_response_code(404);
    echo "Evento non trovato.";
    exit;
}

// Flag per JSON destinato a essere incorporato in <script>: neutralizza
// </script>, apici e & cosi' che nessun valore di stringa possa uscire dal blocco.
$JSON_JS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

$track_points = !empty($event['track_points']) ? json_decode($event['track_points'], true) : [];
if (!is_array($track_points)) $track_points = [];
$track_json = json_encode($track_points, $JSON_JS);

// Dati del popup passati come oggetto unico (niente interpolazione PHP dentro il template literal JS)
$popup_json = json_encode([
    'callsign' => $event['callsign'],
    'hex'      => $event['hex'],
    'alt'      => $event['alt_baro'],
    'gs'       => $event['gs'],
    'squawk'   => $event['squawk'],
], $JSON_JS);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Evento <?= htmlspecialchars($event['hex']) ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 20px;
            background: #f0f2f5;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        h1 {
            margin: 0 0 20px;
            font-size: 1.5rem;
        }
        .container {
            display: flex;
            flex: 1;
            gap: 20px;
            min-height: 0;
        }
        .panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 20px;
            overflow: auto;
        }
        .left-panel {
            flex: 0 0 40%;
            display: flex;
            flex-direction: column;
        }
        .right-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        #map {
            flex: 1;
            width: 100%;
            min-height: 400px;
            border-radius: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        th, td {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
            text-align: left;
            vertical-align: top;
        }
        th {
            width: 40%;
            color: #555;
            background: #fafafa;
        }
        td {
            word-break: break-word;
        }
        @media (max-width: 768px) {
            body { height: auto; }
            .container {
                flex-direction: column;
                height: auto;
            }
            .left-panel, .right-panel {
                flex: none;
                width: 100%;
            }
            #map {
                min-height: 350px;
            }
        }
    </style>
</head>
<body>
<h1>Dettaglio evento</h1>
<div class="container">
    <div class="panel left-panel">
        <table>
            <tr><th>ID</th><td><?= (int)$event['id'] ?></td></tr>
            <tr><th>Data UTC</th><td><?= htmlspecialchars($event['first_seen_utc']) ?></td></tr>
            <tr><th>ICAO</th><td>
                <a href="https://www.flightdb.net/aircraft.php?modes=<?= urlencode($event['hex']) ?>" target="_blank">
                    <?= htmlspecialchars($event['hex']) ?>
                </a>
            </td></tr>
            <tr><th>Callsign</th><td><?= htmlspecialchars($event['callsign']) ?></td></tr>
            <tr><th>Registration</th><td><?= htmlspecialchars($event['reg']) ?></td></tr>
            <tr><th>Model</th><td><?= htmlspecialchars($event['model_t']) ?></td></tr>
            <tr><th>Tipo evento</th><td><?= htmlspecialchars($event['event_type']) ?></td></tr>
            <tr><th>Note</th><td><?= htmlspecialchars($event['note']) ?></td></tr>
            <tr><th>Squawk</th><td><?= htmlspecialchars($event['squawk']) ?></td></tr>
            <tr><th>Altitudine (ft)</th><td><?= htmlspecialchars($event['alt_baro']) ?></td></tr>
            <tr><th>Velocità (kt)</th><td><?= htmlspecialchars($event['gs']) ?></td></tr>
        </table>
    </div>
    <div class="panel right-panel">
        <div id="map"></div>
    </div>
</div>

<script>
    const track = <?= $track_json ?>;
    const ev = <?= $popup_json ?>;
    const mapEl = document.getElementById('map');
    if (typeof L === 'undefined') {
        mapEl.innerText = 'Mappa non disponibile (libreria Leaflet non caricata).';
    } else if (track && track.length > 0) {
        const last = track[track.length-1];
        const map = L.map('map').setView([last.lat, last.lon], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const latlngs = track.map(p => [p.lat, p.lon]);
        L.polyline(latlngs, {color: 'red', weight: 3}).addTo(map);

        const marker = L.marker([last.lat, last.lon]).addTo(map);
        marker.bindPopup(
            '<b>' + (ev.callsign || '') + ' (' + (ev.hex || '') + ')</b><br>' +
            'Alt: ' + (ev.alt ?? '') + ' ft<br>' +
            'GS: ' + (ev.gs ?? '') + ' kt<br>' +
            'Squawk: ' + (ev.squawk ?? '')
        ).openPopup();
    } else {
        mapEl.innerText = 'Nessuna traccia disponibile.';
    }
</script>
</body>
</html>
