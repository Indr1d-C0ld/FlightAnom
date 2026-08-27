<?php
// index.php - Elenco eventi con filtri, ordinamento tramite intestazioni, link esterni, copia valori e paginazione

ini_set('display_errors', '0'); // produzione: nessun dettaglio errore al client (gia' Off nel php.ini, ridondanza difensiva)

require_once __DIR__ . '/csrf.php';           // sessione + token per il toggle preferiti
require_once __DIR__ . '/favorites_lib.php';

$db_path = fa_config()['db_path'];
$milair_base_url = rtrim((string) fa_config()['milair_base_url'], '/');

// Timezone
$tz_italy = new DateTimeZone('Europe/Rome');
$tz_utc = new DateTimeZone('UTC');

// Gestione rapida intervalli di tempo
$quick_range = $_GET['quick_range'] ?? '';

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Il campo e' <input type="date">, ma la querystring e' forgiabile: scarta i valori non validi
// invece di lasciare che DateTime() sollevi un'eccezione.
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

if ($quick_range) {
    $now = new DateTime('now', $tz_italy);
    switch ($quick_range) {
        case 'today':
            $date_from = $now->format('Y-m-d');
            $date_to = $now->format('Y-m-d');
            break;
        case '7days':
            $date_to = $now->format('Y-m-d');
            $date_from = (clone $now)->modify('-6 days')->format('Y-m-d');
            break;
        case 'month':
            $date_to = $now->format('Y-m-d');
            $date_from = (clone $now)->modify('-29 days')->format('Y-m-d');
            break;
        case 'year':
            $date_to = $now->format('Y-m-d');
            $date_from = (clone $now)->modify('-364 days')->format('Y-m-d');
            break;
        case 'all':
            $date_from = '';
            $date_to = '';
            break;
    }
}

// Filtri
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

$only_fav = !empty($_GET['fav']);
$fav_set = [];

// Conversione date italiane → UTC per confronto corretto
if ($date_from) {
    $dt_from = new DateTime($date_from . ' 00:00:00', $tz_italy);
    $dt_from->setTimezone($tz_utc);
    $where[] = "first_seen_utc >= ?";
    $params[] = $dt_from->format('Y-m-d H:i:s') . ' UTC';
}
if ($date_to) {
    $dt_to = new DateTime($date_to . ' 23:59:59', $tz_italy);
    $dt_to->setTimezone($tz_utc);
    $where[] = "first_seen_utc <= ?";
    $params[] = $dt_to->format('Y-m-d H:i:s') . ' UTC';
}

// Ordinamento (default: data discendente)
$sort = $_GET['sort'] ?? 'date';
$dir = $_GET['dir'] ?? 'desc';
$sort_whitelist = [
    'date' => 'first_seen_utc',
    'type' => 'event_type',
    'icao' => 'hex',
    'callsign' => 'callsign',
    'note' => 'note'
];
if (!isset($sort_whitelist[$sort])) $sort = 'date';
$order_by = $sort_whitelist[$sort];
$dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

// Paginazione
$per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

