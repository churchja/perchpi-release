#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# perchpi:e6-queue-worker-v1
"""
process_ai_queue.py \u2014 PerchPi AI false-positive queue worker.

Drains ai_pending_checks by calling helpers in utils.ai_check. Designed to run
every minute via cron; self-locking via flock(2), so overlapping invocations
are safe (the late arrival exits immediately).

Per row, in one transaction:
  - If detection was deleted: drop queue row silently (orphaned)
  - If attempts >= MAX_ATTEMPTS: log abandoned + drop
  - If rate-limited: increment attempts + log throttled + leave queued
  - Else: call AI, log decision, INSERT quarantine if false_positive, drop

Stats are printed as a single line for cron mail / journal scraping.

Exit codes:
  0  ran (may have processed 0 rows)
  1  another worker holds the lock
  2  fatal error before processing
"""

import argparse
import datetime
import fcntl
import os
import sqlite3
import sys
import time

# Locate ai_check.py (sibling of this script's utils/ directory)
_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(_SCRIPT_DIR, "utils"))
import ai_check  # noqa: E402

DB_PATH = "/home/birder/BirdNET-Pi/scripts/birds.db"
LOCK_PATH = "/tmp/perchpi-ai-queue-worker.lock"
DEFAULT_BATCH_SIZE = 5
MAX_ATTEMPTS = 3
# perchpi:ai-queue-resilience-v1 — minimum seconds before a row whose last
# call returned 429 may be re-attempted. Prevents thundering herd against
# Gemini's per-minute rate window after a transient 429 burst.
RETRY_DELAY_SECONDS = 300
# perchpi:ai-queue-resilience-v1 — in-memory RPM cap, kept conservatively
# below Gemini Flash free tier. Worker enforces this in addition to the
# DB-based rate check, which is racy within a batch.
WORKER_RPM_CAP = 4  # perchpi:ai-queue-resilience-v2 (lowered from 5 for Gemini Flash headroom)


def utcnow_iso():
    return datetime.datetime.utcnow().isoformat(timespec="seconds") + "Z"


def acquire_lock():
    """Return open fd if lock acquired, None if another instance holds it."""
    fd = os.open(LOCK_PATH, os.O_CREAT | os.O_RDWR, 0o644)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return fd
    except BlockingIOError:
        os.close(fd)
        return None


def log_decision_safe(cur, **fields):
    """Wrapper that never raises \u2014 telemetry must not break processing."""
    try:
        ai_check._pp_log_ai_decision(cur, **fields)
    except Exception as e:
        sys.stderr.write("[warn] _pp_log_ai_decision failed: %s\n" % e)


