# -*- coding: utf-8 -*-
"""
PerchPi AI False-Positive Checker — shared helpers.

Extracted from scripts/utils/reporting.py on 2026-05-10 as part of Phase E6
(sync→async refactor). Originally introduced across markers:
  - perchpi:ai-fp-helpers-v1            — _pp_load_ai_fp_config
  - perchpi:e2-ai-logging-v1            — _pp_log_ai_decision + _pp_check_with_ai
  - perchpi:e3-cluster-filter-v1        — _pp_load_filter_config + _pp_should_check_with_ai
  - perchpi:e3-skip-species-info-v1     — amends _pp_should_check_with_ai
  - perchpi:e4-rate-limit-v1            — _pp_check_rate_limits + amends loader

Used by:
  - utils.reporting._pp_maybe_quarantine_by_rowid (enqueues into ai_pending_checks)
  - scripts/process_ai_queue.py (cron worker; runs actual AI calls + quarantines)

Functions exported:
  _pp_load_ai_fp_config()                                    — birdnet.conf reader
  _pp_load_filter_config()                                   — birdnet.conf reader
  _pp_log_ai_decision(cur, **fields)                         — ai_decisions INSERT
  _pp_should_check_with_ai(cur, detection_id, prob, thr)     — cluster filter
  _pp_check_rate_limits(cur)                                 — RPM/RPD gate
  _pp_check_with_ai(cur, detection_id, prob, thr)            — Gemini API call

All functions take a SQLite cursor as their first non-self argument; the
caller is responsible for transaction management (commit/rollback). The
log helper is fail-open (telemetry MUST NOT break detection writes).
"""
# perchpi:e6-ai-check-module-v1

def _pp_load_ai_fp_config():
    """Returns (enabled: bool, api_key: str|None). Defaults: (False, None)."""
    enabled = False
    api_key = None
    try:
        with open("/etc/birdnet/birdnet.conf", "r") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, _, v = line.partition("=")
                k = k.strip()
                v = v.strip().strip('"').strip("'")
                if k == "AI_FALSE_POSITIVE_ENABLED":
                    enabled = v == "1"
                elif k == "GEMINI_API_KEY":
                    api_key = v if v else None
    except Exception:
        pass
    return enabled, api_key


# ═════════════════════════════════════════════════════════════════════════
# perchpi:e2-ai-logging-v1 — AI decision telemetry logger
# Writes one row to ai_decisions table per AI eligibility decision.
# Fails open: any error in logging is swallowed (NEVER blocks detection writes).
# ═════════════════════════════════════════════════════════════════════════


