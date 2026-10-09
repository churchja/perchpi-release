#!/usr/bin/env python3
"""
detection_flatline_watchdog.py

Periodic check (every 15 min via cron) for PerchPi pipeline health.
Catches the silent-failure modes that systemd's "active (running)" status
won't detect.

Three checks, any one triggers an alert:

  CHECK_1 (db_write_path_broken)
    Daylight only. If analyzer log shows scores above CONFIDENCE threshold
    in the last N minutes but the DB max(Date||Time) is older than M minutes,
    something is broken between analysis and insert. This was 2026-05-21's
    bug (perchpi:reporting-insert-columns-v1).

  CHECK_2 (analyzer_wedged)
    24/7. If no analyzer log lines in the last 10 minutes, the analyzer
    process is wedged even though systemd thinks it's alive.

  CHECK_3 (audio_pipeline_dead)
    24/7. If no new files in StreamData/ in the last 5 minutes, the
    recording process or USB mic is broken.

OUTPUTS
  /home/birder/BirdNET-Pi/watchdog/state.json   — current health snapshot
  /home/birder/BirdNET-Pi/watchdog/alerts.log   — append-only alert history

SUPPRESSION
  4-hour re-fire window per failure mode. Once a check fires, it won't
  re-alert for the same mode until either (a) the mode clears and re-fires,
  or (b) 4 hours pass.

DAYLIGHT GATING
  CHECK_1 only fires during civil-twilight-bracketed daylight, computed
  from lat/lon in birdnet.conf using a self-contained sunrise/sunset
  algorithm (no external deps). At night birds are quiet enough that a
  legitimate flatline is biology, not a bug.

TIMEZONE CONVENTION
  This file uses naive local-time `datetime.now()` throughout — by design,
  not by oversight. The DB-staleness check parses `detections.Date+Time`
  (LOCAL under UTC-migration Option B) and compares against `datetime.now()`.
  State-file timestamps follow the same convention: naive local in, naive
  local out. Do NOT introduce timezone-aware writes here without first
  migrating `detections.Date+Time` to UTC — otherwise the subtractions in
  `check_db_write_path()` and `should_fire()` will raise TypeError (audit
  Finding 1b, marker perchpi:watchdog-tz-doc-v1).

EXIT
  Always returns 0. Cron should fail open — if the watchdog itself errors,
  log it and move on; don't add another monitoring layer.
"""

import json
import math
import os
import re
import sqlite3
import subprocess
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

# ---- Paths and tunables ---------------------------------------------------

BIRDNET_HOME = Path('/home/birder/BirdNET-Pi')
DB_PATH = BIRDNET_HOME / 'scripts' / 'birds.db'
BIRDNET_CONF = Path('/etc/birdnet/birdnet.conf')
STREAMDATA_DIR = Path('/home/birder/BirdSongs/StreamData')

WATCHDOG_DIR = BIRDNET_HOME / 'watchdog'
STATE_FILE = WATCHDOG_DIR / 'state.json'
ALERTS_LOG = WATCHDOG_DIR / 'alerts.log'

# Suppression window per failure mode
REFIRE_WINDOW_HOURS = 4

# CHECK_1: stale DB threshold during daylight
DB_STALE_MINUTES = 30  # if DB max-time older than this AND scores in log -> alert
SCORES_LOOKBACK_MINUTES = 30  # window to scan analyzer log for scores

# CHECK_2: analyzer must produce log entries within this window
ANALYZER_QUIET_MINUTES = 10

# CHECK_3: StreamData must have files modified within this window
AUDIO_STALE_MINUTES = 5

# Marker for traceability (matches your patcher discipline)
MARKER = 'perchpi:detection-flatline-watchdog-v1'


# ---- Sunrise/sunset (NOAA algorithm, no deps) ------------------------------