def process_one(con, cur, row, stats, verbose=False):
    """Process one queue row. Returns nothing; updates stats in place."""
    detection_id = row["detection_id"]
    geomodel_prob = row["geomodel_prob"]
    geomodel_week = row["geomodel_week"]
    threshold = row["threshold_used"]
    attempts = row["attempts"]

    # 1) Does the detection still exist?
    det = cur.execute(
        "SELECT Sci_Name, Com_Name, Confidence FROM detections WHERE ROWID = ?",
        (detection_id,),
    ).fetchone()
    if det is None:
        cur.execute(
            "DELETE FROM ai_pending_checks WHERE detection_id = ?",
            (detection_id,),
        )
        con.commit()
        stats["orphaned"] += 1
        if verbose:
            print("  detection %d: orphaned (no longer in detections)" % detection_id)
        return
    sci_name, com_name, confidence = det[0], det[1], det[2]

    # 2) Have we exhausted attempts? Final abandon.
    if attempts >= MAX_ATTEMPTS:
        log_decision_safe(
            cur,
            detection_id=detection_id,
            species_sci=sci_name,
            species_com=com_name,
            confidence=confidence,
            geomodel_prob=geomodel_prob,
            action="abandoned",
            skip_reason="max_attempts_exceeded",
            error_message=(row["last_error"] or "")[:500],
        )
        cur.execute(
            "DELETE FROM ai_pending_checks WHERE detection_id = ?",
            (detection_id,),
        )
        con.commit()
        stats["abandoned"] += 1
        if verbose:
            print("  detection %d: ABANDONED after %d attempts" %
                  (detection_id, attempts))
        return

    # 3) Rate limit gate (same check the producer used before enqueueing,
    #    re-checked here because hours may have passed between enqueue and now).
    rate = ai_check._pp_check_rate_limits(cur)
    if not rate.get("allowed"):
        cur.execute(
            "UPDATE ai_pending_checks SET attempts = attempts + 1, "
            "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
            (
                ("rate_limit:" + str(rate.get("reason", "")))[:500],
                utcnow_iso(),
                detection_id,
            ),
        )
        log_decision_safe(
            cur,
            detection_id=detection_id,
            species_sci=sci_name,
            species_com=com_name,
            confidence=confidence,
            geomodel_prob=geomodel_prob,
            action="throttled",
            skip_reason=rate.get("reason"),
        )
        con.commit()
        stats["throttled"] += 1
        if verbose:
            print("  detection %d: throttled (%s) \u2014 attempts now %d" %
                  (detection_id, rate.get("reason"), attempts + 1))
        return

    # 4) Call AI.
    try:
        ai_result = ai_check._pp_check_with_ai(
            cur, detection_id, geomodel_prob, threshold
        )
    except Exception as e:
        cur.execute(
            "UPDATE ai_pending_checks SET attempts = attempts + 1, "
            "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
            (
                ("exception:" + str(e))[:500],
                utcnow_iso(),
                detection_id,
            ),
        )
        con.commit()
        stats["errored"] += 1
        if verbose:
            print("  detection %d: ai exception %s \u2014 attempts now %d" %
                  (detection_id, e, attempts + 1))
        return

    # 5) Log + (optionally) quarantine + delete from queue. Single transaction.
    log_decision_safe(
        cur,
        detection_id=detection_id,
        species_sci=ai_result.get("species_sci") or sci_name,
        species_com=ai_result.get("species_com") or com_name,
        confidence=ai_result.get("confidence") if ai_result.get("confidence") is not None else confidence,
        geomodel_prob=geomodel_prob,
        action=ai_result.get("action"),
        verdict=ai_result.get("verdict"),
        verdict_confidence=ai_result.get("verdict_confidence"),
        verdict_reasoning=ai_result.get("verdict_reasoning"),
        latency_ms=ai_result.get("latency_ms"),
        http_status=ai_result.get("http_status"),
        model_used=ai_result.get("model_used"),
        prompt_tokens=ai_result.get("prompt_tokens"),
        response_tokens=ai_result.get("response_tokens"),
        error_message=ai_result.get("error_message"),
    )

    # perchpi:ai-queue-resilience-v1 — don't drop row on 429; re-queue for retry
    if ai_result.get("action") == "rate_limited":
        cur.execute(
            "UPDATE ai_pending_checks SET attempts = attempts + 1, "
            "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
            ("rate_limited:" + (ai_result.get("error_message") or "429")[:200],
             utcnow_iso(), detection_id),
        )
        con.commit()
        stats["throttled"] += 1
        if verbose:
            print("  detection %d: 429 — re-queued (attempts now %d)" %
                  (detection_id, attempts + 1))
        return

    # perchpi:ai-queue-resilience-v2 — 503 is transient Google-side error; re-queue
    if ai_result.get("action") == "errored" and ai_result.get("http_status") == 503:
        cur.execute(
            "UPDATE ai_pending_checks SET attempts = attempts + 1, "
            "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
            ("transient_503:" + (ai_result.get("error_message") or "503")[:200],
             utcnow_iso(), detection_id),
        )
        con.commit()
        stats["throttled"] += 1
        if verbose:
            print("  detection %d: 503 — re-queued (attempts now %d)" %
                  (detection_id, attempts + 1))
        return

    verdict = ai_result.get("verdict")
    if verdict == "false_positive":
        # perchpi:ai-flagged-quarantine-disabled-v1
        # ROUTING DISABLED 2026-05-17. Empirical evidence (n=2/2 ai-flagged
        # rows at time of disable) shows Gemini's false_positive verdicts
        # contradict Perch+BirdNET acoustic consensus on this audio task.
        # Verdict is still captured via _pp_log_ai_decision earlier in this
        # function — only the destructive quarantine routing is removed.
        # To re-enable: uncomment the cur.execute() block and the
        # stats["quarantined"] += 1 line below.
        #
        # cur.execute(
        #     "INSERT OR IGNORE INTO quarantined_detections "
        #     "(detection_id, quarantined_at, reason, geomodel_probability, "
        #     " geomodel_week, threshold_at_quarantine) "
        #     "VALUES (?, ?, 'ai-flagged', ?, ?, ?)",
        #     (
        #         detection_id,
        #         utcnow_iso(),
        #         geomodel_prob,
        #         geomodel_week,
        #         threshold,
        #     ),
        # )
        # stats["quarantined"] += 1
        stats["ai_flagged_not_quarantined"] = stats.get("ai_flagged_not_quarantined", 0) + 1
    else:
        stats["confirmed"] += 1

    cur.execute(
        "DELETE FROM ai_pending_checks WHERE detection_id = ?",
        (detection_id,),
    )
    con.commit()
    if verbose:
        print("  detection %d: verdict=%s action=%s" %
              (detection_id, verdict, ai_result.get("action")))


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_SIZE,
                    help="max rows to process per invocation (default %d)" %
                    DEFAULT_BATCH_SIZE)
    ap.add_argument("--verbose", "-v", action="store_true",
                    help="print per-row detail")
    args = ap.parse_args()

    lock_fd = acquire_lock()
    if lock_fd is None:
        if args.verbose:
            print("another worker is running; exiting")
        return 1

    try:
        con = sqlite3.connect(DB_PATH, timeout=30.0)
        con.row_factory = sqlite3.Row
        cur = con.cursor()

        # perchpi:e7-trust-gate-v1 — priority ordering. Priority 1 (urgent,
        # unestablished species + marginal-geomodel) drains before priority 2
        # (normal untrusted). FIFO within priority. Old rows pre-e7 default
        # to priority 2 via ALTER TABLE ADD COLUMN ... DEFAULT 2.
        # perchpi:ai-queue-resilience-v1 — exclude rows recently retried (429 cooldown)
        rows = cur.execute(
            "SELECT detection_id, queued_at, attempts, last_error, "
            "       last_attempt_at, geomodel_prob, geomodel_week, threshold_used "
            "FROM ai_pending_checks "
            "WHERE last_attempt_at IS NULL "
            "   OR last_attempt_at < datetime('now', '-' || ? || ' seconds') "
            "ORDER BY priority ASC, queued_at ASC LIMIT ?",
            (RETRY_DELAY_SECONDS, args.batch_size),
        ).fetchall()

        if not rows:
            if args.verbose:
                print("queue empty")
            return 0

        stats = {
            "throttled": 0, "errored": 0, "orphaned": 0,
            "abandoned": 0, "quarantined": 0, "confirmed": 0,
        }
        t_start = time.monotonic()
        # perchpi:ai-queue-resilience-v1 — in-memory call ledger closes
        # the batch-level race where DB-based rate check sees pre-commit state.
        call_ledger = []

        for row in rows:
            # perchpi:ai-queue-resilience-v1 — in-memory RPM pre-check
            now_mono = time.monotonic()
            call_ledger[:] = [t for t in call_ledger if now_mono - t < 60.0]
            if len(call_ledger) >= WORKER_RPM_CAP:
                detection_id = row["detection_id"]
                # perchpi:ai-queue-resilience-v2 — log throttle event for observability
                det_info = cur.execute(
                    "SELECT Sci_Name, Com_Name, Confidence FROM detections WHERE ROWID = ?",
                    (detection_id,)
                ).fetchone()
                if det_info:
                    log_decision_safe(
                        cur,
                        detection_id=detection_id,
                        species_sci=det_info[0],
                        species_com=det_info[1],
                        confidence=det_info[2],
                        geomodel_prob=row["geomodel_prob"],
                        action="throttled",
                        skip_reason="worker-rpm-cap",
                    )
                cur.execute(
                    "UPDATE ai_pending_checks SET attempts = attempts + 1, "
                    "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
                    ("worker-rpm-cap", utcnow_iso(), detection_id),
                )
                con.commit()
                stats["throttled"] += 1
                if args.verbose:
                    print("  detection %d: in-memory RPM cap reached, deferred" %
                          detection_id)
                continue
            # Reserve a slot in the ledger before the call so subsequent
            # iterations see this call counted even if it raises.
            call_ledger.append(now_mono)
            try:
                process_one(con, cur, row, stats, verbose=args.verbose)
            except Exception as e:
                # Belt-and-suspenders: one bad row mustn't kill the batch.
                try:
                    con.rollback()
                except Exception:
                    pass
                stats["errored"] += 1
                sys.stderr.write(
                    "[error] row %s: %s\n" % (row["detection_id"], e)
                )
                try:
                    cur.execute(
                        "UPDATE ai_pending_checks SET attempts = attempts + 1, "
                        "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
                        (
                            ("worker_exception:" + str(e))[:500],
                            utcnow_iso(),
                            row["detection_id"],
                        ),
                    )
                    con.commit()
                except Exception:
                    try:
                        con.rollback()
                    except Exception:
                        pass

        elapsed = time.monotonic() - t_start
        print(
            "processed=%d throttled=%d errored=%d orphaned=%d abandoned=%d "
            "quarantined=%d confirmed=%d elapsed=%.2fs"
            % (
                len(rows), stats["throttled"], stats["errored"],
                stats["orphaned"], stats["abandoned"],
                stats["quarantined"], stats["confirmed"], elapsed,
            )
        )
        return 0
    except Exception as e:
        sys.stderr.write("FATAL: %s\n" % e)
        return 2
    finally:
        try:
            os.close(lock_fd)
        except Exception:
            pass


if __name__ == "__main__":
    sys.exit(main())
