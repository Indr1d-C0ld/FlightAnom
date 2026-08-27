<?php
// edit_favorite.php - Modifica la nota annotabile di un aeromobile preferito.
// GET ?hex=...  -> form con la nota corrente
// POST hex, note, csrf_token -> upsert e redirect a favorites.php
ini_set('display_errors', '0');
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/favorites_lib.php';

$hex = strtolower(trim($_GET['hex'] ?? $_POST['hex'] ?? ''));
if (!fav_valid_hex($hex)) {
    http_response_code(400);
    echo 'HEX non valido.';
    exit;
}

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
        // upsert: la nota si puo' salvare anche se l'hex non era ancora tra i preferiti
        $pdo->prepare("INSERT INTO favorites (hex, note) VALUES (?, ?)
                       ON CONFLICT(hex) DO UPDATE SET note = excluded.note")
            ->execute([$hex, $note]);
    } catch (Throwable $e) {
        error_log('edit_favorite.php: ' . $e->getMessage());
        http_response_code(500);
        echo 'Errore nel salvataggio.';
        exit;
    }
    header('Location: favorites.php');
    exit;
}

// GET: carica la nota esistente
$note = '';
try {
    $pdo = fav_open(false);
    if (fav_table_exists($pdo)) {
        $st = $pdo->prepare("SELECT note FROM favorites WHERE hex = ?");
        $st->execute([$hex]);
        $row = $st->fetch();
        if ($row) {
            $note = (string)($row['note'] ?? '');
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
    <title>Nota preferito — <?= htmlspecialchars($hex) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <p class="nav-links"><a href="index.php">← Eventi</a> <a href="favorites.php">⭐ Preferiti</a></p>
    <h1>Nota preferito — <?= htmlspecialchars($hex) ?></h1>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="hex" value="<?= htmlspecialchars($hex) ?>">
        <p>
            <textarea name="note" rows="6" maxlength="2000" style="width:100%;max-width:640px;"
                      placeholder="Annotazione libera su questo aeromobile…"><?= htmlspecialchars($note) ?></textarea>
        </p>
        <button type="submit">Salva</button>
        <a href="favorites.php">Annulla</a>
    </form>
</div>
</body>
</html>
