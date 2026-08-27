#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Monitor ADS-B per poligono personalizzato – versione per portale web.
Salva eventi in SQLite con traccia per mappa interattiva.
Rilevamento robusto con filtro aeroporti per ridurre falsi positivi.
Rimosso rilevamento di velocità bassa e quota bassa.
Corretto timestamp UTC.
"""

import argparse
import sqlite3
import json
import time
import math
import fcntl
import os
import sys
from collections import defaultdict, deque
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Dict, List, Optional, Tuple

import requests

# ---------------------------
# Tiles Italia (copertura completa)
# ---------------------------
TILES = [
    (45.5, 9.2, 250),
    (44.5, 11.3, 200),
    (43.8, 11.3, 200),
    (41.9, 12.5, 200),
    (40.8, 14.3, 200),
    (39.2, 9.1, 250),
    (38.1, 15.6, 250),
    (37.5, 13.4, 200),
]

API_TEMPLATE = "https://opendata.adsb.fi/api/v2/lat/{lat}/lon/{lon}/dist/{rng}"
HTTP_TIMEOUT = 15
HTTP_RETRIES = 2
HTTP_BACKOFF = 2.0

# Soglie default (rimosse min_gs e min_alt)
DEF_MAX_ALT_FT = 60000
DEF_MIN_GS_KT = 0    # non più usato, tenuto per retrocompatibilità parametri
DEF_MAX_GS_KT = 650
DEF_MAX_VS_FPM = 8000
DEF_MAX_DGS_KTS = 250

# Percorso del DB: variabile d'ambiente FLIGHT_ANOM_DB, altrimenti ../db/events.db
# rispetto a questo file (funziona sia nel deployment live sia nel repo).
DB_FILE = os.environ.get("FLIGHT_ANOM_DB") or os.path.normpath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "db", "events.db")
)

# ---------------------------
# Dataclass
# ---------------------------
@dataclass
class Aircraft:
    hex: str
    flight: str
    lat: Optional[float]
    lon: Optional[float]
    alt_baro: Optional[int]
    gs: Optional[float]
    ts: Optional[float]
    reg: Optional[str] = None
    squawk: Optional[str] = None
    ground: Optional[bool] = None
    model_t: Optional[str] = None

# ---------------------------
# Geo utility
# ---------------------------
def safe_float(val):
    try:
        return float(val)
    except (TypeError, ValueError):
        return None

def safe_int(val):
    try:
        return int(val)
    except (TypeError, ValueError):
        return None

def safe_bool(val) -> Optional[bool]:
    if isinstance(val, bool):
        return val
    s = str(val).strip().lower()
    if s in ("true", "1", "yes"):
        return True
    if s in ("false", "0", "no"):
        return False
    return None

def haversine_km(p1: Tuple[float, float], p2: Tuple[float, float]) -> float:
    R = 6371.0
    lat1, lon1 = map(math.radians, p1)
    lat2, lon2 = map(math.radians, p2)
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat/2)**2 + math.cos(lat1)*math.cos(lat2)*math.sin(dlon/2)**2
    return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))

def heading(p1: Tuple[float, float], p2: Tuple[float, float]) -> Optional[float]:
    dy = p2[0] - p1[0]
    dx = p2[1] - p1[1]
    if dx == 0 and dy == 0:
        return None
    return math.degrees(math.atan2(dx, dy)) % 360

def angle_diff_deg(a: float, b: float) -> float:
    d = abs(a - b) % 360.0
    return d if d <= 180.0 else 360.0 - d

def point_in_ring(point: Tuple[float, float], ring: List[Tuple[float, float]]) -> bool:
    x, y = point[1], point[0]
    inside = False
    n = len(ring)
    for i in range(n):
        yi, xi = ring[i][0], ring[i][1]
        yj, xj = ring[(i + 1) % n][0], ring[(i + 1) % n][1]
        if ((yi > y) != (yj > y)) and (x < (xj - xi) * (y - yi) / (yj - yi + 1e-12) + xi):
            inside = not inside
    return inside

def point_in_polygon(point: Tuple[float, float], polygon: List[List[Tuple[float, float]]]) -> bool:
    if not polygon:
        return False
    if not point_in_ring(point, polygon[0]):
        return False
    for hole in polygon[1:]:
        if point_in_ring(point, hole):
            return False
    return True

def in_any_polygon(lat: Optional[float], lon: Optional[float],
                   polygons: List[List[List[Tuple[float, float]]]]) -> bool:
    if lat is None or lon is None:
        return False
    pt = (lat, lon)
    return any(point_in_polygon(pt, poly) for poly in polygons)

def load_polygons_from_geojson(path: str) -> List[List[List[Tuple[float, float]]]]:
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
    polys = []
    if isinstance(data, dict) and data.get("type") == "FeatureCollection":
        for feat in data.get("features", []):
            geom = feat.get("geometry", {})
            gtype = geom.get("type")
            coords = geom.get("coordinates", [])
            if gtype == "Polygon":
                polys.append([[(float(pt[1]), float(pt[0])) for pt in ring] for ring in coords])
            elif gtype == "MultiPolygon":
                for polycoords in coords:
                    polys.append([[(float(pt[1]), float(pt[0])) for pt in ring] for ring in polycoords])
    elif isinstance(data, dict) and "polygons" in data:
        for poly in data["polygons"]:
            polys.append([[(float(pt[0]), float(pt[1])) for pt in ring] for ring in poly])
    return polys

# ---------------------------
# Rate limiting (lockfile)
# ---------------------------
def api_rate_guard():
    lockfile = "/tmp/adsbfi_api.lock"
    with open(lockfile, "a+") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        f.seek(0)
        try:
            last = float(f.read().strip())
        except Exception:
            last = 0.0
        now = time.time()
        delta = now - last
        if delta < 1.05:
            time.sleep(1.05 - delta)
        f.seek(0)
        f.truncate()
        f.write(str(time.time()))
        f.flush()
        fcntl.flock(f, fcntl.LOCK_UN)

def fetch_tile(lat: float, lon: float, rng_nm: int) -> Optional[List[dict]]:
    """Ritorna la lista aeromobili della tile, [] se la tile e' genuinamente
    vuota, None se il fetch e' fallito dopo i retry (tile non recuperata)."""
    api_rate_guard()
    url = API_TEMPLATE.format(lat=lat, lon=lon, rng=rng_nm)
    last_exc = None
    for attempt in range(HTTP_RETRIES + 1):
        try:
            r = requests.get(url, timeout=HTTP_TIMEOUT)
            r.raise_for_status()
            return r.json().get("aircraft", []) or []
        except Exception as e:
            last_exc = e
            if attempt < HTTP_RETRIES:
                time.sleep(HTTP_BACKOFF * (attempt + 1))
    print(f"[WARN] Fetch fallito {url} — {last_exc}", file=sys.stderr)
    return None

