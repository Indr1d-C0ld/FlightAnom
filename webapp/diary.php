<?php
// diary.php - Diario di bordo: sintesi giornaliere degli eventi.
// Pubblico in lettura (solo voci pubblicate). Rigenerazione digest,
// pubblicazione e narrativa assistita da IA: riservate all'admin.
ini_set('display_errors', '0');
require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/diary_lib.php';
require_once __DIR__ . '/ai_lib.php';

$is_admin = (current_role() === 'admin');
$msg = (string) ($_GET['m'] ?? '');
$err = (string) ($_GET['e'] ?? '');

try {
    $pdo = fav_open(true);
    diary_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('diary.php open: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!DOCTYPE html><meta charset="UTF-8"><title>Diario non disponibile</title><p>Diario non disponibile. Riprova tra qualche minuto.');
}

// --- Azioni admin (POST) ---------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$is_admin) {
        http_response_code(403);
        exit('403');
    }
    $day = (string) ($_POST['day'] ?? '');
    $act = (string) ($_POST['action'] ?? '');
    if (!csrf_check()) {
        $err = 'Sessione scaduta, ricarica la pagina.';
    } elseif (!diary_valid_day($day)) {
        $err = 'Data non valida.';
    } else {
        try {
            if ($act === 'refresh') {
                diary_store_digest($pdo, $day, diary_build_digest($pdo, $day));
                $msg = "Digest del $day rigenerato.";
            } elseif ($act === 'publish') {
                diary_ensure_day($pdo, $day);
                $now = gmdate('Y-m-d H:i:s') . ' UTC';
                $pdo->prepare("UPDATE diary_days SET published = 1, published_at = ?, updated_at = ? WHERE day = ?")
                    ->execute([$now, $now, $day]);
                $msg = "Voce del $day pubblicata nel diario.";
            } elseif ($act === 'unpublish') {
                $pdo->prepare("UPDATE diary_days SET published = 0, updated_at = ? WHERE day = ?")
                    ->execute([gmdate('Y-m-d H:i:s') . ' UTC', $day]);
                $msg = "Voce del $day ritirata dal diario pubblico.";
            } elseif ($act === 'narrative') {
                if (ROLE_RANK[current_role()] < (ROLE_RANK[ai_min_role()] ?? 99)) {
                    $err = 'Permessi insufficienti per la sintesi IA.';
                } elseif (!ai_enabled()) {
                    $err = 'Assistente IA non configurato.';
                } else {
                    @set_time_limit(0);
                    ignore_user_abort(true);
                    $r = diary_ensure_day($pdo, $day);
                    $digest = json_decode($r['digest_json'], true) ?: [];
                    $res = ai_generate($digest);
                    if (!$res['ok']) {
                        $err = 'Sintesi non generata: ' . $res['error'];
                    } else {
                        $pdo->prepare(
                            "UPDATE diary_days SET narrative_md = ?, narrative_model = ?,
                                    narrative_generated_at = ?, updated_at = ? WHERE day = ?"
                        )->execute([
                            $res['text'], $res['model'],
                            gmdate('Y-m-d H:i:s') . ' UTC', gmdate('Y-m-d H:i:s') . ' UTC', $day,
                        ]);
                        $extra = !empty($res['stats']['seconds']) ? " ({$res['stats']['seconds']}s)" : '';
                        $msg = "Sintesi IA generata per il $day{$extra}. Rileggila prima di pubblicare.";
                    }
                }
            } elseif ($act === 'narrative_save') {
                $txt = trim((string) ($_POST['narrative_md'] ?? ''));
                $pdo->prepare("UPDATE diary_days SET narrative_md = ?, updated_at = ? WHERE day = ?")
                    ->execute([$txt !== '' ? $txt : null, gmdate('Y-m-d H:i:s') . ' UTC', $day]);
                $msg = $txt !== '' ? "Testo della sintesi del $day salvato." : "Sintesi del $day svuotata.";
            } elseif ($act === 'narrative_clear') {
                $pdo->prepare("UPDATE diary_days SET narrative_md = NULL, narrative_model = NULL,
                               narrative_generated_at = NULL, updated_at = ? WHERE day = ?")
                    ->execute([gmdate('Y-m-d H:i:s') . ' UTC', $day]);
                $msg = "Sintesi IA del $day eliminata.";
            } else {
                $err = 'Azione sconosciuta.';
            }
        } catch (Throwable $e) {
            error_log('diary.php action: ' . $e->getMessage());
            $err = 'Operazione non riuscita.';
        }
    }
    $q = 'diary.php';
    if (diary_valid_day($day)) {
        $q .= '?day=' . urlencode($day);
        $q .= ($msg ? '&m=' : '&e=') . urlencode($msg ?: $err);
    } else {
        $q .= '?' . ($msg ? 'm=' : 'e=') . urlencode($msg ?: $err);
    }
    header('Location: ' . $q);
    exit;
}

