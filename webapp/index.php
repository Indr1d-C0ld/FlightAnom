<?php
// index.php - Elenco eventi con filtri, ordinamento tramite intestazioni, link esterni, copia valori e paginazione

ini_set('display_errors', '0'); // produzione: nessun dettaglio errore al client (gia' Off nel php.ini, ridondanza difensiva)

require_once __DIR__ . '/csrf.php';           // sessione + CSRF + auth
require_once __DIR__ . '/favorites_lib.php';

$can_edit = is_logged_in();   // collaboratore/admin: mostra le azioni di scrittura

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
foreach (['hex' => 'hex', 'callsign' => 'callsign', 'reg' => 'reg',
          'squawk' => 'squawk', 'model' => 'model_t',
          'country' => 'country', 'operator' => 'operator'] as $qs => $col) {
    if (!empty($_GET[$qs])) {
        $where[] = "$col LIKE ?";
        $params[] = '%' . $_GET[$qs] . '%';
    }
}
$min_conf = isset($_GET['min_conf']) && is_numeric($_GET['min_conf'])
    ? max(0.0, min(1.0, (float) $_GET['min_conf'])) : 0.0;
if ($min_conf > 0) {
    $where[] = "confidence >= ?";
    $params[] = $min_conf;
}
$only_mil = !empty($_GET['mil']);
if ($only_mil) {
    $where[] = "is_mil = 1";
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
    'subtype' => 'subtype',
    'icao' => 'hex',
    'naz' => 'country',
    'callsign' => 'callsign',
    'op' => 'operator',
    'reg' => 'reg',
    'model' => 'model_t',
    'squawk' => 'squawk',
    'conf' => 'confidence',
    'note' => 'note',
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
    $sql = "SELECT id, first_seen_utc, last_seen_utc, hex, callsign, reg, model_t, squawk,
                   event_type, subtype, note, confidence, is_mil, laps, duration_s,
                   country, operator
            FROM events";
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
if (!function_exists('format_italian_datetime')):
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
endif;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title>Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <h1>✈️ Flight Anomaly Monitor</h1>
    <p class="nav-links"><a href="favorites.php">⭐ Preferiti</a> <a href="stats.php">📊 Statistiche</a> <span style="float:right;"><?= auth_nav_html() ?></span></p>
    <form method="GET" class="filters" id="filterForm">
        <select name="event_type">
            <option value="">Tutti i tipi</option>
            <option value="PATTERN" <?= ($_GET['event_type']??'')=='PATTERN'?'selected':'' ?>>Pattern</option>
            <option value="PROX" <?= ($_GET['event_type']??'')=='PROX'?'selected':'' ?>>Prossimità</option>
            <option value="ANOMALY" <?= ($_GET['event_type']??'')=='ANOMALY'?'selected':'' ?>>Anomalia</option>
        </select>

        <input type="text" name="hex" placeholder="ICAO hex" value="<?= htmlspecialchars($_GET['hex']??'') ?>">
        <input type="text" name="callsign" placeholder="Callsign" value="<?= htmlspecialchars($_GET['callsign']??'') ?>">
        <input type="text" name="reg" placeholder="Registrazione" value="<?= htmlspecialchars($_GET['reg']??'') ?>">
        <input type="text" name="model" placeholder="Modello" value="<?= htmlspecialchars($_GET['model']??'') ?>">
        <input type="text" name="squawk" placeholder="Squawk" value="<?= htmlspecialchars($_GET['squawk']??'') ?>">
        <input type="text" name="country" placeholder="Naz. ISO2" value="<?= htmlspecialchars($_GET['country']??'') ?>">
        <input type="text" name="operator" placeholder="Compagnia ICAO" value="<?= htmlspecialchars($_GET['operator']??'') ?>">
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        <label title="Confidenza minima">conf ≥
            <select name="min_conf">
                <?php foreach (['0'=>'—','0.25'=>'0.25','0.4'=>'0.40','0.6'=>'0.60','0.8'=>'0.80'] as $v=>$lbl): ?>
                    <option value="<?= $v ?>" <?= (string)($_GET['min_conf']??'0')===$v?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label title="Solo aeromobili con flag militare"><input type="checkbox" name="mil" value="1" <?= $only_mil ? 'checked' : '' ?>> Solo mil</label>
        <label title="Mostra solo eventi tra i preferiti"><input type="checkbox" name="fav" value="1" <?= $only_fav ? 'checked' : '' ?>> Solo preferiti</label>
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

    <div class="autorefresh">
        <label><input type="checkbox" id="ar-toggle"> Auto-aggiorna</label>
        <select id="ar-interval" aria-label="Intervallo di aggiornamento">
            <option value="10">10 s</option>
            <option value="30">30 s</option>
            <option value="60">1 min</option>
            <option value="120">2 min</option>
            <option value="300">5 min</option>
        </select>
        <span id="ar-status" class="muted"></span>
    </div>

    <div id="events-region">
    <div class="table-scroll">
    <table class="cards">
        <thead>
            <tr>
                <th><a href="<?= htmlspecialchars(sort_url('date', $sort, $dir)) ?>">Data <?= $sort=='date' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('type', $sort, $dir)) ?>">Tipo <?= $sort=='type' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('icao', $sort, $dir)) ?>">ICAO <?= $sort=='icao' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('naz', $sort, $dir)) ?>">Naz. <?= $sort=='naz' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('callsign', $sort, $dir)) ?>">Callsign <?= $sort=='callsign' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('op', $sort, $dir)) ?>">Compagnia <?= $sort=='op' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('reg', $sort, $dir)) ?>">Reg <?= $sort=='reg' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('model', $sort, $dir)) ?>">Modello <?= $sort=='model' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('squawk', $sort, $dir)) ?>">Squawk <?= $sort=='squawk' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('conf', $sort, $dir)) ?>">Conf <?= $sort=='conf' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th><a href="<?= htmlspecialchars(sort_url('note', $sort, $dir)) ?>">Note <?= $sort=='note' ? ($dir=='ASC' ? '▲' : '▼') : '' ?></a></th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev): ?>
            <?php
                $hex = $ev['hex'];
                $callsign = $ev['callsign'];
                $flightradar24_url = !empty($callsign) ? 'https://www.flightradar24.com/' . urlencode($callsign) : '';
                $planespotters_url = !empty($callsign) ? 'https://www.planespotters.net/search?q=' . urlencode($callsign) : '';
                $conf = $ev['confidence'];
                $tipo = $ev['event_type'] . (!empty($ev['subtype']) ? ' · ' . $ev['subtype'] : '');
            ?>
            <tr>
                <td data-label="Data"><?= htmlspecialchars(format_italian_datetime($ev['first_seen_utc'])) ?></td>
                <td data-label="Tipo"><?= htmlspecialchars($tipo) ?><?php if (!empty($ev['laps'])): ?> <span class="muted">(<?= (int)$ev['laps'] ?> giri)</span><?php endif; ?></td>
                <td data-label="ICAO">
                    <?php if (!empty($ev['is_mil'])): ?><span class="mil-badge" title="Flag militare">⚑</span> <?php endif; ?>
                    <a href="https://www.flightdb.net/aircraft.php?modes=<?= urlencode($hex) ?>" target="_blank" title="Apri FlightDB per ICAO <?= htmlspecialchars($hex) ?>">
                        <?= htmlspecialchars($hex) ?>
                    </a>
                    <?php if ($milair_base_url !== ''): ?>
                        <a href="<?= htmlspecialchars($milair_base_url) ?>/index.php?date_from=&date_to=&hex=<?= urlencode($hex) ?>&callsign=&reg=&model=&note=&rarity=" target="_blank" title="Cerca su MilAir ITA" class="icon-link">🔍</a>
                    <?php endif; ?>
                    <button class="copy-btn" onclick="copyText(this.dataset.copy)" data-copy="<?= htmlspecialchars($hex) ?>" title="Copia ICAO">📋</button>
                </td>
                <td data-label="Naz."><?php $cc = $ev['country'] ?? ''; ?><a href="index.php?country=<?= urlencode($cc) ?>&amp;quick_range=all" title="Eventi di <?= htmlspecialchars($cc) ?> (sempre)"><?= fa_country_flag_html($cc) ?> <span class="muted"><?= htmlspecialchars($cc) ?></span></a></td>
                <td data-label="Callsign">
                    <?php if (!empty($callsign)): ?>
                        <a href="<?= htmlspecialchars($planespotters_url) ?>" target="_blank" title="Cerca <?= htmlspecialchars($callsign) ?> su Planespotters">
                            <?= htmlspecialchars($callsign) ?>
                        </a>
                        <button class="copy-btn" onclick="copyText(this.dataset.copy)" data-copy="<?= htmlspecialchars($callsign) ?>" title="Copia Callsign">📋</button>
                    <?php else: ?>
                        <?= htmlspecialchars($callsign) ?>
                    <?php endif; ?>
                </td>
                <td data-label="Compagnia"><?php $op = $ev['operator'] ?? ''; if ($op !== ''): $opl = fa_operator_logo($op); ?><a href="index.php?operator=<?= urlencode($op) ?>&amp;quick_range=all" title="Tutti gli eventi di <?= htmlspecialchars($op) ?> (sempre)"><?php if ($opl): ?><img src="<?= htmlspecialchars($opl) ?>" class="op-logo" alt=""><?php endif; ?><?= htmlspecialchars($op) ?></a><?php endif; ?></td>
                <td data-label="Reg"><?= htmlspecialchars($ev['reg'] ?? '') ?></td>
                <td data-label="Modello"><?php $sil = fa_silhouette_path($ev['model_t'] ?? ''); if ($sil): ?><img src="<?= htmlspecialchars($sil) ?>" class="model-silhouette" alt="" title="<?= htmlspecialchars($ev['model_t']) ?>"><?php endif; ?><?= htmlspecialchars($ev['model_t'] ?? '') ?></td>
                <td data-label="Squawk"><?= htmlspecialchars($ev['squawk'] ?? '') ?></td>
                <td data-label="Conf"><?php if ($conf !== null && $conf !== ''): ?><span class="conf conf-<?= $conf >= 0.7 ? 'hi' : ($conf >= 0.4 ? 'mid' : 'lo') ?>"><?= number_format((float)$conf, 2) ?></span><?php endif; ?></td>
                <td data-label="Note"><?= htmlspecialchars($ev['note']) ?></td>
                <?php $is_fav = isset($fav_set[(int) $ev['id']]); ?>
                <td class="actions" data-label="">
                    <?php if ($can_edit): ?>
                        <button type="button" class="fav-btn<?= $is_fav ? ' is-fav' : '' ?>"
                                data-id="<?= (int) $ev['id'] ?>"
                                aria-pressed="<?= $is_fav ? 'true' : 'false' ?>"
                                title="<?= $is_fav ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti' ?>"><?= $is_fav ? '⭐' : '☆' ?></button>
                    <?php elseif ($is_fav): ?>
                        <span class="fav-static" title="Evento preferito">⭐</span>
                    <?php endif; ?>
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
    </div>

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
    </div><!-- /#events-region -->
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

