# Changelog — FlightAnom

## Non ancora rilasciato

### Aggiunto
- Prima pubblicazione open source (GPL-3.0) del portale e del monitor.
- Watchlist di **aeromobili preferiti** (chiave `hex`) con **nota annotabile**:
  `favorites.php`, `edit_favorite.php`, `toggle_favorite.php`, `favorites_lib.php`.
  Toggle ⭐ per riga e filtro "Solo preferiti" in `index.php`; token CSRF via
  sessione (`csrf.php`).
- `webapp/config.sample.php` + `fa_config()`: `db_path` e `milair_base_url`
  configurabili; il monitor legge il DB da `$FLIGHT_ANOM_DB` o da `../db/events.db`.
- `schema.sql`, `deploy/*.sample`, `.htaccess.example`.

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
