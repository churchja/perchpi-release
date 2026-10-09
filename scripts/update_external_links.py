#!/usr/bin/env python3
# ────────────────────────────────────────────────────────────────────────────
# perchpi:external-links-v1
# ────────────────────────────────────────────────────────────────────────────
# Fetches the BirdNET-Cornell taxonomy CSV and extracts cross-platform IDs
# (eBird, Macaulay Library, iNaturalist, observation.org, Xeno-Canto)
# for all ~14,000 species the BirdNET model can detect.
#
# Output: ~/BirdNET-Pi/data/external_links.json
#   { "Cardinalis cardinalis": {"ebird_code": "norcar", "ml_taxon_code": "norcar",
#     "inat_id": 9091, "observationorg_id": 71337, "xc_name": "Cardinalis cardinalis"}, ... }
#
# Re-run quarterly to pick up taxonomy updates. Stale codes still work
# (eBird/iNat/etc. URLs are stable across taxonomy revisions).
#
# Usage: python3 update_external_links.py
# ────────────────────────────────────────────────────────────────────────────
import csv
import io
import json
import os
import sys
import urllib.request

API_URL = "https://birdnet.cornell.edu/taxonomy/api/download/csv"
OUT_PATH = os.path.expanduser("~/BirdNET-Pi/data/external_links.json")
WANTED_FIELDS = [
    "ebird_code",
    "ml_taxon_code",
    "inat_id",
    "observationorg_id",
    "xc_name",
]

def coerce_int(v):
    """iNat and obs IDs are integers; CSV gives strings. Empty -> None."""
    if v is None or v == "":
        return None
    try:
        return int(v)
    except (ValueError, TypeError):
        return None

def coerce_str(v):
    """eBird/ML codes and xc_name are strings. Empty -> None."""
    if v is None or v == "":
        return None
    return str(v).strip() or None

def main():
    print(f"Fetching: {API_URL}")
    req = urllib.request.Request(API_URL, headers={"User-Agent": "PerchPi/external-links-v1"})
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = resp.read().decode("utf-8")
    print(f"  Downloaded {len(raw):,} bytes")

    reader = csv.DictReader(io.StringIO(raw))
    if reader.fieldnames is None:
        sys.exit("ABORT: CSV had no header row")

    missing = [f for f in ["scientific_name"] + WANTED_FIELDS if f not in reader.fieldnames]
    if missing:
        sys.exit(f"ABORT: CSV missing expected columns: {missing}")

    result = {}
    total = 0
    with_any_id = 0

    for row in reader:
        total += 1
        sci = (row.get("scientific_name") or "").strip()
        if not sci:
            continue

        entry = {
            "ebird_code":         coerce_str(row.get("ebird_code")),
            "ml_taxon_code":      coerce_str(row.get("ml_taxon_code")),
            "inat_id":            coerce_int(row.get("inat_id")),
            "observationorg_id":  coerce_int(row.get("observationorg_id")),
            "xc_name":            coerce_str(row.get("xc_name")),
        }

        # Skip species with zero cross-link IDs — pointless to store
        if not any(entry.values()):
            continue

        result[sci] = entry
        with_any_id += 1

    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    tmp_path = OUT_PATH + ".tmp"
    with open(tmp_path, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, separators=(",", ":"), sort_keys=True)
    os.replace(tmp_path, OUT_PATH)

    out_size = os.path.getsize(OUT_PATH)
    print(f"  Total CSV rows: {total:,}")
    print(f"  Species with >=1 cross-link ID: {with_any_id:,}")
    print(f"  Species skipped (no IDs): {total - with_any_id:,}")
    print(f"  Wrote: {OUT_PATH} ({out_size:,} bytes)")

    # Sample for sanity-check
    sample_sci = "Cardinalis cardinalis"
    if sample_sci in result:
        print(f"\n  Sample — {sample_sci}:")
        print(f"    {json.dumps(result[sample_sci], indent=4)}")

if __name__ == "__main__":
    main()
