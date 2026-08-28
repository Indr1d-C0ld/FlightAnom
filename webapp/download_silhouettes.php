#!/usr/bin/env php
<?php
// download_silhouettes.php - Scarica le silhouette mancanti per i tipi velivolo
// visti negli eventi. Formato VRS/VirtualRadar (~85x20 BMP). Da eseguire via cron.
//   0 */6 * * * /usr/bin/php /percorso/download_silhouettes.php >> .../silhouettes.log 2>&1
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando.\n");
}

require __DIR__ . '/favorites_lib.php';   // fa_config() -> db_path

$db_path = fa_config()['db_path'];
$dir = __DIR__ . '/silhouettes';
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    fwrite(STDERR, "Impossibile creare $dir\n");
    exit(1);
}

// Sorgente sovrascrivibile: SILHOUETTE_SRC=https://host/path/ (deve finire con /)
$src = getenv('SILHOUETTE_SRC') ?: 'https://www.flightdb.net/img/silhouettes/';

try {
    $pdo = new PDO("sqlite:$db_path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $models = $pdo->query(
        "SELECT DISTINCT model_t FROM events WHERE model_t IS NOT NULL AND model_t <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    fwrite(STDERR, "DB: " . $e->getMessage() . "\n");
    exit(1);
}

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'header'  => "User-Agent: Mozilla/5.0 (FlightAnom silhouette fetch)\r\n",
]]);

$dl = $skip = $fail = 0;
foreach ($models as $m) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($m)));
    if ($safe === '') {
        continue;
    }
    $local = "$dir/$safe.bmp";
    if (is_file($local) && filesize($local) > 0) {
        $skip++;
        continue;
    }
    $data = @file_get_contents($src . rawurlencode($safe) . '.bmp', false, $ctx);
    if ($data !== false && strlen($data) > 100 && @file_put_contents($local, $data) !== false) {
        $dl++;
        echo date('c') . " scaricata $safe.bmp (" . strlen($data) . " byte)\n";
    } else {
        $fail++;
        fwrite(STDERR, date('c') . " fallita $safe\n");
    }
    usleep(200000);   // ~5 richieste/s: gentile con la sorgente
}

echo date('c') . " modelli=" . count($models) . " scaricate=$dl presenti=$skip fallite=$fail\n";
