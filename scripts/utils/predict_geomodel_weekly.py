#!/usr/bin/env python3
"""
predict_geomodel_weekly.py — PerchPi weekly geomodel prediction cache

Runs the BirdNET+ Geomodel V3.0.2 ONNX model for ALL 48 weeks at the
configured lat/lon, writes the result to predictions.json. PHP pages read
this cache at render time for instant rarity pills and phenology curves.

Output schema ($GEOMODEL_DIR/predictions.json):
{
  "metadata": {
    "lat": 12.3456,
    "lon": -65.4321,
    "computed_at": "2026-05-06T08:00:00+00:00",
    "current_week": 19,
    "geomodel_version": "v3.0.2",
    "n_species": 12012,
    "buckets": {"expected_min": 0.30, "uncommon_min": 0.05}
  },
  "species": {
    "Cardinalis cardinalis": {
      "code":    "norcar",
      "common":  "Northern Cardinal",
      "current": 0.999,
      "bucket":  "expected",
      "weeks":   [0.995, 0.998, ..., 0.994]   # 48 floats, 3 decimals
    },
    ...
  }
}

Run via systemd timer perchpi-geomodel-weekly.timer (Mon 03:00 weekly).
Logs to journald.

PerchPi marker: perchpi:geomodel-weekly-cron-v1
"""

import datetime
import json
import logging
import os
import sys
from pathlib import Path

import numpy as np
import onnxruntime as ort

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)
log = logging.getLogger("geomodel_weekly")


# ─────────────────────────────────────────────────────────────────────────
# Bucket thresholds (locked Session C decision)
# ─────────────────────────────────────────────────────────────────────────
BUCKET_EXPECTED_MIN = 0.30
BUCKET_UNCOMMON_MIN = 0.05


def read_conf(path: str) -> dict:
    """Parse a KEY=VALUE conf file (birdnet.conf style)."""
    out = {}
    if not Path(path).exists():
        return out
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, _, v = line.partition("=")
            out[k.strip()] = v.strip().strip('"').strip("'")
    return out


