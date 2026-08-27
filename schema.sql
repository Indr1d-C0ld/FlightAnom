-- Flight Anomaly Monitor - schema del database SQLite.
--
-- Non serve applicarlo a mano: monitor/flight_anom.py lo crea/aggiorna in
-- init_db() al primo avvio (tabella events, colonna near_airport, indici).
-- La tabella favorites viene creata pigramente dalla webapp al primo uso.
-- Questo file e' il riferimento canonico.

PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    first_seen_utc  TEXT NOT NULL,          -- 'YYYY-MM-DD HH:MM:SS UTC'
    hex             TEXT,                   -- ICAO 24-bit, minuscolo
    callsign        TEXT,
    reg             TEXT,
    model_t         TEXT,
    lat             REAL,
    lon             REAL,
    alt_baro        INTEGER,
    gs              REAL,
    squawk          TEXT,
    ground          INTEGER,
    event_type      TEXT,                   -- PATTERN | PROX | ANOMALY
    note            TEXT,
    track_points    TEXT,                   -- JSON: [{"lat":..,"lon":..}, ...]
    screenshot_path TEXT,                   -- riservato, non popolato
    near_airport    INTEGER DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_events_first_seen ON events(first_seen_utc);
CREATE INDEX IF NOT EXISTS idx_events_type_seen  ON events(event_type, first_seen_utc);
CREATE INDEX IF NOT EXISTS idx_events_hex        ON events(hex);
CREATE INDEX IF NOT EXISTS idx_events_callsign   ON events(callsign);

-- Preferiti: watchlist per-aeromobile con nota annotabile (una per hex).
CREATE TABLE IF NOT EXISTS favorites (
    hex        TEXT PRIMARY KEY,
    note       TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
