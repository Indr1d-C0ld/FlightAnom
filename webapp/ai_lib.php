<?php
// ai_lib.php - Assistente di analisi opzionale tramite server LLM locale (Ollama).
//
// Disattivato di default: si abilita valorizzando `ai_base_url` in config.php.
// La chiamata avviene SOLO lato server (rete locale); il browser non contatta
// mai il modello. L'URL e' preso dalla configurazione, mai da input utente.
//
// L'output e' una BOZZA: va riletta da un operatore prima di pubblicarla nel
// diario. Usata da diary.php (azione admin "genera sintesi").

require_once __DIR__ . '/favorites_lib.php';

/** Parametri effettivi dell'assistente (da fa_config()). */
function ai_cfg(): array
{
    $c = fa_config();
    return [
        'base_url'  => rtrim((string) ($c['ai_base_url'] ?? ''), '/'),
        'model'     => (string) ($c['ai_model'] ?? 'qwen2.5:14b'),
        'num_ctx'   => max(2048, (int) ($c['ai_num_ctx'] ?? 16384)),
        'timeout_s' => max(30, (int) ($c['ai_timeout_s'] ?? 420)),
        'min_role'  => (string) ($c['ai_min_role'] ?? 'admin'),
    ];
}

/** true se la funzione e' configurata (base_url non vuoto) e cURL disponibile. */
function ai_enabled(): bool
{
    return ai_cfg()['base_url'] !== '' && function_exists('curl_init');
}

/** Ruolo minimo richiesto per generare la sintesi. */
function ai_min_role(): string
{
    return ai_cfg()['min_role'] ?: 'admin';
}

const AI_SYSTEM_PROMPT = <<<'TXT'
Sei un analista OSINT di traffico aereo. Ricevi dati AGGREGATI di eventi anomali
rilevati da un monitor ADS-B per una singola giornata. Redigi una sintesi in
italiano, in Markdown, di massimo ~400 parole, con esattamente queste sezioni:

## Highlights
## Anomalie e outlier
## Ricorrenze e correlazioni
## Pattern geografici
## Da approfondire

Regole:
- Usa ESCLUSIVAMENTE i dati forniti. Non aggiungere contesto esterno, non
  ipotizzare identita', intenzioni o scenari non desumibili dai numeri.
- Quando citi un evento usa il valore reale del suo campo "id" nella forma
  #id (es. #1234). NON numerare gli eventi progressivamente e non inventare id:
  se un elemento non ha un id nei dati, descrivilo senza "#".
- In "Ricorrenze e correlazioni" segnala lo stesso mezzo/operatore/area che
  compare piu' volte nella giornata o rispetto ai 14 giorni precedenti.
- Se i dati sono scarsi o assenti, dichiaralo in una riga e fermati.
- Niente preamboli o chiuse: solo le cinque sezioni.
TXT;

/** Messaggio utente: la giornata + il digest (sfoltito) in JSON. */
function ai_user_message(array $digest): string
{
    $slim = $digest;
    foreach (['repeat_aircraft', 'high_confidence', 'longest', 'most_laps',
              'area_clusters', 'proximity', 'emergencies', 'by_operator',
              'by_country', 'recurring_callsigns'] as $k) {
        if (!empty($slim[$k]) && is_array($slim[$k])) {
            $slim[$k] = array_slice($slim[$k], 0, 15);
        }
    }
    unset($slim['by_hour']); // poco utile alla narrativa, occupa spazio

    $json = json_encode(
        $slim,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    $day = (string) ($digest['day'] ?? '');

    return "Giornata analizzata: {$day} (ora locale Europe/Rome).\n\n"
         . "Dati aggregati (JSON):\n```json\n{$json}\n```\n\n"
         . "Redigi la sintesi seguendo le istruzioni di sistema.";
}

/**
 * Genera la sintesi discorsiva dal digest.
 * Ritorna ['ok'=>bool, 'text'?, 'model'?, 'stats'?, 'error'?].
 */
function ai_generate(array $digest): array
{
    $cfg = ai_cfg();
    if ($cfg['base_url'] === '') {
        return ['ok' => false, 'error' => 'Assistente IA non configurato.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Estensione cURL non disponibile.'];
    }

    $payload = [
        'model'    => $cfg['model'],
        'stream'   => false,
        'options'  => [
            'num_ctx'     => $cfg['num_ctx'],
            'temperature' => 0.3,
            'top_p'       => 0.9,
        ],
        'messages' => [
            ['role' => 'system', 'content' => AI_SYSTEM_PROMPT],
            ['role' => 'user',   'content' => ai_user_message($digest)],
        ],
    ];

    $ch = curl_init($cfg['base_url'] . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $cfg['timeout_s'],
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $errs  = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['ok' => false, 'error' => "Timeout: il modello non ha risposto entro {$cfg['timeout_s']}s. La workstation è accesa?"];
    }
    if ($errno) {
        return ['ok' => false, 'error' => "Connessione al server modello fallita ({$errs})."];
    }
    if ($code !== 200) {
        return ['ok' => false, 'error' => "Il server modello ha risposto HTTP {$code}."];
    }

    $j = json_decode((string) $raw, true);
    $text = is_array($j) ? trim((string) ($j['message']['content'] ?? '')) : '';
    if ($text === '') {
        return ['ok' => false, 'error' => 'Risposta del modello vuota o non interpretabile.'];
    }

    $stats = [];
    if (isset($j['eval_count'], $j['eval_duration']) && $j['eval_duration'] > 0) {
        $stats['tok_s']   = round($j['eval_count'] / ($j['eval_duration'] / 1e9), 1);
        $stats['tokens']  = (int) $j['eval_count'];
    }
    if (isset($j['total_duration'])) {
        $stats['seconds'] = round($j['total_duration'] / 1e9, 1);
    }

    return ['ok' => true, 'text' => $text, 'model' => $cfg['model'], 'stats' => $stats];
}
