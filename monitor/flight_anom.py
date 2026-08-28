#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Monitor ADS-B per poligono personalizzato - versione per portale web.

Rileva su TUTTO il traffico dentro il poligono (non solo militare):
  - PATTERN  orbite/racetrack, tagliaerba (survey), reticolato (grid)
  - PROX     prossimita'/formazione (cluster, inseguimento/trail)
  - ANOMALY  squawk di emergenza, quota sostenuta fuori scala

Ogni rilevamento porta una confidenza [0..1] = geometria + cinematica
(banda velocita', stabilita' di quota, tempo sulla stazione) + priori
(flag militare dbFlags, blocco hex, callsign, tipo velivolo ISR). I priori
NON filtrano: alzano solo la confidenza. Sotto --min-confidence l'evento
non viene registrato.

Modello a EPISODIO: un pattern continuo = una riga aggiornata in-place
(first/last_seen, durata, giri, confidenza) finche' non cessa.

Tracce tempo-consapevoli: ogni punto ha timestamp; un buco > --segment-gap-s
apre un nuovo segmento (niente falsi loop da sortite diverse fuse).
"""

import argparse
import fcntl
import json
import math
import os
import re
import sqlite3
import sys
import time
from collections import defaultdict, deque
from dataclasses import dataclass, field
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

DB_FILE = os.environ.get("FLIGHT_ANOM_DB") or os.path.normpath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "db", "events.db")
)

# ---------------------------
# Priori (militare / ISR). Sovrascrivibili con monitor/priors.json:
#   { "mil_hex_prefixes": [...], "mil_callsign_regex": "...", "isr_types": [...] }
# ---------------------------
MIL_HEX_PREFIXES = ("ae", "adf")  # blocco USAF (AE____, ADF___): affidabile

MIL_CS_RE = re.compile(
    r"^(RRR|RCH|CNV|CTM|FAF|GAF|IAM|AME|NATO|FORTE|HOMER|REDEYE|COBRA|JEDI|SLAM|"
    r"SNIPER|MMF|MAGMA|YETI|DUKE|REEF|BART|GRZLY|PYTHON|VIKING|POLIZIA|GDF|"
    r"FIAMME|SOCCORSO|GUARDIA|CARAB)\b",
    re.I,
)

ISR_TYPES = {
    "E3TF", "E3CF", "E3", "E6", "E8", "E11", "P8", "P3", "EP3", "P3C",
    "B350", "B300", "C12", "RC12", "MC12", "B190", "SW4",
    "GLF5", "GL5T", "GLEX", "G550", "CL60", "C560", "C56X", "BE20",
    "RQ4", "MQ4", "MQ9", "MQ1", "MQ1C", "RQ7", "U2", "WC135", "OC135", "KC135",
    "AT8T", "C208", "P68", "DA42", "DA62", "VUT", "AC90", "C82R",
}

# Callsign in stile compagnia aerea: 3 lettere ICAO + cifra (RYR86FZ, DLH4AB...)
AIRLINE_CS_RE = re.compile(r"^[A-Z]{3}\d")

# Tipi ICAO di linea/regionali: non fanno pattern di sorveglianza. Usati SOLO
# come discriminante "traffico ordinario" (holding/vettoramento), mai come filtro.
AIRLINER_TYPES = {
    "A19N", "A20N", "A21N", "A318", "A319", "A320", "A321", "A310", "A306", "A30B",
    "A332", "A333", "A338", "A339", "A342", "A343", "A345", "A346", "A359", "A35K", "A388",
    "B712", "B722", "B732", "B733", "B734", "B735", "B736", "B737", "B738", "B739",
    "B37M", "B38M", "B39M", "B3XM",
    "B741", "B742", "B743", "B744", "B748", "B752", "B753", "B762", "B763", "B764",
    "B772", "B773", "B77L", "B77W", "B778", "B779", "B788", "B789", "B78X",
    "E170", "E75L", "E75S", "E190", "E195", "E290", "E295", "E135", "E145",
    "CRJ2", "CRJ7", "CRJ9", "CRJX", "AT43", "AT45", "AT72", "AT75", "AT76",
    "DH8A", "DH8B", "DH8C", "DH8D", "BCS1", "BCS3", "SU95",
    "MD82", "MD83", "MD88", "MD90", "F70", "F100", "RJ85", "RJ1H",
}

# Auto-incroci minimi perche' un pattern sia RETICOLATO (regolabile da CLI).
GRID_MIN_SELFX = 5

# ===========================================================================
# Nazionalita' (ICAO 24-bit block -> ISO2) e operatore (codice compagnia ICAO)
# ===========================================================================
ICAO_RANGES = [  # (start, end, ISO2) - Annex 10 Vol III, via tar1090 flags.js
    (0x004000,0x0047FF,'ZW'), (0x006000,0x006FFF,'MZ'), (0x008000,0x00FFFF,'ZA'), (0x010000,0x017FFF,'EG'),
    (0x018000,0x01FFFF,'LY'), (0x020000,0x027FFF,'MA'), (0x028000,0x02FFFF,'TN'), (0x030000,0x0307FF,'BW'),
    (0x032000,0x032FFF,'BI'), (0x034000,0x034FFF,'CM'), (0x035000,0x0357FF,'KM'), (0x036000,0x036FFF,'CG'),
    (0x038000,0x038FFF,'CI'), (0x03E000,0x03EFFF,'GA'), (0x040000,0x040FFF,'ET'), (0x042000,0x042FFF,'GQ'),
    (0x044000,0x044FFF,'GH'), (0x046000,0x046FFF,'GN'), (0x048000,0x0487FF,'GW'), (0x04A000,0x04A7FF,'LS'),
    (0x04C000,0x04CFFF,'KE'), (0x050000,0x050FFF,'LR'), (0x054000,0x054FFF,'MG'), (0x058000,0x058FFF,'MW'),
    (0x05A000,0x05A7FF,'MV'), (0x05C000,0x05CFFF,'ML'), (0x05E000,0x05E7FF,'MR'), (0x060000,0x0607FF,'MU'),
    (0x062000,0x062FFF,'NE'), (0x064000,0x064FFF,'NG'), (0x068000,0x068FFF,'UG'), (0x06A000,0x06AFFF,'QA'),
    (0x06C000,0x06CFFF,'CF'), (0x06E000,0x06EFFF,'RW'), (0x070000,0x070FFF,'SN'), (0x074000,0x0747FF,'SC'),
    (0x076000,0x0767FF,'SL'), (0x078000,0x078FFF,'SO'), (0x07A000,0x07A7FF,'SZ'), (0x07C000,0x07CFFF,'SD'),
    (0x080000,0x080FFF,'TZ'), (0x084000,0x084FFF,'TD'), (0x088000,0x088FFF,'TG'), (0x08A000,0x08AFFF,'ZM'),
    (0x08C000,0x08CFFF,'CD'), (0x090000,0x090FFF,'AO'), (0x094000,0x0947FF,'BJ'), (0x096000,0x0967FF,'CV'),
    (0x098000,0x0987FF,'DJ'), (0x09A000,0x09AFFF,'GM'), (0x09C000,0x09CFFF,'BF'), (0x09E000,0x09E7FF,'ST'),
    (0x0A0000,0x0A7FFF,'DZ'), (0x0A8000,0x0A8FFF,'BS'), (0x0AA000,0x0AA7FF,'BB'), (0x0AB000,0x0AB7FF,'BZ'),
    (0x0AC000,0x0ADFFF,'CO'), (0x0AE000,0x0AEFFF,'CR'), (0x0B0000,0x0B0FFF,'CU'), (0x0B2000,0x0B2FFF,'SV'),
    (0x0B4000,0x0B4FFF,'GT'), (0x0B6000,0x0B6FFF,'GY'), (0x0B8000,0x0B8FFF,'HT'), (0x0BA000,0x0BAFFF,'HN'),
    (0x0BC000,0x0BC7FF,'VC'), (0x0BE000,0x0BEFFF,'JM'), (0x0C0000,0x0C0FFF,'NI'), (0x0C2000,0x0C2FFF,'PA'),
    (0x0C4000,0x0C4FFF,'DO'), (0x0C6000,0x0C6FFF,'TT'), (0x0C8000,0x0C8FFF,'SR'), (0x0CA000,0x0CA7FF,'AG'),
    (0x0CC000,0x0CC7FF,'GD'), (0x0D0000,0x0D7FFF,'MX'), (0x0D8000,0x0DFFFF,'VE'), (0x100000,0x1FFFFF,'RU'),
    (0x201000,0x2017FF,'NA'), (0x202000,0x2027FF,'ER'), (0x300000,0x33FFFF,'IT'), (0x340000,0x37FFFF,'ES'),
    (0x380000,0x3BFFFF,'FR'), (0x3C0000,0x3FFFFF,'DE'), (0x400000,0x4001BF,'BM'), (0x400000,0x43FFFF,'GB'),
    (0x4001C0,0x4001FF,'KY'), (0x400300,0x4003FF,'TC'), (0x424135,0x4241F2,'KY'), (0x424200,0x4246FF,'BM'),
    (0x424700,0x424899,'KY'), (0x424B00,0x424BFF,'IM'), (0x43BE00,0x43BEFF,'BM'), (0x43E700,0x43EAFD,'IM'),
    (0x43EAFE,0x43EEFF,'GG'), (0x440000,0x447FFF,'AT'), (0x448000,0x44FFFF,'BE'), (0x450000,0x457FFF,'BG'),
    (0x458000,0x45FFFF,'DK'), (0x460000,0x467FFF,'FI'), (0x468000,0x46FFFF,'GR'), (0x470000,0x477FFF,'HU'),
    (0x478000,0x47FFFF,'NO'), (0x480000,0x487FFF,'NL'), (0x488000,0x48FFFF,'PL'), (0x490000,0x497FFF,'PT'),
    (0x498000,0x49FFFF,'CZ'), (0x4A0000,0x4A7FFF,'RO'), (0x4A8000,0x4AFFFF,'SE'), (0x4B0000,0x4B7FFF,'CH'),
    (0x4B8000,0x4BFFFF,'TR'), (0x4C0000,0x4C7FFF,'RS'), (0x4C8000,0x4C87FF,'CY'), (0x4CA000,0x4CAFFF,'IE'),
    (0x4CC000,0x4CCFFF,'IS'), (0x4D0000,0x4D07FF,'LU'), (0x4D2000,0x4D27FF,'MT'), (0x4D4000,0x4D47FF,'MC'),
    (0x500000,0x5007FF,'SM'), (0x501000,0x5017FF,'AL'), (0x501800,0x501FFF,'HR'), (0x502800,0x502FFF,'LV'),
    (0x503800,0x503FFF,'LT'), (0x504800,0x504FFF,'MD'), (0x505800,0x505FFF,'SK'), (0x506800,0x506FFF,'SI'),
    (0x507800,0x507FFF,'UZ'), (0x508000,0x50FFFF,'UA'), (0x510000,0x5107FF,'BY'), (0x511000,0x5117FF,'EE'),
    (0x512000,0x5127FF,'MK'), (0x513000,0x5137FF,'BA'), (0x514000,0x5147FF,'GE'), (0x515000,0x5157FF,'TJ'),
    (0x516000,0x5167FF,'ME'), (0x600000,0x6007FF,'AM'), (0x600800,0x600FFF,'AZ'), (0x601000,0x6017FF,'KG'),
    (0x601800,0x601FFF,'TM'), (0x680000,0x6807FF,'BT'), (0x681000,0x6817FF,'FM'), (0x682000,0x6827FF,'MN'),
    (0x683000,0x6837FF,'KZ'), (0x684000,0x6847FF,'PW'), (0x700000,0x700FFF,'AF'), (0x702000,0x702FFF,'BD'),
    (0x704000,0x704FFF,'MM'), (0x706000,0x706FFF,'KW'), (0x708000,0x708FFF,'LA'), (0x70A000,0x70AFFF,'NP'),
    (0x70C000,0x70C7FF,'OM'), (0x70E000,0x70EFFF,'KH'), (0x710000,0x717FFF,'SA'), (0x718000,0x71FFFF,'KR'),
    (0x720000,0x727FFF,'KP'), (0x728000,0x72FFFF,'IQ'), (0x730000,0x737FFF,'IR'), (0x738000,0x73FFFF,'IL'),
    (0x740000,0x747FFF,'JO'), (0x748000,0x74FFFF,'LB'), (0x750000,0x757FFF,'MY'), (0x758000,0x75FFFF,'PH'),
    (0x760000,0x767FFF,'PK'), (0x768000,0x76FFFF,'SG'), (0x770000,0x777FFF,'LK'), (0x778000,0x77FFFF,'SY'),
    (0x780000,0x7BFFFF,'CN'), (0x789000,0x789FFF,'HK'), (0x7C0000,0x7FFFFF,'AU'), (0x800000,0x83FFFF,'IN'),
    (0x840000,0x87FFFF,'JP'), (0x880000,0x887FFF,'TH'), (0x888000,0x88FFFF,'VN'), (0x890000,0x890FFF,'YE'),
    (0x894000,0x894FFF,'BH'), (0x895000,0x8957FF,'BN'), (0x896000,0x896FFF,'AE'), (0x897000,0x8977FF,'SB'),
    (0x898000,0x898FFF,'PG'), (0x899000,0x8997FF,'TW'), (0x8A0000,0x8A7FFF,'ID'), (0x900000,0x9007FF,'MH'),
    (0x901000,0x9017FF,'SK'), (0x902000,0x9027FF,'WS'), (0xA00000,0xAFFFFF,'US'), (0xC00000,0xC3FFFF,'CA'),
    (0xC80000,0xC87FFF,'NZ'), (0xC88000,0xC88FFF,'FJ'), (0xC8A000,0xC8A7FF,'NR'), (0xC8C000,0xC8C7FF,'LC'),
    (0xC8D000,0xC8D7FF,'TO'), (0xC8E000,0xC8E7FF,'KI'), (0xC90000,0xC907FF,'VU'), (0xC91000,0xC917FF,'AD'),
    (0xC92000,0xC927FF,'DM'), (0xC93000,0xC937FF,'KN'), (0xC94000,0xC947FF,'SS'), (0xC95000,0xC957FF,'TL'),
    (0xC97000,0xC977FF,'TV'), (0xE00000,0xE3FFFF,'AR'), (0xE40000,0xE7FFFF,'BR'), (0xE80000,0xE80FFF,'CL'),
    (0xE84000,0xE84FFF,'EC'), (0xE88000,0xE88FFF,'PY'), (0xE8C000,0xE8CFFF,'PE'), (0xE90000,0xE90FFF,'UY'),
    (0xE94000,0xE94FFF,'BO'),
]

# Prefisso di immatricolazione -> ISO2 (ordine: piu' specifici prima).
REG_PREFIX_COUNTRY = {
    "MM": "IT", "I-": "IT", "F-": "FR", "D-": "DE", "G-": "GB",
    "EC-": "ES", "PH-": "NL", "OO-": "BE", "HB-": "CH", "OE-": "AT",
    "OK-": "CZ", "OM-": "SK", "SP-": "PL", "HA-": "HU", "YR-": "RO",
    "LZ-": "BG", "9A-": "HR", "S5-": "SI", "YU-": "RS", "Z3-": "MK",
    "T7-": "SM", "3A-": "MC", "9H-": "MT", "5B-": "CY", "TC-": "TR",
    "4X-": "IL", "SU-": "EG", "5A-": "LY", "CN-": "MA", "7T-": "DZ",
    "TS-": "TN", "JY-": "JO", "OD-": "LB", "YK-": "SY", "EP-": "IR",
    "A6-": "AE", "A7-": "QA", "9K-": "KW", "VT-": "IN", "AP-": "PK",
    "JA-": "JP", "HL-": "KR", "HS-": "TH", "VN-": "VN",
    "9V-": "SG", "PK-": "ID", "9M-": "MY", "RP-": "PH", "ZK-": "NZ",
    "VH-": "AU", "C-": "CA", "XA-": "MX", "XB-": "MX", "XC-": "MX",
    "PT-": "BR", "PR-": "BR", "PP-": "BR", "LV-": "AR", "CC-": "CL",
    "HK-": "CO", "OB-": "PE", "YV-": "VE", "TI-": "CR", "TG-": "GT",
    "HR-": "HN", "YS-": "SV", "YN-": "NI", "HP-": "PA", "CU-": "CU",
    "HI-": "DO", "V2-": "AG", "8P-": "BB", "J3-": "GD", "9Y-": "TT",
    "PJ-": "SX", "B-": "CN", "N": "US",
}

# Prefisso di callsign (soprattutto militare/stato) -> ISO2.
CALLSIGN_PREFIX_COUNTRY = {
    "IAM": "IT", "RCH": "US", "CNV": "US", "CTM": "FR", "PLF": "PL",
    "GAF": "DE", "BAF": "BE", "HUAF": "HU", "ROF": "RO", "CZE": "CZ",
    "RRR": "GB", "RRS": "GB", "NATO": "ZZ",
}


def country_from_hex(hx):
    try:
        v = int(hx, 16)
    except (TypeError, ValueError):
        return None
    for s, e, cc in ICAO_RANGES:
        if s <= v <= e:
            return cc
    return None


def country_from_reg(reg):
    if not reg:
        return None
    r = reg.strip().upper()
    for pfx, cc in REG_PREFIX_COUNTRY.items():
        if r.startswith(pfx):
            return cc
    return None


def country_from_callsign(cs):
    if not cs:
        return None
    c = cs.strip().upper()
    for pfx, cc in CALLSIGN_PREFIX_COUNTRY.items():
        if c.startswith(pfx):
            return cc
    return None


def resolve_country(hx, reg, callsign):
    """ISO 3166-1 alpha-2, o 'ZZ' se non determinabile. hex (blocco ICAO)
    e' la fonte primaria; reg e callsign i fallback."""
    return (country_from_hex(hx)
            or country_from_reg(reg)
            or country_from_callsign(callsign)
            or "ZZ")


