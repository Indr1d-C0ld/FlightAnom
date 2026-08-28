#!/usr/bin/env php
<?php
// auth_useradd.php - crea o aggiorna un utente del portale.
//   php auth_useradd.php <username> <admin|collaboratore>
// La password viene chiesta al prompt (non passa dagli argomenti/history).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando.\n");
}
require_once __DIR__ . '/auth.php';

$username = trim($argv[1] ?? '');
$role = trim($argv[2] ?? '');
if ($username === '' || !in_array($role, ['admin', 'collaboratore'], true)) {
    fwrite(STDERR, "Uso: php auth_useradd.php <username> <admin|collaboratore>\n");
    exit(2);
}

fwrite(STDOUT, "Password per '$username': ");
system('stty -echo 2>/dev/null');
$p1 = trim(fgets(STDIN));
fwrite(STDOUT, "\nRipeti la password: ");
$p2 = trim(fgets(STDIN));
system('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");

if ($p1 === '' || $p1 !== $p2) {
    fwrite(STDERR, "Le password non coincidono (o vuote).\n");
    exit(1);
}
if (strlen($p1) < 8) {
    fwrite(STDERR, "Password troppo corta (min 8).\n");
    exit(1);
}

$db = get_auth_db();
$hash = password_hash($p1, PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (username, password_hash, role) VALUES (:u, :h, :r)
              ON CONFLICT(username) DO UPDATE SET password_hash = :h, role = :r")
   ->execute([':u' => $username, ':h' => $hash, ':r' => $role]);

echo "Utente '$username' ($role) salvato in " . auth_db_path() . "\n";