# ---------------------------
# Aeroporti
# ---------------------------
def load_airports(json_path: str) -> List[dict]:
    with open(json_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def nearest_airport(lat: float, lon: float, airports: List[dict]) -> Tuple[Optional[dict], float]:
    min_dist = float('inf')
    nearest = None
    for apt in airports:
        d = haversine_km((lat, lon), (apt['lat'], apt['lon']))
        if d < min_dist:
            min_dist = d
            nearest = apt
    return nearest, min_dist

# ---------------------------
# Pattern detection
# ---------------------------
def detect_loop_or_racetrack(track: List[Tuple[float, float]],
                             loop_close_km: float = 3.0,
                             min_points: int = 30,
                             min_span_km: float = 10.0,
                             min_laps: int = 2) -> Optional[str]:
    if len(track) < min_points:
        return None
    dist_start_end = haversine_km(track[0], track[-1])
    if dist_start_end > loop_close_km:
        return None
    lats = [p[0] for p in track]
    lons = [p[1] for p in track]
    span_lat = haversine_km((min(lats), min(lons)), (max(lats), min(lons)))
    span_lon = haversine_km((min(lats), min(lons)), (min(lats), max(lons)))
    major = max(span_lat, span_lon)
    minor = min(span_lat, span_lon)
    if major < min_span_km or minor < 2:
        return None
    aspect_ratio = major / (minor + 1e-6)
    shape = "LOOP/CERCHIO" if aspect_ratio < 1.5 else "RACETRACK"
    crossings = 0
    mid_lat = (max(lats) + min(lats)) / 2
    for i in range(len(track) - 1):
        if (track[i][0] - mid_lat) * (track[i+1][0] - mid_lat) < 0:
            crossings += 1
    if crossings < min_laps * 2:
        return None
    return shape

def detect_lawnmower(track: List[Tuple[float, float]],
                     min_points: int = 14,
                     heading_tolerance: float = 15.0,
                     required_passes: int = 4,
                     min_span_km: float = 15.0) -> bool:
    if len(track) < min_points:
        return False
    lats = [p[0] for p in track]
    lons = [p[1] for p in track]
    span = haversine_km((min(lats), min(lons)), (max(lats), max(lons)))
    if span < min_span_km:
        return False
    heads = []
    for i in range(len(track) - 1):
        h = heading(track[i], track[i+1])
        if h is None:
            continue
        heads.append(h % 180)
    if not heads:
        return False
    clusters = [[], []]
    base = min(heads, key=lambda x: sum(angle_diff_deg(x, y) for y in heads))
    for h in heads:
        if angle_diff_deg(h, base) < heading_tolerance:
            clusters[0].append(h)
        elif angle_diff_deg((h+180) % 180, base) < heading_tolerance:
            clusters[1].append(h)
    if len(clusters[0]) < required_passes or len(clusters[1]) < required_passes:
        return False
    sequence = []
    for h in heads:
        if angle_diff_deg(h, base) < heading_tolerance:
            sequence.append("A")
        elif angle_diff_deg((h+180) % 180, base) < heading_tolerance:
            sequence.append("B")
    alternations = sum(1 for i in range(1, len(sequence)) if sequence[i] != sequence[i-1])
    return alternations >= (required_passes - 1)

def detect_mesh(track: List[Tuple[float, float]],
                min_points: int = 40,
                perpendicular_tolerance: float = 10.0,
                min_crossings: int = 6,
                min_family_ratio: float = 0.25) -> bool:
    if len(track) < min_points:
        return False
    heads = [heading(track[i], track[i+1]) for i in range(len(track)-1)]
    heads = [int(round((h or 0)/10.0)*10) % 180 for h in heads if h is not None]
    if not heads:
        return False
    uniq = sorted(set(heads))
    pairs = [(a, b) for a in uniq for b in uniq
             if abs(((a-b)+180)%180 - 90) <= perpendicular_tolerance]
    if not pairs:
        return False
    def family(h, a, b, tol):
        if abs(((h-a)+180)%180) <= tol:
            return "A"
        if abs(((h-b)+180)%180) <= tol:
            return "B"
        return None
    a, b = pairs[0]
    fam_counts = {"A": 0, "B": 0}
    crossings = 0
    last = None
    for h in heads:
        f = family(h, a, b, perpendicular_tolerance)
        if f:
            fam_counts[f] += 1
            if f != last:
                crossings += 1
                last = f
    total = fam_counts["A"] + fam_counts["B"]
    if total == 0:
        return False
    if fam_counts["A"]/total < min_family_ratio or fam_counts["B"]/total < min_family_ratio:
        return False
    return crossings >= min_crossings

# ---------------------------
# Prossimità / formazione
# ---------------------------
def same_direction(h1: Optional[float], h2: Optional[float], tol_deg: float) -> bool:
    if h1 is None or h2 is None:
        return False
    return angle_diff_deg(h1, h2) <= tol_deg

def approx_following(p_lead: Tuple[float, float], h_lead: Optional[float],
                     p_trail: Tuple[float, float], h_trail: Optional[float],
                     tol_deg: float) -> bool:
    if h_lead is None or h_trail is None:
        return False
    if angle_diff_deg(h_lead, h_trail) > tol_deg:
        return False
    bt = heading(p_lead, p_trail)
    if bt is None:
        return False
    return angle_diff_deg((h_lead + 180.0) % 360.0, bt) <= tol_deg

# ---------------------------
# Anomaly detection (senza GS bassa e ALT bassa)
# ---------------------------
def detect_anomalies(ac: Aircraft, prev: Optional[Aircraft], dt_sec: Optional[float],
                     max_alt_ft: int,
                     max_gs_kt: float,
                     max_vs_fpm: float, max_dgs_kts: float) -> List[str]:
    seen = set()
    # Esclude aerei a terra
    is_ground = False
    if ac.ground is True:
        is_ground = True
    elif ac.alt_baro is not None and ac.alt_baro <= 100 and (ac.gs is None or ac.gs < 60):
        is_ground = True
    if is_ground:
        return []

    if ac.squawk and str(ac.squawk).strip() in {"7500", "7600", "7700"}:
        seen.add(f"SQUAWK {ac.squawk}")
    if ac.gs is not None:
        if ac.gs > max_gs_kt:
            seen.add(f"GS alta {ac.gs:.0f} kt")
    if ac.alt_baro is not None:
        if ac.alt_baro > max_alt_ft:
            seen.add(f"ALT alta {ac.alt_baro} ft")
    if prev and dt_sec and dt_sec > 0:
        if ac.gs is not None and prev.gs is not None:
            dgs = ac.gs - prev.gs
            if abs(dgs) > max_dgs_kts:
                seen.add(f"ΔGS {dgs:+.0f} kt")
        if ac.alt_baro is not None and prev.alt_baro is not None:
            vs_fpm = ((ac.alt_baro - prev.alt_baro) / dt_sec) * 60.0
            if abs(vs_fpm) > max_vs_fpm:
                seen.add(f"VS {vs_fpm:.0f} fpm")
    return sorted(seen)

# ---------------------------
# Database
# ---------------------------
def init_db():
    conn = sqlite3.connect(DB_FILE, timeout=15.0)
    # WAL: letture web concorrenti senza "database is locked".
    # synchronous=NORMAL e' sicuro in WAL (perdita al piu' dell'ultima
    # transazione in caso di crash del SO, mai corruzione del file).
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA synchronous=NORMAL")
    conn.execute("PRAGMA busy_timeout=15000")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_seen_utc TEXT NOT NULL,
            hex TEXT,
            callsign TEXT,
            reg TEXT,
            model_t TEXT,
            lat REAL,
            lon REAL,
            alt_baro INTEGER,
            gs REAL,
            squawk TEXT,
            ground INTEGER,
            event_type TEXT,
            note TEXT,
            track_points TEXT,
            screenshot_path TEXT,   -- riservato per uso futuro, attualmente mai popolato
            near_airport INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    try:
        conn.execute("ALTER TABLE events ADD COLUMN near_airport INTEGER DEFAULT 0")
    except sqlite3.OperationalError:
        pass
    # Indici per index.php / api/events.php (filtri e ordinamento).
    # idx_events_type_seen copre il caso comune "filtra per tipo, ordina per data".
    for ddl in (
        "CREATE INDEX IF NOT EXISTS idx_events_first_seen ON events(first_seen_utc)",
        "CREATE INDEX IF NOT EXISTS idx_events_type_seen  ON events(event_type, first_seen_utc)",
        "CREATE INDEX IF NOT EXISTS idx_events_hex        ON events(hex)",
        "CREATE INDEX IF NOT EXISTS idx_events_callsign   ON events(callsign)",
    ):
        conn.execute(ddl)
    conn.commit()
    return conn

def save_event(conn, timestamp, ac: Aircraft, event_type, note, track: List[Tuple[float, float]],
               near_airport_flag=0):
    track_json = json.dumps([{"lat": p[0], "lon": p[1]} for p in track])
    conn.execute("""
        INSERT INTO events (first_seen_utc, hex, callsign, reg, model_t,
                           lat, lon, alt_baro, gs, squawk, ground,
                           event_type, note, track_points, near_airport)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    """, (
        timestamp, ac.hex, ac.flight, ac.reg, ac.model_t,
        ac.lat, ac.lon, ac.alt_baro, ac.gs, ac.squawk,
        1 if ac.ground else 0,
        event_type, note, track_json, near_airport_flag
    ))
    conn.commit()

# ---------------------------
# Main loop
# ---------------------------
def main():
    ap = argparse.ArgumentParser(description="Monitor ADS-B con poligono")
    ap.add_argument("--interval", type=int, default=60)
    ap.add_argument("--polygons-file", required=True)
    ap.add_argument("--max-alt-ft", type=int, default=DEF_MAX_ALT_FT)
    ap.add_argument("--max-gs-kt", type=float, default=DEF_MAX_GS_KT)
    ap.add_argument("--max-vs-fpm", type=float, default=DEF_MAX_VS_FPM)
    ap.add_argument("--max-dgs-kts", type=float, default=DEF_MAX_DGS_KTS)
    ap.add_argument("--proximity-km", type=float, default=3.0)
    ap.add_argument("--prox_angle_deg", type=float, default=20.0)
    ap.add_argument("--prox_alt_diff_ft", type=float, default=500.0)
    ap.add_argument("--prox_gs_diff_kt", type=float, default=40.0)
    ap.add_argument("--anomaly-cooldown", type=int, default=300)
    ap.add_argument("--pattern-cooldown", type=int, default=900)
    ap.add_argument("--prox-cooldown", type=int, default=600)
    ap.add_argument("--loop-min-points", type=int, default=30)
    ap.add_argument("--loop-close-km", type=float, default=3.0)
    ap.add_argument("--loop-min-span-km", type=float, default=10.0)
    ap.add_argument("--loop-min-laps", type=int, default=3)
    ap.add_argument("--lawn-min-points", type=int, default=14)
    ap.add_argument("--lawn-heading-tol", type=float, default=15.0)
    ap.add_argument("--lawn-required-passes", type=int, default=5)
    ap.add_argument("--lawn-min-span-km", type=float, default=15.0)
    ap.add_argument("--mesh-min-points", type=int, default=40)
    ap.add_argument("--mesh-perp-tol", type=float, default=10.0)
    ap.add_argument("--mesh-min-crossings", type=int, default=6)

    args = ap.parse_args()

    polygons = load_polygons_from_geojson(args.polygons_file)
    script_dir = os.path.dirname(os.path.abspath(__file__))
    airports_path = os.path.join(script_dir, 'airports.json')
    airports = load_airports(airports_path) if os.path.exists(airports_path) else []

    conn = init_db()

    track_history: Dict[str, deque] = defaultdict(lambda: deque(maxlen=120))
    prev_state: Dict[str, Aircraft] = {}
    last_anom_alert: Dict[str, float] = {}
    last_pattern_alert: Dict[Tuple[str, str], float] = {}
    last_prox_alert: Dict[Tuple[str, str, str], float] = {}

    print("Monitor avviato. Premere Ctrl+C per fermare.")
    while True:
        t0 = time.time()
        tiles_raw = [fetch_tile(lat, lon, rng) for (lat, lon, rng) in TILES]
        cycle_degraded = any(t is None for t in tiles_raw)
        if cycle_degraded:
            n_fail = sum(1 for t in tiles_raw if t is None)
            print(f"[WARN] Ciclo degradato: {n_fail}/{len(TILES)} tile non recuperate; "
                  f"rilevamento pattern saltato per questo ciclo.", file=sys.stderr)
        all_raw = [ac for t in tiles_raw if t for ac in t]

        aircraft: List[Aircraft] = []
        for ac in all_raw:
            try:
                a = Aircraft(
                    hex=(ac.get("hex") or "").lower(),
                    flight=(ac.get("flight") or "").strip(),
                    lat=safe_float(ac.get("lat")),
                    lon=safe_float(ac.get("lon")),
                    alt_baro=safe_int(ac.get("alt_baro")),
                    gs=safe_float(ac.get("gs")),
                    ts=safe_float(ac.get("seen_pos_timestamp") or ac.get("seen_timestamp")),
                    reg=(ac.get("r") or ac.get("reg") or "").strip() or None,
                    squawk=str(ac.get("squawk")).strip() if ac.get("squawk") else None,
                    ground=safe_bool(ac.get("ground")),
                    model_t=(ac.get("t") or None),
                )
                aircraft.append(a)
            except Exception:
                continue

        if polygons:
            aircraft = [ac for ac in aircraft if in_any_polygon(ac.lat, ac.lon, polygons)]

        # Timestamp UTC corretto
        now_str = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC')

        for ac in aircraft:
            if ac.lat is not None and ac.lon is not None:
                track_history[ac.hex].append((ac.lat, ac.lon))

        # --- Pattern ---
        # Su un ciclo con tile mancanti le tracce possono avere buchi che
        # falsano il rilevamento (falsa chiusura di loop, pattern spezzati):
        # si salta. Anomalie e prossimita', puntuali, restano attive.
        for ac in ([] if cycle_degraded else aircraft):
            track = list(track_history[ac.hex])
            if not track:
                continue
            pattern = None
            loop_type = detect_loop_or_racetrack(
                track,
                min_points=args.loop_min_points,
                loop_close_km=args.loop_close_km,
                min_span_km=args.loop_min_span_km,
                min_laps=args.loop_min_laps
            )
            if loop_type:
                pattern = loop_type
            elif detect_lawnmower(track,
                                  min_points=args.lawn_min_points,
                                  heading_tolerance=args.lawn_heading_tol,
                                  required_passes=args.lawn_required_passes,
                                  min_span_km=args.lawn_min_span_km):
                pattern = "TAGLIAERBA"
            elif detect_mesh(track,
                             min_points=args.mesh_min_points,
                             perpendicular_tolerance=args.mesh_perp_tol,
                             min_crossings=args.mesh_min_crossings):
                pattern = "MESH/RETICOLATO"

            if pattern:
                key = (ac.hex, pattern)
                now_ts = time.time()
                if now_ts - last_pattern_alert.get(key, 0) >= args.pattern_cooldown:
                    near_flag = 0
                    skip = False
                    if airports:
                        apt, dist_km = nearest_airport(ac.lat, ac.lon, airports)
                        if apt and dist_km < apt.get('exclusion_km', 0):
                            near_flag = 1
                            if pattern != "MESH/RETICOLATO":
                                skip = True
                    if not skip:
                        save_event(conn, now_str, ac, "PATTERN", pattern, track, near_flag)
                        print(f"PATTERN {pattern} per {ac.hex} {ac.flight} (near={near_flag})")
                        last_pattern_alert[key] = now_ts

        # --- Prossimità ---
        cur_head: Dict[str, Optional[float]] = {}
        for ac in aircraft:
            th = track_history[ac.hex]
            cur_head[ac.hex] = heading(th[-2], th[-1]) if len(th) >= 2 else None

        for i, ac1 in enumerate(aircraft):
            if not (ac1.lat and ac1.lon):
                continue
            p1 = (ac1.lat, ac1.lon)
            h1 = cur_head.get(ac1.hex)
            for j in range(i+1, len(aircraft)):
                ac2 = aircraft[j]
                if not (ac2.lat and ac2.lon):
                    continue
                if ac1.hex == ac2.hex:
                    continue
                p2 = (ac2.lat, ac2.lon)
                h2 = cur_head.get(ac2.hex)
                dist = haversine_km(p1, p2)
                if dist >= args.proximity_km:
                    continue
                alt_ok = (ac1.alt_baro is not None and ac2.alt_baro is not None and
                          abs(ac1.alt_baro - ac2.alt_baro) <= args.prox_alt_diff_ft)
                gs_ok = (ac1.gs is not None and ac2.gs is not None and
                         abs(ac1.gs - ac2.gs) <= args.prox_gs_diff_kt)
                dir_ok = same_direction(h1, h2, args.prox_angle_deg)
                if not (alt_ok and gs_ok and dir_ok):
                    continue
                label = "CLUSTER"
                if approx_following(p_lead=p1, h_lead=h1, p_trail=p2, h_trail=h2, tol_deg=args.prox_angle_deg) \
                   or approx_following(p_lead=p2, h_lead=h2, p_trail=p1, h_trail=h1, tol_deg=args.prox_angle_deg):
                    label = "INSEGUIMENTO"
                key = tuple(sorted([ac1.hex, ac2.hex]) + [label])
                now_ts = time.time()
                if now_ts - last_prox_alert.get(key, 0) < args.prox_cooldown:
                    continue

                near1 = near2 = 0
                if airports:
                    apt1, d1 = nearest_airport(ac1.lat, ac1.lon, airports)
                    if apt1 and d1 < apt1.get('exclusion_km', 0):
                        near1 = 1
                    apt2, d2 = nearest_airport(ac2.lat, ac2.lon, airports)
                    if apt2 and d2 < apt2.get('exclusion_km', 0):
                        near2 = 1

                note1 = f"{label}; peer={ac2.hex}; dist={dist:.1f} km"
                note2 = f"{label}; peer={ac1.hex}; dist={dist:.1f} km"
                save_event(conn, now_str, ac1, "PROX", note1, list(track_history[ac1.hex]), near1)
                save_event(conn, now_str, ac2, "PROX", note2, list(track_history[ac2.hex]), near2)
                print(f"PROX {label} {ac1.hex}/{ac2.hex} (near1={near1}, near2={near2})")
                last_prox_alert[key] = now_ts

        # --- Anomalie (solo GS alta, ALT alta, ΔGS, VS, squawk) ---
        for ac in aircraft:
            prev = prev_state.get(ac.hex)
            dt_sec = None
            if prev and ac.ts and prev.ts:
                try:
                    dt_sec = max(0.0, float(ac.ts) - float(prev.ts))
                except Exception:
                    dt_sec = None
            anomalies = detect_anomalies(
                ac, prev, dt_sec,
                args.max_alt_ft,
                args.max_gs_kt,
                args.max_vs_fpm, args.max_dgs_kts
            )
            if anomalies:
                now_ts = time.time()
                if now_ts - last_anom_alert.get(ac.hex, 0) >= args.anomaly_cooldown:
                    near_flag = 0
                    skip = False
                    if airports:
                        apt, dist_km = nearest_airport(ac.lat, ac.lon, airports)
                        if apt and dist_km < apt.get('exclusion_km', 0):
                            near_flag = 1
                            has_emergency = any("SQUAWK" in a for a in anomalies)
                            if not has_emergency and ac.alt_baro is not None and ac.alt_baro < 5000:
                                skip = True
                    if not skip:
                        note = "; ".join(anomalies)
                        save_event(conn, now_str, ac, "ANOMALY", note,
                                   list(track_history[ac.hex]), near_flag)
                        print(f"ANOMALY {ac.hex}: {note} (near={near_flag})")
                        last_anom_alert[ac.hex] = now_ts
            prev_state[ac.hex] = ac

        elapsed = time.time() - t0
        sleep_for = max(1, int(round(args.interval - elapsed)))
        time.sleep(sleep_for)

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("Monitor arrestato.")