def sun_times_utc(year: int, month: int, day: int, lat: float, lon: float):
    """Return (sunrise_utc, sunset_utc) as datetime objects.

    Uses the official NOAA sunrise equation. Civil-twilight version
    (zenith=96°) — gives an extra ~30 min buffer at each end vs official
    sunrise, which matches when birds actually start/stop singing.

    Returns None for both if the sun never rises/sets that day (polar latitudes).
    Indiana never hits that, but the guard keeps the function general.
    """
    zenith = 96.0  # civil twilight; use 90.833 for official sunrise/sunset
    rad = math.radians
    deg = math.degrees

    # Day of year
    n = (datetime(year, month, day) - datetime(year, 1, 1)).days + 1

    results = []
    for is_sunrise in (True, False):
        # Approx time of event in fractional hours
        lng_hour = lon / 15.0
        t = n + ((6.0 - lng_hour) / 24.0) if is_sunrise else n + ((18.0 - lng_hour) / 24.0)

        # Sun's mean anomaly
        M = (0.9856 * t) - 3.289

        # Sun's true longitude
        L = M + (1.916 * math.sin(rad(M))) + (0.020 * math.sin(rad(2 * M))) + 282.634
        L = L % 360

        # Right ascension
        RA = deg(math.atan(0.91764 * math.tan(rad(L)))) % 360

        # RA needs to be in same quadrant as L
        L_quadrant = math.floor(L / 90) * 90
        RA_quadrant = math.floor(RA / 90) * 90
        RA = RA + (L_quadrant - RA_quadrant)
        RA = RA / 15.0  # convert to hours

        # Sun's declination
        sin_dec = 0.39782 * math.sin(rad(L))
        cos_dec = math.cos(math.asin(sin_dec))

        # Sun's local hour angle
        cos_h = (math.cos(rad(zenith)) - (sin_dec * math.sin(rad(lat)))) / \
                (cos_dec * math.cos(rad(lat)))
        if cos_h > 1 or cos_h < -1:
            return (None, None)  # sun never rises or never sets

        H = 360 - deg(math.acos(cos_h)) if is_sunrise else deg(math.acos(cos_h))
        H = H / 15.0

        # Local mean time
        T = H + RA - (0.06571 * t) - 6.622

        # UTC time
        UT = (T - lng_hour) % 24

        hours = int(UT)
        minutes = int((UT - hours) * 60)
        seconds = int(((UT - hours) * 60 - minutes) * 60)

        # Some days the math gives 24:xx for sunrise edge cases — normalize
        if hours >= 24:
            hours -= 24
            dt = datetime(year, month, day, hours, minutes, seconds, tzinfo=timezone.utc) + timedelta(days=1)
        else:
            dt = datetime(year, month, day, hours, minutes, seconds, tzinfo=timezone.utc)
        results.append(dt)

    # perchpi:watchdog-sunset-utc-fix-v1
    # Sunset west of Greenwich can fall on the next UTC date. If the
    # computed sunset (results[1]) is earlier than sunrise (results[0]),
    # the algorithm wrapped; shift sunset forward by 24h.
    rise_dt, set_dt = results
    if set_dt < rise_dt:
        # perchpi:watchdog-timedelta-scope-fix-v1 (use module-level timedelta; local re-import caused F823)
        set_dt = set_dt + timedelta(days=1)
    return (rise_dt, set_dt)


def get_lat_lon():
    """Read LATITUDE / LONGITUDE from birdnet.conf."""
    lat = lon = None
    try:
        with open(BIRDNET_CONF) as f:
            for line in f:
                line = line.strip()
                if line.startswith('LATITUDE='):
                    lat = float(line.split('=', 1)[1].strip().strip('"\''))
                elif line.startswith('LONGITUDE='):
                    lon = float(line.split('=', 1)[1].strip().strip('"\''))
    except (OSError, ValueError):
        pass
    return lat, lon


def is_daylight_now() -> bool:
    """Return True if current UTC time is between civil sunrise and civil sunset."""
    lat, lon = get_lat_lon()
    if lat is None or lon is None:
        # Fail open — if we can't determine daylight, assume daylight
        # (preferable to silently suppressing alerts)
        return True
    now = datetime.now(timezone.utc)
    rise, set_ = sun_times_utc(now.year, now.month, now.day, lat, lon)
    if rise is None or set_ is None:
        return True
    return rise <= now <= set_


