<?php
// diary_lib.php - "Diario di bordo": sintesi giornaliera degli eventi.
//
// Ogni giorno (fuso Europe/Rome) ha una riga in diary_days:
//   - digest_json  : aggregati DETERMINISTICI (solo SQL), sempre rigenerabili
//   - narrative_md : sintesi discorsiva OPZIONALE (assistente IA locale, fase 2)
//   - published    : 0 = bozza (solo admin) / 1 = visibile nel diario pubblico
//
// La pagina e' diary.php (pubblica in lettura). La rigenerazione del digest,
// la generazione della narrativa e la pubblicazione sono azioni da admin.

require_once __DIR__ . '/favorites_lib.php';

const DIARY_HI_CONF   = 0.70;   // soglia "alta confidenza" per gli highlight
const DIARY_LIST_MAX  = 120;    // righe max nella lista del diario

/** true se $d e' una data valida "YYYY-MM-DD". */
function diary_valid_day(?string $d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $t = DateTime::createFromFormat('!Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

/** "Oggi" nel fuso Europe/Rome (YYYY-MM-DD). */
function diary_today(): string
{
    return (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
}

/** [inizio, fine) del giorno locale $day come stringhe "Y-m-d H:i:s UTC". */
function diary_day_bounds_utc(string $day): array
{
    $rome = new DateTimeZone('Europe/Rome');
    $utc  = new DateTimeZone('UTC');
    $a = (new DateTime($day . ' 00:00:00', $rome))->setTimezone($utc);
    $b = (new DateTime($day . ' 00:00:00', $rome))->modify('+1 day')->setTimezone($utc);
    return [$a->format('Y-m-d H:i:s') . ' UTC', $b->format('Y-m-d H:i:s') . ' UTC'];
}

function diary_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS diary_days (
        day                    TEXT PRIMARY KEY,
        digest_json            TEXT NOT NULL,
        digest_generated_at    TEXT NOT NULL,
        event_count            INTEGER NOT NULL DEFAULT 0,
        narrative_md           TEXT,
        narrative_model        TEXT,
        narrative_generated_at TEXT,
        published              INTEGER NOT NULL DEFAULT 0,
        published_at           TEXT,
        updated_at             TEXT
    )");
}

/** Riga di diary_days per $day, o null. */
function diary_get(PDO $pdo, string $day): ?array
{
    diary_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM diary_days WHERE day = ?");
    $s->execute([$day]);
    $r = $s->fetch();
    return $r ?: null;
}

/**
 * Aggregati deterministici del giorno. Nessuna dipendenza esterna: sola lettura
 * di events. Gli id evento sono inclusi cosi' la pagina (e la narrativa IA) puo'
 * linkare a view.php.
 */
function diary_build_digest(PDO $pdo, string $day): array
{
    [$a, $b] = diary_day_bounds_utc($day);
    $W   = "first_seen_utc >= ? AND first_seen_utc < ?";
    $arg = [$a, $b];

    $rows = function (string $sql, array $p) use ($pdo): array {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    };
    $col = function (string $sql, array $p) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn();
    };

    $total = (int) $col("SELECT COUNT(*) FROM events WHERE $W", $arg);
    $n_mil = (int) $col("SELECT COUNT(*) FROM events WHERE $W AND is_mil = 1", $arg);
    $avg   = $col("SELECT ROUND(AVG(confidence), 2) FROM events WHERE $W AND confidence IS NOT NULL", $arg);

    $by_type    = $rows("SELECT event_type t, COUNT(*) n FROM events WHERE $W GROUP BY t ORDER BY n DESC", $arg);
    $by_subtype = $rows("SELECT COALESCE(NULLIF(subtype,''),'—') s, COUNT(*) n FROM events WHERE $W GROUP BY s ORDER BY n DESC", $arg);
    $by_oper    = $rows("SELECT operator, COUNT(*) n FROM events WHERE $W AND operator <> '' GROUP BY operator ORDER BY n DESC LIMIT 12", $arg);
    $by_country = $rows("SELECT COALESCE(NULLIF(country,''),'ZZ') c, COUNT(*) n FROM events WHERE $W GROUP BY c ORDER BY n DESC LIMIT 12", $arg);

    $by_hour_raw = $rows("SELECT CAST(substr(first_seen_utc,12,2) AS INT) h, COUNT(*) n FROM events WHERE $W GROUP BY h", $arg);
    $by_hour = array_fill(0, 24, 0);
    foreach ($by_hour_raw as $r) {
        $h = (int) $r['h'];
        if ($h >= 0 && $h <= 23) {
            $by_hour[$h] = (int) $r['n'];
        }
    }

    $repeat_ac = $rows(
        "SELECT hex, MAX(callsign) callsign, MAX(model_t) model_t, MAX(operator) operator,
                MAX(country) country, MAX(is_mil) is_mil, COUNT(*) n,
                MIN(first_seen_utc) first_utc, MAX(COALESCE(last_seen_utc,first_seen_utc)) last_utc,
                GROUP_CONCAT(id) ids
         FROM events WHERE $W
         GROUP BY hex HAVING n >= 2
         ORDER BY n DESC, hex LIMIT 20",
        $arg
    );

    $high_conf = $rows(
        "SELECT id, first_seen_utc, event_type, subtype, hex, callsign, operator, country,
                ROUND(confidence,2) confidence, laps, duration_s, is_mil
         FROM events WHERE $W AND confidence >= " . DIARY_HI_CONF . "
         ORDER BY confidence DESC, duration_s DESC LIMIT 20",
        $arg
    );

    $emergencies = $rows(
        "SELECT id, first_seen_utc, hex, callsign, squawk, note
         FROM events WHERE $W AND squawk IN ('7500','7600','7700')
         ORDER BY first_seen_utc",
        $arg
    );

    $proximity = $rows(
        "SELECT id, first_seen_utc, hex, callsign, subtype, note
         FROM events WHERE $W AND event_type = 'PROX'
         ORDER BY first_seen_utc LIMIT 20",
        $arg
    );

    $longest = $rows(
        "SELECT id, hex, callsign, subtype, duration_s, laps, ROUND(confidence,2) confidence
         FROM events WHERE $W AND duration_s > 0
         ORDER BY duration_s DESC LIMIT 5",
        $arg
    );

    $most_laps = $rows(
        "SELECT id, hex, callsign, subtype, laps, duration_s, ROUND(confidence,2) confidence
         FROM events WHERE $W AND laps IS NOT NULL AND laps > 0
         ORDER BY laps DESC, duration_s DESC LIMIT 5",
        $arg
    );

    // Zone con piu' pattern: celle di ~0.5 gradi.
    $clusters = $rows(
        "SELECT ROUND(lat*2)/2.0 clat, ROUND(lon*2)/2.0 clon, COUNT(*) n,
                GROUP_CONCAT(DISTINCT COALESCE(NULLIF(subtype,''), event_type)) kinds,
                GROUP_CONCAT(id) ids
         FROM events
         WHERE $W AND event_type = 'PATTERN' AND lat IS NOT NULL AND lon IS NOT NULL
         GROUP BY clat, clon HAVING n >= 2
         ORDER BY n DESC LIMIT 10",
        $arg
    );

    // Baseline dei 14 giorni precedenti: attori "nuovi".
    $pa   = (new DateTime(rtrim($a, ' UTC'), new DateTimeZone('UTC')))
                ->modify('-14 days')->format('Y-m-d H:i:s') . ' UTC';
    $prev = "first_seen_utc >= ? AND first_seen_utc < ?";

    $new_oper = $rows(
        "SELECT DISTINCT operator FROM events WHERE $W AND operator <> ''
           AND operator NOT IN (SELECT DISTINCT operator FROM events WHERE $prev AND operator <> '')
         ORDER BY operator",
        [$a, $b, $pa, $a]
    );
    $new_country = $rows(
        "SELECT DISTINCT country FROM events WHERE $W AND country NOT IN ('', 'ZZ')
           AND country NOT IN (SELECT DISTINCT country FROM events WHERE $prev AND country NOT IN ('', 'ZZ'))
         ORDER BY country",
        [$a, $b, $pa, $a]
    );

    // Callsign ricorrenti: visti oggi e in >= 3 giorni distinti nelle 2 settimane.
    $recurring = $rows(
        "SELECT callsign, COUNT(DISTINCT substr(first_seen_utc,1,10)) days, COUNT(*) n
         FROM events
         WHERE first_seen_utc >= ? AND first_seen_utc < ? AND callsign <> ''
           AND callsign IN (SELECT callsign FROM events WHERE $W AND callsign <> '')
         GROUP BY callsign HAVING days >= 3
         ORDER BY days DESC, n DESC LIMIT 12",
        [$pa, $b, $a, $b]
    );

    return [
        'day'          => $day,
        'window_utc'   => [$a, $b],
        'generated_at' => gmdate('c'),
        'totals'       => [
            'events'         => $total,
            'mil'            => $n_mil,
            'civ'            => $total - $n_mil,
            'avg_confidence' => ($avg === false || $avg === null) ? null : (float) $avg,
            'high_confidence' => count($high_conf),
            'emergencies'    => count($emergencies),
            'proximity'      => count($proximity),
        ],
        'by_type'             => $by_type,
        'by_subtype'          => $by_subtype,
        'by_hour'             => $by_hour,
        'by_operator'         => $by_oper,
        'by_country'          => $by_country,
        'repeat_aircraft'     => $repeat_ac,
        'high_confidence'     => $high_conf,
        'emergencies'         => $emergencies,
        'proximity'           => $proximity,
        'longest'             => $longest,
        'most_laps'           => $most_laps,
        'area_clusters'       => $clusters,
        'new_operators'       => array_values(array_filter(array_column($new_oper, 'operator'))),
        'new_countries'       => array_values(array_filter(array_column($new_country, 'country'))),
        'recurring_callsigns' => $recurring,
    ];
}

/** Inserisce/aggiorna il digest del giorno (non tocca narrativa / published). */
function diary_store_digest(PDO $pdo, string $day, array $digest): void
{
    diary_ensure_schema($pdo);
    $now  = gmdate('Y-m-d H:i:s') . ' UTC';
    $json = json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $s = $pdo->prepare(
        "INSERT INTO diary_days (day, digest_json, digest_generated_at, event_count, updated_at)
         VALUES (:d, :j, :g, :n, :u)
         ON CONFLICT(day) DO UPDATE SET
            digest_json = :j, digest_generated_at = :g, event_count = :n, updated_at = :u"
    );
    $s->execute([
        ':d' => $day,
        ':j' => $json,
        ':g' => $now,
        ':n' => (int) ($digest['totals']['events'] ?? 0),
        ':u' => $now,
    ]);
}

/**
 * Restituisce la riga del giorno, (ri)calcolando il digest se manca, se e' il
 * giorno in corso, o se $force. Da usare SOLO in contesti admin: il pubblico
 * legge esclusivamente le voci gia' pubblicate (diary_get).
 */
function diary_ensure_day(PDO $pdo, string $day, bool $force = false): array
{
    $row   = diary_get($pdo, $day);
    $stale = !$row || $force || $day >= diary_today();
    if ($stale) {
        diary_store_digest($pdo, $day, diary_build_digest($pdo, $day));
        $row = diary_get($pdo, $day);
    }
    return $row;
}

/** Righe recenti per la lista del diario. */
function diary_recent(PDO $pdo, bool $only_published, int $limit = DIARY_LIST_MAX): array
{
    diary_ensure_schema($pdo);
    $sql = "SELECT day, event_count, published, published_at, digest_json,
                   (narrative_md IS NOT NULL AND narrative_md <> '') AS has_narrative
            FROM diary_days";
    if ($only_published) {
        $sql .= " WHERE published = 1";
    }
    $sql .= " ORDER BY day DESC LIMIT " . (int) $limit;
    return $pdo->query($sql)->fetchAll();
}

/** Mini-renderer Markdown -> HTML per la narrativa (sottoinsieme sicuro). */
function diary_md_to_html(string $s): string
{
    $s = str_replace("\r\n", "\n", trim($s));
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $lines = explode("\n", $s);
    $out = '';
    $in_ul = false;
    $flush_ul = function () use (&$out, &$in_ul) {
        if ($in_ul) {
            $out .= "</ul>\n";
            $in_ul = false;
        }
    };
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '') {
            $flush_ul();
            continue;
        }
        if (preg_match('/^#{2,4}\s+(.*)$/', $t, $m)) {
            $flush_ul();
            $out .= '<h3>' . $m[1] . "</h3>\n";
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if (!$in_ul) {
                $out .= "<ul>\n";
                $in_ul = true;
            }
            $out .= '<li>' . $m[1] . "</li>\n";
            continue;
        }
        $flush_ul();
        $out .= '<p>' . $t . "</p>\n";
    }
    $flush_ul();

    $out = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $out);
    $out = preg_replace('/(^|[\s(])#(\d{1,7})\b/', '$1<a href="view.php?id=$2">#$2</a>', $out);
    return $out;
}
