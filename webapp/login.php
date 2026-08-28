<?php
// login.php - Accesso per collaboratori/admin.
ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
auth_bootstrap();

$next = $_GET['next'] ?? $_POST['next'] ?? 'index.php';
// Consenti solo URL relativi allo stesso portale.
if (!preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $next) || str_contains($next, '//')) {
    $next = 'index.php';
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Sessione scaduta, riprova.';
    } else {
        $res = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($res['ok']) {
            header('Location: ' . $next);
            exit;
        }
        $error = $res['error'];
    }
}
if (is_logged_in() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . $next);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accedi — Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container" style="max-width:420px;">
    <p class="nav-links"><a href="index.php">← Eventi</a></p>
    <h1>Accedi</h1>
    <p class="muted">Il portale è pubblico in lettura. L'accesso serve solo per gestire i preferiti.</p>
    <?php if ($error): ?><p style="color:#c0392b;"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <p><input type="text" name="username" placeholder="Utente" required autofocus style="width:100%;box-sizing:border-box;padding:8px;"></p>
        <p><input type="password" name="password" placeholder="Password" required style="width:100%;box-sizing:border-box;padding:8px;"></p>
        <button type="submit" style="padding:8px 16px;">Entra</button>
    </form>
</div>
</body>
</html>