def _pp_load_filter_config():
    """Returns dict of filter thresholds from birdnet.conf (with defaults).

    Keys (default values shown):
      lone_threshold (3)     - min cluster_30min to be considered at all
      abundant_daily (300)   - species_today count above which species is
                               "trusted abundant" (e.g. Cardinal at this site)
      abundant_override (15) - even abundant species are checked if
                               cluster_5min exceeds this (extreme cluster)
      cluster_5min (5)       - cluster_5min count that triggers AI check
      cluster_30min (10)     - cluster_30min count that triggers AI check
    """
    # perchpi:e7-trust-gate-v1 — trust-based gate config keys.
    # Old cluster keys (lone_threshold, abundant_*, cluster_*) are DEPRECATED
    # under e7 and kept here only for backward-compat with any external reader.
    # They are not consulted by _pp_should_check_with_ai under e7.
    cfg = {
        # DEPRECATED (e7): cluster-based gating removed.
        "lone_threshold": 3,
        "abundant_daily": 300,
        "abundant_override": 15,
        "cluster_5min": 5,
        "cluster_30min": 10,
        # e4: rate-limit caps (UNCHANGED).
        "rate_rpm_max": 8,
        "rate_rpd_max": 200,
        # e7: trust-based gate.
        "trust_fast_confidence": 0.90,   # any historical hit >= this -> trusted forever
        "trust_slow_count": 10,          # earned trust: required detection count
        "trust_slow_confidence": 0.75,   # earned trust: required peak confidence
        "skip_floor": 0.85,              # this detection >= floor -> skip AI
        "marginal_prob": 0.10,           # untrusted + prob<this -> priority 1
        "establish_count": 5,            # new species: required count in window
        "establish_days": 14,            # new species: lookback window (days)
        "establish_distinct_days": 3,    # new species: required distinct dates
        "per_species_daily_cap": 5,      # max AI calls per (species, day)
        "audit_sample_rate": 0.01,       # fraction of skips flipped to checks
    }
    # (conf_key, cfg_key, type_caster) — caster is int or float per key.
    key_map = {
        "AI_FP_FILTER_LONE_THRESHOLD":     ("lone_threshold", int),
        "AI_FP_FILTER_ABUNDANT_DAILY":     ("abundant_daily", int),
        "AI_FP_FILTER_ABUNDANT_OVERRIDE":  ("abundant_override", int),
        "AI_FP_FILTER_CLUSTER_5MIN":       ("cluster_5min", int),
        "AI_FP_FILTER_CLUSTER_30MIN":      ("cluster_30min", int),
        "AI_FP_RATE_LIMIT_RPM_MAX":        ("rate_rpm_max", int),
        "AI_FP_RATE_LIMIT_RPD_MAX":        ("rate_rpd_max", int),
        # e7
        "AI_FP_TRUST_FAST_CONFIDENCE":     ("trust_fast_confidence", float),
        "AI_FP_TRUST_SLOW_COUNT":          ("trust_slow_count", int),
        "AI_FP_TRUST_SLOW_CONFIDENCE":     ("trust_slow_confidence", float),
        "AI_FP_SKIP_FLOOR":                ("skip_floor", float),
        "AI_FP_MARGINAL_PROB":             ("marginal_prob", float),
        "AI_FP_ESTABLISH_COUNT":           ("establish_count", int),
        "AI_FP_ESTABLISH_DAYS":            ("establish_days", int),
        "AI_FP_ESTABLISH_DISTINCT_DAYS":   ("establish_distinct_days", int),
        "AI_FP_PER_SPECIES_DAILY_CAP":     ("per_species_daily_cap", int),
        "AI_FP_AUDIT_SAMPLE_RATE":         ("audit_sample_rate", float),
    }
    try:
        with open("/etc/birdnet/birdnet.conf", "r") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, _, v = line.partition("=")
                k = k.strip()
                v = v.strip().strip('"').strip("'")
                if k in key_map:
                    cfg_key, caster = key_map[k]
                    try:
                        cfg[cfg_key] = caster(v)
                    except (ValueError, TypeError):
                        pass
    except (OSError, IOError):
        pass
    return cfg


def _pp_log_ai_decision(cur, **fields):
    """Insert one row into ai_decisions audit table. Fail-open."""
    # perchpi:e7-trust-gate-v1 — cluster_5min/cluster_30min DEPRECATED but
    # kept for column-compat with pre-e7 rows; new rows write NULL to them.
    cols = [
        "detection_id", "species_sci", "species_com", "confidence",
        "geomodel_prob", "cluster_5min", "cluster_30min", "species_today",
        "species_total_count", "species_max_confidence",
        "count_in_last_14_days", "distinct_days_in_last_14_days",
        "action", "skip_reason", "verdict", "verdict_confidence",
        "verdict_reasoning", "latency_ms", "http_status", "model_used",
        "prompt_tokens", "response_tokens", "error_message",
    ]
    try:
        values = [fields.get(c) for c in cols]
        placeholders = ", ".join("?" * len(cols))
        # perchpi:utc-mig-ai-check-v1 — created_at written via SQLite UTC strftime,
        # bypassing schema DEFAULT (which is localtime). No Python tz import needed.
        cur.execute(
            "INSERT INTO ai_decisions (created_at, " + ", ".join(cols) + ") "
            "VALUES (strftime('%Y-%m-%d %H:%M:%S','now'), " + placeholders + ")",
            values,
        )
    except Exception:
        # Telemetry MUST NOT break detection writes.
        pass


# ═════════════════════════════════════════════════════════════════════════
# perchpi:e3-cluster-filter-v1 — Cluster-based pre-filter for AI checks.
# Most detections are SKIPPED here without an AI call (lone detections, or
# trusted-abundant species like Cardinal at this location). Only suspicious
# cluster signatures trigger an AI call. Thresholds load from birdnet.conf
# with defaults if missing.
# ═════════════════════════════════════════════════════════════════════════


