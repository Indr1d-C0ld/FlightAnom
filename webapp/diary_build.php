#!/usr/bin/env php
<?php
// diary_build.php - calcola (e, con --publish, pubblica) il digest del Diario
// per uno o piu' giorni. Pensato per il cron: una riga al giorno costruisce e
// pubblica la voce di IERI. Non tocca la sintesi IA (resta manuale).
//
//   php diary_build.php --publish                 # digest di IERI + pubblicazione
//   php diary_build.php --day=2026-09-01          # un giorno preciso (senza pubblicare)
//   php diary_build.php --publish --backfill=14   # ultimi 14 giorni (fino a ieri)
//   php diary_build.php --publish --quiet         # solo errori sullo stderr
//
// --publish marca la voce come pubblica SOLO se non lo e' mai stata prima:
// un eventuale "Ritira dal diario" fatto a mano dall'admin resta rispettato.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando.\n");
}

require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/diary_lib.php';

$opts = getopt('', ['publish', 'quiet', 'day:', 'backfill:', 'min-events:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Uso: php diary_build.php [--publish] [--day=YYYY-MM-DD] [--backfill=N]"
                 . " [--min-events=N] [--quiet]\n");
    exit(0);
}

$do_publish = isset($opts['publish']);
$quiet      = isset($opts['quiet']);
// Un giorno senza eventi non e' una voce di diario: e' un buco nei dati (monitor
// fermo, database appena azzerato, backfill oltre l'inizio dello storico).
// Il digest viene comunque calcolato e salvato, ma non pubblicato.
$min_events = isset($opts['min-events']) ? max(0, (int) $opts['min-events']) : 1;
$log = function (string $s) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $s . "\n");
    }
};

// --- Giorni da elaborare -----------------------------------------------------
$rome = new DateTimeZone('Europe/Rome');
$yesterday = (new DateTime('yesterday', $rome))->format('Y-m-d');

$days = [];
if (isset($opts['day'])) {
    if (!diary_valid_day($opts['day'])) {
        fwrite(STDERR, "Data non valida: {$opts['day']} (attesa YYYY-MM-DD).\n");
        exit(2);
    }
    $days = [$opts['day']];
} elseif (isset($opts['backfill'])) {
    $n = (int) $opts['backfill'];
    if ($n < 1 || $n > 366) {
        fwrite(STDERR, "--backfill fuori range (1..366).\n");
        exit(2);
    }
    $end = new DateTime($yesterday, $rome);
    for ($i = $n - 1; $i >= 0; $i--) {
        $days[] = (clone $end)->modify("-{$i} day")->format('Y-m-d');
    }
} else {
    $days = [$yesterday];
}

// --- Elaborazione ----------------------------------------------------------
try {
    $pdo = fav_open(true);
    diary_ensure_schema($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'diary_build: impossibile aprire il database: ' . $e->getMessage() . "\n");
    exit(1);
}

$errors = 0;
foreach ($days as $day) {
    try {
        $digest = diary_build_digest($pdo, $day);
        diary_store_digest($pdo, $day, $digest);
        $n = (int) ($digest['totals']['events'] ?? 0);

        $note = 'non pubblicato (usa --publish)';
        if ($do_publish) {
            $row = diary_get($pdo, $day);
            if ((int) $row['published'] === 1) {
                $note = 'gia\' pubblicato';
            } elseif (!empty($row['published_at'])) {
                $note = 'ritirato a mano: lasciato non pubblico';
            } elseif ($n < $min_events) {
                $note = sprintf('%d eventi (< %d): non pubblicato', $n, $min_events);
            } else {
                $now = gmdate('Y-m-d H:i:s') . ' UTC';
                $pdo->prepare("UPDATE diary_days SET published = 1, published_at = ?, updated_at = ? WHERE day = ?")
                    ->execute([$now, $now, $day]);
                $note = 'pubblicato';
            }
        }
        $log(sprintf('%s: %d eventi, digest aggiornato — %s', $day, $n, $note));
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "diary_build: errore su {$day}: " . $e->getMessage() . "\n");
    }
}

exit($errors ? 1 : 0);
