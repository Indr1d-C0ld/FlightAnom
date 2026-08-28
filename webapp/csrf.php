<?php
// csrf.php - alias storico. Le funzioni CSRF e la sessione vivono in auth.php.
require_once __DIR__ . '/auth.php';
if (function_exists('auth_bootstrap')) {
    auth_bootstrap();
}