def operator_from_callsign(cs):
    """Codice compagnia ICAO (3 lettere) se il callsign e' in stile linea."""
    c = (cs or "").strip().upper()
    return c[:3] if AIRLINE_CS_RE.match(c) else None



def load_priors_override(script_dir: str) -> None:
    global MIL_HEX_PREFIXES, MIL_CS_RE, ISR_TYPES
    path = os.path.join(script_dir, "priors.json")
    if not os.path.exists(path):
        return
    try:
        with open(path, "r", encoding="utf-8") as f:
            d = json.load(f)
        if isinstance(d.get("mil_hex_prefixes"), list):
            MIL_HEX_PREFIXES = tuple(str(p).lower() for p in d["mil_hex_prefixes"])
        if isinstance(d.get("mil_callsign_regex"), str):
            MIL_CS_RE = re.compile(d["mil_callsign_regex"], re.I)
        if isinstance(d.get("isr_types"), list):
            ISR_TYPES = {str(t).upper() for t in d["isr_types"]}
        print(f"[INFO] priors.json caricato ({path})")
    except Exception as e:
        print(f"[WARN] priors.json non valido: {e}", file=sys.stderr)


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
    dbflags: int = 0
    is_mil: bool = False


@dataclass
class TP:
    """Punto di traccia con timestamp (epoch wall-clock del campionamento)."""
    lat: float
    lon: float
    alt: Optional[int]
    gs: Optional[float]
    t: float