# ---- The three checks ------------------------------------------------------

def check_audio_pipeline() -> dict:
    """CHECK_3: StreamData/ must have files modified within AUDIO_STALE_MINUTES."""
    try:
        if not STREAMDATA_DIR.is_dir():
            return {'ok': False, 'reason': f'StreamData dir missing: {STREAMDATA_DIR}'}
        newest_mtime = max(
            (f.stat().st_mtime for f in STREAMDATA_DIR.iterdir() if f.is_file()),
            default=0,
        )
        age_min = (time.time() - newest_mtime) / 60.0
        if newest_mtime == 0:
            return {'ok': False, 'reason': 'StreamData/ empty', 'newest_age_min': None}
        if age_min > AUDIO_STALE_MINUTES:
            return {'ok': False,
                    'reason': f'Newest audio file is {age_min:.1f} min old (threshold {AUDIO_STALE_MINUTES})',
                    'newest_age_min': round(age_min, 1)}
        return {'ok': True, 'newest_age_min': round(age_min, 1)}
    except Exception as e:
        return {'ok': True, 'error': f'check skipped: {e}'}


def check_analyzer_alive() -> dict:
    """CHECK_2: birdnet_analysis.service log must have entries in last ANALYZER_QUIET_MINUTES."""
    try:
        since = f'{ANALYZER_QUIET_MINUTES + 1} minutes ago'
        out = subprocess.run(
            ['journalctl', '-u', 'birdnet_analysis.service',
             '--since', since, '--no-pager', '-q'],
            capture_output=True, text=True, timeout=30,
        )
        if out.returncode != 0:
            return {'ok': True, 'error': f'journalctl rc={out.returncode}: {out.stderr.strip()[:200]}'}
        lines = [ln for ln in out.stdout.splitlines() if ln.strip()]
        if not lines:
            return {'ok': False,
                    'reason': f'No analyzer log entries in last {ANALYZER_QUIET_MINUTES} min',
                    'recent_lines': 0}
        return {'ok': True, 'recent_lines': len(lines)}
    except Exception as e:
        return {'ok': True, 'error': f'check skipped: {e}'}


def get_confidence_threshold() -> float:
    try:
        with open(BIRDNET_CONF) as f:
            for line in f:
                line = line.strip()
                if line.startswith('CONFIDENCE='):
                    return float(line.split('=', 1)[1].strip().strip('"\''))
    except (OSError, ValueError):
        pass
    return 0.5  # safe default


def check_db_write_path() -> dict:
    """CHECK_1: if daylight + scores logged + stale DB -> write path broken.

    Daylight-gated to avoid false positives at night when birds are quiet.
    """
    daylight = is_daylight_now()
    if not daylight:
        return {'ok': True, 'skipped': 'night'}

    # Max(Date||Time) from DB
    try:
        con = sqlite3.connect(f'file:{DB_PATH}?mode=ro', uri=True, timeout=5)
        cur = con.execute("SELECT MAX(Date||' '||Time) FROM detections WHERE Date >= date('now','-1 day','localtime');")
        row = cur.fetchone()
        con.close()
        db_max = row[0] if row else None
    except Exception as e:
        return {'ok': True, 'error': f'db query failed: {e}'}

    if not db_max:
        # No detections in last 24h. Could be legitimate (new install, very quiet).
        # Defer to CHECK_2/CHECK_3 to catch process/audio issues.
        return {'ok': True, 'note': 'no detections in last 24h (deferred)'}

    # Parse db_max as local time
    try:
        db_dt = datetime.strptime(db_max, '%Y-%m-%d %H:%M:%S')
    except ValueError:
        return {'ok': True, 'error': f'unparseable db_max: {db_max!r}'}

    db_age_min = (datetime.now() - db_dt).total_seconds() / 60.0

    if db_age_min <= DB_STALE_MINUTES:
        return {'ok': True, 'db_age_min': round(db_age_min, 1)}

    # DB is stale during daylight. Check if analyzer is seeing birds (scores in log).
    threshold = get_confidence_threshold()
    try:
        since = f'{SCORES_LOOKBACK_MINUTES + 1} minutes ago'
        out = subprocess.run(
            ['journalctl', '-u', 'birdnet_analysis.service',
             '--since', since, '--no-pager', '-q'],
            capture_output=True, text=True, timeout=30,
        )
        # Find all scores in lines like: "0.0;3.0-(Genus species_Common Name, 0.6543)"
        scores = [float(m.group(1)) for m in re.finditer(r',\s*([0-9]+\.[0-9]+)\)', out.stdout)]
        max_score = max(scores) if scores else 0.0
    except Exception as e:
        return {'ok': True, 'error': f'log scan failed: {e}', 'db_age_min': round(db_age_min, 1)}

    if max_score >= threshold:
        return {
            'ok': False,
            'reason': (f'DB stale {db_age_min:.0f} min during daylight, '
                       f'but analyzer logged score {max_score:.3f} >= {threshold} threshold. '
                       f'Write path likely broken (cf. perchpi:reporting-insert-columns-v1).'),
            'db_age_min': round(db_age_min, 1),
            'max_score_in_log': round(max_score, 3),
            'threshold': threshold,
        }
    else:
        return {
            'ok': True,
            'note': f'DB stale {db_age_min:.0f} min but no scores >= {threshold} in log; likely quiet period',
            'db_age_min': round(db_age_min, 1),
            'max_score_in_log': round(max_score, 3),
        }


