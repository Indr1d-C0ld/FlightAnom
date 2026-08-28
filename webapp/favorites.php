<?php
// favorites.php - Elenco degli EVENTI preferiti con nota annotabile.
ini_set('display_errors', '0');
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/favorites_lib.php';

$can_edit = is_logged_in();

$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'created_at';
$order  = (($_GET['order'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$rows = [];
$load_error = false;
try {
    $pdo = fav_open(false);
    if (fav_table_exists($pdo)) {
        $rows = $pdo->query("
            SELECT f.event_id, f.note AS fav_note, f.created_at,
                   e.first_seen_utc, e.hex, e.callsign, e.event_type,
                   e.note AS ev_note
            FROM favorites f
            JOIN events e ON e.id = f.event_id
        ")->fetchAll();
    }
} catch (Throwable $e) {
    error_log('favorites.php: ' . $e->getMessage());
    $load_error = true;
}

if ($search !== '') {
    $needle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        $hay = mb_strtolower(implode(' ', [
            $r['hex'], $r['callsign'], $r['event_type'], $r['ev_note'], $r['fav_note'],
        ]));
        return mb_strpos($hay, $needle) !== false;
    }));
}

$field_map = [
    'seen' => 'first_seen_utc', 'type' => 'event_type', 'hex' => 'hex',
    'callsign' => 'callsign', 'ev_note' => 'ev_note', 'fav_note' => 'fav_note',
    'created_at' => 'created_at',
];
$key = $field_map[$sort] ?? 'created_at';
usort($rows, function ($a, $b) use ($key, $order) {
    if ($key === 'first_seen_utc' || $key === 'created_at') {
        $cmp = strcmp((string) $a[$key], (string) $b[$key]);
    } else {
        $cmp = strcasecmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));
    }
    return $order === 'asc' ? $cmp : -$cmp;
});

$get_params = array_intersect_key($_GET, array_flip(['q', 'sort', 'order']));

function fav_sort_link(string $col, string $label, string $sort, string $order, array $get_params): string {
    $new_order = ($sort === $col && $order === 'asc') ? 'desc' : 'asc';
    $arrow = ($sort === $col) ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
    $qs = http_build_query(array_merge($get_params, ['sort' => $col, 'order' => $new_order]));
    return '<a href="?' . htmlspecialchars($qs) . '">' . htmlspecialchars($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title>Eventi preferiti — Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .fav-note { font-style: italic; color: #6c757d; }
        .filter-bar { margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    </style>
</head>
<body>
<div class="container">
    <p class="nav-links"><a href="index.php">← Eventi</a> <a href="favorites.php">⭐ Preferiti</a> <a href="stats.php">📊 Statistiche</a> <span style="float:right;"><?= auth_nav_html() ?></span></p>
    <h1>⭐ Eventi preferiti</h1>

    <?php if ($load_error): ?>
        <p>Impossibile leggere i preferiti in questo momento. Riprova tra qualche minuto.</p>
    <?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="hex, callsign, tipo, nota…" value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Filtra</button>
        <a href="favorites.php">Reset</a>
        <span style="margin-left:auto;color:#6c757d;font-size:0.9em;"><?= count($rows) ?> risultati</span>
    </form>

    <div class="table-scroll">
    <table class="cards">
        <thead>
            <tr>
                <th></th>
                <th><?= fav_sort_link('seen', 'Data', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('type', 'Tipo', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('hex', 'ICAO', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('callsign', 'Callsign', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('ev_note', 'Note evento', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('fav_note', 'Nota preferito', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('created_at', 'Aggiunto il', $sort, $order, $get_params) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="">
                    <?php if ($can_edit): ?>
                        <button type="button" class="fav-btn is-fav" data-id="<?= (int) $r['event_id'] ?>"
                                title="Rimuovi dai preferiti" aria-pressed="true">⭐</button>
                    <?php else: ?>
                        <span class="fav-static" title="Evento preferito">⭐</span>
                    <?php endif; ?>
                </td>
                <td data-label="Data"><?= htmlspecialchars(fav_format_it($r['first_seen_utc'])) ?></td>
                <td data-label="Tipo"><?= htmlspecialchars($r['event_type']) ?></td>
                <td data-label="ICAO">
                    <a href="index.php?hex=<?= urlencode($r['hex']) ?>" title="Filtra gli eventi per questo ICAO"><?= htmlspecialchars($r['hex']) ?></a>
                    <a href="https://www.flightdb.net/aircraft.php?modes=<?= urlencode($r['hex']) ?>" target="_blank" class="icon-link" title="FlightDB">🔗</a>
                </td>
                <td data-label="Callsign"><?= htmlspecialchars($r['callsign']) ?></td>
                <td data-label="Note evento"><?= htmlspecialchars($r['ev_note']) ?></td>
                <td data-label="Nota preferito">
                    <?php if ((string) $r['fav_note'] !== ''): ?>
                        <span class="fav-note"><?= nl2br(htmlspecialchars($r['fav_note'])) ?></span>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
                        <a href="edit_favorite.php?id=<?= (int) $r['event_id'] ?>" class="icon-link" title="Modifica nota">✏️</a>
                    <?php endif; ?>
                </td>
                <td data-label="Aggiunto il"><?= htmlspecialchars(fav_format_it($r['created_at'] . ' UTC')) ?></td>
                <td data-label="">
                    <a href="view.php?id=<?= (int) $r['event_id'] ?>" class="icon-link" title="Apri mappa traccia">🗺️</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;color:#6c757d;">
                <?= $search !== '' ? 'Nessun preferito per il filtro corrente.' : 'Nessun evento tra i preferiti. Aggiungili con ☆ dall\'elenco eventi.' ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

document.addEventListener('click', e => {
    const el = e.target.closest('.fav-btn');
    if (!el) return;
    e.preventDefault();
    const id = el.dataset.id;
    if (!confirm('Rimuovere l\'evento #' + id + ' dai preferiti?')) return;
    fetch('toggle_favorite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({id: id, action: 'remove', csrf_token: CSRF})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            const tr = el.closest('tr');
            if (tr) tr.remove();
        } else {
            alert('Errore: ' + (d.error || 'operazione non riuscita'));
        }
    })
    .catch(() => alert('Errore di rete.'));
});
</script>
</body>
</html>