# ---------------------------
# Utility numeriche / geo
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
    a = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def angle_diff_deg(a: float, b: float) -> float:
    d = abs(a - b) % 360.0
    return d if d <= 180.0 else 360.0 - d


def ad180(a: float, b: float) -> float:
    """Differenza angolare fra prue considerate mod 180 (rotta e reciproca uguali)."""
    d = abs(a - b) % 180.0
    return min(d, 180.0 - d)


def to_local_xy(pts: List[Tuple[float, float]], ref: Tuple[float, float]) -> List[Tuple[float, float]]:
    """Proiezione equirettangolare in km attorno a ref: x = est, y = nord."""
    lat0, lon0 = ref
    k = math.cos(math.radians(lat0))
    return [((lon - lon0) * 111.320 * k, (lat - lat0) * 110.574) for (lat, lon) in pts]


def path_len_km(xy: List[Tuple[float, float]]) -> float:
    return sum(math.hypot(xy[i + 1][0] - xy[i][0], xy[i + 1][1] - xy[i][1])
              for i in range(len(xy) - 1))


def pca_axes(xy: List[Tuple[float, float]]) -> Tuple[float, float, float, Tuple[float, float]]:
    """Ritorna (major, minor, orient_deg, centro) da PCA della nuvola di punti (km).
    major/minor ~ 2*sigma lungo gli assi principali (dimensione caratteristica)."""
    n = len(xy)
    mx = sum(p[0] for p in xy) / n
    my = sum(p[1] for p in xy) / n
    sxx = sum((p[0] - mx) ** 2 for p in xy) / n
    syy = sum((p[1] - my) ** 2 for p in xy) / n
    sxy = sum((p[0] - mx) * (p[1] - my) for p in xy) / n
    tr = sxx + syy
    det = sxx * syy - sxy * sxy
    disc = max(0.0, tr * tr / 4.0 - det)
    l1 = tr / 2.0 + math.sqrt(disc)
    l2 = tr / 2.0 - math.sqrt(disc)
    major = 2.0 * math.sqrt(max(l1, 0.0))
    minor = 2.0 * math.sqrt(max(l2, 0.0))
    ang = 0.5 * math.atan2(2 * sxy, sxx - syy)
    return major, minor, math.degrees(ang), (mx, my)


def turning_total_deg(xy: List[Tuple[float, float]]) -> float:
    """Somma dei valori assoluti dei cambi di prua lungo il percorso.
    Per n giri di un circuito chiuso ~ n*360."""
    total = 0.0
    prev_h = None
    for i in range(len(xy) - 1):
        dx = xy[i + 1][0] - xy[i][0]
        dy = xy[i + 1][1] - xy[i][1]
        if dx == 0 and dy == 0:
            continue
        h = math.degrees(math.atan2(dx, dy))
        if prev_h is not None:
            d = (h - prev_h + 180.0) % 360.0 - 180.0
            total += abs(d)
        prev_h = h
    return total


def leg_headings_mod180(xy: List[Tuple[float, float]]) -> List[float]:
    out = []
    for i in range(len(xy) - 1):
        dx = xy[i + 1][0] - xy[i][0]
        dy = xy[i + 1][1] - xy[i][1]
        if abs(dx) + abs(dy) < 1e-6:
            continue
        out.append(math.degrees(math.atan2(dx, dy)) % 180.0)
    return out


# ---------------------------
# Poligono
# ---------------------------
def point_in_ring(point, ring) -> bool:
    x, y = point[1], point[0]
    inside = False
    n = len(ring)
    for i in range(n):
        yi, xi = ring[i][0], ring[i][1]
        yj, xj = ring[(i + 1) % n][0], ring[(i + 1) % n][1]
        if ((yi > y) != (yj > y)) and (x < (xj - xi) * (y - yi) / (yj - yi + 1e-12) + xi):
            inside = not inside
    return inside


def point_in_polygon(point, polygon) -> bool:
    if not polygon:
        return False
    if not point_in_ring(point, polygon[0]):
        return False
    for hole in polygon[1:]:
        if point_in_ring(point, hole):
            return False
    return True


def in_any_polygon(lat, lon, polygons) -> bool:
    if lat is None or lon is None:
        return False
    pt = (lat, lon)
    return any(point_in_polygon(pt, poly) for poly in polygons)


def load_polygons_from_geojson(path: str):
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
# Rate limiting + fetch
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
        delta = time.time() - last
        if delta < 1.05:
            time.sleep(1.05 - delta)
        f.seek(0)
        f.truncate()
        f.write(str(time.time()))
        f.flush()
        fcntl.flock(f, fcntl.LOCK_UN)


def fetch_tile(lat: float, lon: float, rng_nm: int) -> Optional[List[dict]]:
    """Lista aeromobili della tile; [] se vuota, None se il fetch e' fallito."""
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
    print(f"[WARN] Fetch fallito {url} - {last_exc}", file=sys.stderr)
    return None


# ---------------------------
# Aeroporti
# ---------------------------
def load_airports(json_path: str) -> List[dict]:
    with open(json_path, "r", encoding="utf-8") as f:
        return json.load(f)


def nearest_airport(lat, lon, airports):
    min_dist = float("inf")
    nearest = None
    for apt in airports:
        d = haversine_km((lat, lon), (apt["lat"], apt["lon"]))
        if d < min_dist:
            min_dist = d
            nearest = apt
    return nearest, min_dist


# ---------------------------
# Priori
# ---------------------------
def prior_score(ac: Aircraft) -> Tuple[bool, float, List[str]]:
    prior = 0.0
    tags: List[str] = []
    is_mil = False
    df = ac.dbflags or 0
    if df & 1:
        is_mil = True
        prior += 0.35
        tags.append("mil(dbFlags)")
    if df & 2:
        prior += 0.10
        tags.append("notevole(dbFlags)")
    hx = (ac.hex or "").lower()
    if hx.startswith(MIL_HEX_PREFIXES):
        is_mil = True
        prior += 0.20
        tags.append("hex-mil")
    cs = (ac.flight or "").upper()
    if cs and MIL_CS_RE.match(cs):
        prior += 0.25
        tags.append("callsign-mil")
    mt = (ac.model_t or "").upper()
    if mt and mt in ISR_TYPES:
        prior += 0.30
        tags.append(f"tipo-ISR({mt})")
    return is_mil, min(prior, 0.75), tags