# ---- State + suppression ---------------------------------------------------

def load_state() -> dict:
    if not STATE_FILE.exists():
        return {'last_alerts': {}, 'last_run': None, 'history': []}
    try:
        return json.loads(STATE_FILE.read_text())
    except (json.JSONDecodeError, OSError):
        return {'last_alerts': {}, 'last_run': None, 'history': []}


def save_state(state: dict) -> None:
    WATCHDOG_DIR.mkdir(exist_ok=True)
    tmp = STATE_FILE.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(state, indent=2))
    tmp.replace(STATE_FILE)


def append_alert_log(msg: str) -> None:
    WATCHDOG_DIR.mkdir(exist_ok=True)
    with open(ALERTS_LOG, 'a') as f:
        f.write(msg + '\n')


def should_fire(state: dict, mode: str) -> bool:
    last = state['last_alerts'].get(mode)
    if not last:
        return True
    try:
        last_dt = datetime.fromisoformat(last)
    except ValueError:
        return True
    return (datetime.now() - last_dt) > timedelta(hours=REFIRE_WINDOW_HOURS)


# ---- Main ------------------------------------------------------------------

def main():
    WATCHDOG_DIR.mkdir(exist_ok=True)
    state = load_state()
    now_iso = datetime.now().isoformat(timespec='seconds')

    checks = {
        'audio_pipeline': check_audio_pipeline(),
        'analyzer_alive': check_analyzer_alive(),
        'db_write_path': check_db_write_path(),
    }

    failures = {mode: result for mode, result in checks.items() if not result.get('ok', True)}

    # Fire alerts for new failures past suppression window
    for mode, result in failures.items():
        if should_fire(state, mode):
            reason = result.get('reason', 'unknown')
            msg = f'[{now_iso}] ALERT [{mode}] {reason}'
            append_alert_log(msg)
            state['last_alerts'][mode] = now_iso

    # Clear suppression for modes that have recovered
    for mode in list(state['last_alerts']):
        if mode not in failures:
            # Mode no longer failing — clear suppression so next failure re-fires immediately
            del state['last_alerts'][mode]
            append_alert_log(f'[{now_iso}] RECOVERED [{mode}]')

    # Update state snapshot
    state['last_run'] = now_iso
    state['marker'] = MARKER
    state['current'] = {
        'checks': checks,
        'failures': list(failures.keys()),
        'is_daylight': is_daylight_now(),
    }
    save_state(state)

    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as e:
        # Last-resort failsafe — log the watchdog's own failure to stderr,
        # exit 0 so cron doesn't generate noise.
        sys.stderr.write(f'watchdog internal error: {e}\n')
        sys.exit(0)