$day = (string) ($_GET['day'] ?? '');
$detail = diary_valid_day($day);

// --- Recupero dati -------------------------------------------------------------
$row = null;
$digest = null;
$forbidden = false;

if ($detail) {
    if ($is_admin) {
        $refresh = isset($_GET['refresh']);
        try {
            $row = diary_ensure_day($pdo, $day, $refresh);
        } catch (Throwable $e) {
            error_log('diary.php ensure: ' . $e->getMessage());
            $err = $err ?: 'Impossibile calcolare la sintesi del giorno.';
        }
    } else {
        $row = diary_get($pdo, $day);
        if (!$row || (int) $row['published'] !== 1) {
            $forbidden = true;
            $row = null;
            http_response_code(404);
        }
    }
    if ($row) {
        $digest = json_decode($row['digest_json'], true) ?: [];
    }
}

$list = $detail ? [] : diary_recent($pdo, !$is_admin);

// --- Helper di rendering -----------------------------------------------------
function d_h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function d_bar($v, $max, int $w = 120): string
{
    $px = $max > 0 ? max(2, (int) round($w * $v / max(1, $max))) : 2;
    return '<span class="bar" style="width:' . $px . 'px"></span>';
}

function d_dur(?int $s): string
{
    $s = (int) $s;
    if ($s <= 0) return '—';
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

/** "d/m/Y" ita da "YYYY-MM-DD". */
function d_day_it(string $ymd): string
{
    $t = DateTime::createFromFormat('!Y-m-d', $ymd);
    return $t ? $t->format('d/m/Y') : $ymd;
}

/** Lista di id evento -> catena di link "#id". */
function d_ids(?string $csv, int $limit = 8): string
{
    $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $csv))), 0, $limit);
    if (!$ids) return '';
    $out = [];
    foreach ($ids as $id) {
        $out[] = '<a href="view.php?id=' . $id . '">#' . $id . '</a>';
    }
    return implode(' ', $out);
}

$page_title = $detail ? ('Diario · ' . d_day_it($day)) : 'Diario di bordo';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d_h($page_title) ?> — Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <p class="nav-links">
        <a href="index.php">← Eventi</a>
        <a href="favorites.php">⭐ Preferiti</a>
        <a href="stats.php">📊 Statistiche</a>
        <a href="diary.php">📓 Diario</a>
        <span style="float:right;"><?= auth_nav_html() ?></span>
    </p>

    <?php if ($msg !== ''): ?><div class="msg ok"><?= d_h($msg) ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="msg err"><?= d_h($err) ?></div><?php endif; ?>