# ---------------------------
# Cinematica del segmento
# ---------------------------
def kinematics_score(seg: List[TP]) -> Tuple[float, List[str], Optional[float], Optional[float], float]:
    gss = [p.gs for p in seg if p.gs is not None]
    alts = [p.alt for p in seg if p.alt is not None]
    dur_s = seg[-1].t - seg[0].t if len(seg) >= 2 else 0.0
    kin = 0.0
    tags: List[str] = []
    mean_gs = sum(gss) / len(gss) if gss else None
    if mean_gs is not None:
        if 120 <= mean_gs <= 320:
            kin += 0.20
            tags.append("gs-orbita")
        elif 90 <= mean_gs < 120:
            kin += 0.10
            tags.append("gs-lento")
    alt_std = None
    if len(alts) >= 3:
        m = sum(alts) / len(alts)
        alt_std = (sum((a - m) ** 2 for a in alts) / len(alts)) ** 0.5
        if alt_std < 400:
            kin += 0.18
            tags.append("quota-bloccata")
        if m > 40000:
            kin += 0.15
            tags.append("quota-alta")
    if dur_s > 90 * 60:
        kin += 0.25
        tags.append("on-station>90m")
    elif dur_s > 45 * 60:
        kin += 0.15
        tags.append("on-station>45m")
    return kin, tags, mean_gs, alt_std, dur_s


# ---------------------------
# Classificatori di pattern (operano su un SEGMENTO di TP)
# ---------------------------
def _xy_of(seg: List[TP]) -> Tuple[List[Tuple[float, float]], Tuple[float, float]]:
    pts = [(p.lat, p.lon) for p in seg]
    ref = (sum(p[0] for p in pts) / len(pts), sum(p[1] for p in pts) / len(pts))
    return to_local_xy(pts, ref), ref


def classify_orbit(seg: List[TP], min_points=12, min_minutes=8.0, min_laps_turn=1.6):
    """Orbite chiuse ripetute: racetrack tipici ISR o cerchi.
    Ritorna (subtype, laps, geom_conf, extent_km, dur_s) o None."""
    if len(seg) < min_points:
        return None
    dur_s = seg[-1].t - seg[0].t
    if dur_s < min_minutes * 60:
        return None
    xy, _ = _xy_of(seg)
    major, minor, _, c = pca_axes(xy)
    if major < 2.0:            # estensione troppo piccola: holding stretto / rumore
        return None
    plen = path_len_km(xy)
    compact = plen / (2.0 * major + 1e-6)   # percorso >> dimensione => sta girando
    if compact < 1.8:
        return None
    laps_turn = turning_total_deg(xy) / 360.0
    if laps_turn < min_laps_turn:
        return None
    maxd = max(math.hypot(p[0] - c[0], p[1] - c[1]) for p in xy)
    if maxd > 2.2 * major:     # esce troppo dalla regione: non e' contenuto
        return None
    ratio = minor / (major + 1e-6)
    subtype = "ORBITA" if ratio > 0.6 else "RACETRACK"
    laps = max(1, round(laps_turn))
    geom = min(1.0, 0.35 + 0.15 * min(laps_turn, 4.0) + 0.10 * min(max(compact - 1.8, 0.0), 2.0))
    return (subtype, laps, geom, 2.0 * major, dur_s)


