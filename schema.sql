-- Flight Anomaly Monitor - schema del database SQLite.
--
-- Non serve applicarlo a mano: monitor/flight_anom.py lo crea/aggiorna in
-- init_db() al primo avvio (tabella events, colonne aggiunte in seguito, indici).
-- La tabella favorites viene creata pigramente dalla webapp al primo uso.
-- Questo file e' il riferimento canonico.

PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    first_seen_utc  TEXT NOT NULL,          -- inizio episodio ('YYYY-MM-DD HH:MM:SS UTC')
    last_seen_utc   TEXT,                   -- ultimo aggiornamento dell'episodio
    hex             TEXT,                   -- ICAO 24-bit, minuscolo
    callsign        TEXT,
    reg             TEXT,
    model_t         TEXT,                   -- designatore ICAO del tipo (campo "t" di adsb.fi)
    lat             REAL,
    lon             REAL,
    alt_baro        INTEGER,
    gs              REAL,
    squawk          TEXT,
    ground          INTEGER,
    is_mil          INTEGER DEFAULT 0,      -- flag militare (dbFlags / blocco hex / callsign)
    event_type      TEXT,                   -- PATTERN | PROX | ANOMALY
    subtype         TEXT,                   -- ORBITA | RACETRACK | TAGLIAERBA | RETICOLATO |
                                            -- CLUSTER | INSEGUIMENTO | SQUAWK-7700 | QUOTA-ALTA ...
    note            TEXT,                   -- descrizione leggibile (tag di confidenza inclusi)
    confidence      REAL,                   -- 0..1
    laps            INTEGER,                -- giri completati (pattern circolari), NULL altrimenti
    duration_s      INTEGER,                -- durata episodio in secondi
    updates         INTEGER DEFAULT 1,      -- quante volte l'episodio e' stato rinfrescato
    track_points    TEXT,                   -- JSON: [{"lat":..,"lon":..,"alt":..,"gs":..,"t":..}]
    screenshot_path TEXT,                   -- riservato, non popolato
    near_airport    INTEGER DEFAULT 0,
    country         TEXT,                   -- ISO 3166-1 alpha-2 (blocco ICAO, poi reg/callsign); 'ZZ' se ignoto
    operator        TEXT,                   -- codice compagnia ICAO (3 lettere) dal callsign, NULL se non di linea
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_events_first_seen ON events(first_seen_utc);
CREATE INDEX IF NOT EXISTS idx_events_last_seen  ON events(last_seen_utc);
CREATE INDEX IF NOT EXISTS idx_events_type_seen  ON events(event_type, first_seen_utc);
CREATE INDEX IF NOT EXISTS idx_events_subtype    ON events(subtype);
CREATE INDEX IF NOT EXISTS idx_events_hex        ON events(hex);
CREATE INDEX IF NOT EXISTS idx_events_callsign   ON events(callsign);
CREATE INDEX IF NOT EXISTS idx_events_reg        ON events(reg);
CREATE INDEX IF NOT EXISTS idx_events_squawk     ON events(squawk);
CREATE INDEX IF NOT EXISTS idx_events_model      ON events(model_t);
CREATE INDEX IF NOT EXISTS idx_events_mil        ON events(is_mil);
CREATE INDEX IF NOT EXISTS idx_events_conf       ON events(confidence);
CREATE INDEX IF NOT EXISTS idx_events_country    ON events(country);
CREATE INDEX IF NOT EXISTS idx_events_operator   ON events(operator);

-- Preferiti: PER-EVENTO, con nota annotabile (una per evento).
-- event_id -> events.id. Creata pigramente dalla webapp al primo uso.
CREATE TABLE IF NOT EXISTS favorites (
    event_id   INTEGER PRIMARY KEY,
    note       TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