<?php if (!$detail): /* ================= LISTA ================= */ ?>

    <h1>📓 Diario di bordo</h1>
    <p class="muted">Sintesi giornaliera degli eventi rilevati: aggregati, ricorrenze e
        segnalazioni. Una voce per giorno (fuso orario Europe/Rome).</p>

    <?php if ($is_admin): ?>
    <form method="get" action="diary.php" class="diary-jump">
        <label>Apri un giorno:
            <input type="date" name="day" value="<?= d_h(diary_today()) ?>" max="<?= d_h(diary_today()) ?>">
        </label>
        <button type="submit">Vai</button>
        <a class="quick-btn" href="diary.php?day=<?= d_h(diary_today()) ?>">Oggi</a>
        <a class="quick-btn" href="diary.php?day=<?= d_h((new DateTime('yesterday', new DateTimeZone('Europe/Rome')))->format('Y-m-d')) ?>">Ieri</a>
    </form>
    <p class="muted">Come admin vedi anche le bozze non pubblicate.</p>
    <?php endif; ?>

    <?php if (!$list): ?>
        <p>Nessuna voce<?= $is_admin ? '' : ' pubblicata' ?> nel diario.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="diary-list">
        <thead><tr>
            <th>Giorno</th><th>Eventi</th><th>Tipi principali</th><th>Sintesi</th>
            <?php if ($is_admin): ?><th>Stato</th><?php endif; ?>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $r):
            $d  = $r['day'];
            $dg = json_decode($r['digest_json'], true) ?: [];
            $tps = [];
            foreach (($dg['by_type'] ?? []) as $t) { $tps[] = $t['t'] . ' ' . $t['n']; }
        ?>
            <tr>
                <td><a href="diary.php?day=<?= d_h($d) ?>"><?= d_h(d_day_it($d)) ?></a></td>
                <td><?= number_format((int) $r['event_count']) ?></td>
                <td class="muted"><?= d_h(implode(' · ', array_slice($tps, 0, 4))) ?></td>
                <td><?= ((int) $r['has_narrative']) ? '📝' : '—' ?></td>
                <?php if ($is_admin): ?>
                <td><?= ((int) $r['published']) ? '<span class="badge-pub">pubblicata</span>' : '<span class="badge-draft">bozza</span>' ?></td>
                <?php endif; ?>
                <td><a href="diary.php?day=<?= d_h($d) ?>">Apri →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

<?php elseif ($forbidden): /* ============ 404 voce non pubblica ============ */ ?>

    <h1>Voce non disponibile</h1>
    <p>La voce di diario del <strong><?= d_h(d_day_it($day)) ?></strong> non è ancora
       stata pubblicata.</p>
    <p><a href="diary.php">← Torna al diario</a></p>

<?php elseif (!$row || !$digest): /* ============ errore ============ */ ?>

    <h1>Diario · <?= d_h(d_day_it($day)) ?></h1>
    <p>Impossibile mostrare la sintesi di questo giorno.</p>
    <p><a href="diary.php">← Torna al diario</a></p>

<?php else: /* ================= DETTAGLIO GIORNO ================= */
    $t = $digest['totals'] ?? [];
    $hours = $digest['by_hour'] ?? array_fill(0, 24, 0);
    $hmax = max(1, max($hours ?: [1]));