def _pp_should_check_with_ai(cur, detection_id, prob, threshold):
    """Trust-based gate for AI false-positive checking.

    perchpi:e7-trust-gate-v1 — Replaces cluster-based gating. AI now checks
    UNESTABLISHED species (the cases we are not yet sure are really at this
    site) and UNTRUSTED detections of established species. Abundant established
    species are trusted as real birds and skip AI.

    Evaluation order:
      1. Unestablished species (count_14d < N OR distinct_days < D) -> CHECK p1
      2. This detection >= skip_floor                                -> skip
      3. max_confidence_all_time >= trust_fast                       -> skip
      4. total_count >= slow_count AND max_conf >= trust_slow        -> skip
      5. Untrusted established species                               -> CHECK
                                       priority 1 if prob < marginal, else 2
      6. Audit sample (1% of skips flipped to checks)                -> CHECK p2
      7. Per-species daily cap exceeded                              -> skip

    Failure mode: each history query has its own try/except, defaulting count
    to 0. If history queries fail, count_14d=0 makes species look unestablished,
    forcing AI check (safety net). Bounded by rate-limit + per-species cap.
    On top-level exception, falls back to check=True with reason 'filter-error'.

    Returns dict with:
      check (bool)
      reason (str)
      priority (int)                  - 1 (urgent) or 2 (normal)
      species_sci, species_com, confidence
      species_total_count             - all-time count prior to this row
      species_max_confidence          - all-time max BirdNET confidence
      count_in_last_14_days           - count in lookback window
      distinct_days_in_last_14_days   - distinct dates in lookback window
      species_today                   - same-species count today (continuity)
      cluster_5min, cluster_30min     - DEPRECATED, always None under e7
    """
    import random as _random

    base = {
        "check": False,
        "reason": "no-row",
        "priority": 2,
        "species_sci": None,
        "species_com": None,
        "confidence": None,
        "species_total_count": None,
        "species_max_confidence": None,
        "count_in_last_14_days": None,
        "distinct_days_in_last_14_days": None,
        "species_today": None,
        "cluster_5min": None,
        "cluster_30min": None,
    }
    try:
        row = cur.execute(
            "SELECT Sci_Name, Com_Name, Confidence FROM detections "
            "WHERE ROWID = ?",
            (detection_id,),
        ).fetchone()
        if not row:
            return base
        sci_name, com_name, conf_val = row
        base["species_sci"] = sci_name
        base["species_com"] = com_name
        base["confidence"] = conf_val

        cfg = _pp_load_filter_config()
        establish_days = int(cfg["establish_days"])

        # ─── History lookups (each independently fail-safe to 0) ────────
        count_14d = 0
        distinct_days_14d = 0
        try:
            r = cur.execute(
                "SELECT COUNT(*), COUNT(DISTINCT Date) FROM detections "
                "WHERE Sci_Name = ? AND ROWID < ? "
                "  AND Date >= date('now', '-' || ? || ' days', 'localtime')",
                (sci_name, detection_id, establish_days),
            ).fetchone()
            if r:
                count_14d = int(r[0] or 0)
                distinct_days_14d = int(r[1] or 0)
        except Exception:
            pass

        total_count = 0
        try:
            r = cur.execute(
                "SELECT COUNT(*) FROM detections "
                "WHERE Sci_Name = ? AND ROWID < ?",
                (sci_name, detection_id),
            ).fetchone()
            if r:
                total_count = int(r[0] or 0)
        except Exception:
            pass

        max_conf = 0.0
        try:
            r = cur.execute(
                "SELECT MAX(Confidence) FROM detections "
                "WHERE Sci_Name = ? AND ROWID < ?",
                (sci_name, detection_id),
            ).fetchone()
            if r and r[0] is not None:
                max_conf = float(r[0])
        except Exception:
            pass

        species_today = 0
        try:
            r = cur.execute(
                "SELECT COUNT(*) FROM detections "
                "WHERE Sci_Name = ? AND Date = date('now', 'localtime')",
                (sci_name,),
            ).fetchone()
            if r:
                species_today = int(r[0] or 0)
        except Exception:
            pass

        base["count_in_last_14_days"] = count_14d
        base["distinct_days_in_last_14_days"] = distinct_days_14d
        base["species_total_count"] = total_count
        base["species_max_confidence"] = max_conf
        base["species_today"] = species_today

        # ─── Intended action: trust-based gate ──────────────────────────
        intended_check = False
        intended_reason = None
        intended_priority = 2

        if (count_14d < cfg["establish_count"]
                or distinct_days_14d < cfg["establish_distinct_days"]):
            intended_check = True
            intended_reason = "unestablished-species"
            intended_priority = 1
        elif conf_val is not None and conf_val >= cfg["skip_floor"]:
            intended_check = False
            intended_reason = "high-confidence-skip"
        elif max_conf >= cfg["trust_fast_confidence"]:
            intended_check = False
            intended_reason = "trusted-fast-path"
        elif (total_count >= cfg["trust_slow_count"]
              and max_conf >= cfg["trust_slow_confidence"]):
            intended_check = False
            intended_reason = "trusted-slow-path"
        else:
            intended_check = True
            intended_reason = "untrusted"
            intended_priority = (
                1 if (prob is not None and prob < cfg["marginal_prob"]) else 2
            )

        # ─── Audit sampling: flip a fraction of skips into checks ───────
        # perchpi:e7-audit-sample-v1 — generates ground-truth on the
        # population the gate THINKS is safe to skip. Use queries on
        # ai_decisions.skip_reason='audit-sample' to validate gate health.
        if (not intended_check
                and cfg["audit_sample_rate"] > 0.0
                and _random.random() < cfg["audit_sample_rate"]):
            intended_check = True
            intended_reason = "audit-sample"
            intended_priority = 2

        # ─── Per-species daily cap: budget protection ───────────────────
        # perchpi:e7-per-species-cap-v1 — prevents a single misbehaving
        # species from burning the daily AI quota. Counts action='called'
        # rows today across both gates (old + e7).
        if intended_check:
            calls_today_this_species = 0
            try:
                r = cur.execute(
                    "SELECT COUNT(*) FROM ai_decisions "
                    "WHERE species_sci = ? AND action = 'called' "
                    "  AND created_at >= datetime('now', 'start of day') /* perchpi:utc-mig-ai-check-v1 */",
                    (sci_name,),
                ).fetchone()
                if r:
                    calls_today_this_species = int(r[0] or 0)
            except Exception:
                pass
            if calls_today_this_species >= cfg["per_species_daily_cap"]:
                intended_check = False
                intended_reason = "per-species-cap"

        base["check"] = intended_check
        base["reason"] = intended_reason
        base["priority"] = intended_priority
        return base
    except Exception:
        # perchpi:e7-failure-open-v1 — Top-level failure defaults to CHECK
        # (was SKIP under e3). Rationale: a broken gate should let the
        # safety net catch suspicious detections rather than silently
        # disable false-positive detection. Bounded by rate-limit + cap.
        base["check"] = True
        base["reason"] = "filter-error"
        base["priority"] = 2
        return base


