# Flight Anomaly Monitor

Monitor self-hosted del traffico ADS-B su un'area geografica a scelta. Un demone
Python interroga i dati pubblici di [adsb.fi](https://adsb.fi), tiene la traccia
recente di ogni aeromobile dentro un poligono e registra tre tipi di evento:

- **PATTERN** — traiettorie geometriche: cerchi/racetrack, "tagliaerba"
  (lawnmower), reticolati (mesh).
- **PROX** — aeromobili vicini con rotta/quota/velocità concordi: cluster o
  inseguimento/formazione.
- **ANOMALY** — squawk di emergenza (7500/7600/7700), ground speed o quota fuori
  scala, variazioni brusche di velocità/quota.

Gli eventi finiscono in SQLite e si sfogliano da una webapp PHP con filtri,
mappa della traccia (Leaflet) e una **watchlist di aeromobili preferiti con note
annotabili**.

Stack: **PHP 8.1+** + **SQLite** per la webapp, **Python 3.9+** per il monitor.
Nessun framework, nessun database server, nessuna build.

## Componenti

| Percorso | Ruolo |
|---|---|
| `monitor/flight_anom.py` | demone: fetch adsb.fi su più tile, filtro poligono, rilevamento pattern/prossimità/anomalie, scrittura eventi |
| `monitor/airports.json` | aeroporti con raggio di esclusione, per ridurre i falsi positivi vicino agli scali |
| `monitor/polygons.example.json` | poligono di copertura di esempio (da sostituire con il proprio) |
| `webapp/index.php` | elenco eventi: filtri, ordinamento, paginazione, toggle ⭐ preferiti |
| `webapp/view.php` | dettaglio evento + mappa della traccia |
| `webapp/favorites.php` + `edit_favorite.php` + `toggle_favorite.php` | watchlist per-aeromobile con nota annotabile |
| `webapp/api/events.php` | stessi eventi in JSON |
| `webapp/config.sample.php` | modello di configurazione |
| `schema.sql` | schema del database (di riferimento; il demone lo crea da sé) |
| `deploy/` | esempi di unit `systemd`, `.htaccess`, script permessi |

## Installazione

```bash
git clone https://github.com/Indr1d-C0ld/FlightAnom.git
cd FlightAnom

# 1. Layout: webapp/ come document root, db/ accanto (o altrove via config)
sudo mkdir -p /opt/flight_anom
sudo cp -r webapp/* /opt/flight_anom/
sudo mkdir -p /opt/flight_anom/db /opt/flight_anom/monitor

# 2. Configurazione webapp
cp webapp/config.sample.php /opt/flight_anom/config.php
$EDITOR /opt/flight_anom/config.php      # db_path, milair_base_url (opzionale)

# 3. Monitor
sudo cp -r monitor/* /opt/flight_anom/monitor/
python3 -m venv /opt/flight_anom/monitor/venv
/opt/flight_anom/monitor/venv/bin/pip install -r /opt/flight_anom/monitor/requirements.txt
cp monitor/polygons.example.json /opt/flight_anom/monitor/polygons.json
$EDITOR /opt/flight_anom/monitor/polygons.json     # il proprio poligono (GeoJSON, [lon,lat])

# 4. Permessi + servizio
sudo cp deploy/setup_permissions.sh.sample /opt/flight_anom/setup_permissions.sh
$EDITOR /opt/flight_anom/setup_permissions.sh      # PROJECT_DIR, USER, GROUP
sudo bash /opt/flight_anom/setup_permissions.sh
sudo cp deploy/flight-anom.service.sample /etc/systemd/system/flight-anom.service
$EDITOR /etc/systemd/system/flight-anom.service    # percorsi, User=
sudo systemctl daemon-reload && sudo systemctl enable --now flight-anom

# 5. Webserver: servire /opt/flight_anom/webapp/ con PHP, config.php un livello
#    sopra la document root oppure dentro webapp/. Proteggere l'accesso:
#    cp .htaccess.example /opt/flight_anom/webapp/.htaccess   (adattare AuthUserFile)
#    cp deploy/htaccess-db.example      /opt/flight_anom/db/.htaccess
#    cp deploy/htaccess-monitor.example /opt/flight_anom/monitor/.htaccess
```

Il database viene creato al primo avvio del demone (`schema.sql` è solo
riferimento). Su area geografica diversa dall'Italia vanno adattati anche i
`TILES` in testa a `monitor/flight_anom.py` e `monitor/airports.json`.

## Configurazione (`config.php`)

| Chiave | Significato | Default |
|---|---|---|
| `db_path` | percorso assoluto del file SQLite (`events` + `favorites`) | `<webapp>/db/events.db` |
| `milair_base_url` | base URL di un'istanza [MilAir ITA](https://github.com/Indr1d-C0ld/MilAir_ITA); se valorizzato aggiunge un link 🔍 per ICAO | `''` (nascosto) |

Il monitor legge il percorso del DB da `$FLIGHT_ANOM_DB`, altrimenti `../db/events.db`
rispetto allo script. Le soglie di rilevamento sono argomenti CLI (vedi
`flight_anom.py --help` e `deploy/flight-anom.service.sample`).

## Dati

adsb.fi fornisce dati ADS-B aggregati dalla community, senza garanzie di
copertura o continuità. Questo progetto non è affiliato con adsb.fi. Usare nel
rispetto dei loro termini.

## Licenza

GPL-3.0-or-later — vedi [`LICENSE`](LICENSE).
