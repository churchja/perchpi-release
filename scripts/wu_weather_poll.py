#!/usr/bin/env python3
# =============================================================================
# PerchPi — Wunderground PWS weather poller
# Marker: perchpi:wu-weather-poller-v1
#
# Reads WEATHER_SOURCE / WUNDERGROUND_API_KEY / WUNDERGROUND_STATION_ID from
# /etc/birdnet/birdnet.conf. No-ops unless WEATHER_SOURCE=pws (so Open-Meteo
# users never make WU calls). Polls WU PWS current conditions and writes a
# flat cache JSON to /var/lib/perchpi-weather/current.json for the header to read.
#
# Stdlib only (urllib, json) — runs on system python3, independent of the venv.
# Atomic write with ownership self-heal (preserve dir owner:group so a root/sudo
# run doesn't leave a root-owned cache the web user can't refresh).
# Never logs the API key.
# =============================================================================
import os, sys, json, tempfile, urllib.request, urllib.error, urllib.parse, datetime

CONF      = "/etc/birdnet/birdnet.conf"
CACHE_DIR = "/var/lib/perchpi-weather"
CACHE     = os.path.join(CACHE_DIR, "current.json")
TIMEOUT   = 15  # seconds

def read_conf():
    """Minimal KEY=VALUE parser for birdnet.conf (quoted or bare)."""
    cfg = {}
    try:
        with open(CONF, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                cfg[k.strip()] = v.strip().strip('"').strip("'")
    except OSError as e:
        print(f"[wu-weather] cannot read {CONF}: {e}", file=sys.stderr)
        sys.exit(1)
    return cfg

def atomic_write_json(path, obj):
    """Write JSON atomically, preserving the parent dir's owner:group so a
    root/sudo invocation doesn't strand a root-owned cache file."""
    d = os.path.dirname(path)
    os.makedirs(d, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=d, prefix=".weather-", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(obj, f, separators=(",", ":"))
        os.replace(tmp, path)
        # self-heal ownership: match the directory's owner:group
        try:
            dstat = os.stat(d)
            os.chown(path, dstat.st_uid, dstat.st_gid)
        except (OSError, PermissionError):
            pass  # non-fatal: best-effort only
        os.chmod(path, 0o644)
    finally:
        if os.path.exists(tmp):
            try: os.unlink(tmp)
            except OSError: pass

def write_status(status, **extra):
    """Write a cache file carrying a status (ok|disabled|error) so the header
    JS can show a subtle stale/unavailable indicator instead of frozen data."""
    obj = {"status": status, "fetchedUtc": datetime.datetime.now(datetime.timezone.utc)
           .strftime("%Y-%m-%dT%H:%M:%SZ")}
    obj.update(extra)
    atomic_write_json(CACHE, obj)

def main():
    cfg = read_conf()
    source  = cfg.get("WEATHER_SOURCE", "open-meteo")
    station = cfg.get("WUNDERGROUND_STATION_ID", "").strip()
    apikey  = cfg.get("WUNDERGROUND_API_KEY", "").strip()

    # No-op unless PWS is selected. Still write a 'disabled' marker so a stale
    # PWS cache from a previous run can't masquerade as live data.
    if source != "pws":
        write_status("disabled", reason="WEATHER_SOURCE!=pws")
        print("[wu-weather] source is not pws; wrote disabled marker")
        return 0

    if not station or not apikey:
        write_status("error", reason="missing station id or api key")
        print("[wu-weather] pws selected but station/key missing", file=sys.stderr)
        return 0  # not a hard failure; header falls back gracefully

    url = ("https://api.weather.com/v2/pws/observations/current"
           f"?stationId={urllib.parse.quote(station)}"
           "&format=json&units=e"
           f"&apiKey={urllib.parse.quote(apikey)}")
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "PerchPi-weather/1"})
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            data = json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        # 401 = bad/inactive key; 204/empty = station mismatch. Don't log the key.
        write_status("error", reason=f"http {e.code}")
        print(f"[wu-weather] HTTP {e.code} from WU API", file=sys.stderr)
        return 0
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as e:
        write_status("error", reason="fetch/parse failed")
        print(f"[wu-weather] fetch/parse error: {type(e).__name__}", file=sys.stderr)
        return 0

    obs_list = (data or {}).get("observations") or []
    if not obs_list:
        write_status("error", reason="no observations")
        print("[wu-weather] empty observations (station id mismatch or no recent upload?)", file=sys.stderr)
        return 0

    o = obs_list[0]
    imp = o.get("imperial") or {}

    def num(v):
        return v if isinstance(v, (int, float)) else None

    out = {
        "status":        "ok",
        "stationID":     o.get("stationID"),
        "neighborhood":  o.get("neighborhood"),
        "obsTimeUtc":    o.get("obsTimeUtc"),
        "obsTimeLocal":  o.get("obsTimeLocal"),
        "humidity":      num(o.get("humidity")),
        "winddir":       num(o.get("winddir")),
        "temp":          num(imp.get("temp")),
        "heatIndex":     num(imp.get("heatIndex")),
        "windChill":     num(imp.get("windChill")),
        "dewpt":         num(imp.get("dewpt")),
        "windSpeed":     num(imp.get("windSpeed")),
        "windGust":      num(imp.get("windGust")),
        "pressure":      num(imp.get("pressure")),
        "precipRate":    num(imp.get("precipRate")),
        "precipTotal":   num(imp.get("precipTotal")),
        "fetchedUtc":    datetime.datetime.now(datetime.timezone.utc)
                         .strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    atomic_write_json(CACHE, out)
    # Log without the key: just confirm a successful write + the temp.
    print(f"[wu-weather] ok: {out['stationID']} temp={out['temp']} hum={out['humidity']} "
          f"rain={out['precipTotal']} -> {CACHE}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