# ═════════════════════════════════════════════════════════════════════════
# perchpi:e4-rate-limit-v1 — Client-side rate limiting.
# Queries ai_decisions to count recent API attempts. Skips next call if
# approaching configured RPM/RPD caps. Fail-SAFE on any error: skip the call.
# ═════════════════════════════════════════════════════════════════════════


def _pp_check_rate_limits(cur):
    """Decide whether the next API call is permitted under client-side caps.

    Counts ai_decisions rows where action attempted the API (called, errored,
    rate_limited) within the last minute and last 24h. Compares against caps
    from _pp_load_filter_config (rate_rpm_max, rate_rpd_max).

    Returns dict:
      allowed (bool)         - True if call is permitted
      reason (str)           - 'within-limits' | 'rpm-cap' | 'rpd-cap' |
                               'rate-check-error'
      calls_last_minute (int|None)
      calls_today (int|None)
    """
    result = {
        "allowed": False,
        "reason": "rate-check-error",
        "calls_last_minute": None,
        "calls_today": None,
    }
    try:
        rpm_row = cur.execute(
            "SELECT COUNT(*) FROM ai_decisions "
            "WHERE action IN ('called', 'errored', 'rate_limited') "
            "  AND created_at >= datetime('now', '-1 minute') /* perchpi:utc-mig-ai-check-v1 */"
        ).fetchone()
        calls_last_minute = int(rpm_row[0]) if rpm_row else 0

        rpd_row = cur.execute(
            "SELECT COUNT(*) FROM ai_decisions "
            "WHERE action IN ('called', 'errored', 'rate_limited') "
            "  AND created_at >= datetime('now', '-1 day') /* perchpi:utc-mig-ai-check-v1 */"
        ).fetchone()
        calls_today = int(rpd_row[0]) if rpd_row else 0

        result["calls_last_minute"] = calls_last_minute
        result["calls_today"] = calls_today

        cfg = _pp_load_filter_config()
        rpm_max = cfg.get("rate_rpm_max", 8)
        rpd_max = cfg.get("rate_rpd_max", 200)

        if calls_last_minute >= rpm_max:
            result["reason"] = "rpm-cap"
            return result
        if calls_today >= rpd_max:
            result["reason"] = "rpd-cap"
            return result

        result["allowed"] = True
        result["reason"] = "within-limits"
        return result
    except Exception:
        # Fail SAFE: do NOT call AI on error.
        return result