// --- Auto-aggiornamento della tabella (client-side, preferenza in localStorage) ---
(function () {
    const KEY = 'fa_autorefresh';
    const toggle = document.getElementById('ar-toggle');
    const sel = document.getElementById('ar-interval');
    const statusEl = document.getElementById('ar-status');
    const region = document.getElementById('events-region');
    if (!toggle || !sel || !region) return;
    let timer = null, busy = false;

    const now = () => new Date().toLocaleTimeString('it-IT');
    function save() {
        try { localStorage.setItem(KEY, JSON.stringify({on: toggle.checked, sec: +sel.value})); } catch (e) {}
    }
    function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        const sy = window.scrollY;
        const ts = region.querySelector('.table-scroll');
        const tx = ts ? ts.scrollLeft : 0;
        fetch(window.location.href, {headers: {'X-Requested-With': 'fetch'}, cache: 'no-store'})
            .then(r => { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(html => {
                const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('events-region');
                if (!fresh) throw new Error('regione assente');
                region.innerHTML = fresh.innerHTML;
                const nts = region.querySelector('.table-scroll');
                if (nts) nts.scrollLeft = tx;
                window.scrollTo(0, sy);
                statusEl.textContent = 'aggiornato ' + now();
            })
            .catch(() => { statusEl.textContent = 'aggiornamento non riuscito ' + now(); })
            .finally(() => { busy = false; });
    }
    function apply() {
        if (timer) { clearInterval(timer); timer = null; }
        save();
        if (toggle.checked) {
            const sec = Math.max(5, +sel.value || 30);
            timer = setInterval(refresh, sec * 1000);
            statusEl.textContent = 'auto ogni ' + (sec >= 60 ? (sec / 60) + ' min' : sec + ' s');
        } else {
            statusEl.textContent = '';
        }
    }
    try {
        const p = JSON.parse(localStorage.getItem(KEY) || '{}');
        toggle.checked = !!p.on;
        if (p.sec && sel.querySelector('option[value="' + p.sec + '"]')) sel.value = String(p.sec);
    } catch (e) {}
    toggle.addEventListener('change', apply);
    sel.addEventListener('change', apply);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && toggle.checked) refresh(); });
    apply();
})();
</script>
</body>
</html>
