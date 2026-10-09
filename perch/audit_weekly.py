#!/usr/bin/env python3
# perchpi:embedding-audit-v2
"""PerchPi embedding-gate audit script.

Read-only. Generates a plain-text health report for the Phase 1 observational
window. Designed for daily/weekly review during soak.

v2 (2026-05-17) additions:
  - Quarantine review activity section (uses quarantine_review_log)
  - AI-flagged routing telemetry (post w.Y patch)
  - Anomaly check: new ai-flagged quarantines (regression detector)

  perchpi:audit-weekly-tz-fix-v1 -- decision_at is now UTC; legacy-tz-corrupt filtered

Usage:
  python3 audit_weekly.py                  # default: last 7 days
  python3 audit_weekly.py --days 1         # last 24 hours
  python3 audit_weekly.py --days 14        # last 14 days
  python3 audit_weekly.py --top 50         # show top N species (default 30)

Suggested weekly cron (Monday 9am):
  0 9 * * 1 /home/birder/BirdNET-Pi/birdnet/bin/python3 \\
    /home/birder/BirdNET-Pi/perch/audit_weekly.py >> \\
    /home/birder/BirdNET-Pi/perch/audit.log 2>&1
"""

import argparse
import sqlite3
import sys
from datetime import datetime, timezone

DB_PATH = "/home/birder/BirdNET-Pi/scripts/birds.db"


def percentile(sorted_values, p):
    """p-th percentile (0-100) from pre-sorted list. Linear interp."""
    if not sorted_values:
        return None
    n = len(sorted_values)
    idx = (p / 100.0) * (n - 1)
    lo = int(idx)
    hi = min(lo + 1, n - 1)
    frac = idx - lo
    return sorted_values[lo] + frac * (sorted_values[hi] - sorted_values[lo])


def hist_bar(value, max_value, width=30):
    if max_value == 0:
        return ""
    n = int(round(width * value / max_value))
    return "#" * n


def fmt_logit(v):
    if v is None:
        return "  N/A "
    return f"{v:+6.2f}"


