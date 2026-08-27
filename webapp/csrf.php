<?php
// csrf.php - Token CSRF minimale basato su sessione.
// Il portale e' gia' dietro HTTP Basic auth, ma il browser reinvia le credenziali
// Basic anche su POST cross-site: il token (piu' SameSite=Lax sul cookie) e' la barriera.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check(): bool {
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}