def classify_lawnmower(seg: List[TP], min_points=16, min_km=6.0, tol=18.0, min_legs=4):
    """Survey a gambe parallele che spazzano un'area (tagliaerba).
    Ritorna (subtype, geom_conf, extent_km, dur_s) o None."""
    if len(seg) < min_points:
        return None
    xy, _ = _xy_of(seg)
    heads = leg_headings_mod180(xy)
    if len(heads) < min_legs * 2:
        return None
    bins = [0] * 36
    for h in heads:
        bins[int(h // 5) % 36] += 1
    dom = max(range(36), key=lambda b: bins[b]) * 5 + 2.5
    aligned = [h for h in heads if ad180(h, dom) <= tol]
    if len(aligned) < 0.55 * len(heads):
        return None
    lr = math.radians(dom)
    lx, ly = math.sin(lr), math.cos(lr)      # lungo-gamba
    px, py = math.cos(lr), -math.sin(lr)     # perpendicolare
    along = [p[0] * lx + p[1] * ly for p in xy]
    perp = [p[0] * px + p[1] * py for p in xy]
    along_span = max(along) - min(along)
    perp_span = max(perp) - min(perp)
    if along_span < min_km or perp_span < 2.0 or perp_span < 0.12 * along_span:
        return None
    legs = 0
    sign = 0
    for i in range(1, len(along)):
        d = along[i] - along[i - 1]
        s = 1 if d > 0.05 else (-1 if d < -0.05 else 0)
        if s and s != sign:
            if sign != 0:
                legs += 1
            sign = s
    if legs < min_legs:
        return None
    geom = min(1.0, 0.35 + 0.07 * min(legs, 8) + 0.10 * min(perp_span / max(along_span, 1e-6) * 3.0, 1.0))
    return ("TAGLIAERBA", geom, max(along_span, perp_span), seg[-1].t - seg[0].t)


def _seg_cross(a, b, c, d) -> bool:
    """True se il segmento a-b interseca c-d (intersezione propria)."""
    def o(p, q, r):
        return (r[1] - p[1]) * (q[0] - p[0]) - (q[1] - p[1]) * (r[0] - p[0])
    d1, d2 = o(c, d, a), o(c, d, b)
    d3, d4 = o(a, b, c), o(a, b, d)
    return (d1 > 0) != (d2 > 0) and (d3 > 0) != (d4 > 0)


def self_intersections(xy: List[Tuple[float, float]], cap: int = 260) -> int:
    """Quante volte la polilinea della traccia incrocia se stessa. Un raster/
    mesh vero si auto-incrocia molte volte; una partenza/avvicinamento no."""
    n = len(xy)
    if n > cap:
        step = n // cap + 1
        xy = xy[::step]
        n = len(xy)
    cnt = 0
    for i in range(n - 1):
        a, b = xy[i], xy[i + 1]
        for j in range(i + 2, n - 1):
            if i == 0 and j == n - 2:
                continue
            if _seg_cross(a, b, xy[j], xy[j + 1]):
                cnt += 1
    return cnt


def classify_grid(seg: List[TP], min_points=30, min_km=6.0, tol=15.0, min_selfx=None):
    """Reticolato: due direzioni ~perpendicolari CHE COPRONO un'area 2D e con la
    traccia che si AUTO-INCROCIA (non il ventaglio di SID/STAR su pista).
    Ritorna (subtype, geom_conf, extent_km, dur_s) o None."""
    if min_selfx is None:
        min_selfx = GRID_MIN_SELFX
    if len(seg) < min_points:
        return None
    xy, _ = _xy_of(seg)
    heads = leg_headings_mod180(xy)
    if len(heads) < min_points * 0.6:
        return None
    bins = [0] * 36
    for h in heads:
        bins[int(h // 5) % 36] += 1
    a_dir = max(range(36), key=lambda b: bins[b]) * 5 + 2.5
    perp_c = (a_dir + 90.0) % 180.0
    b_bin = max(range(36), key=lambda b: (bins[b] if ad180(b * 5 + 2.5, perp_c) <= tol else -1))
    b_dir = b_bin * 5 + 2.5
    fa = sum(1 for h in heads if ad180(h, a_dir) <= tol) / len(heads)
    fb = sum(1 for h in heads if ad180(h, b_dir) <= tol) / len(heads)
    if fa < 0.30 or fb < 0.20 or (fa + fb) < 0.65:
        return None
    ar = math.radians(a_dir)
    br = math.radians(b_dir)
    ax, ay = math.sin(ar), math.cos(ar)
    bx, by = math.sin(br), math.cos(br)
    pa = [p[0] * ax + p[1] * ay for p in xy]
    pb = [p[0] * bx + p[1] * by for p in xy]
    span_a, span_b = max(pa) - min(pa), max(pb) - min(pb)
    if span_a < min_km or span_b < min_km:
        return None
    cells = {(int(va // 2.0), int(vb // 2.0)) for va, vb in zip(pa, pb)}
    if len(cells) < 12:
        return None
    if len({c[0] for c in cells}) < 3 or len({c[1] for c in cells}) < 3:
        return None
    sx = self_intersections(xy)
    if sx < min_selfx:
        return None
    geom = min(1.0, 0.28 + 0.02 * min(len(cells), 20) + 0.015 * min(sx, 16)
               + 0.08 * min((fa + fb - 0.65) * 3.0, 1.0))
    return ("RETICOLATO", geom, max(span_a, span_b), seg[-1].t - seg[0].t)


def classify_pattern(seg: List[TP]):
    """Sceglie il pattern con confidenza geometrica migliore.
    Ritorna (subtype, laps|None, geom_conf, extent_km, dur_s) o None."""
    cands = []
    o = classify_orbit(seg)
    if o:
        cands.append((o[0], o[1], o[2], o[3], o[4]))
    lw = classify_lawnmower(seg)
    if lw:
        cands.append((lw[0], None, lw[1], lw[2], lw[3]))
    g = classify_grid(seg)
    if g:
        cands.append((g[0], None, g[1], g[2], g[3]))
    if not cands:
        return None
    return max(cands, key=lambda c: c[2])


# ---------------------------
# Discriminante "traffico ordinario" (holding ATC / vettoramento)
# ---------------------------
def alt_trend(seg: List[TP]) -> Tuple[float, float]:
    """Regressione lineare della quota nel tempo. Ritorna (pendenza in ft/min,
    delta totale ft = fine - inizio)."""
    pts = [(p.t, p.alt) for p in seg if p.alt is not None]
    if len(pts) < 3:
        return 0.0, 0.0
    t0 = pts[0][0]
    xs = [p[0] - t0 for p in pts]
    ys = [float(p[1]) for p in pts]
    n = len(xs)
    mx = sum(xs) / n
    my = sum(ys) / n
    den = sum((x - mx) ** 2 for x in xs)
    if den <= 0:
        return 0.0, ys[-1] - ys[0]
    slope = sum((xs[i] - mx) * (ys[i] - my) for i in range(n)) / den  # ft/s
    return slope * 60.0, ys[-1] - ys[0]


def leg_spacing_cv(seg: List[TP]) -> Optional[float]:
    """Coefficiente di variazione della spaziatura fra gambe parallele.
    Un raster vero ha spaziatura regolare (cv basso); un vettoramento no.
    None se non calcolabile (< 3 gambe distinte)."""
    xy, _ = _xy_of(seg)
    heads = leg_headings_mod180(xy)
    if len(heads) < 6:
        return None
    bins = [0] * 36
    for h in heads:
        bins[int(h // 5) % 36] += 1
    dom = math.radians(max(range(36), key=lambda b: bins[b]) * 5 + 2.5)
    lx, ly = math.sin(dom), math.cos(dom)      # lungo-gamba
    px, py = math.cos(dom), -math.sin(dom)     # perpendicolare
    along = [p[0] * lx + p[1] * ly for p in xy]
    perp = [p[0] * px + p[1] * py for p in xy]
    offsets: List[float] = []
    run = [perp[0]]
    sign = 0
    for i in range(1, len(along)):
        d = along[i] - along[i - 1]
        s = 1 if d > 0.03 else (-1 if d < -0.03 else 0)
        if s and sign and s != sign:
            if len(run) >= 3:
                offsets.append(sum(run) / len(run))
            run = []
        if s:
            sign = s
        run.append(perp[i])
    if len(run) >= 3:
        offsets.append(sum(run) / len(run))
    if len(offsets) < 3:
        return None
    offsets.sort()
    gaps = [b - a for a, b in zip(offsets, offsets[1:]) if b - a > 0.15]
    if len(gaps) < 2:
        return None
    m = sum(gaps) / len(gaps)
    if m <= 0:
        return None
    sd = (sum((g - m) ** 2 for g in gaps) / len(gaps)) ** 0.5
    return sd / m


def mundane_traffic_penalty(seg: List[TP], ac: "Aircraft", res) -> Tuple[float, bool, List[str]]:
    """Stima quanto un pattern rilevato somigli a traffico ordinario
    (attesa ATC / vettoramento) invece che a sorveglianza deliberata.
    Ritorna (penalita' 0..1, reject_bool, tag). reject NON e' definitivo:
    in main un prior ISR/militare forte lo scavalca."""
    subtype, _laps, _geom, extent_km, _dur = res
    tags: List[str] = []
    score = 0.0

    cs = (ac.flight or "").upper()
    is_airline_cs = bool(AIRLINE_CS_RE.match(cs)) and not (MIL_CS_RE.match(cs) if cs else None)
    is_airline_type = (ac.model_t or "").upper() in AIRLINER_TYPES
    slope_fpm, dalt = alt_trend(seg)
    gss = [p.gs for p in seg if p.gs is not None]
    mean_gs = sum(gss) / len(gss) if gss else None

    if subtype in ("ORBITA", "RACETRACK"):
        if 4.0 <= extent_km <= 18.0:
            score += 0.35
            tags.append("racetrack-compatto")
        if is_airline_cs:
            score += 0.25
            tags.append("callsign-compagnia")
        if is_airline_type:
            score += 0.20
            tags.append("tipo-airliner")
        # calo netto di quota durante il pattern: un'orbita ISR tiene la quota,
        # un aereo in attesa scende (stack step-down). Conta il delta, non la pendenza.
        if dalt < -900 and slope_fpm < 120:
            score += 0.30
            tags.append(f"in discesa {int(dalt)} ft")
        if mean_gs is not None:
            if 160 <= mean_gs <= 290:
                score += 0.10
            elif mean_gs < 150:
                score -= 0.20
                tags.append("gs-lenta")
    elif subtype in ("RETICOLATO", "TAGLIAERBA"):
        if is_airline_cs:
            score += 0.30
            tags.append("callsign-compagnia")
        if is_airline_type:
            score += 0.25
            tags.append("tipo-airliner")
        if dalt < -900:      # i survey volano livellati
            score += 0.35
            tags.append(f"in discesa {int(dalt)} ft")
        cv = leg_spacing_cv(seg)
        if cv is not None:
            if cv > 0.55:
                score += 0.35
                tags.append(f"spaziatura irregolare cv={cv:.2f}")
            elif cv < 0.30:
                score -= 0.15
                tags.append("spaziatura regolare")

    score = max(0.0, min(1.0, score))
    return score, score >= 0.65, tags


# ---------------------------
# Prossimita' / formazione
# ---------------------------
def heading_xy(p1: Tuple[float, float], p2: Tuple[float, float]) -> Optional[float]:
    dy = p2[0] - p1[0]
    dx = p2[1] - p1[1]
    if dx == 0 and dy == 0:
        return None
    return math.degrees(math.atan2(dx, dy)) % 360.0


def approx_following(p_lead, h_lead, p_trail, h_trail, tol_deg) -> bool:
    if h_lead is None or h_trail is None:
        return False
    if angle_diff_deg(h_lead, h_trail) > tol_deg:
        return False
    bt = heading_xy(p_lead, p_trail)
    if bt is None:
        return False
    return angle_diff_deg((h_lead + 180.0) % 360.0, bt) <= tol_deg


# ---------------------------
# Anomalie
# ---------------------------
EMERGENCY_SQUAWKS = {"7500", "7600", "7700"}


def anomaly_checks(ac: Aircraft, seg: List[TP]) -> List[Tuple[str, str, float]]:
    """Ritorna lista di (subtype, note, base_conf)."""
    out = []
    is_ground = ac.ground is True or (
        ac.alt_baro is not None and ac.alt_baro <= 100 and (ac.gs is None or ac.gs < 60)
    )
    if is_ground:
        return out
    sq = (ac.squawk or "").strip()
    if sq in EMERGENCY_SQUAWKS:
        out.append((f"SQUAWK-{sq}", f"Squawk di emergenza {sq}", 0.95))
    alts = [p.alt for p in seg if p.alt is not None]
    if len(alts) >= 3 and sum(alts) / len(alts) > 45000 and (ac.gs is None or ac.gs > 150):
        out.append(("QUOTA-ALTA", f"Quota sostenuta ~{int(sum(alts)/len(alts))} ft", 0.55))
    return out


# ---------------------------
# Database
# ---------------------------
NEW_COLUMNS = [
    ("last_seen_utc", "TEXT"),
    ("is_mil", "INTEGER DEFAULT 0"),
    ("subtype", "TEXT"),
    ("confidence", "REAL"),
    ("laps", "INTEGER"),
    ("duration_s", "INTEGER"),
    ("updates", "INTEGER DEFAULT 1"),
    ("near_airport", "INTEGER DEFAULT 0"),
    ("country", "TEXT"),
    ("operator", "TEXT"),
]

INDEXES = [
    "CREATE INDEX IF NOT EXISTS idx_events_first_seen ON events(first_seen_utc)",
    "CREATE INDEX IF NOT EXISTS idx_events_last_seen  ON events(last_seen_utc)",
    "CREATE INDEX IF NOT EXISTS idx_events_type_seen  ON events(event_type, first_seen_utc)",
    "CREATE INDEX IF NOT EXISTS idx_events_subtype    ON events(subtype)",
    "CREATE INDEX IF NOT EXISTS idx_events_hex        ON events(hex)",
    "CREATE INDEX IF NOT EXISTS idx_events_callsign   ON events(callsign)",
    "CREATE INDEX IF NOT EXISTS idx_events_reg        ON events(reg)",
    "CREATE INDEX IF NOT EXISTS idx_events_squawk     ON events(squawk)",
    "CREATE INDEX IF NOT EXISTS idx_events_model      ON events(model_t)",
    "CREATE INDEX IF NOT EXISTS idx_events_mil        ON events(is_mil)",
    "CREATE INDEX IF NOT EXISTS idx_events_conf       ON events(confidence)",
    "CREATE INDEX IF NOT EXISTS idx_events_country    ON events(country)",
    "CREATE INDEX IF NOT EXISTS idx_events_operator   ON events(operator)",
]


def init_db():
    conn = sqlite3.connect(DB_FILE, timeout=15.0)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA synchronous=NORMAL")
    conn.execute("PRAGMA busy_timeout=15000")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_seen_utc TEXT NOT NULL,
            last_seen_utc TEXT,
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
            is_mil INTEGER DEFAULT 0,
            event_type TEXT,
            subtype TEXT,
            note TEXT,
            confidence REAL,
            laps INTEGER,
            duration_s INTEGER,
            updates INTEGER DEFAULT 1,
            track_points TEXT,
            near_airport INTEGER DEFAULT 0,
            country TEXT,
            operator TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    # Colonne aggiunte in versioni successive: idempotenti su DB preesistenti.
    for name, decl in NEW_COLUMNS:
        try:
            conn.execute(f"ALTER TABLE events ADD COLUMN {name} {decl}")
        except sqlite3.OperationalError:
            pass
    for ddl in INDEXES:
        conn.execute(ddl)
    conn.commit()
    return conn


def track_json(seg: List[TP]) -> str:
    return json.dumps([
        {"lat": round(p.lat, 5), "lon": round(p.lon, 5),
         "alt": p.alt, "gs": p.gs, "t": int(p.t)}
        for p in seg
    ])


def episode_upsert(conn, episodes: dict, key, now_ts: float, now_str: str,
                   ac: Aircraft, event_type: str, subtype: str, note: str,
                   confidence: float, laps: Optional[int], seg: List[TP],
                   near_flag: int, episode_gap_s: float) -> Tuple[int, bool]:
    dur = int(seg[-1].t - seg[0].t) if len(seg) >= 2 else 0
    tj = track_json(seg)
    country = resolve_country(ac.hex, ac.reg, ac.flight)
    operator = operator_from_callsign(ac.flight)
    ep = episodes.get(key)
    if ep and now_ts - ep["last_ts"] <= episode_gap_s:
        conn.execute("""
            UPDATE events SET last_seen_utc=?, lat=?, lon=?, alt_baro=?, gs=?, squawk=?,
                   callsign=?, reg=?, model_t=?, is_mil=?, note=?, confidence=?, laps=?,
                   duration_s=?, updates=updates+1, track_points=?, near_airport=?,
                   country=?, operator=?
            WHERE id=?
        """, (now_str, ac.lat, ac.lon, ac.alt_baro, ac.gs, ac.squawk,
              ac.flight, ac.reg, ac.model_t, 1 if ac.is_mil else 0, note, confidence,
              laps, dur, tj, near_flag, country, operator, ep["id"]))
        conn.commit()
        ep["last_ts"] = now_ts
        return ep["id"], False
    cur = conn.execute("""
        INSERT INTO events (first_seen_utc, last_seen_utc, hex, callsign, reg, model_t,
               lat, lon, alt_baro, gs, squawk, ground, is_mil, event_type, subtype, note,
               confidence, laps, duration_s, updates, track_points, near_airport,
               country, operator)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)
    """, (now_str, now_str, ac.hex, ac.flight, ac.reg, ac.model_t,
          ac.lat, ac.lon, ac.alt_baro, ac.gs, ac.squawk, 1 if ac.ground else 0,
          1 if ac.is_mil else 0, event_type, subtype, note, confidence, laps, dur,
          tj, near_flag, country, operator))
    conn.commit()
    eid = cur.lastrowid
    episodes[key] = {"id": eid, "first_ts": now_ts, "last_ts": now_ts}
    return eid, True


# ---------------------------
# Parsing aeromobile
# ---------------------------
def parse_aircraft(raw: dict) -> Optional[Aircraft]:
    try:
        df = raw.get("dbFlags")
        df = int(df) if df is not None else 0
    except (TypeError, ValueError):
        df = 0
    try:
        return Aircraft(
            hex=(raw.get("hex") or "").lower(),
            flight=(raw.get("flight") or "").strip(),
            lat=safe_float(raw.get("lat")),
            lon=safe_float(raw.get("lon")),
            alt_baro=safe_int(raw.get("alt_baro")),
            gs=safe_float(raw.get("gs")),
            ts=safe_float(raw.get("seen_pos_timestamp") or raw.get("seen_pos") or raw.get("seen")),
            reg=(raw.get("r") or raw.get("reg") or "").strip() or None,
            squawk=str(raw.get("squawk")).strip() if raw.get("squawk") else None,
            ground=safe_bool(raw.get("ground")),
            model_t=(raw.get("t") or None),
            dbflags=df,
        )
    except Exception:
        return None


# ---------------------------
# Selftest offline
# ---------------------------
def _synth(gen, n, dt=30.0, alt=25000, gs=200.0):
    t0 = time.time()
    return [TP(lat, lon, alt, gs, t0 + i * dt) for i, (lat, lon) in enumerate(gen(n))]


def selftest() -> int:
    def racetrack(n):
        # 2 gambe da ~18 km orientate E-W + semicerchi ai capi, ~3 giri
        pts = []
        cx, cy = 43.0, 11.0
        for i in range(n):
            phase = (i / n) * 3 * 2 * math.pi
            # parametrizzazione stadio: lungo x, semicerchi in y
            u = (phase % (2 * math.pi))
            if u < math.pi:
                x = -0.11 + (0.22) * (u / math.pi)
                y = 0.03 * math.sin(u)
            else:
                v = u - math.pi
                x = 0.11 - (0.22) * (v / math.pi)
                y = -0.03 * math.sin(v)
            pts.append((cx + y, cy + x))
        return pts

    def circle(n):
        cx, cy = 43.0, 11.0
        return [(cx + 0.09 * math.cos(3 * 2 * math.pi * i / n),
                 cy + 0.11 * math.sin(3 * 2 * math.pi * i / n)) for i in range(n)]

    def lawn(n):
        # 6 gambe N-S lunghe ~14 km, spaziate ~2 km in E
        pts = []
        legs = 6
        per = n // legs
        for L in range(legs):
            lonoff = L * 0.025
            for k in range(per):
                frac = k / per
                lat = 43.0 + (0.13 * frac if L % 2 == 0 else 0.13 * (1 - frac))
                pts.append((lat, 11.0 + lonoff))
        while len(pts) < n:
            pts.append(pts[-1])
        return pts

    def grid(n):
        # copertura area, a serpentina (boustrophedon): 5 linee E-W poi 5 N-S,
        # ogni linea invertita rispetto alla precedente -> niente falso loop
        pts = []
        m = max(4, n // 20)
        for L in range(5):
            latL = 43.0 + L * 0.03
            rng = range(m) if L % 2 == 0 else range(m - 1, -1, -1)
            for k in rng:
                pts.append((latL, 11.0 + 0.15 * (k / m)))
        for C in range(5):
            lonC = 11.0 + C * 0.03
            rng = range(m) if C % 2 == 0 else range(m - 1, -1, -1)
            for k in rng:
                pts.append((43.0 + 0.15 * (k / m), lonC))
        while len(pts) < n:
            pts.append(pts[-1])
        return pts

    def line(n):
        return [(43.0 + 0.006 * i, 11.0 + 0.006 * i) for i in range(n)]

    checks = []
    o = classify_orbit(_synth(racetrack, 90))
    checks.append(("racetrack->RACETRACK", o is not None and o[0] == "RACETRACK"))
    o = classify_orbit(_synth(circle, 90))
    checks.append(("circle->ORBITA", o is not None and o[0] == "ORBITA"))
    lw = classify_lawnmower(_synth(lawn, 120))
    checks.append(("lawn->TAGLIAERBA", lw is not None))
    g = classify_grid(_synth(grid, 200))
    checks.append(("grid->RETICOLATO", g is not None))
    checks.append(("line->nulla", classify_pattern(_synth(line, 90)) is None))
    # auto-incroci: la griglia ne ha molti, una L (base+finale) no
    gxy, _ = _xy_of(_synth(grid, 200))
    checks.append(("self-intersections griglia >= 5", self_intersections(gxy) >= 5))
    Lshape = [(43.0, 11.0 + 0.002 * i) for i in range(40)] + [(43.0 + 0.002 * i, 11.08) for i in range(40)]
    checks.append(("L-shape non e' RETICOLATO",
                   classify_grid(_synth(lambda n: Lshape, 80)) is None))
    checks.append(("line non-orbita", classify_orbit(_synth(line, 90)) is None))
    # priori
    ac = Aircraft("ae1234", "FORTE11", 43, 11, 55000, 300, time.time(),
                  model_t="RQ4", dbflags=1)
    mil, pr, tg = prior_score(ac)
    checks.append(("prior RQ4/FORTE/mil", mil and pr >= 0.7))
    ac2 = Aircraft("3c1234", "DLH123", 43, 11, 36000, 450, time.time(), model_t="A320")
    _, pr2, _ = prior_score(ac2)
    checks.append(("prior civ ~0", pr2 == 0.0))

    # --- nazionalita' / operatore ---
    checks.append(("country hex IT", resolve_country("30a1b2", None, None) == "IT"))
    checks.append(("country hex DE", resolve_country("3c48aa", None, None) == "DE"))
    checks.append(("country hex US", resolve_country("a1b2c3", None, None) == "US"))
    checks.append(("country reg fallback", resolve_country("ffffff", "I-ABCD", None) == "IT"))
    checks.append(("country ignoto -> ZZ", resolve_country("ffffff", None, None) == "ZZ"))
    checks.append(("operator RYR", operator_from_callsign("RYR86FZ") == "RYR"))
    checks.append(("operator GA -> None", operator_from_callsign("N12345") is None))

    # --- discriminante holding / traffico ordinario ---
    def _seg(gen, n, dt=30.0, alt0=25000, alt1=None, gs=200.0):
        if alt1 is None:
            alt1 = alt0
        t0 = time.time()
        pts = gen(n)
        return [TP(la, lo, int(alt0 + (alt1 - alt0) * i / max(1, len(pts) - 1)), gs, t0 + i * dt)
                for i, (la, lo) in enumerate(pts)]

    def small_racetrack(n):
        pts = []
        cx, cy = 43.0, 11.0
        for i in range(n):
            u = ((i / n) * 3 * 2 * math.pi) % (2 * math.pi)
            if u < math.pi:
                x = -0.055 + 0.11 * (u / math.pi); y = 0.018 * math.sin(u)
            else:
                v = u - math.pi; x = 0.055 - 0.11 * (v / math.pi); y = -0.018 * math.sin(v)
            pts.append((cx + y, cy + x))
        return pts

    # A: racetrack compatto in discesa, callsign+tipo di linea -> scartato
    s = _seg(small_racetrack, 90, alt0=15000, alt1=11000, gs=230)
    ac = Aircraft("3c6789", "RYR1234", 43, 11, 13000, 230, time.time(), model_t="B738")
    r = classify_pattern(s)
    _p, rej, _t = mundane_traffic_penalty(s, ac, r) if r else (0, False, [])
    checks.append(("holding: RYR B738 racetrack compatto in discesa -> reject",
                   r is not None and rej is True))

    # B: racetrack ampio livellato, militare/ISR -> NON scartato
    s = _seg(racetrack, 90, alt0=45000, gs=380)
    ac = Aircraft("ae9999", "FORTE22", 43, 11, 45000, 380, time.time(), model_t="RQ4")
    r = classify_pattern(s)
    _p, rej, _t = mundane_traffic_penalty(s, ac, r) if r else (0, False, [])
    checks.append(("holding: FORTE/RQ4 racetrack ampio livellato -> non reject",
                   r is not None and rej is False))

    # C: survey a spaziatura regolare livellato (C208) -> penalita' bassa
    s = _seg(lawn, 120, alt0=8000, alt1=8000, gs=120)
    ac = Aircraft("440abc", "", 43, 11, 8000, 120, time.time(), model_t="C208")
    r = classify_pattern(s)
    p, rej, _t = mundane_traffic_penalty(s, ac, r) if r else (1, True, [])
    checks.append(("holding: survey C208 spaziatura regolare -> preservato",
                   r is not None and rej is False and p < 0.25))

    # D: "griglia" in discesa con callsign di linea -> scartata
    s = _seg(grid, 200, alt0=20000, alt1=15000, gs=250)
    ac = Aircraft("3c1111", "EZY55AB", 43, 11, 17000, 250, time.time(), model_t="A20N")
    r = classify_pattern(s)
    _p, rej, _t = mundane_traffic_penalty(s, ac, r) if r else (0, False, [])
    checks.append(("holding: EZY/A20N griglia in discesa -> reject",
                   r is not None and rej is True))

    ok = True
    for name, res in checks:
        print(f"  [{'PASS' if res else 'FAIL'}] {name}")
        ok = ok and res
    print("SELFTEST:", "OK" if ok else "FALLITO")
    return 0 if ok else 1


# ---------------------------
# Main
# ---------------------------
def build_argparser() -> argparse.ArgumentParser:
    ap = argparse.ArgumentParser(description="Monitor ADS-B con poligono")
    ap.add_argument("--interval", type=int, default=60)
    ap.add_argument("--polygons-file")
    ap.add_argument("--min-confidence", type=float, default=0.25,
                    help="soglia minima per registrare un PATTERN")
    ap.add_argument("--hold-reject", type=float, default=0.65,
                    help="penalita' 'traffico ordinario' oltre la quale il PATTERN "
                         "viene scartato (a meno di prior ISR/militare forte). 1.0 = mai")
    ap.add_argument("--grid-min-selfx", type=int, default=5,
                    help="auto-incroci minimi della traccia per un RETICOLATO")
    ap.add_argument("--segment-gap-s", type=float, default=600.0,
                    help="buco temporale che apre un nuovo segmento di traccia")
    ap.add_argument("--episode-gap-s", type=float, default=1200.0,
                    help="silenzio oltre il quale un episodio si considera chiuso")
    ap.add_argument("--track-maxlen", type=int, default=300)
    ap.add_argument("--prune-idle-s", type=float, default=1800.0)
    ap.add_argument("--proximity-km", type=float, default=2.5)
    ap.add_argument("--prox_angle_deg", type=float, default=15.0)
    ap.add_argument("--prox_alt_diff_ft", type=float, default=400.0)
    ap.add_argument("--prox_gs_diff_kt", type=float, default=35.0)
    ap.add_argument("--prox-streak", type=int, default=2,
                    help="cicli consecutivi di prossimita' prima di registrare")
    ap.add_argument("--prox-cooldown", type=int, default=600)
    ap.add_argument("--selftest", action="store_true")
    return ap


def main():
    args, _unknown = build_argparser().parse_known_args()
    global GRID_MIN_SELFX
    GRID_MIN_SELFX = args.grid_min_selfx
    if args.selftest:
        sys.exit(selftest())
    if not args.polygons_file:
        print("ERRORE: --polygons-file obbligatorio.", file=sys.stderr)
        sys.exit(2)

    script_dir = os.path.dirname(os.path.abspath(__file__))
    load_priors_override(script_dir)
    polygons = load_polygons_from_geojson(args.polygons_file)
    airports_path = os.path.join(script_dir, "airports.json")
    airports = load_airports(airports_path) if os.path.exists(airports_path) else []

    conn = init_db()

    tracks: Dict[str, deque] = defaultdict(lambda: deque(maxlen=args.track_maxlen))
    last_pt_ts: Dict[str, float] = {}
    episodes: dict = {}
    prox_streak: Dict[frozenset, int] = defaultdict(int)
    last_prox_alert: Dict[tuple, float] = {}

    print(f"Monitor avviato (min-confidence={args.min_confidence}). Ctrl+C per fermare.")
    while True:
        t0 = time.time()
        tiles_raw = [fetch_tile(lat, lon, rng) for (lat, lon, rng) in TILES]
        cycle_degraded = any(t is None for t in tiles_raw)
        if cycle_degraded:
            nf = sum(1 for t in tiles_raw if t is None)
            print(f"[WARN] Ciclo degradato: {nf}/{len(TILES)} tile mancanti; pattern saltati.",
                  file=sys.stderr)
        all_raw = [a for t in tiles_raw if t for a in t]

        aircraft: List[Aircraft] = []
        for raw in all_raw:
            a = parse_aircraft(raw)
            if a and a.lat is not None and a.lon is not None:
                aircraft.append(a)
        if polygons:
            aircraft = [ac for ac in aircraft if in_any_polygon(ac.lat, ac.lon, polygons)]

        now_ts = time.time()
        now_str = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
        seen_now = set()

        # --- aggiorna tracce (con segmentazione sui buchi) ---
        for ac in aircraft:
            seen_now.add(ac.hex)
            prev_t = last_pt_ts.get(ac.hex)
            if prev_t is not None and now_ts - prev_t > args.segment_gap_s:
                tracks[ac.hex].clear()
            tracks[ac.hex].append(TP(ac.lat, ac.lon, ac.alt_baro, ac.gs, now_ts))
            last_pt_ts[ac.hex] = now_ts

        # --- potatura tracce inattive ---
        for hx in [h for h, t in last_pt_ts.items() if now_ts - t > args.prune_idle_s]:
            tracks.pop(hx, None)
            last_pt_ts.pop(hx, None)

        # --- chiusura episodi silenti ---
        episodes = {k: v for k, v in episodes.items() if now_ts - v["last_ts"] <= args.episode_gap_s}

        # --- PATTERN ---
        if not cycle_degraded:
            for ac in aircraft:
                seg = list(tracks[ac.hex])
                if len(seg) < 12:
                    continue
                res = classify_pattern(seg)
                if not res:
                    continue
                subtype, laps, geom, extent_km, dur_s = res
                ac.is_mil, prior, ptags = prior_score(ac)
                kin, ktags, mean_gs, alt_std, _ = kinematics_score(seg)

                # Discriminante traffico ordinario (attesa ATC / vettoramento).
                # Un prior ISR/militare forte scavalca lo scarto.
                hold_pen, hold_reject, htags = mundane_traffic_penalty(seg, ac, res)
                strong_isr = ac.is_mil or prior >= 0.30
                if hold_reject and not strong_isr and hold_pen >= args.hold_reject:
                    continue

                # Area terminale: RETICOLATO/TAGLIAERBA a bassa quota vicino a uno
                # scalo = ventaglio di partenze/avvicinamenti su pista, non un survey.
                apt, dkm = (nearest_airport(ac.lat, ac.lon, airports) if airports else (None, 1e9))
                if subtype in ("RETICOLATO", "TAGLIAERBA") and not strong_isr and apt:
                    seg_min_alt = min((p.alt for p in seg if p.alt is not None), default=99999)
                    term_km = max(apt.get("exclusion_km", 0), 45.0)
                    if dkm < term_km and seg_min_alt < 4500:
                        htags.append(f"area-terminale {apt.get('icao', '')}")
                        continue

                confidence = max(0.0, min(1.0, 0.45 * geom + kin + prior - hold_pen * 0.40))

                near_flag = 0
                if apt and dkm < apt.get("exclusion_km", 0):
                    near_flag = 1
                    # vicino allo scalo: registra solo se militare o confidenza alta
                    if not ac.is_mil and confidence < 0.5:
                        continue
                if confidence < args.min_confidence:
                    continue

                bits = [f"conf={confidence:.2f}"]
                if laps:
                    bits.append(f"{laps} giri")
                bits.append(f"~{extent_km:.0f} km")
                bits.append(f"{int(dur_s // 60)} min")
                if ptags:
                    bits.append("prior:" + "/".join(ptags))
                if ktags:
                    bits.append("kin:" + "/".join(ktags))
                if htags:
                    bits.append("holding?:" + "/".join(htags))
                note = f"{subtype}; " + "; ".join(bits)
                key = (ac.hex, "PATTERN", subtype)
                _eid, is_new = episode_upsert(conn, episodes, key, now_ts, now_str, ac,
                                              "PATTERN", subtype, note, confidence, laps,
                                              seg, near_flag, args.episode_gap_s)
                if is_new:
                    print(f"PATTERN {subtype} {ac.hex} {ac.flight} conf={confidence:.2f} "
                          f"(mil={ac.is_mil} near={near_flag})")

        # --- PROSSIMITA' ---
        cur_head: Dict[str, Optional[float]] = {}
        for ac in aircraft:
            th = tracks[ac.hex]
            if len(th) >= 2:
                cur_head[ac.hex] = heading_xy((th[-2].lat, th[-2].lon), (th[-1].lat, th[-1].lon))
            else:
                cur_head[ac.hex] = None

        pairs_now = set()
        for i, ac1 in enumerate(aircraft):
            for j in range(i + 1, len(aircraft)):
                ac2 = aircraft[j]
                if ac1.hex == ac2.hex:
                    continue
                dist = haversine_km((ac1.lat, ac1.lon), (ac2.lat, ac2.lon))
                if dist >= args.proximity_km:
                    continue
                alt_ok = (ac1.alt_baro is not None and ac2.alt_baro is not None and
                          abs(ac1.alt_baro - ac2.alt_baro) <= args.prox_alt_diff_ft)
                gs_ok = (ac1.gs is not None and ac2.gs is not None and
                         abs(ac1.gs - ac2.gs) <= args.prox_gs_diff_kt)
                h1, h2 = cur_head.get(ac1.hex), cur_head.get(ac2.hex)
                dir_ok = (h1 is not None and h2 is not None and angle_diff_deg(h1, h2) <= args.prox_angle_deg)
                if not (alt_ok and gs_ok and dir_ok):
                    continue
                label = "CLUSTER"
                if approx_following((ac1.lat, ac1.lon), h1, (ac2.lat, ac2.lon), h2, args.prox_angle_deg) or \
                   approx_following((ac2.lat, ac2.lon), h2, (ac1.lat, ac1.lon), h1, args.prox_angle_deg):
                    label = "INSEGUIMENTO"
                pkey = frozenset((ac1.hex, ac2.hex))
                pairs_now.add(pkey)
                prox_streak[pkey] += 1
                if prox_streak[pkey] < args.prox_streak:
                    continue
                ckey = (pkey, label)
                if now_ts - last_prox_alert.get(ckey, 0) < args.prox_cooldown:
                    continue

                lead, trail = ac1, ac2
                near_flag = 0
                if airports:
                    apt, dkm = nearest_airport(lead.lat, lead.lon, airports)
                    if apt and dkm < apt.get("exclusion_km", 0):
                        near_flag = 1
                mil_pair = 0
                m1, _, _ = prior_score(ac1)
                m2, _, _ = prior_score(ac2)
                lead.is_mil = m1 or m2
                mil_pair = 1 if (m1 or m2) else 0
                conf = 0.45 + (0.25 if mil_pair else 0.0) + (0.15 if label == "INSEGUIMENTO" else 0.0)
                note = (f"{label}; peer={trail.hex} {trail.flight or ''}; dist={dist:.1f} km; "
                        f"conf={conf:.2f}" + ("; mil" if mil_pair else ""))
                seg = list(tracks[ac1.hex]) or [TP(ac1.lat, ac1.lon, ac1.alt_baro, ac1.gs, now_ts)]
                key = (pkey, "PROX", label)
                _eid, is_new = episode_upsert(conn, episodes, key, now_ts, now_str, lead,
                                              "PROX", label, note, conf, None, seg,
                                              near_flag, args.episode_gap_s)
                last_prox_alert[ckey] = now_ts
                if is_new:
                    print(f"PROX {label} {ac1.hex}/{ac2.hex} dist={dist:.1f} conf={conf:.2f}")

        # streak azzerato per le coppie non piu' vicine
        for pk in [p for p in prox_streak if p not in pairs_now]:
            prox_streak.pop(pk, None)

        # --- ANOMALIE ---
        for ac in aircraft:
            seg = list(tracks[ac.hex])
            for subtype, note_txt, base_conf in anomaly_checks(ac, seg):
                near_flag = 0
                if airports:
                    apt, dkm = nearest_airport(ac.lat, ac.lon, airports)
                    if apt and dkm < apt.get("exclusion_km", 0) and not subtype.startswith("SQUAWK"):
                        near_flag = 1
                        if ac.alt_baro is not None and ac.alt_baro < 5000:
                            continue
                ac.is_mil, prior, ptags = prior_score(ac)
                conf = max(0.0, min(1.0, base_conf + 0.15 * (1 if ac.is_mil else 0)))
                note = note_txt + (f"; prior:{'/'.join(ptags)}" if ptags else "")
                key = (ac.hex, "ANOMALY", subtype)
                _eid, is_new = episode_upsert(conn, episodes, key, now_ts, now_str, ac,
                                              "ANOMALY", subtype, note, conf, None,
                                              seg or [TP(ac.lat, ac.lon, ac.alt_baro, ac.gs, now_ts)],
                                              near_flag, args.episode_gap_s)
                if is_new:
                    print(f"ANOMALY {subtype} {ac.hex}: {note_txt} conf={conf:.2f}")

        elapsed = time.time() - t0
        time.sleep(max(1, int(round(args.interval - elapsed))))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("Monitor arrestato.")
