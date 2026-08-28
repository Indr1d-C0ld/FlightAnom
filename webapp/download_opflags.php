#!/usr/bin/env php
<?php
// download_opflags.php - Popola opflags/ con i loghi compagnia (VRS OperatorFlags).
//
//   php download_opflags.php --zip OperatorFlags.zip
//       estrae i loghi per-codice (CODICE.bmp/png) da un pacchetto
//       OperatorFlags.zip  (github.com/rikgale/VRSOperatorFlags, GPL-3.0).
//
//   OPFLAGS_SRC=https://mirror/opflags/ php download_opflags.php
//       scarica per singolo codice operatore visto negli eventi da un mirror
//       per-file (base URL, deve terminare con /).
//
// Da eseguire come CLI. Vedi deploy/crontab.sample.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando.\n");
}
require __DIR__ . '/favorites_lib.php';

$dir = __DIR__ . '/opflags';
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    fwrite(STDERR, "Impossibile creare $dir\n");
    exit(1);
}

$zipIdx = array_search('--zip', $argv, true);
if ($zipIdx !== false && isset($argv[$zipIdx + 1])) {
    $zip = $argv[$zipIdx + 1];
    $za = new ZipArchive();
    if ($za->open($zip) !== true) {
        fwrite(STDERR, "Zip non apribile: $zip\n");
        exit(1);
    }
    $n = 0;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = $za->getNameIndex($i);
        // solo i loghi "per codice operatore": CODICE.bmp/png, nessun trattino
        if (!preg_match('#^[A-Za-z0-9]{2,4}\.(bmp|png)$#i', $name)) {
            continue;
        }
        $data = $za->getFromIndex($i);
        if ($data !== false && strlen($data) > 100) {
            file_put_contents($dir . '/' . strtoupper($name), $data);
            $n++;
        }
    }
    $za->close();
    echo "Estratti $n loghi per-codice in $dir\n";
    exit(0);
}

$src = getenv('OPFLAGS_SRC') ?: '';
if ($src === '') {
    fwrite(STDERR,
        "Nessuna sorgente.\n"
        . "  - php download_opflags.php --zip OperatorFlags.zip\n"
        . "    (pacchetto da https://github.com/rikgale/VRSOperatorFlags)\n"
        . "  - oppure OPFLAGS_SRC=https://mirror/opflags/ php download_opflags.php\n");
    exit(2);
}

try {
    $pdo = new PDO("sqlite:" . fa_config()['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $codes = $pdo->query(
        "SELECT DISTINCT operator FROM events WHERE operator IS NOT NULL AND operator <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    fwrite(STDERR, "DB: " . $e->getMessage() . "\n");
    exit(1);
}

$ctx = stream_context_create(['http' => [
    'timeout' => 10,
    'header'  => "User-Agent: Mozilla/5.0 (FlightAnom opflags fetch)\r\n",
]]);
$dl = $skip = $fail = 0;
foreach ($codes as $c) {
    $c = strtoupper(trim($c));
    if (!preg_match('/^[A-Z0-9]{2,4}$/', $c)) {
        continue;
    }
    $local = "$dir/$c.bmp";
    if (is_file($local) && filesize($local) > 0) {
        $skip++;
        continue;
    }
    $data = @file_get_contents($src . $c . '.bmp', false, $ctx);
    if ($data !== false && strlen($data) > 100 && @file_put_contents($local, $data) !== false) {
        $dl++;
    } else {
        $fail++;
    }
    usleep(200000);
}
echo date('c') . " operatori=" . count($codes) . " scaricati=$dl presenti=$skip falliti=$fail\n";
