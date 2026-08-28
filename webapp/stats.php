<?php
// stats.php - Statistiche del portale e del database, con classifiche.
ini_set('display_errors', '0');
require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/auth.php';

function st_rows(PDO $p, string $sql, array $args = []): array {
    $s = $p->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}
function st_scalar(PDO $p, string $sql, array $args = []) {
    $s = $p->prepare($sql);
    $s->execute($args);
    return $s->fetchColumn();
}
function st_bar($v, $max, int $w = 150): string {
    $px = $max > 0 ? max(2, (int) round($w * $v / $max)) : 2;
    return '<span class="bar" style="width:' . $px . 'px"></span>';
}
function st_h(?string $s): string { return htmlspecialchars((string) $s); }
function st_dt_short(?string $utc): string {
    $s = fav_format_it($utc);                 // d/m/Y H:i:s
    return preg_replace('#^(\d{2}/\d{2})/\d{4} (\d{2}:\d{2}):\d{2}$#', '$1 $2', $s);
}
function st_dur(?int $s): string {
    $s = (int) $s;
    if ($s <= 0) return '—';
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

$db_path = fa_config()['db_path'];

try {
    if (!file_exists($db_path)) {
        throw new RuntimeException("database non inizializzato: $db_path");
    }
    $pdo = fav_open(false);

    $tzu = new DateTimeZone('UTC');
    $now = new DateTime('now', $tzu);
    $d1  = (clone $now)->modify('-24 hours')->format('Y-m-d H:i:s') . ' UTC';
    $d7  = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s') . ' UTC';
    $d30 = (clone $now)->modify('-30 days')->format('Y-m-d H:i:s') . ' UTC';

    $total      = (int) st_scalar($pdo, "SELECT COUNT(*) FROM events");
    $last24     = (int) st_scalar($pdo, "SELECT COUNT(*) FROM events WHERE first_seen_utc >= ?", [$d1]);
    $last7      = (int) st_scalar($pdo, "SELECT COUNT(*) FROM events WHERE first_seen_utc >= ?", [$d7]);
    $distinct   = (int) st_scalar($pdo, "SELECT COUNT(DISTINCT hex) FROM events");
    $mil_cnt    = (int) st_scalar($pdo, "SELECT COUNT(*) FROM events WHERE is_mil = 1");
    $avg_conf   = st_scalar($pdo, "SELECT ROUND(AVG(confidence), 2) FROM events WHERE confidence IS NOT NULL");
    $min_seen   = st_scalar($pdo, "SELECT MIN(first_seen_utc) FROM events");
    $max_seen   = st_scalar($pdo, "SELECT MAX(COALESCE(last_seen_utc, first_seen_utc)) FROM events");
    $emerg_cnt  = (int) st_scalar($pdo, "SELECT COUNT(*) FROM events WHERE squawk IN ('7500','7600','7700')");

    $days_span = 1.0;
    if ($min_seen) {
        try {
            $a = new DateTime(rtrim($min_seen, ' UTC'), $tzu);
            $days_span = max(1.0, ($now->getTimestamp() - $a->getTimestamp()) / 86400.0);
        } catch (Exception $e) {}
    }
    $per_day = round($total / $days_span, 1);
    $db_mb = round((@filesize($db_path) ?: 0) / 1048576, 1);

    $by_type    = st_rows($pdo, "SELECT event_type t, COUNT(*) n FROM events GROUP BY t ORDER BY n DESC");
    $by_subtype = st_rows($pdo, "SELECT COALESCE(NULLIF(subtype,''),'(nessuno)') s, COUNT(*) n FROM events GROUP BY s ORDER BY n DESC");
    $top_ac = st_rows($pdo, "SELECT hex, MAX(callsign) callsign, MAX(model_t) model_t, MAX(is_mil) is_mil, COUNT(*) n
                             FROM events GROUP BY hex ORDER BY n DESC LIMIT 20");
    $top_cs = st_rows($pdo, "SELECT callsign, COUNT(*) n FROM events WHERE callsign IS NOT NULL AND callsign<>'' GROUP BY callsign ORDER BY n DESC LIMIT 15");
    $top_md = st_rows($pdo, "SELECT model_t, COUNT(*) n FROM events WHERE model_t IS NOT NULL AND model_t<>'' GROUP BY model_t ORDER BY n DESC LIMIT 15");
    $top_rg = st_rows($pdo, "SELECT reg, COUNT(*) n FROM events WHERE reg IS NOT NULL AND reg<>'' GROUP BY reg ORDER BY n DESC LIMIT 15");
    $top_sq = st_rows($pdo, "SELECT squawk, COUNT(*) n FROM events WHERE squawk IS NOT NULL AND squawk<>'' GROUP BY squawk ORDER BY n DESC LIMIT 15");
    $top_op = st_rows($pdo, "SELECT operator, COUNT(*) n FROM events WHERE operator IS NOT NULL AND operator<>'' GROUP BY operator ORDER BY n DESC LIMIT 15");
    $top_cc = st_rows($pdo, "SELECT COALESCE(NULLIF(country,''),'ZZ') country, COUNT(*) n FROM events GROUP BY 1 ORDER BY n DESC LIMIT 20");
    $near   = st_rows($pdo, "SELECT near_airport, COUNT(*) n FROM events GROUP BY near_airport");
    $longest = st_rows($pdo, "SELECT first_seen_utc, hex, callsign, subtype, duration_s, laps, confidence
                              FROM events WHERE duration_s IS NOT NULL AND duration_s > 0 ORDER BY duration_s DESC LIMIT 10");
    $mostlaps = st_rows($pdo, "SELECT first_seen_utc, hex, callsign, subtype, laps, duration_s, confidence
                               FROM events WHERE laps IS NOT NULL ORDER BY laps DESC, duration_s DESC LIMIT 10");
    $conf_buckets = st_rows($pdo, "SELECT CAST(confidence*4 AS INT) b, COUNT(*) n FROM events WHERE confidence IS NOT NULL GROUP BY b ORDER BY b");
    $per_day_rows = st_rows($pdo, "SELECT substr(first_seen_utc,1,10) d, COUNT(*) n FROM events WHERE first_seen_utc >= ? GROUP BY d ORDER BY d", [$d30]);
    $per_hour = st_rows($pdo, "SELECT substr(first_seen_utc,12,2) h, COUNT(*) n FROM events GROUP BY h ORDER BY h");
    $emergencies = st_rows($pdo, "SELECT first_seen_utc, hex, callsign, squawk, note FROM events WHERE squawk IN ('7500','7600','7700') ORDER BY first_seen_utc DESC LIMIT 15");

} catch (Throwable $e) {
    error_log('stats.php: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Servizio non disponibile</title></head>'
       . '<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
       . '<h1>Statistiche non disponibili</h1><p>Riprova tra qualche minuto.</p></body></html>';
    exit;
}

$hour_map = array_fill(0, 24, 0);
foreach ($per_hour as $r) { $hour_map[(int) $r['h']] = (int) $r['n']; }
$hour_max = max(1, max($hour_map));
$day_max = 1;
foreach ($per_day_rows as $r) { $day_max = max($day_max, (int) $r['n']); }
$bucket_lbl = ['0–0.25', '0.25–0.50', '0.50–0.75', '0.75–1.0'];
$bucket_map = array_fill(0, 4, 0);
foreach ($conf_buckets as $r) { $bi = max(0, min(3, (int) $r['b'])); $bucket_map[$bi] += (int) $r['n']; }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statistiche — Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <p class="nav-links"><a href="index.php">← Eventi</a> <a href="favorites.php">⭐ Preferiti</a> <a href="stats.php">📊 Statistiche</a> <span style="float:right;"><?= auth_nav_html() ?></span></p>
    <h1>📊 Statistiche</h1>

    <div class="kpi-row">
        <div class="kpi"><div class="n"><?= number_format($total) ?></div><div class="l">eventi totali</div></div>
        <div class="kpi"><div class="n"><?= number_format($last24) ?></div><div class="l">ultime 24 h</div></div>
        <div class="kpi"><div class="n"><?= number_format($last7) ?></div><div class="l">ultimi 7 giorni</div></div>
        <div class="kpi"><div class="n"><?= number_format($per_day, 1) ?></div><div class="l">media/giorno</div></div>
        <div class="kpi"><div class="n"><?= number_format($distinct) ?></div><div class="l">aeromobili distinti</div></div>
        <div class="kpi"><div class="n"><?= $total ? round(100 * $mil_cnt / $total, 1) : 0 ?>%</div><div class="l">flag militare</div></div>
        <div class="kpi"><div class="n"><?= $avg_conf !== null ? $avg_conf : '—' ?></div><div class="l">confidenza media</div></div>
        <div class="kpi"><div class="n"><?= number_format($emerg_cnt) ?></div><div class="l">squawk emergenza</div></div>
        <div class="kpi"><div class="n"><?= $db_mb ?> MB</div><div class="l">database</div></div>
    </div>
    <p class="muted">Periodo dati: <?= st_h(fav_format_it($min_seen)) ?> → <?= st_h(fav_format_it($max_seen)) ?> (orari raggruppati in UTC).</p>

    <div class="stats-grid">

        <div class="stats-card">
            <h2>Per tipo</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $by_type ?: [['n'=>1]]));
            foreach ($by_type as $r): ?>
                <tr><td><?= st_h($r['t']) ?></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Per sottotipo</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $by_subtype ?: [['n'=>1]]));
            foreach ($by_subtype as $r): ?>
                <tr><td><?= st_h($r['s']) ?></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Confidenza (distribuzione)</h2>
            <table><?php $mx = max(1, max($bucket_map));
            foreach ($bucket_lbl as $i => $lbl): ?>
                <tr><td><?= $lbl ?></td><td><?= number_format($bucket_map[$i]) ?></td><td><?= st_bar($bucket_map[$i], $mx) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Militare vs civile / vicino a scalo</h2>
            <table>
                <tr><td>Flag militare</td><td><?= number_format($mil_cnt) ?></td></tr>
                <tr><td>Civile / non marcato</td><td><?= number_format($total - $mil_cnt) ?></td></tr>
                <?php foreach ($near as $r): ?>
                <tr><td><?= ((int)$r['near_airport'] === 1) ? 'In zona di esclusione aeroporto' : 'Lontano da scali' ?></td><td><?= number_format($r['n']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="stats-card">
            <h2>Classifica aeromobili (n° eventi)</h2>
            <table>
                <tr><th>ICAO</th><th>Callsign</th><th>Modello</th><th>N°</th></tr>
                <?php foreach ($top_ac as $r): ?>
                <tr>
                    <td><?php if ((int)$r['is_mil'] === 1): ?><span class="mil-badge">⚑</span> <?php endif; ?><a href="index.php?hex=<?= urlencode($r['hex']) ?>&amp;quick_range=all"><?= st_h($r['hex']) ?></a></td>
                    <td><?= st_h($r['callsign']) ?></td><td><?= st_h($r['model_t']) ?></td><td><?= number_format($r['n']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="stats-card">
            <h2>Classifica callsign</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $top_cs ?: [['n'=>1]]));
            foreach ($top_cs as $r): ?>
                <tr><td><a href="index.php?callsign=<?= urlencode($r['callsign']) ?>&amp;quick_range=all"><?= st_h($r['callsign']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Classifica compagnie</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $top_op ?: [['n'=>1]]));
            foreach ($top_op as $r): $ol = fa_operator_logo($r['operator']); ?>
                <tr><td><?php if ($ol): ?><img src="<?= htmlspecialchars($ol) ?>" class="op-logo" alt=""><?php endif; ?><a href="index.php?operator=<?= urlencode($r['operator']) ?>&amp;quick_range=all"><?= st_h($r['operator']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?>
                <?php if (!$top_op): ?><tr><td colspan="3" class="muted">Nessuna compagnia identificata.</td></tr><?php endif; ?></table>
        </div>

        <div class="stats-card">
            <h2>Nazionalità</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $top_cc ?: [['n'=>1]]));
            foreach ($top_cc as $r): ?>
                <tr><td><a href="index.php?country=<?= urlencode($r['country']) ?>&amp;quick_range=all"><?= fa_country_flag_html($r['country']) ?> <?= st_h($r['country']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Classifica modelli</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $top_md ?: [['n'=>1]]));
            foreach ($top_md as $r): ?>
                <?php $sil = fa_silhouette_path($r['model_t']); ?>
                <tr><td><?php if ($sil): ?><img src="<?= htmlspecialchars($sil) ?>" class="model-silhouette" alt=""><?php endif; ?><a href="index.php?model=<?= urlencode($r['model_t']) ?>&amp;quick_range=all"><?= st_h($r['model_t']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Classifica registrazioni</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $top_rg ?: [['n'=>1]]));
            foreach ($top_rg as $r): ?>
                <tr><td><a href="index.php?reg=<?= urlencode($r['reg']) ?>&amp;quick_range=all"><?= st_h($r['reg']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Squawk più frequenti</h2>
            <table><?php $mx = max(array_map(fn($r) => (int)$r['n'], $top_sq ?: [['n'=>1]]));
            foreach ($top_sq as $r): $em = in_array($r['squawk'], ['7500','7600','7700'], true); ?>
                <tr><td><a href="index.php?squawk=<?= urlencode($r['squawk']) ?>&amp;quick_range=all"><?= st_h($r['squawk']) ?></a><?= $em ? ' <span class="mil-badge">⚠</span>' : '' ?></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Episodi più lunghi</h2>
            <table>
                <tr><th>Data</th><th>ICAO</th><th>Sottotipo</th><th>Durata</th><th>Giri</th></tr>
                <?php foreach ($longest as $r): ?>
                <tr><td><?= st_h(st_dt_short($r["first_seen_utc"])) ?></td><td><?= st_h($r['hex']) ?></td><td><?= st_h($r['subtype']) ?></td><td><?= st_dur($r['duration_s']) ?></td><td><?= $r['laps'] !== null ? (int)$r['laps'] : '—' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$longest): ?><tr><td colspan="5" class="muted">Nessun episodio con durata registrata.</td></tr><?php endif; ?>
            </table>
        </div>

        <div class="stats-card">
            <h2>Più giri (pattern circolari)</h2>
            <table>
                <tr><th>Data</th><th>ICAO</th><th>Sottotipo</th><th>Giri</th><th>Durata</th></tr>
                <?php foreach ($mostlaps as $r): ?>
                <tr><td><?= st_h(st_dt_short($r["first_seen_utc"])) ?></td><td><?= st_h($r['hex']) ?></td><td><?= st_h($r['subtype']) ?></td><td><?= (int)$r['laps'] ?></td><td><?= st_dur($r['duration_s']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$mostlaps): ?><tr><td colspan="5" class="muted">Nessun pattern con conteggio giri.</td></tr><?php endif; ?>
            </table>
        </div>

        <div class="stats-card">
            <h2>Attività per ora (UTC)</h2>
            <table>
                <?php for ($h = 0; $h < 24; $h++): ?>
                <tr><td><?= sprintf('%02d', $h) ?></td><td><?= number_format($hour_map[$h]) ?></td><td><?= st_bar($hour_map[$h], $hour_max, 110) ?></td></tr>
                <?php endfor; ?>
            </table>
        </div>

        <div class="stats-card">
            <h2>Attività per giorno (ultimi 30)</h2>
            <table>
                <?php foreach ($per_day_rows as $r): ?>
                <tr><td><?= st_h($r['d']) ?></td><td><?= number_format($r['n']) ?></td><td><?= st_bar($r['n'], $day_max, 110) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$per_day_rows): ?><tr><td class="muted">Nessun evento negli ultimi 30 giorni.</td></tr><?php endif; ?>
            </table>
        </div>

    </div>

    <div class="stats-card" style="margin-top:18px;">
        <h2>⚠ Ultimi squawk di emergenza</h2>
        <table>
            <tr><th>Data</th><th>ICAO</th><th>Callsign</th><th>Squawk</th><th>Note</th></tr>
            <?php foreach ($emergencies as $r): ?>
            <tr><td><?= st_h(st_dt_short($r["first_seen_utc"])) ?></td><td><?= st_h($r['hex']) ?></td><td><?= st_h($r['callsign']) ?></td><td><?= st_h($r['squawk']) ?></td><td><?= st_h($r['note']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$emergencies): ?><tr><td colspan="5" class="muted">Nessuno squawk di emergenza registrato.</td></tr><?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>
