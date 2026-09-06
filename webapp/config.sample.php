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

    // --- Assistente di analisi del Diario (server LLM locale, Ollama) ----------
    // Opzionale e DISATTIVATO se 'ai_base_url' e' vuoto: nessun pulsante, nessuna
    // chiamata di rete. Quando attivo, diary.php offre all'admin un pulsante per
    // generare una sintesi discorsiva della giornata a partire dal digest. La
    // chiamata parte SOLO dal server verso l'host indicato (tipicamente in LAN);
    // il browser non contatta mai il modello. La sintesi e' una bozza: va
    // riletta e poi pubblicata a mano.
    'ai_base_url'  => '',                 // es. 'http://ollama.local:11434'
    'ai_model'     => 'qwen2.5:14b',      // un modello gia' scaricato su quell'host
    'ai_num_ctx'   => 16384,             // finestra di contesto richiesta a Ollama
    'ai_timeout_s' => 600,               // attesa massima della risposta (modelli locali = lenti)
    'ai_min_role'  => 'admin',           // 'admin' o 'collaboratore'
];