def _pp_check_with_ai(cur, detection_id, prob, threshold):
    """Ask Gemini whether this geomodel-passing detection is a false positive.

    Returns a dict with telemetry fields:
      verdict ('real' | 'false_positive' | 'error'),
      verdict_confidence, verdict_reasoning,
      species_sci, species_com, confidence, species_today,
      http_status, latency_ms, model_used,
      prompt_tokens, response_tokens, error_message,
      action ('called' | 'errored' | 'rate_limited').

    Caller logs the dict via _pp_log_ai_decision() and branches on verdict.
    Errors are returned (never raised). Quarantine logic is unchanged:
    only verdict=='false_positive' triggers quarantine downstream.
    """
    import time as _time
    result = {
        "verdict": "real",
        "verdict_confidence": None,
        "verdict_reasoning": None,
        "species_sci": None,
        "species_com": None,
        "confidence": None,
        "species_today": None,
        "http_status": None,
        "latency_ms": None,
        "model_used": "gemini-2.5-flash",
        "prompt_tokens": None,
        "response_tokens": None,
        "error_message": None,
        "action": "errored",
    }
    try:
        enabled, api_key = _pp_load_ai_fp_config()
        if not enabled or not api_key:
            result["error_message"] = "ai_disabled_or_no_key"
            return result

        row = cur.execute(
            "SELECT Sci_Name, Com_Name, Date, Time, Confidence "
            "FROM detections WHERE ROWID = ?",
            (detection_id,),
        ).fetchone()
        if not row:
            result["verdict"] = "error"
            result["error_message"] = "detection_row_not_found"
            return result
        sci_name, com_name, date, ttime, conf = row
        result["species_sci"] = sci_name
        result["species_com"] = com_name
        result["confidence"] = conf

        cluster = cur.execute(
            "SELECT COUNT(*), MIN(Confidence), MAX(Confidence) "
            "FROM detections "
            "WHERE Sci_Name = ? "
            "  AND datetime(Date || ' ' || Time) >= datetime('now', '-24 hours')",
            (sci_name,),
        ).fetchone()
        cluster_count = cluster[0] if cluster else 1
        cluster_min = round((cluster[1] or 0) * 100, 1) if cluster else 0
        cluster_max = round((cluster[2] or 0) * 100, 1) if cluster else 0
        result["species_today"] = cluster_count

        prompt = (
            "You are evaluating whether an acoustic bird detection from BirdNET-Pi "
            "is a real bird or a likely false positive. Be CONSERVATIVE — only "
            "judge 'false_positive' if there is strong pattern evidence. When "
            "uncertain, prefer 'real' or 'uncertain'.\n\n"
            "DETECTION:\n"
            f"- Species: {com_name} ({sci_name})\n"
            f"- When: {date} at {ttime} (24h clock, local time, US Indiana)\n"
            f"- BirdNET confidence: {round(conf*100, 1)}%\n"
            f"- Geomodel weekly probability: {round(prob, 4)} (above {threshold} "
            "threshold, so geographically plausible at this location and week)\n"
            f"- 24h cluster: {cluster_count} detection(s) of this species in last "
            f"24h; confidence range {cluster_min}%–{cluster_max}%\n\n"
            "FALSE POSITIVE SIGNS:\n"
            "- Mechanical noise (HVAC hum, traffic, wind in trees, electronic buzz)\n"
            "- Many detections in tight time window with uniformly high confidence "
            "(suggests sustained noise event)\n"
            "- Species detected at clearly wrong time-of-day (e.g., owls at noon, "
            "diurnal songbirds at midnight)\n"
            "- Acoustically-similar species often confused (e.g., insect trills "
            "vs screech-owl, House Finch chips vs Evening Grosbeak)\n\n"
            "REAL BIRD SIGNS:\n"
            "- Variable confidence values across cluster\n"
            "- Time-of-day matches species ecology\n"
            "- Reasonable temporal spacing (not back-to-back constant)\n"
            "- Single or few detections (lone calls common)\n\n"
            'Respond with ONLY valid JSON of the form: '
            '{"verdict": "real" | "false_positive" | "uncertain", '
            '"confidence": 0.0-1.0, "reasoning": "brief, under 100 chars"}'
        )

        import urllib.request as _urlreq
        import urllib.error as _urlerr
        import json as _json

        url = (
            "https://generativelanguage.googleapis.com/v1beta/"
            "models/gemini-2.5-flash:generateContent?key=" + api_key
        )
        body = _json.dumps({
            "contents": [{"parts": [{"text": prompt}]}],
            "generationConfig": {
                "temperature": 0.3,
                "responseMimeType": "application/json",
            },
        }).encode("utf-8")
        req = _urlreq.Request(
            url,
            data=body,
            headers={"Content-Type": "application/json"},
        )

        t_start = _time.monotonic()
        resp_data = None
        try:
            with _urlreq.urlopen(req, timeout=30) as resp:  # perchpi:ai-queue-resilience-v1 (was timeout=10)
                result["http_status"] = resp.getcode()
                resp_data = _json.loads(resp.read().decode("utf-8"))
        except _urlerr.HTTPError as e:
            result["latency_ms"] = int((_time.monotonic() - t_start) * 1000)
            result["http_status"] = e.code
            result["error_message"] = "HTTPError " + str(e.code)
            result["action"] = "rate_limited" if e.code == 429 else "errored"
            return result
        except _urlerr.URLError as e:
            result["latency_ms"] = int((_time.monotonic() - t_start) * 1000)
            result["error_message"] = "URLError: " + str(getattr(e, "reason", e))
            result["action"] = "errored"
            return result
        except (TimeoutError, OSError) as e:
            result["latency_ms"] = int((_time.monotonic() - t_start) * 1000)
            result["error_message"] = type(e).__name__ + ": " + str(e)
            result["action"] = "errored"
            return result
        except Exception as e:
            result["latency_ms"] = int((_time.monotonic() - t_start) * 1000)
            result["error_message"] = type(e).__name__ + ": " + str(e)
            result["action"] = "errored"
            return result

        result["latency_ms"] = int((_time.monotonic() - t_start) * 1000)

        try:
            usage = resp_data.get("usageMetadata", {}) if isinstance(resp_data, dict) else {}
            result["prompt_tokens"] = usage.get("promptTokenCount")
            result["response_tokens"] = usage.get("candidatesTokenCount")
        except Exception:
            pass

        try:
            text = resp_data["candidates"][0]["content"]["parts"][0]["text"]
            judgment = _json.loads(text)
            verdict = judgment.get("verdict", "uncertain")
            conf_score = float(judgment.get("confidence", 0.0))
            reasoning = judgment.get("reasoning")
            result["verdict_confidence"] = conf_score
            result["verdict_reasoning"] = reasoning
        except (KeyError, IndexError, ValueError, TypeError) as e:
            result["error_message"] = "parse_error: " + type(e).__name__
            result["action"] = "errored"
            return result

        result["action"] = "called"
        if verdict == "false_positive" and conf_score >= 0.7:
            result["verdict"] = "false_positive"
        else:
            result["verdict"] = "real"
        return result
    except Exception as e:
        result["error_message"] = "unhandled: " + type(e).__name__ + ": " + str(e)
        result["action"] = "errored"
        return result
