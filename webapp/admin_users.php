<?php
// admin_users.php - Gestione utenti del portale (solo admin).
ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
auth_bootstrap();
require_role('admin');

$db  = get_auth_db();
$me  = current_user();
$msg = '';
$err = '';
const ROLES = ['collaboratore', 'admin'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $err = 'Sessione scaduta, ricarica la pagina.';
    } else {
        $act = $_POST['action'] ?? '';
        try {
            if ($act === 'create') {
                $u  = trim($_POST['username'] ?? '');
                $p  = (string) ($_POST['password'] ?? '');
                $p2 = (string) ($_POST['password2'] ?? '');
                $r  = $_POST['role'] ?? '';
                if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $u)) {
                    $err = 'Username non valido (3–32 caratteri: lettere, cifre, . _ -).';
                } elseif (!in_array($r, ROLES, true)) {
                    $err = 'Ruolo non valido.';
                } elseif (strlen($p) < 8) {
                    $err = 'Password troppo corta (minimo 8).';
                } elseif ($p !== $p2) {
                    $err = 'Le password non coincidono.';
                } else {
                    $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)")
                       ->execute([$u, password_hash($p, PASSWORD_DEFAULT), $r]);
                    $msg = "Utente “{$u}” creato ({$r}).";
                }
            } elseif ($act === 'setrole') {
                $id = (int) ($_POST['id'] ?? 0);
                $r  = $_POST['role'] ?? '';
                if (!in_array($r, ROLES, true)) {
                    $err = 'Ruolo non valido.';
                } elseif ($id === (int) $me['id']) {
                    $err = 'Non puoi modificare il tuo stesso ruolo.';
                } elseif ($r !== 'admin' && user_is_admin($db, $id) && admin_user_count($db) <= 1) {
                    $err = 'Deve restare almeno un amministratore.';
                } else {
                    $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$r, $id]);
                    $msg = 'Ruolo aggiornato.';
                }
            } elseif ($act === 'resetpw') {
                $id = (int) ($_POST['id'] ?? 0);
                $p  = (string) ($_POST['password'] ?? '');
                if (strlen($p) < 8) {
                    $err = 'Password troppo corta (minimo 8).';
                } else {
                    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
                       ->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
                    $msg = 'Password reimpostata.';
                }
            } elseif ($act === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id === (int) $me['id']) {
                    $err = 'Non puoi eliminare te stesso.';
                } elseif (user_is_admin($db, $id) && admin_user_count($db) <= 1) {
                    $err = 'Deve restare almeno un amministratore.';
                } else {
                    $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
                    $msg = 'Utente eliminato.';
                }
            } elseif ($act === 'changeown') {
                $cur = (string) ($_POST['current'] ?? '');
                $p   = (string) ($_POST['new'] ?? '');
                $p2  = (string) ($_POST['new2'] ?? '');
                $s = $db->prepare("SELECT password_hash FROM users WHERE id=?");
                $s->execute([(int) $me['id']]);
                $row = $s->fetch();
                if (!$row || !password_verify($cur, $row['password_hash'])) {
                    $err = 'Password attuale errata.';
                } elseif (strlen($p) < 8) {
                    $err = 'Nuova password troppo corta (minimo 8).';
                } elseif ($p !== $p2) {
                    $err = 'Le nuove password non coincidono.';
                } else {
                    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
                       ->execute([password_hash($p, PASSWORD_DEFAULT), (int) $me['id']]);
                    $msg = 'La tua password è stata aggiornata.';
                }
            }
        } catch (PDOException $e) {
            error_log('admin_users.php: ' . $e->getMessage());
            $err = str_contains($e->getMessage(), 'UNIQUE')
                 ? 'Esiste già un utente con questo username.'
                 : 'Operazione non riuscita.';
        } catch (Throwable $e) {
            error_log('admin_users.php: ' . $e->getMessage());
            $err = 'Operazione non riuscita.';
        }
    }
}

