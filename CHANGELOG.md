# Changelog — FlightAnom

## Non ancora rilasciato

### Motore di detection — riscrittura
- **Tracce tempo-consapevoli e segmentate**: ogni punto ha timestamp; un buco
  oltre `--segment-gap-s` apre un nuovo segmento (basta falsi loop da sortite
  diverse fuse). Potatura degli hex inattivi.
- **ORBITA / RACETRACK**: via il fragile test "inizio ≈ fine"; ora integrale
  dell'angolo di virata (∮dθ≈n·360), ellisse PCA per assi e orientamento reali,
  contenimento nella regione. Riporta il **numero di giri**.
- **TAGLIAERBA**: istogramma delle prue + verifica dello *sweep perpendicolare*
  (deve coprire un'area 2D).
- **RETICOLATO**: due direzioni perpendicolari **con copertura 2D reale**
  (≥12 celle da 2 km, distribuzione ≥3×3). Non spara più sul manovrare comune.
- **Confidenza [0..1]** = `0.45·geometria + cinematica + priori`; sotto
  `--min-confidence` non si registra. Cinematica = banda di velocità, stabilità
  di quota, tempo sulla stazione.
- **Priori** (non filtrano, solo confidenza): `dbFlags` (militare / notevole),
  blocco hex, regex callsign, allowlist tipi ISR — override in `priors.json`.
- **Modello a episodio**: un pattern continuo = una riga aggiornata in-place
  (`first`/`last_seen`, `duration_s`, `laps`, `updates`).
- **Prossimità**: richiede `--prox-streak` cicli consecutivi; una riga per coppia.
- **Anomalie**: tenuti solo squawk d'emergenza e quota sostenuta fuori scala
  (tolti i filtri GS/VS/ΔGS che catturavano glitch del sensore).
- `flight_anom.py --selftest` per la verifica offline dei classificatori.

### Aggiunto
- Prima pubblicazione open source (GPL-3.0) del portale e del monitor.
- **Eventi preferiti** con **nota annotabile** (una per evento, chiave
  `event_id` -> `events.id`): `favorites.php`, `edit_favorite.php`,
  `toggle_favorite.php`, `favorites_lib.php`. Toggle ⭐ per riga (colonna
  azioni) e filtro "Solo preferiti" in `index.php`; token CSRF via sessione
  (`csrf.php`). `fav_ensure_schema()` sposta da parte un'eventuale vecchia
  tabella `favorites` a chiave `hex` (`favorites_legacy_hex`).
  In `favorites.php` un solo comando di rimozione: la ⭐ a inizio riga
  (rimossa la ✖ in coda).
- `webapp/config.sample.php` + `fa_config()`: `db_path` e `milair_base_url`
  configurabili; il monitor legge il DB da `$FLIGHT_ANOM_DB` o da `../db/events.db`.
- **Nuovi campi come filtro/ricerca in `index.php`**: registrazione, squawk,
  modello, confidenza minima, "solo militari"; colonne Reg/Modello/Squawk/Conf,
  badge ⚑ militare, sottotipo e n° giri.
- **`webapp/stats.php`**: statistiche del portale e del DB con classifiche
  (per tipo/sottotipo, top aeromobili/callsign/modelli/registrazioni, squawk,
  militare vs civile, episodi più lunghi, più giri, distribuzione della
  confidenza, attività per ora e per giorno, ultimi squawk d'emergenza).
- Schema `events` esteso: `last_seen_utc`, `is_mil`, `subtype`, `confidence`,
  `laps`, `duration_s`, `updates` + relativi indici.
- `schema.sql`, `deploy/*.sample`, `.htaccess.example`, `monitor/priors.example.json`.

### Sicurezza / robustezza
- `api/events.php`: filtri data allineati alla webapp (Europe/Rome→UTC), niente
  `track_points` in risposta, `?limit` con tetto, `try/catch`, `X-Content-Type-Options`.
- SQLite in **WAL** + indici (`idx_events_first_seen`, `idx_events_type_seen`,
  `idx_events_hex`, `idx_events_callsign`), `PRAGMA busy_timeout` lato PHP.
- `index.php` / `view.php`: `try/catch` con pagina d'errore generica,
  `display_errors` off; `view.php` inietta JSON in `<script>` con
  `JSON_HEX_TAG|APOS|QUOT|AMP`.
- Leaflet caricato con Subresource Integrity + fallback.
- Monitor: `fetch_tile` distingue "tile vuota" da "fetch fallito"; su un ciclo
  con tile mancanti il rilevamento pattern viene saltato per non falsare le
  tracce.
