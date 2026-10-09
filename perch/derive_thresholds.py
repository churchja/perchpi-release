#!/usr/bin/env python3
# perchpi:embedding-threshold-derivation-v1
"""Derive per-species embedding thresholds from Phase 1 observational data.

Read-only against ai_decisions. Generates suggested per-species thresholds for
species_embedding_thresholds based on observed logit percentiles.

Methodology:
  - clearly_tp_logit: Conservative percentile of observed logits (default P20).
    Roughly: ~80% of historical detections for this species would be
    auto-accepted at the derived threshold. Higher percentile = tighter accept
    threshold = fewer LLM bypass cost savings but more confidence per accept.

  - clearly_fp_logit: NOT derived by default. Phase 1 has produced ~zero
    rejects, so there is no labeled FP signal to derive a per-species reject
    threshold from observed data. The script optionally derives one from the
    accept-band distribution (--fp-percentile N), but with strong cautions.
    Real per-species FP thresholds should come from labeled FP data (e.g.,
    LLM verdicts marked 'false_positive') in a future script (v2).

Output:
  - stdout: header + per-species table + commented SQL preview
  - optional --output-csv FILE: machine-readable CSV

Usage:
  python3 derive_thresholds.py                       # 14 days, P20 TP, no FP
  python3 derive_thresholds.py --days 7
  python3 derive_thresholds.py --min-n 50            # require more samples
  python3 derive_thresholds.py --tp-percentile 15    # tighter accept threshold
  python3 derive_thresholds.py --fp-percentile 2     # P2 from accept dist (CAUTION)
  python3 derive_thresholds.py --output-csv out.csv

Not for production deploy by itself. The output is a *recommendation* — the
Phase 2 deploy script handles the actual species_embedding_thresholds writes
after human review.
"""

import argparse
import csv
import sqlite3
import sys
from datetime import datetime

DB_PATH = "/home/birder/BirdNET-Pi/scripts/birds.db"


def percentile(sorted_values, p):
    """Linear-interpolated p-th percentile (p in 0-100)."""
    if not sorted_values:
        return None
    n = len(sorted_values)
    idx = (p / 100.0) * (n - 1)
    lo = int(idx)
    hi = min(lo + 1, n - 1)
    frac = idx - lo
    return sorted_values[lo] + frac * (sorted_values[hi] - sorted_values[lo])


def fmt_logit(v, width=9):
    if v is None:
        return f"{'(null)':>{width}}"
    return f"{v:>+{width}.2f}"