def birdnet_week(date: datetime.date | None = None) -> int:
    """
    Return the BirdNET-style week number 1-48 for a given date.
    BirdNET maps the year into 48 equal-length 'weeks' (vs ISO 52),
    so the geomodel input expects 1-48.
    """
    date = date or datetime.date.today()
    day_of_year = date.timetuple().tm_yday
    return min(48, max(1, ((day_of_year - 1) * 48 // 366) + 1))


def main() -> int:
    conf_path = "/etc/birdnet/birdnet.conf"
    conf = read_conf(conf_path)

    # Location
    try:
        lat = float(conf.get("LATITUDE", "0"))
        lon = float(conf.get("LONGITUDE", "0"))
    except ValueError:
        lat, lon = 0.0, 0.0
    if lat == 0.0 and lon == 0.0:
        log.warning(
            "LATITUDE/LONGITUDE not configured (or both 0). Predictions will be "
            "computed at Null Island and won't match your local birds. Set "
            "lat/lon in Settings or birdnet.conf and re-run."
        )

    # Geomodel directory
    geomodel_dir = Path(os.path.expanduser(
        conf.get("GEOMODEL_DIR", str(Path.home() / "BirdNET-Pi" / "geomodel"))
    ))
    onnx_path = geomodel_dir / "geomodel_fp16.onnx"
    labels_path = geomodel_dir / "labels.txt"
    output_path = geomodel_dir / "predictions.json"
    version_path = geomodel_dir / "VERSION"

    if not onnx_path.exists():
        log.error("Geomodel ONNX not found at %s", onnx_path)
        log.error("Run deploy-geomodel.sh (Session B) before this cron.")
        return 1
    if not labels_path.exists():
        log.error("Geomodel labels not found at %s", labels_path)
        return 1

    version = version_path.read_text().strip() if version_path.exists() else "unknown"

    # ─────────────────────────────────────────────────────────────────
    # Load ONNX
    # ─────────────────────────────────────────────────────────────────
    log.info("Loading ONNX from %s", onnx_path)
    sess_options = ort.SessionOptions()
    # Cron isn't latency-sensitive; let ORT use 2 cores for the burst.
    sess_options.intra_op_num_threads = 2
    sess = ort.InferenceSession(
        str(onnx_path),
        sess_options=sess_options,
        providers=["CPUExecutionProvider"],
    )
    input_name = sess.get_inputs()[0].name
    output_name = sess.get_outputs()[0].name
    log.info(
        "ONNX ready (input=%s, output=%s). Reading labels...",
        input_name, output_name,
    )

    # ─────────────────────────────────────────────────────────────────
    # Load labels (tab-separated: code, sci_name, common_name)
    # ─────────────────────────────────────────────────────────────────
    labels = []  # list of (code, sci_name, common_name)
    with open(labels_path, encoding="utf-8") as f:
        for raw in f:
            parts = raw.rstrip("\n").split("\t")
            if len(parts) >= 3:
                labels.append((parts[0], parts[1], parts[2]))
    n_species = len(labels)
    if n_species == 0:
        log.error("Labels file is empty: %s", labels_path)
        return 1
    log.info("Loaded %d species labels", n_species)

    # ─────────────────────────────────────────────────────────────────
    # Run inference for all 48 weeks
    # ─────────────────────────────────────────────────────────────────
    log.info(
        "Running 48-week geomodel inference at lat=%.4f lon=%.4f...",
        lat, lon,
    )
    weeks_probs = np.zeros((48, n_species), dtype=np.float32)
    started = datetime.datetime.now()
    for w in range(1, 49):
        sample = np.array([[lat, lon, float(w)]], dtype=np.float32)
        out = sess.run([output_name], {input_name: sample})[0][0]
        if out.shape[0] != n_species:
            log.error(
                "ONNX output size %d does not match labels count %d",
                out.shape[0], n_species,
            )
            return 1
        weeks_probs[w - 1] = out
    elapsed = (datetime.datetime.now() - started).total_seconds()
    log.info("Inference done in %.2fs", elapsed)

    # ─────────────────────────────────────────────────────────────────
    # Build output
    # ─────────────────────────────────────────────────────────────────
    current_week = birdnet_week()
    current_idx = current_week - 1
    log.info(
        "Current BirdNET week: %d. Bucketing species with thresholds "
        "expected≥%.2f, uncommon≥%.2f",
        current_week, BUCKET_EXPECTED_MIN, BUCKET_UNCOMMON_MIN,
    )

    species_out = {}
    n_expected = n_uncommon = n_rare = 0
    for i, (code, sci, com) in enumerate(labels):
        # Round for JSON compactness (3 decimals = ~0.001 precision, plenty
        # for visualization and bucketing)
        weeks = [round(float(weeks_probs[w][i]), 3) for w in range(48)]
        current = round(float(weeks_probs[current_idx][i]), 3)
        if current >= BUCKET_EXPECTED_MIN:
            bucket = "expected"
            n_expected += 1
        elif current >= BUCKET_UNCOMMON_MIN:
            bucket = "uncommon"
            n_uncommon += 1
        else:
            bucket = "rare"
            n_rare += 1
        species_out[sci] = {
            "code": code,
            "common": com,
            "current": current,
            "bucket": bucket,
            "weeks": weeks,
        }

    log.info(
        "Bucket distribution: expected=%d, uncommon=%d, rare=%d",
        n_expected, n_uncommon, n_rare,
    )

    output = {
        "metadata": {
            "lat": lat,
            "lon": lon,
            "computed_at": datetime.datetime.now(datetime.timezone.utc)
                .isoformat(timespec="seconds"),
            "current_week": current_week,
            "geomodel_version": version,
            "n_species": n_species,
            "buckets": {
                "expected_min": BUCKET_EXPECTED_MIN,
                "uncommon_min": BUCKET_UNCOMMON_MIN,
            },
        },
        "species": species_out,
    }

    # ─────────────────────────────────────────────────────────────────
    # Atomic write via tmp file + rename
    # ─────────────────────────────────────────────────────────────────
    tmp_path = output_path.with_suffix(".json.tmp")
    with open(tmp_path, "w", encoding="utf-8") as f:
        json.dump(output, f, separators=(",", ":"))
    tmp_path.replace(output_path)

    size_kb = output_path.stat().st_size // 1024
    log.info(
        "Wrote %s (%d KB, %d species, week %d)",
        output_path, size_kb, n_species, current_week,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