try {
    $users = $db->query(
        "SELECT id, username, role, created_at, last_login_at, last_login_ip
         FROM users ORDER BY (role='admin') DESC, username"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('admin_users.php: ' . $e->getMessage());
    $users = [];
    $err = $err ?: 'Impossibile leggere gli utenti.';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestione utenti — Flight Anomaly Monitor</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .admin-forms { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin-bottom: 22px; }
        .admin-card { border: 1px solid #e0e0e0; border-radius: 8px; padding: 14px; }
        .admin-card h2 { margin: 0 0 10px; font-size: 1.05rem; }
        .admin-card label { display: block; margin: 8px 0 2px; font-size: 0.9rem; color: #555; }
        .admin-card input, .admin-card select { width: 100%; box-sizing: border-box; padding: 6px 8px; }
        .msg { padding: 8px 12px; border-radius: 6px; margin: 10px 0; }
        .msg.ok { background: #d4edda; color: #155724; }
        .msg.err { background: #f8d7da; color: #721c24; }
        td form { display: inline; }
        td.uactions form + form { margin-left: 6px; }
        td.uactions input[type="password"] { width: 8rem; padding: 3px 5px; }
    </style>
</head>
<body>
<div class="container">
    <p class="nav-links"><a href="index.php">← Eventi</a> <a href="stats.php">📊 Statistiche</a> <span style="float:right;"><?= auth_nav_html() ?></span></p>
    <h1>👤 Gestione utenti</h1>
    <p class="muted">Ruoli: <strong>collaboratore</strong> = può gestire i preferiti; <strong>admin</strong> = + gestione utenti.
        Il portale resta pubblico in lettura per tutti.</p>

    <?php if ($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="admin-forms">
        <div class="admin-card">
            <h2>Nuovo utente</h2>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="action" value="create">
                <label>Username</label>
                <input type="text" name="username" required pattern="[A-Za-z0-9_.\-]{3,32}">
                <label>Ruolo</label>
                <select name="role"><option value="collaboratore">collaboratore</option><option value="admin">admin</option></select>
                <label>Password (min 8)</label>
                <input type="password" name="password" required minlength="8">
                <label>Ripeti password</label>
                <input type="password" name="password2" required minlength="8">
                <p><button type="submit">Crea utente</button></p>
            </form>
        </div>
        <div class="admin-card">
            <h2>Cambia la mia password</h2>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="action" value="changeown">
                <label>Password attuale</label><input type="password" name="current" required>
                <label>Nuova password (min 8)</label><input type="password" name="new" required minlength="8">
                <label>Ripeti nuova password</label><input type="password" name="new2" required minlength="8">
                <p><button type="submit">Aggiorna</button></p>
            </form>
        </div>
    </div>

    <div class="table-scroll">
    <table>
        <thead><tr>
            <th>Username</th><th>Ruolo</th><th>Creato</th><th>Ultimo accesso</th><th>IP</th><th>Azioni</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u): $uid = (int) $u['id']; $self = $uid === (int) $me['id']; ?>
            <tr>
                <td><?= htmlspecialchars($u['username']) ?><?= $self ? ' <span class="muted">(tu)</span>' : '' ?></td>
                <td>
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="action" value="setrole"><input type="hidden" name="id" value="<?= $uid ?>">
                        <select name="role" onchange="this.form.submit()" <?= $self ? 'disabled' : '' ?>>
                            <?php foreach (ROLES as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td class="muted"><?= htmlspecialchars($u['created_at'] ?? '') ?></td>
                <td class="muted"><?= htmlspecialchars($u['last_login_at'] ?? '—') ?></td>
                <td class="muted"><?= htmlspecialchars($u['last_login_ip'] ?? '—') ?></td>
                <td class="uactions">
                    <form method="post" onsubmit="return confirm('Reimpostare la password di <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="resetpw"><input type="hidden" name="id" value="<?= $uid ?>">
                        <input type="password" name="password" placeholder="nuova pw" minlength="8" required>
                        <button type="submit">Reset pw</button>
                    </form>
                    <?php if (!$self): ?>
                    <form method="post" onsubmit="return confirm('Eliminare l\'utente <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $uid ?>">
                        <button type="submit">Elimina</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="6" class="muted">Nessun utente.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>
