<?php
declare(strict_types=1);

/**
 * Flight Anomaly Monitor - configurazione della webapp.
 *
 * Copiare in `config.php` (stessa cartella) e adattare. `config.php` NON va
 * versionato. Tutte le chiavi sono opzionali: quelle omesse usano i default
 * di fa_config() in favorites_lib.php, pensati per un deployment "flat"
 * (i .php e la cartella db/ nella stessa directory).
 */

return [
    // Percorso assoluto del database SQLite (tabelle events + favorites).
    // Default: <cartella della webapp>/db/events.db
    // Deve essere leggibile/scrivibile dall'utente del webserver e da quello
    // che esegue monitor/flight_anom.py.
    'db_path' => __DIR__ . '/db/events.db',

    // Base URL di un'istanza MilAir ITA (https://.../milair_ita). Se valorizzato,
    // nella lista eventi compare per ogni ICAO un link 🔍 di ricerca su MilAir.
    // Vuoto = nessun link.
    'milair_base_url' => '',
];
