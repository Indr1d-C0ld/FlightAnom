<?php
// logout.php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
logout();
header('Location: index.php');