def main():
    parser = argparse.ArgumentParser(
        description="Derive per-species embedding thresholds"
    )
    parser.add_argument('--days', type=int, default=14,
                        help='Lookback window in days (default 14)')
    parser.add_argument('--min-n', type=int, default=20,
                        help='Minimum samples per species (default 20)')
    parser.add_argument('--tp-percentile', type=float, default=20.0,
                        help='Percentile for clearly_tp_logit (default 20)')
    parser.add_argument('--fp-percentile', type=float, default=None,
                        help='Percentile for clearly_fp_logit. Default: None. '
                             'USE WITH CAUTION: derived from accept-band, '
                             'not from labeled FPs.')
    parser.add_argument('--output-csv', type=str, default=None,
                        help='Write CSV to this file')
    args = parser.parse_args()

    conn = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    cur = conn.cursor()

    cur.execute(
        "SELECT species_sci, embedding_logit FROM ai_decisions "
        "WHERE embedding_logit IS NOT NULL "
        "AND created_at >= datetime('now', ?)",  # perchpi:utc-mig-derive-thresholds-readers-v1
        (f'-{args.days} days',)
    )
    rows = cur.fetchall()

    by_sp = {}
    for sci, logit in rows:
        by_sp.setdefault(sci, []).append(logit)

    print("=" * 120)
    print(f"  PerchPi embedding threshold derivation")
    print(f"  Generated: {datetime.now().isoformat(timespec='seconds')}")
    print(f"  Window: last {args.days} days  |  Min N: {args.min_n}  |  "
          f"TP percentile: P{args.tp_percentile}")
    if args.fp_percentile is None:
        print(f"  FP threshold: NOT DERIVED  "
              f"(species_embedding_thresholds.clearly_fp_logit stays NULL; "
              f"worker uses global default 0.0)")
    else:
        print(f"  FP percentile: P{args.fp_percentile}  "
              f"(CAUTION: derived from accept-band, not from labeled FP data)")
    print("=" * 120)
    print()

    print(f"Total embeddings in window:    {len(rows):,}")
    print(f"Distinct species:              {len(by_sp)}")

    enough = sorted(
        [(sp, sorted(lst)) for sp, lst in by_sp.items() if len(lst) >= args.min_n],
        key=lambda kv: -len(kv[1])
    )
    too_few = [(sp, sorted(lst)) for sp, lst in by_sp.items()
               if len(lst) < args.min_n]

    print(f"Species with N >= {args.min_n}:          {len(enough)}")
    print(f"Species with N <  {args.min_n}:          {len(too_few)}  "
          f"(global defaults remain in effect)")
    print()

    if not enough:
        print("# No species meets the minimum sample size. Exiting.")
        return 0

    # Per-species distribution table
    print(f"{'Species':<32} {'N':>5}  "
          f"{'min':>7} {'p5':>7} {'p10':>7} {'p25':>7} {'med':>7} "
          f"{'p75':>7} {'p95':>7} {'max':>7}  "
          f"{'TP_thr':>9} {'FP_thr':>9}")
    print(f"{'-'*32} {'-'*5}  "
          f"{'-'*7} {'-'*7} {'-'*7} {'-'*7} {'-'*7} "
          f"{'-'*7} {'-'*7} {'-'*7}  "
          f"{'-'*9} {'-'*9}")

    results = []
    for sci, logits in enough:
        tp = percentile(logits, args.tp_percentile)
        fp = (percentile(logits, args.fp_percentile)
              if args.fp_percentile is not None else None)
        results.append({
            'scientific_name': sci,
            'n_samples': len(logits),
            'clearly_tp_logit': tp,
            'clearly_fp_logit': fp,
            'min': logits[0],
            'p5': percentile(logits, 5),
            'p10': percentile(logits, 10),
            'p25': percentile(logits, 25),
            'median': percentile(logits, 50),
            'p75': percentile(logits, 75),
            'p95': percentile(logits, 95),
            'max': logits[-1],
        })
        print(
            f"{sci[:32]:<32} {len(logits):>5}  "
            f"{logits[0]:>+7.2f} "
            f"{percentile(logits, 5):>+7.2f} "
            f"{percentile(logits, 10):>+7.2f} "
            f"{percentile(logits, 25):>+7.2f} "
            f"{percentile(logits, 50):>+7.2f} "
            f"{percentile(logits, 75):>+7.2f} "
            f"{percentile(logits, 95):>+7.2f} "
            f"{logits[-1]:>+7.2f}  "
            f"{fmt_logit(tp)} {fmt_logit(fp)}"
        )

    # SQL preview (commented out)
    print()
    print("-- SQL preview (commented; review before applying)")
    print("-- The Phase 2 deploy script handles the actual UPSERT, "
          "joining scientific_name -> perch_class_idx via labels.csv")
    print("-- These UPDATE statements assume species_embedding_thresholds rows "
          "already exist for each species (PK perch_class_idx)")
    print("--")
    for r in results:
        safe_name = r['scientific_name'].replace("'", "''")
        if r['clearly_fp_logit'] is not None:
            print(
                f"-- UPDATE species_embedding_thresholds SET "
                f"clearly_tp_logit = {r['clearly_tp_logit']:.4f}, "
                f"clearly_fp_logit = {r['clearly_fp_logit']:.4f}, "
                f"source = 'observed', n_samples = {r['n_samples']}, "
                f"updated_at = datetime('now','localtime') "
                f"WHERE scientific_name = '{safe_name}';"
            )
        else:
            print(
                f"-- UPDATE species_embedding_thresholds SET "
                f"clearly_tp_logit = {r['clearly_tp_logit']:.4f}, "
                f"source = 'observed', n_samples = {r['n_samples']}, "
                f"updated_at = datetime('now','localtime') "
                f"WHERE scientific_name = '{safe_name}';"
            )

    # Low-N species (informational)
    if too_few:
        print()
        print(f"# Species below min-N (will continue using global defaults):")
        for sci, logits in too_few[:20]:
            print(f"#   {sci:<32} N={len(logits):>3}  "
                  f"median={percentile(logits, 50):+.2f}")
        if len(too_few) > 20:
            print(f"#   ... {len(too_few) - 20} more omitted")

    # CSV output
    if args.output_csv:
        with open(args.output_csv, 'w', newline='') as f:
            w = csv.DictWriter(f, fieldnames=[
                'scientific_name', 'n_samples',
                'clearly_tp_logit', 'clearly_fp_logit',
                'min', 'p5', 'p10', 'p25', 'median', 'p75', 'p95', 'max'
            ])
            w.writeheader()
            for r in results:
                w.writerow(r)
        print(f"\n# CSV written to: {args.output_csv}")

    conn.close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