try {
    // Lo schema (tabella events + indici) e' di competenza del demone
    // monitor/flight_anom.py: qui si legge soltanto.
    if (!file_exists($db_path)) {
        throw new RuntimeException("database non ancora inizializzato: $db_path");
    }
    $pdo = new PDO("sqlite:$db_path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000'); // attende invece di fallire con "database is locked"

    // Preferiti: set di event_id per la stella e (se richiesto) per filtrare
    $fav_set = fav_id_set($pdo);
    if ($only_fav) {
        if ($fav_set) {
            $ph = implode(',', array_fill(0, count($fav_set), '?'));
            $where[] = "id IN ($ph)";
            foreach (array_keys($fav_set) as $eid) {
                $params[] = $eid;
            }
        } else {
            $where[] = "1 = 0"; // nessun preferito -> nessun risultato
        }
    }

    // Conta il totale dei record con i filtri attivi
    $count_sql = "SELECT COUNT(*) FROM events";
    if ($where) {
        $count_sql .= " WHERE " . implode(" AND ", $where);
    }
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_records / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    // Query principale con LIMIT/OFFSET
    $sql = "SELECT id, first_seen_utc, hex, callsign, event_type, note FROM events";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY $order_by $dir LIMIT $per_page OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('index.php: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><title>Servizio non disponibile</title></head>
<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">
<h1>Servizio temporaneamente non disponibile</h1>
<p>Non &egrave; stato possibile leggere gli eventi. Riprova tra qualche minuto.</p>
</body></html>
    <?php
    exit;
}

// Funzione per convertire data UTC in formato italiano (senza fuso)
function format_italian_datetime($utc_str) {
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

// Genera URL per ordinamento cliccando sull'intestazione (preserva pagina e filtri)
function sort_url($column, $current_sort, $current_dir) {
    $params = $_GET;
    unset($params['sort'], $params['dir']);
    if ($column == $current_sort) {
        $new_dir = ($current_dir == 'ASC') ? 'desc' : 'asc';
    } else {
        $new_dir = 'asc';
    }
    $params['sort'] = $column;
    $params['dir'] = $new_dir;
    $params['page'] = 1; // reset a prima pagina
    return '?' . http_build_query($params);
}

// Genera URL per la paginazione (preserva tutti i parametri correnti)
function page_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title>Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .copy-btn { background: none; border: none; cursor: pointer; padding: 0 4px; font-size: 1em; }
        .icon-link { text-decoration: none; margin: 0 4px; font-size: 1.2em; }
        .icon-link.disabled { opacity: 0.5; cursor: not-allowed; }
        .actions { white-space: nowrap; }
        .quick-range { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 5px; }
        .quick-btn { padding: 5px 10px; cursor: pointer; }
        .filters select, .filters input { margin-right: 5px; }
        th a { color: inherit; text-decoration: none; }
        th a:hover { text-decoration: underline; }
        .pagination { margin: 20px 0; text-align: center; display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; align-items: center; }
        .pagination a, .pagination span {
            padding: 6px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            background: #fff;
            display: inline-block;
        }
        .pagination .current {
            background: #007BFF;
            color: white;
            border-color: #007BFF;
            font-weight: bold;
        }
        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>✈️ Flight Anomaly Monitor</h1>
    <p class="nav-links"><a href="favorites.php">⭐ Preferiti</a></p>
    <form method="GET" class="filters" id="filterForm">
        <select name="event_type">
            <option value="">Tutti i tipi</option>
            <option value="PATTERN" <?= ($_GET['event_type']??'')=='PATTERN'?'selected':'' ?>>Pattern</option>
            <option value="PROX" <?= ($_GET['event_type']??'')=='PROX'?'selected':'' ?>>Prossimità</option>
            <option value="ANOMALY" <?= ($_GET['event_type']??'')=='ANOMALY'?'selected':'' ?>>Anomalia</option>
        </select>

        <input type="text" name="hex" placeholder="ICAO hex" value="<?= htmlspecialchars($_GET['hex']??'') ?>">
        <input type="text" name="callsign" placeholder="Callsign" value="<?= htmlspecialchars($_GET['callsign']??'') ?>">
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        <label title="Mostra solo eventi di aeromobili tra i preferiti">
            <input type="checkbox" name="fav" value="1" <?= $only_fav ? 'checked' : '' ?>> Solo preferiti
        </label>
        <button type="submit">Filtra</button>
        <!-- Campi nascosti per mantenere l'ordinamento -->
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
        <input type="hidden" name="page" value="1">

        <!-- Pulsanti rapidi DENTRO il form -->
        <div class="quick-range">
            <button type="submit" name="quick_range" value="today" class="quick-btn">Oggi</button>
            <button type="submit" name="quick_range" value="7days" class="quick-btn">Ultimi 7 giorni</button>
            <button type="submit" name="quick_range" value="month" class="quick-btn">Ultimo mese</button>
            <button type="submit" name="quick_range" value="year" class="quick-btn">Ultimo anno</button>
            <button type="submit" name="quick_range" value="all" class="quick-btn">Sempre</button>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th><a href="<?= htmlspecialchars(sort_url('date', $sort, $dir)) ?>">Data <?= $sort=='date' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('type', $sort, $dir)) ?>">Tipo <?= $sort=='type' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('icao', $sort, $dir)) ?>">ICAO <?= $sort=='icao' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('callsign', $sort, $dir)) ?>">Callsign <?= $sort=='callsign' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('note', $sort, $dir)) ?>">Note <?= $sort=='note' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th>Mappa</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev): ?>
            <?php
                $hex = $ev['hex'];
                $callsign = $ev['callsign'];
                $flightradar24_url = !empty($callsign) ? 'https://www.flightradar24.com/' . urlencode($callsign) : '';
                $planespotters_url = !empty($callsign) ? 'https://www.planespotters.net/search?q=' . urlencode($callsign) : '';
            ?>
            <tr>
                <td><?= htmlspecialchars(format_italian_datetime($ev['first_seen_utc'])) ?></td>
                <td><?= htmlspecialchars($ev['event_type']) ?></td>
                <td>
                    <a href="https://www.flightdb.net/aircraft.php?modes=<?= urlencode($hex) ?>" target="_blank" title="Apri FlightDB per ICAO <?= htmlspecialchars($hex) ?>">
                        <?= htmlspecialchars($hex) ?>
                    </a>
                    <?php if ($milair_base_url !== ''): ?>
                        <a href="<?= htmlspecialchars($milair_base_url) ?>/index.php?date_from=&date_to=&hex=<?= urlencode($hex) ?>&callsign=&reg=&model=&note=&rarity=" target="_blank" title="Cerca su MilAir ITA" class="icon-link">🔍</a>
                    <?php endif; ?>
                    <button class="copy-btn" onclick="copyText(this.dataset.copy)" data-copy="<?= htmlspecialchars($hex) ?>" title="Copia ICAO">📋</button>
                </td>
                <td>
                    <?php if (!empty($callsign)): ?>
                        <a href="<?= htmlspecialchars($planespotters_url) ?>" target="_blank" title="Cerca <?= htmlspecialchars($callsign) ?> su Planespotters">
                            <?= htmlspecialchars($callsign) ?>
                        </a>
                        <button class="copy-btn" onclick="copyText(this.dataset.copy)" data-copy="<?= htmlspecialchars($callsign) ?>" title="Copia Callsign">📋</button>
                    <?php else: ?>
                        <?= htmlspecialchars($callsign) ?>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($ev['note']) ?></td>
                <?php $is_fav = isset($fav_set[(int) $ev['id']]); ?>
                <td class="actions">
                    <button type="button" class="fav-btn<?= $is_fav ? ' is-fav' : '' ?>"
                            data-id="<?= (int) $ev['id'] ?>"
                            aria-pressed="<?= $is_fav ? 'true' : 'false' ?>"
                            title="<?= $is_fav ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti' ?>"><?= $is_fav ? '⭐' : '☆' ?></button>
                    <a href="view.php?id=<?= $ev['id'] ?>" title="Apri mappa traccia" class="icon-link">🗺️</a>
                    <?php if ($flightradar24_url): ?>
                        <a href="<?= htmlspecialchars($flightradar24_url) ?>" target="_blank" title="Apri Flightradar24 per <?= htmlspecialchars($callsign) ?>" class="icon-link">✈️</a>
                    <?php else: ?>
                        <span title="Callsign non disponibile" class="icon-link disabled">✈️</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= htmlspecialchars(page_url($page - 1)) ?>">← Precedente</a>
        <?php else: ?>
            <span class="disabled">← Precedente</span>
        <?php endif; ?>

        <?php
        // Mostra un intervallo di pagine (massimo 7 numeri)
        $start = max(1, $page - 3);
        $end = min($total_pages, $page + 3);
        if ($start > 1) {
            echo '<a href="' . htmlspecialchars(page_url(1)) . '">1</a>';
            if ($start > 2) echo '<span>…</span>';
        }
        for ($i = $start; $i <= $end; $i++):
            if ($i == $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars(page_url($i)) ?>"><?= $i ?></a>
            <?php endif;
        endfor;
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<span>…</span>';
            echo '<a href="' . htmlspecialchars(page_url($total_pages)) . '">' . $total_pages . '</a>';
        }
        ?>

        <?php if ($page < $total_pages): ?>
            <a href="<?= htmlspecialchars(page_url($page + 1)) ?>">Successiva →</a>
        <?php else: ?>
            <span class="disabled">Successiva →</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function copyText(text) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(() => fallbackCopy(text));
    } else {
        fallbackCopy(text);
    }
}
function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
    } catch (e) {}
    document.body.removeChild(textarea);
}

// --- Preferiti: toggle ⭐ per evento ---
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
document.addEventListener('click', e => {
    const btn = e.target.closest('.fav-btn');
    if (!btn) return;
    const id = btn.dataset.id;
    btn.disabled = true;
    fetch('toggle_favorite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({id: id, action: 'toggle', csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) { alert('Errore: ' + (d.error || 'operazione non riuscita')); return; }
        btn.classList.toggle('is-fav', d.favorited);
        btn.textContent = d.favorited ? '⭐' : '☆';
        btn.setAttribute('aria-pressed', d.favorited ? 'true' : 'false');
        btn.title = d.favorited ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti';
    })
    .catch(() => alert('Errore di rete.'))
    .finally(() => { btn.disabled = false; });
});
</script>
</body>
</html>
