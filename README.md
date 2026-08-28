# Flight Anomaly Monitor

Monitor self-hosted del traffico ADS-B su un'area geografica a scelta. Un demone
Python interroga i dati pubblici di [adsb.fi](https://adsb.fi), ricostruisce la
traccia tempo-consapevole di ogni aeromobile dentro un poligono e registra tre
tipi di evento:

- **PATTERN** — geometrie di volo: **ORBITA** / **RACETRACK** (rilevati con
  integrale dell'angolo di virata + ellisse PCA, con conteggio dei giri),
  **TAGLIAERBA** (survey a gambe parallele che spazzano un'area), **RETICOLATO**
  (due direzioni perpendicolari che coprono davvero un'area 2D). I pattern
  circolari e a griglia passano poi da un **discriminante "traffico ordinario"**:
  attesa ATC / vettoramento (racetrack compatto, callsign e tipo di linea,
  discesa netta durante il pattern, spaziatura irregolare fra le gambe)
  abbassano la confidenza o scartano l'evento, salvo prior ISR/militare.
- **PROX** — aeromobili vicini con rotta/quota/velocità concordi per più cicli
  consecutivi: cluster o inseguimento/formazione.
- **ANOMALY** — squawk di emergenza (7500/7600/7700), quota sostenuta fuori scala.

Il monitoraggio è su **tutto** il traffico, non solo quello militare. Ogni
rilevamento porta una **confidenza [0..1]** = geometria + cinematica (banda di
velocità tipica delle orbite, stabilità di quota, tempo sulla stazione) +
**priori** (flag militare `dbFlags`, blocco hex, callsign, tipo velivolo ISR).
I priori non filtrano: alzano solo la confidenza. Sotto `--min-confidence`
l'evento non viene registrato. Un pattern continuo è **un solo evento**
aggiornato in-place (modello a episodio: `first`/`last_seen`, durata, giri).

Gli eventi finiscono in SQLite e si sfogliano da una webapp PHP con filtri
(HEX, callsign, registrazione, squawk, modello, confidenza minima, solo
militari, date), mappa della traccia (Leaflet), pagina **statistiche** con
classifiche, ed **eventi preferiti con nota annotabile**.

Stack: **PHP 8.1+** + **SQLite** per la webapp, **Python 3.9+** per il monitor.
Nessun framework, nessun database server, nessuna build.

## Componenti

| Percorso | Ruolo |
|---|---|
| `monitor/flight_anom.py` | demone: fetch adsb.fi, tracce segmentate, classificatori di pattern, scoring di confidenza, priori, modello a episodio. `--selftest` per i test offline |
| `monitor/airports.json` | aeroporti con raggio di esclusione, per ridurre i falsi positivi vicino agli scali |
| `monitor/polygons.example.json` | poligono di copertura di esempio (da sostituire con il proprio) |
| `monitor/priors.example.json` | override opzionale di blocchi hex / regex callsign / tipi ISR |
| `webapp/index.php` | elenco eventi: filtri (HEX/callsign/reg/squawk/modello/conf/mil/date), ordinamento, paginazione, toggle ⭐ |
| `webapp/view.php` | dettaglio evento + mappa della traccia |
| `webapp/stats.php` | statistiche del portale e del DB, classifiche |
| `webapp/favorites.php` + `edit_favorite.php` + `toggle_favorite.php` | eventi preferiti con nota annotabile |
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
rispetto allo script. Le soglie sono argomenti CLI (vedi `flight_anom.py --help`
e `deploy/flight-anom.service.sample`); le principali:

| Argomento | Default | Significato |
|---|---|---|
| `--min-confidence` | 0.25 | soglia minima per registrare un PATTERN |
| `--hold-reject` | 0.65 | penalità "traffico ordinario" (holding ATC / vettoramento) oltre la quale il PATTERN viene scartato, a meno di prior ISR/militare forte. `1.0` per disattivare |
| `--segment-gap-s` | 600 | buco temporale che apre un nuovo segmento di traccia |
| `--episode-gap-s` | 1200 | silenzio oltre il quale un episodio si considera chiuso |
| `--prox-streak` | 2 | cicli consecutivi di prossimità prima di registrare |

I priori militari/ISR incorporati si sovrascrivono con `monitor/priors.json`
(vedi `monitor/priors.example.json`).

### Test del motore

```bash
monitor/venv/bin/python3 monitor/flight_anom.py --selftest
```

## Dati

adsb.fi fornisce dati ADS-B aggregati dalla community, senza garanzie di
copertura o continuità. Questo progetto non è affiliato con adsb.fi. Usare nel
rispetto dei loro termini.

## Licenza

GPL-3.0-or-later — vedi [`LICENSE`](LICENSE).
