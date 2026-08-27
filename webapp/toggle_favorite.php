<?php
// toggle_favorite.php - Aggiunge/rimuove un aeromobile (hex) dai preferiti.
// POST: hex, action=add|remove|toggle, csrf_token. Risposta JSON.
ini_set('display_errors', '0');
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/favorites_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito']);
    exit;
}
if (!csrf_check()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token non valido']);
    exit;
}

$hex = strtolower(trim($_POST['hex'] ?? ''));
$action = $_POST['action'] ?? 'toggle';
if (!fav_valid_hex($hex)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'HEX non valido']);
    exit;
}

try {
    $pdo = fav_open(true);
    fav_ensure_schema($pdo);

    if ($action === 'toggle') {
        $st = $pdo->prepare("SELECT 1 FROM favorites WHERE hex = ?");
        $st->execute([$hex]);
        $action = $st->fetchColumn() ? 'remove' : 'add';
    }

    if ($action === 'add') {
        $pdo->prepare("INSERT OR IGNORE INTO favorites (hex) VALUES (?)")->execute([$hex]);
        $favorited = true;
    } elseif ($action === 'remove') {
        $pdo->prepare("DELETE FROM favorites WHERE hex = ?")->execute([$hex]);
        $favorited = false;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Azione non valida']);
        exit;
    }

    echo json_encode(['ok' => true, 'hex' => $hex, 'favorited' => $favorited]);
} catch (Throwable $e) {
    error_log('toggle_favorite.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore interno']);
}
