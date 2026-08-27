<?php
// favorites.php - Elenco degli aeromobili preferiti con nota annotabile.
// Per ogni hex mostra i dati dell'evento piu' recente + conteggio eventi.
ini_set('display_errors', '0');
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/favorites_lib.php';

$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'created_at';
$order  = (($_GET['order'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$rows = [];
$load_error = false;
try {
    $pdo = fav_open(false);
    if (fav_table_exists($pdo)) {
        $favs = $pdo->query("SELECT hex, note AS fav_note, created_at FROM favorites")->fetchAll();
        $latest = $pdo->prepare(
            "SELECT callsign, reg, model_t, event_type, first_seen_utc
             FROM events WHERE hex = ? ORDER BY first_seen_utc DESC LIMIT 1"
        );
        $counter = $pdo->prepare("SELECT COUNT(*) FROM events WHERE hex = ?");
        foreach ($favs as $f) {
            $latest->execute([$f['hex']]);
            $ev = $latest->fetch() ?: [];
            $counter->execute([$f['hex']]);
            $rows[] = [
                'hex'        => $f['hex'],
                'fav_note'   => (string)($f['fav_note'] ?? ''),
                'created_at' => (string)($f['created_at'] ?? ''),
                'callsign'   => (string)($ev['callsign'] ?? ''),
                'reg'        => (string)($ev['reg'] ?? ''),
                'model_t'    => (string)($ev['model_t'] ?? ''),
                'event_type' => (string)($ev['event_type'] ?? ''),
                'last_seen'  => (string)($ev['first_seen_utc'] ?? ''),
                'n_events'   => (int)$counter->fetchColumn(),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('favorites.php: ' . $e->getMessage());
    $load_error = true;
}

if ($search !== '') {
    $needle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        $hay = mb_strtolower(implode(' ', [
            $r['hex'], $r['callsign'], $r['reg'], $r['model_t'], $r['fav_note'],
        ]));
        return mb_strpos($hay, $needle) !== false;
    }));
}

$field_map = [
    'hex' => 'hex', 'callsign' => 'callsign', 'reg' => 'reg', 'model_t' => 'model_t',
    'type' => 'event_type', 'seen' => 'last_seen', 'events' => 'n_events',
    'fav_note' => 'fav_note', 'created_at' => 'created_at',
];
$key = $field_map[$sort] ?? 'created_at';
usort($rows, function ($a, $b) use ($key, $order) {
    if ($key === 'n_events') {
        $cmp = $a['n_events'] <=> $b['n_events'];
    } elseif ($key === 'last_seen' || $key === 'created_at') {
        $cmp = strcmp((string)$a[$key], (string)$b[$key]);
    } else {
        $cmp = strcasecmp((string)$a[$key], (string)$b[$key]);
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
    <title>Preferiti — Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .fav-note { font-style: italic; color: #6c757d; }
        .filter-bar { margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        td.num { text-align: right; }
    </style>
</head>
<body>
<div class="container">
    <p class="nav-links"><a href="index.php">← Eventi</a> <a href="favorites.php">⭐ Preferiti</a></p>
    <h1>⭐ Aeromobili preferiti</h1>

    <?php if ($load_error): ?>
        <p>Impossibile leggere i preferiti in questo momento. Riprova tra qualche minuto.</p>
    <?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="hex, callsign, reg, modello, nota…" value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Filtra</button>
        <a href="favorites.php">Reset</a>
        <span style="margin-left:auto;color:#6c757d;font-size:0.9em;"><?= count($rows) ?> risultati</span>
    </form>

    <table>
        <thead>
            <tr>
                <th></th>
                <th><?= fav_sort_link('hex', 'ICAO', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('callsign', 'Callsign', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('reg', 'Reg', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('model_t', 'Modello', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('seen', 'Ultimo evento', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('events', 'N° eventi', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('fav_note', 'Nota', $sort, $order, $get_params) ?></th>
                <th><?= fav_sort_link('created_at', 'Aggiunto il', $sort, $order, $get_params) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <button type="button" class="fav-btn is-fav" data-hex="<?= htmlspecialchars($r['hex']) ?>"
                            title="Rimuovi dai preferiti" aria-pressed="true">⭐</button>
                </td>
                <td>
                    <a href="index.php?hex=<?= urlencode($r['hex']) ?>" title="Filtra gli eventi per questo ICAO"><?= htmlspecialchars($r['hex']) ?></a>
                    <a href="https://www.flightdb.net/aircraft.php?modes=<?= urlencode($r['hex']) ?>" target="_blank" class="icon-link" title="FlightDB">🔗</a>
                </td>
                <td><?= htmlspecialchars($r['callsign']) ?></td>
                <td><?= htmlspecialchars($r['reg']) ?></td>
                <td><?= htmlspecialchars($r['model_t']) ?></td>
                <td>
                    <?php if ($r['last_seen'] !== ''): ?>
                        <?= htmlspecialchars($r['event_type']) ?> · <?= htmlspecialchars(fav_format_it($r['last_seen'])) ?>
                    <?php else: ?>
                        <span style="color:#6c757d;">nessun evento</span>
                    <?php endif; ?>
                </td>
                <td class="num"><?= $r['n_events'] ?></td>
                <td>
                    <?php if ($r['fav_note'] !== ''): ?>
                        <span class="fav-note"><?= nl2br(htmlspecialchars($r['fav_note'])) ?></span>
                    <?php endif; ?>
                    <a href="edit_favorite.php?hex=<?= urlencode($r['hex']) ?>" class="icon-link" title="Modifica nota">✏️</a>
                </td>
                <td><?= htmlspecialchars(fav_format_it($r['created_at'] . ' UTC')) ?></td>
                <td>
                    <a href="#" class="fav-remove" data-hex="<?= htmlspecialchars($r['hex']) ?>" title="Rimuovi dai preferiti">✖</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="10" style="text-align:center;color:#6c757d;">
                <?= $search !== '' ? 'Nessun preferito per il filtro corrente.' : 'Nessun aeromobile tra i preferiti. Aggiungili con ☆ dall\'elenco eventi.' ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function favRequest(hex, action) {
    return fetch('toggle_favorite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({hex: hex, action: action, csrf_token: CSRF})
    }).then(r => r.json());
}

document.addEventListener('click', e => {
    const rm = e.target.closest('.fav-remove, .fav-btn');
    if (!rm) return;
    e.preventDefault();
    const hex = rm.dataset.hex;
    if (!confirm('Rimuovere ' + hex + ' dai preferiti?')) return;
    favRequest(hex, 'remove').then(d => {
        if (d.ok) {
            const tr = rm.closest('tr');
            if (tr) tr.remove();
        } else {
            alert('Errore: ' + (d.error || 'operazione non riuscita'));
        }
    }).catch(() => alert('Errore di rete.'));
});
</script>
</body>
</html>