?>

    <p class="nav-links" style="margin-bottom:6px;"><a href="diary.php">← Tutte le voci</a></p>
    <h1>📓 <?= d_h(d_day_it($day)) ?></h1>
    <p class="muted">
        Finestra: <?= d_h($digest['window_utc'][0] ?? '') ?> → <?= d_h($digest['window_utc'][1] ?? '') ?>.
        Digest generato: <?= d_h(fav_format_it(str_replace('T', ' ', substr((string) ($digest['generated_at'] ?? ''), 0, 19)) . ' UTC')) ?>.
        <?php if ($is_admin): ?>
            Stato: <?= ((int) $row['published']) ? '<strong>pubblicata</strong>' : '<strong>bozza</strong>' ?>.
        <?php endif; ?>
    </p>

    <?php if ($is_admin): $has_narr = !empty($row['narrative_md']); ?>
    <div class="diary-admin">
        <form method="post" action="diary.php">
            <?= csrf_field() ?>
            <input type="hidden" name="day" value="<?= d_h($day) ?>">
            <button type="submit" name="action" value="refresh">↻ Rigenera digest</button>
            <?php if ((int) $row['published']): ?>
                <button type="submit" name="action" value="unpublish" class="btn-warn"
                        onclick="return confirm('Ritirare la voce del <?= d_h($day) ?> dal diario pubblico?')">Ritira dal diario</button>
            <?php else: ?>
                <button type="submit" name="action" value="publish" class="btn-primary"
                        onclick="return confirm('Pubblicare la voce del <?= d_h($day) ?> nel diario pubblico?')">Pubblica nel diario</button>
            <?php endif; ?>
        </form>

        <?php if (ai_enabled()): ?>
        <hr>
        <form method="post" action="diary.php" class="ai-gen"
              onsubmit="var b=this.querySelector('button');b.textContent='Generazione in corso… (può richiedere qualche minuto)';b.disabled=true;">
            <?= csrf_field() ?>
            <input type="hidden" name="day" value="<?= d_h($day) ?>">
            <input type="hidden" name="action" value="narrative">
            <button type="submit">
                <?= $has_narr ? '🧠 Rigenera sintesi IA' : '🧠 Genera sintesi IA' ?>
            </button>
            <span class="muted">Modello locale · la bozza va riletta prima della pubblicazione.</span>
        </form>

        <?php if ($has_narr): ?>
        <form method="post" action="diary.php" class="ai-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="day" value="<?= d_h($day) ?>">
            <label class="muted" for="nmd">Testo della sintesi (Markdown) — modificabile:</label>
            <textarea id="nmd" name="narrative_md" rows="12"><?= d_h($row['narrative_md']) ?></textarea>
            <div>
                <button type="submit" name="action" value="narrative_save" class="btn-primary">Salva modifiche</button>
                <button type="submit" name="action" value="narrative_clear" class="btn-warn"
                        onclick="return confirm('Eliminare la sintesi del <?= d_h($day) ?>?')">Elimina sintesi</button>
                <span class="muted">
                    <?= $row['narrative_model'] ? d_h($row['narrative_model']) . ' · ' : '' ?>
                    generata <?= d_h(fav_format_it($row['narrative_generated_at'])) ?>
                </span>
            </div>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <p class="muted">Assistente IA non configurato (<code>ai_base_url</code> in <code>config.php</code>).</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    $show_narr = !empty($row['narrative_md']) && ((int) $row['published'] === 1 || $is_admin);
    if ($show_narr):
        $pub = (int) $row['published'] === 1;
    ?>
    <div class="diary-narrative">
        <?php if ($is_admin && !$pub): ?><p class="ai-note" style="border:0;margin:0 0 8px;padding:0;"><strong>Anteprima bozza</strong> — non ancora visibile al pubblico.</p><?php endif; ?>
        <?= diary_md_to_html($row['narrative_md']) ?>
        <p class="ai-note">Sintesi redatta con assistenza IA locale<?= $row['narrative_model'] ? ' (' . d_h($row['narrative_model']) . ')' : '' ?> e rivista da un operatore. I dati di riferimento sono il digest qui sotto: verificare sempre sui record citati.</p>
    </div>
    <?php endif; ?>

    <div class="kpi-row">
        <div class="kpi"><div class="n"><?= number_format((int) ($t['events'] ?? 0)) ?></div><div class="l">eventi</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['mil'] ?? 0)) ?></div><div class="l">flag militare</div></div>
        <div class="kpi"><div class="n"><?= $t['avg_confidence'] !== null ? number_format((float) $t['avg_confidence'], 2) : '—' ?></div><div class="l">confidenza media</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['high_confidence'] ?? 0)) ?></div><div class="l">alta confidenza</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['emergencies'] ?? 0)) ?></div><div class="l">squawk emergenza</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['proximity'] ?? 0)) ?></div><div class="l">prossimità</div></div>
    </div>

    <?php if ((int) ($t['events'] ?? 0) === 0): ?>
        <p>Nessun evento registrato in questo giorno.</p>
    <?php else: ?>

    <div class="stats-grid">

        <div class="stats-card">
            <h2>Per tipo</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_type'] ?: [['n' => 1]]));
            foreach ($digest['by_type'] as $r): ?>
                <tr><td><?= d_h($r['t']) ?></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Per sottotipo</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_subtype'] ?: [['n' => 1]]));
            foreach ($digest['by_subtype'] as $r): ?>
                <tr><td><?= d_h($r['s']) ?></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx) ?></td></tr>
            <?php endforeach; ?></table>
        </div>

        <div class="stats-card">
            <h2>Attività per ora (UTC)</h2>
            <table><?php for ($h = 0; $h < 24; $h++): if ($hours[$h] === 0) continue; ?>
                <tr><td><?= sprintf('%02d', $h) ?></td><td><?= number_format($hours[$h]) ?></td><td><?= d_bar($hours[$h], $hmax, 110) ?></td></tr>
            <?php endfor; ?></table>
        </div>

        <?php if ($digest['by_operator']): ?>
        <div class="stats-card">
            <h2>Compagnie / operatori</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_operator']));
            foreach ($digest['by_operator'] as $r): $ol = fa_operator_logo($r['operator']); ?>
                <tr><td><?php if ($ol): ?><img src="<?= d_h($ol) ?>" class="op-logo" alt=""><?php endif; ?><a href="index.php?operator=<?= urlencode($r['operator']) ?>&amp;quick_range=all"><?= d_h($r['operator']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>
        <?php endif; ?>

        <?php if ($digest['by_country']): ?>
        <div class="stats-card">
            <h2>Nazionalità</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_country']));
            foreach ($digest['by_country'] as $r): ?>
                <tr><td><a href="index.php?country=<?= urlencode($r['c']) ?>&amp;quick_range=all"><?= fa_country_flag_html($r['c']) ?> <?= d_h($r['c']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>
        <?php endif; ?>

        <?php if ($digest['repeat_aircraft']): ?>
        <div class="stats-card">
            <h2>Mezzi ricorrenti nella giornata</h2>
            <table>
                <tr><th>ICAO</th><th>Callsign</th><th>Modello</th><th>Naz.</th><th>N°</th><th>Eventi</th></tr>
                <?php foreach ($digest['repeat_aircraft'] as $r): ?>
                <tr>
                    <td><?php if ((int) $r['is_mil'] === 1): ?><span class="mil-badge">⚑</span> <?php endif; ?><a href="index.php?hex=<?= urlencode($r['hex']) ?>&amp;quick_range=all"><?= d_h($r['hex']) ?></a></td>
                    <td><?= d_h($r['callsign']) ?></td>
                    <td><?= d_h($r['model_t']) ?></td>
                    <td><?= fa_country_flag_html($r['country'] ?? '') ?></td>
                    <td><?= (int) $r['n'] ?></td>
                    <td class="muted"><?= d_ids($r['ids']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['high_confidence']): ?>
        <div class="stats-card">
            <h2>Alta confidenza (≥ <?= number_format(DIARY_HI_CONF, 2) ?>)</h2>
            <table>
                <tr><th>#</th><th>Tipo</th><th>ICAO</th><th>Callsign</th><th>Op.</th><th>Naz.</th><th>Conf</th><th>Giri</th><th>Durata</th></tr>
                <?php foreach ($digest['high_confidence'] as $r): ?>
                <tr>
                    <td><a href="view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= d_h($r['event_type'] . ($r['subtype'] ? ' · ' . $r['subtype'] : '')) ?></td>
                    <td><?php if ((int) $r['is_mil'] === 1): ?><span class="mil-badge">⚑</span> <?php endif; ?><?= d_h($r['hex']) ?></td>
                    <td><?= d_h($r['callsign']) ?></td>
                    <td><?= d_h($r['operator']) ?></td>
                    <td><?= fa_country_flag_html($r['country'] ?? '') ?></td>
                    <td><?= number_format((float) $r['confidence'], 2) ?></td>
                    <td><?= $r['laps'] !== null ? (int) $r['laps'] : '—' ?></td>
                    <td><?= d_dur($r['duration_s'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['area_clusters']): ?>
        <div class="stats-card">
            <h2>Zone con più pattern</h2>
            <table>
                <tr><th>Area (≈)</th><th>N°</th><th>Tipi</th><th>Eventi</th></tr>
                <?php foreach ($digest['area_clusters'] as $r): ?>
                <tr>
                    <td><?= number_format((float) $r['clat'], 1) ?>, <?= number_format((float) $r['clon'], 1) ?></td>
                    <td><?= (int) $r['n'] ?></td>
                    <td class="muted"><?= d_h($r['kinds']) ?></td>
                    <td class="muted"><?= d_ids($r['ids']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['longest']): ?>
        <div class="stats-card">
            <h2>Episodi più lunghi</h2>
            <table>
                <tr><th>#</th><th>ICAO</th><th>Callsign</th><th>Sottotipo</th><th>Durata</th><th>Giri</th></tr>
                <?php foreach ($digest['longest'] as $r): ?>
                <tr>
                    <td><a href="view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= d_h($r['hex']) ?></td><td><?= d_h($r['callsign']) ?></td>
                    <td><?= d_h($r['subtype']) ?></td>
                    <td><?= d_dur($r['duration_s'] ?? 0) ?></td>
                    <td><?= $r['laps'] !== null ? (int) $r['laps'] : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['most_laps']): ?>
        <div class="stats-card">
            <h2>Più giri</h2>
            <table>
                <tr><th>#</th><th>ICAO</th><th>Callsign</th><th>Sottotipo</th><th>Giri</th><th>Durata</th></tr>
                <?php foreach ($digest['most_laps'] as $r): ?>
                <tr>
                    <td><a href="view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= d_h($r['hex']) ?></td><td><?= d_h($r['callsign']) ?></td>
                    <td><?= d_h($r['subtype']) ?></td>
                    <td><?= (int) $r['laps'] ?></td>
                    <td><?= d_dur($r['duration_s'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['emergencies']): ?>
        <div class="stats-card">
            <h2>⚠ Squawk di emergenza</h2>
            <table>
                <tr><th>#</th><th>Ora</th><th>ICAO</th><th>Callsign</th><th>Squawk</th></tr>
                <?php foreach ($digest['emergencies'] as $r): ?>
                <tr>
                    <td><a href="view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= d_h(substr((string) $r['first_seen_utc'], 11, 5)) ?></td>
                    <td><?= d_h($r['hex']) ?></td><td><?= d_h($r['callsign']) ?></td>
                    <td><strong><?= d_h($r['squawk']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['proximity']): ?>
        <div class="stats-card">
            <h2>Eventi di prossimità</h2>
            <table>
                <tr><th>#</th><th>Ora</th><th>ICAO</th><th>Callsign</th><th>Sottotipo</th></tr>
                <?php foreach ($digest['proximity'] as $r): ?>
                <tr>
                    <td><a href="view.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= d_h(substr((string) $r['first_seen_utc'], 11, 5)) ?></td>
                    <td><?= d_h($r['hex']) ?></td><td><?= d_h($r['callsign']) ?></td>
                    <td><?= d_h($r['subtype']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php
        $has_novelty = $digest['new_operators'] || $digest['new_countries'] || $digest['recurring_callsigns'];
        if ($has_novelty): ?>
        <div class="stats-card">
            <h2>Novità e ricorrenze (vs 14 giorni)</h2>
            <table>
                <?php if ($digest['new_operators']): ?>
                <tr><td>Operatori nuovi</td><td class="muted"><?= d_h(implode(', ', $digest['new_operators'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($digest['new_countries']): ?>
                <tr><td>Nazioni nuove</td><td class="muted"><?= d_h(implode(', ', $digest['new_countries'])) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($digest['recurring_callsigns'] as $r): ?>
                <tr><td><a href="index.php?callsign=<?= urlencode($r['callsign']) ?>&amp;quick_range=all"><?= d_h($r['callsign']) ?></a></td><td class="muted"><?= (int) $r['days'] ?> giorni · <?= (int) $r['n'] ?> eventi</td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; /* eventi > 0 */ ?>

<?php endif; /* dettaglio */ ?>

</div>
</body>
</html>
