<?php
// edit_favorite.php - Modifica la nota annotabile di un EVENTO preferito.
// GET ?id=...           -> form con la nota corrente + riepilogo evento
// POST id, note, csrf_token -> upsert e redirect a favorites.php
ini_set('display_errors', '0');
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/favorites_lib.php';

require_role('collaboratore');   // login + ruolo (redirect al login se anonimo)

$id = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
if (!fav_valid_id($id)) {
    http_response_code(400);
    echo 'ID evento non valido.';
    exit;
}
$id = (int) $id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        echo 'CSRF token non valido.';
        exit;
    }
    $note = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 2000) {
        $note = mb_substr($note, 0, 2000);
    }
    try {
        $pdo = fav_open(true);
        fav_ensure_schema($pdo);
        // upsert: la nota si puo' salvare anche se l'evento non era ancora tra i preferiti
        $pdo->prepare("INSERT INTO favorites (event_id, note) VALUES (?, ?)
                       ON CONFLICT(event_id) DO UPDATE SET note = excluded.note")
            ->execute([$id, $note]);
    } catch (Throwable $e) {
        error_log('edit_favorite.php: ' . $e->getMessage());
        http_response_code(500);
        echo 'Errore nel salvataggio.';
        exit;
    }
    header('Location: favorites.php');
    exit;
}

// GET: riepilogo evento + nota esistente
$note = '';
$event = null;
try {
    $pdo = fav_open(false);
    $st = $pdo->prepare("SELECT id, first_seen_utc, hex, callsign, event_type, note
                         FROM events WHERE id = ?");
    $st->execute([$id]);
    $event = $st->fetch() ?: null;
    if (fav_table_exists($pdo)) {
        $st = $pdo->prepare("SELECT note FROM favorites WHERE event_id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $note = (string) ($row['note'] ?? '');
        }
    }
} catch (Throwable $e) {
    error_log('edit_favorite.php: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota evento #<?= $id ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <p class="nav-links"><a href="index.php">← Eventi</a> <a href="favorites.php">⭐ Preferiti</a></p>
    <h1>Nota per l'evento #<?= $id ?></h1>
    <?php if ($event): ?>
        <p>
            <strong><?= htmlspecialchars(fav_format_it($event['first_seen_utc'])) ?></strong> ·
            <?= htmlspecialchars($event['event_type']) ?> ·
            ICAO <?= htmlspecialchars($event['hex']) ?>
            <?php if (!empty($event['callsign'])): ?> · <?= htmlspecialchars($event['callsign']) ?><?php endif; ?>
            · <a href="view.php?id=<?= $id ?>">traccia 🗺️</a>
        </p>
        <?php if (!empty($event['note'])): ?>
            <p style="color:#6c757d;">Nota dell'evento: <?= htmlspecialchars($event['note']) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p style="color:#c0392b;">Attenzione: nessun evento con id <?= $id ?> nel database.</p>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <p>
            <textarea name="note" rows="6" maxlength="2000" style="width:100%;max-width:640px;"
                      placeholder="Annotazione libera su questo evento…"><?= htmlspecialchars($note) ?></textarea>
        </p>
        <button type="submit">Salva</button>
        <a href="favorites.php">Annulla</a>
    </form>
</div>
</body>
</html>
