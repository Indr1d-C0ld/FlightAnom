<?php
// toggle_favorite.php - Aggiunge/rimuove un EVENTO dai preferiti.
// POST: id (events.id), action=add|remove|toggle, csrf_token. Risposta JSON.
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
require_role('collaboratore', true);   // login + ruolo (risposta JSON)
if (!csrf_check()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token non valido']);
    exit;
}

$id = trim((string) ($_POST['id'] ?? ''));
$action = $_POST['action'] ?? 'toggle';
if (!fav_valid_id($id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID evento non valido']);
    exit;
}
$id = (int) $id;

try {
    $pdo = fav_open(true);
    fav_ensure_schema($pdo);

    // L'evento deve esistere (per "add"/"toggle"->add)
    $exists = $pdo->prepare("SELECT 1 FROM events WHERE id = ?");
    $exists->execute([$id]);
    $event_exists = (bool) $exists->fetchColumn();

    if ($action === 'toggle') {
        $st = $pdo->prepare("SELECT 1 FROM favorites WHERE event_id = ?");
        $st->execute([$id]);
        $action = $st->fetchColumn() ? 'remove' : 'add';
    }

    if ($action === 'add') {
        if (!$event_exists) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Evento inesistente']);
            exit;
        }
        $pdo->prepare("INSERT OR IGNORE INTO favorites (event_id) VALUES (?)")->execute([$id]);
        $favorited = true;
    } elseif ($action === 'remove') {
        $pdo->prepare("DELETE FROM favorites WHERE event_id = ?")->execute([$id]);
        $favorited = false;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Azione non valida']);
        exit;
    }

    echo json_encode(['ok' => true, 'id' => $id, 'favorited' => $favorited]);
} catch (Throwable $e) {
    error_log('toggle_favorite.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore interno']);
}