def collect_anomalies(cur, days, species_data, latencies, queue_depth, queue_retried):
    """Build list of anomaly strings. Empty list = healthy."""
    out = []

    # Errored embeddings
    cur.execute(
        "SELECT COUNT(*) FROM ai_decisions "
        "WHERE embedding_verdict = 'errored' "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    errored = cur.fetchone()[0]
    if errored > 0:
        out.append(f"ERRORED EMBEDDINGS: {errored} (check worker.log)")

    # Queue items with persistent retries (excluding current in-flight)
    if queue_retried > 5:
        out.append(
            f"QUEUE WITH RETRIES: {queue_retried} items have attempts>0 "
            "(transient OK; persistent = investigate)"
        )

    # Latency degradation vs Phase 0 baseline (~400ms median, ~500ms p95)
    if latencies:
        p95 = percentile(latencies, 95)
        if p95 and p95 > 1000:
            out.append(
                f"HIGH P95 LATENCY: {int(p95)}ms "
                "(Phase 0 baseline p95 ~500ms)"
            )

    # Per-species reject rate (only for well-sampled species)
    for sci, lst in species_data:
        if len(lst) < 20:
            continue
        verdicts_only = [v for _, v in lst]
        rej_rate = sum(1 for v in verdicts_only if v == 'reject') / len(lst)
        if rej_rate > 0.5:
            out.append(
                f"HIGH REJECT RATE: {sci}: "
                f"{rej_rate*100:.0f}% of {len(lst)} rejected"
            )

    # Skipped embeddings as fraction of total
    cur.execute(
        "SELECT COUNT(*) FROM ai_decisions "
        "WHERE embedding_verdict = 'skipped' "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    skipped = cur.fetchone()[0]
    cur.execute(
        "SELECT COUNT(*) FROM ai_decisions "
        "WHERE embedding_verdict IS NOT NULL "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    total = cur.fetchone()[0]
    if total > 100 and skipped / total > 0.10:
        out.append(
            f"HIGH SKIP RATE: {skipped}/{total} = "
            f"{skipped*100/total:.1f}% skipped "
            "(expected <5% from name-match gap)"
        )

    # v2: new ai-flagged quarantine entries (regression detector for w.Y patch)
    cur.execute(
        "SELECT COUNT(*) FROM quarantined_detections "
        "WHERE reason = 'ai-flagged' "
        "AND quarantined_at > '2026-05-17T06:23:36Z'  -- w.Y patch deploy time UTC"
    )
    recent_ai_flagged = cur.fetchone()[0]
    if recent_ai_flagged > 0:
        out.append(
            f"REGRESSION: {recent_ai_flagged} new 'ai-flagged' quarantine(s) "
            "in last 24h (w.Y patch should prevent this; verify "
            "process_ai_queue.py marker)"
        )

    return out


def section_quarantine_review(cur, days):
    """v2: Quarantine review activity section.

    Shows decisions made via Quarantine UI, joined with embedding logits.
    This is the labeled-FP signal that Phase 2 threshold derivation needs.
    """
    try:
        cur.execute(
            "SELECT decision, COUNT(*) FROM quarantine_review_log "
            "WHERE decision_at >= datetime('now', ?) "
            "GROUP BY decision",
            (f'-{days} days',)
        )
        decisions = dict(cur.fetchall())
    except sqlite3.OperationalError as e:
        if 'no such table' in str(e):
            print("\nQuarantine review activity: not available "
                  "(quarantine_review_log table not present; "
                  "see (w.Z) prereq deploy)")
            return
        raise

    total_decisions = sum(decisions.values())
    print(f"\nQuarantine review activity:")
    if total_decisions == 0:
        print(f"  No decisions in window.")
        return

    print(f"  Total decisions:  {total_decisions}")
    for d in ['delete', 'unhide']:
        count = decisions.get(d, 0)
        pct = 100.0 * count / total_decisions if total_decisions else 0
        print(f"    {d:7}: {count:>4}  ({pct:5.1f}%)")

    # Time-to-decision
    cur.execute(
        "SELECT quarantine_age_minutes FROM quarantine_review_log "
        "WHERE decision_at >= datetime('now', ?) "
        "AND quarantine_age_minutes IS NOT NULL "
        "AND (notes IS NULL OR notes != 'legacy-tz-corrupt') "
        "ORDER BY quarantine_age_minutes",
        (f'-{days} days',)
    )
    ages = [r[0] for r in cur.fetchall()]
    if ages:
        print(f"  Time-to-decision (minutes):")
        print(f"    min={ages[0]} med={int(percentile(ages, 50))} "
              f"p75={int(percentile(ages, 75))} max={ages[-1]}")

    # Per-species crosstab with embedding logits
    cur.execute(
        "SELECT species_com, decision, COUNT(*), AVG(embedding_logit), "
        "       MIN(embedding_logit), MAX(embedding_logit) "
        "FROM quarantine_review_log "
        "WHERE decision_at >= datetime('now', ?) "
        "  AND species_com IS NOT NULL "
        "GROUP BY species_com, decision "
        "ORDER BY species_com, decision",
        (f'-{days} days',)
    )
    rows = cur.fetchall()
    per_species = {}
    for sp, dec, count, avg_l, min_l, max_l in rows:
        per_species.setdefault(sp, {})
        per_species[sp][dec] = {
            'count': count, 'avg': avg_l, 'min': min_l, 'max': max_l
        }

    if per_species:
        print(f"\n  Per-species crosstab (avg/min/max embedding_logit):")
        print(f"    {'Species':<26} "
              f"{'Del':>4} {'avg_logit(del)':>16} "
              f"{'Unh':>4} {'avg_logit(unh)':>16}")
        print(f"    {'-'*26} "
              f"{'-'*4} {'-'*16} "
              f"{'-'*4} {'-'*16}")
        for sp in sorted(per_species.keys()):
            d = per_species[sp].get('delete', {})
            u = per_species[sp].get('unhide', {})
            d_count = d.get('count', 0)
            u_count = u.get('count', 0)
            d_logit_str = (
                f"{d['avg']:+.2f}/{d['min']:+.2f}/{d['max']:+.2f}"
                if d.get('avg') is not None else "      N/A      "
            )
            u_logit_str = (
                f"{u['avg']:+.2f}/{u['min']:+.2f}/{u['max']:+.2f}"
                if u.get('avg') is not None else "      N/A      "
            )
            print(f"    {sp[:26]:<26} "
                  f"{d_count:>4} {d_logit_str:>16} "
                  f"{u_count:>4} {u_logit_str:>16}")

    print("  (Decisions are Phase 2's primary labeled FP/TP signal.")
    print("   Use derive_thresholds.py v2 — to be written — to consume these.)")


def section_ai_flagged_telemetry(cur, days):
    """v2: AI-flagged routing telemetry (post w.Y patch).

    Counts Gemini false_positive verdicts in window vs. how many landed in
    quarantined_detections. Post-patch, FP-verdict count should exceed
    ai-flagged quarantine count.
    """
    cur.execute(
        "SELECT COUNT(*) FROM ai_decisions "
        "WHERE action = 'called' AND verdict = 'false_positive' "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    fp_verdicts = cur.fetchone()[0]

    # perchpi:utc-mig-audit-weekly-full-v1 — both tables now use UTC consistently
    cur.execute(
        "SELECT COUNT(*) FROM quarantined_detections "
        "WHERE reason = 'ai-flagged' "
        "AND datetime(quarantined_at) >= datetime('now', ?)  /* perchpi:utc-mig-quarantined-window-bugfix-v1 */",
        (f'-{days} days',)
    )
    ai_flagged_q = cur.fetchone()[0]

    print(f"\nAI-flagged routing telemetry (post 2026-05-17 disable):")
    print(f"  Gemini false_positive verdicts in window:    {fp_verdicts}")
    print(f"  Quarantined as 'ai-flagged' in window:       {ai_flagged_q}")

    if fp_verdicts == 0 and ai_flagged_q == 0:
        print(f"  (No activity in window — typical at low LLM call rate.)")
        return

    not_quarantined = fp_verdicts - ai_flagged_q

    if not_quarantined > 0:
        print(f"  → {not_quarantined} FP verdict(s) NOT quarantined "
              f"(w.Y patch working as designed)")

        # Show embedding logits of these to compare Perch vs Gemini
        cur.execute(
            "SELECT ad.embedding_logit, ad.species_sci "
            "FROM ai_decisions ad "
            "WHERE ad.action = 'called' AND ad.verdict = 'false_positive' "
            "  AND ad.embedding_logit IS NOT NULL "
            "  AND ad.created_at >= datetime('now', ?) "
            "  AND NOT EXISTS ("
            "    SELECT 1 FROM quarantined_detections qd "
            "    WHERE qd.detection_id = ad.detection_id "
            "    AND qd.reason = 'ai-flagged'"
            "  ) "
            "ORDER BY ad.embedding_logit DESC",
            (f'-{days} days',)
        )
        rows = cur.fetchall()
        if rows:
            print(f"  Perch logits on Gemini-FP detections "
                  f"(disagreements where Perch said accept):")
            for logit, sci in rows[:10]:
                marker = " ← strong disagreement" if logit > 10.0 else ""
                print(f"    {sci:<32} logit={logit:+.2f}{marker}")
            if len(rows) > 10:
                print(f"    ... {len(rows) - 10} more omitted")

    if ai_flagged_q > 0:
        print(f"  WARNING: {ai_flagged_q} ai-flagged quarantine(s) in window")
        print(f"  These should be pre-2026-05-17 only (w.Y disabled new routing)")


def run_audit(days, top_n):
    conn = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    cur = conn.cursor()

    print("=" * 78)
    print(f"  PerchPi embedding-gate audit  (last {days} day(s))")
    print(f"  Generated: {datetime.now(timezone.utc).isoformat(timespec='seconds')} UTC")
    print(f"  Audit script: v2 (quarantine review + ai-flagged telemetry)")
    print("=" * 78)

    # --- Overall counts ---
    cur.execute(
        "SELECT COUNT(*) FROM ai_decisions "
        "WHERE embedding_logit IS NOT NULL "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    completed = cur.fetchone()[0]

    cur.execute(
        "SELECT COUNT(*) FROM ai_decisions "
        "WHERE embedding_verdict IS NOT NULL "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    total_verdicts = cur.fetchone()[0]

    cur.execute("SELECT COUNT(*) FROM embedding_pending")
    queue_depth = cur.fetchone()[0]

    cur.execute("SELECT COUNT(*) FROM embedding_pending WHERE attempts > 0")
    queue_retried = cur.fetchone()[0]

    print(f"\nOverall in window:")
    print(f"  Embeddings completed:    {completed:>6,}")
    print(f"  Skipped (no logit):      {total_verdicts - completed:>6,}")
    print(f"  Queue depth (now):       {queue_depth:>6}")
    print(f"  Queue items w/ retries:  {queue_retried:>6}")

    if total_verdicts == 0:
        print("\nNo embedding data in window. Worker not running, hook not firing, "
              "or window too short. Bailing.")
        return

    # --- Pull per-species data once, reused below ---
    cur.execute(
        "SELECT species_sci, embedding_logit, embedding_verdict "
        "FROM ai_decisions "
        "WHERE embedding_logit IS NOT NULL "
        "AND created_at >= datetime('now', ?)",
        (f'-{days} days',)
    )
    rows = cur.fetchall()
    by_sp = {}
    for sci, logit, verdict in rows:
        by_sp.setdefault(sci, []).append((logit, verdict))
    species_data = sorted(by_sp.items(), key=lambda kv: -len(kv[1]))

    # --- Latency stats ---
    cur.execute(
        "SELECT embedding_latency_ms FROM ai_decisions "
        "WHERE embedding_latency_ms IS NOT NULL "
        "AND created_at >= datetime('now', ?) "
        "ORDER BY embedding_latency_ms",
        (f'-{days} days',)
    )
    latencies = [r[0] for r in cur.fetchall()]

    # --- Anomalies (printed near top for at-a-glance) ---
    anomalies = collect_anomalies(
        cur, days, species_data, latencies, queue_depth, queue_retried
    )
    print("\nAnomalies:")
    if anomalies:
        for line in anomalies:
            print(f"  ! {line}")
    else:
        print("  none.")

    # --- Verdict breakdown ---
    cur.execute(
        "SELECT embedding_verdict, COUNT(*) "
        "FROM ai_decisions "
        "WHERE embedding_verdict IS NOT NULL "
        "AND created_at >= datetime('now', ?) "
        "GROUP BY embedding_verdict ORDER BY COUNT(*) DESC",
        (f'-{days} days',)
    )
    verdicts = cur.fetchall()
    print("\nVerdict breakdown:")
    for verdict, count in verdicts:
        pct = 100.0 * count / total_verdicts
        bar = hist_bar(count, total_verdicts)
        print(f"  {verdict:12s} {count:>6,}  {pct:5.1f}%  {bar}")

    # --- Latency printed ---
    if latencies:
        print("\nLatency (ms):")
        print(f"  min   = {latencies[0]}")
        print(f"  p25   = {int(percentile(latencies, 25))}")
        print(f"  med   = {int(percentile(latencies, 50))}")
        print(f"  p75   = {int(percentile(latencies, 75))}")
        print(f"  p95   = {int(percentile(latencies, 95))}")
        print(f"  max   = {latencies[-1]}")

    # --- Per-species table ---
    species_enough = [(sp, lst) for sp, lst in species_data if len(lst) >= 3]
    print(f"\nSpecies with N>=3 detections (top {min(top_n, len(species_enough))} "
          f"of {len(species_enough)}):")
    print(f"  {'Species':<32} {'N':>5}  "
          f"{'min':>7} {'p25':>7} {'med':>7} {'p75':>7} {'max':>7}  "
          f"{'A':>4}/{'M':>4}/{'R':>4}")
    print(f"  {'-' * 32} {'-' * 5}  "
          f"{'-' * 7} {'-' * 7} {'-' * 7} {'-' * 7} {'-' * 7}  "
          f"{'-' * 4}/{'-' * 4}/{'-' * 4}")

    for sci, lst in species_enough[:top_n]:
        logits = sorted([l for l, _ in lst])
        verdicts_only = [v for _, v in lst]
        a = sum(1 for v in verdicts_only if v == 'accept')
        m = sum(1 for v in verdicts_only if v == 'ambiguous')
        r = sum(1 for v in verdicts_only if v == 'reject')
        sci_short = sci[:32]
        print(
            f"  {sci_short:<32} {len(lst):>5} "
            f"{fmt_logit(logits[0]):>7} "
            f"{fmt_logit(percentile(logits, 25)):>7} "
            f"{fmt_logit(percentile(logits, 50)):>7} "
            f"{fmt_logit(percentile(logits, 75)):>7} "
            f"{fmt_logit(logits[-1]):>7}  "
            f"{a:>4}/{m:>4}/{r:>4}"
        )

    if len(species_enough) > top_n:
        print(f"  ... {len(species_enough) - top_n} more species omitted "
              "(use --top N to show more)")

    # --- LLM crosstab ---
    cur.execute(
        "SELECT embedding_verdict, verdict, COUNT(*) "
        "FROM ai_decisions "
        "WHERE embedding_verdict IS NOT NULL AND verdict IS NOT NULL "
        "AND verdict <> '' "
        "AND created_at >= datetime('now', ?) "
        "GROUP BY embedding_verdict, verdict "
        "ORDER BY embedding_verdict, verdict",
        (f'-{days} days',)
    )
    crosstab = cur.fetchall()
    if crosstab:
        total_overlap = sum(c for _, _, c in crosstab)
        print(f"\nEmbedding vs LLM verdict crosstab ({total_overlap} rows, both opined):")
        print(f"  {'embedding':<12} {'LLM verdict':<16} {'count':>6}")
        for emb_v, llm_v, count in crosstab:
            print(f"  {emb_v:<12} {llm_v:<16} {count:>6}")
        print("  (interpretation: 'accept'+'real' agree; 'reject'+'false_positive' "
              "agree; rest disagree or ambiguous)")
    else:
        print("\nEmbedding vs LLM crosstab: no overlap in window "
              "(typical — LLM only opines on a small fraction of detections).")

    # --- v2 NEW: Quarantine review activity ---
    section_quarantine_review(cur, days)

    # --- v2 NEW: AI-flagged routing telemetry ---
    section_ai_flagged_telemetry(cur, days)

    print("\n" + "=" * 78)
    conn.close()


def main():
    parser = argparse.ArgumentParser(description="PerchPi embedding-gate audit (v2)")
    parser.add_argument('--days', type=int, default=7,
                        help='Lookback window in days (default 7)')
    parser.add_argument('--top', type=int, default=30,
                        help='Max species in per-species table (default 30)')
    args = parser.parse_args()
    run_audit(args.days, args.top)
    return 0


if __name__ == '__main__':
    sys.exit(main())
