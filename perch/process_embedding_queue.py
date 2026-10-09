#!/usr/bin/env python3
# perchpi:embedding-queue-worker-v1
"""PerchPi embedding-gate queue worker.

Drains embedding_pending by running Perch v2 inference on each detection's
audio file. Writes embedding_logit + embedding_verdict + embedding_latency_ms
to the earliest ai_decisions row for that detection_id.

Cron-launched every minute; flock-protected against overlap. Companion to
process_ai_queue.py; independent queue, independent worker.

Per row, in one transaction:
  - Look up detection metadata; if orphaned, drop queue row silently
  - Build FLAC path; if missing, write verdict='skipped' + drop
  - Run Perch inference (~0.4s on Pi 5)
  - If Perch label miss (~4% gap), write verdict='skipped' + drop
  - Compute verdict from logit vs per-species thresholds (default fallback)
  - UPDATE earliest ai_decisions row for this detection; DELETE queue row
"""

import datetime
import fcntl
import os
import sqlite3
import sys
import time
import traceback
from math import gcd

import numpy as np
import onnxruntime as ort
import soundfile as sf
from scipy.signal import resample_poly

# --- Configuration ---
DB_PATH      = "/home/birder/BirdNET-Pi/scripts/birds.db"
ONNX_PATH    = "/home/birder/BirdNET-Pi/perch/perch_v2.onnx"
LABELS_PATH  = "/home/birder/BirdNET-Pi/perch/labels.csv"

# perchpi:worker-taxonomy-alias-v1
# BirdNET ships some species under different scientific names than Perch's
# labels.csv lists. Without these aliases, affected species silently get
# verdict='skipped' instead of being embedded. Verified 2026-05-17 by
# walking ai_decisions skipped rows against labels.csv same-epithet matches.
# Distinct from reporting.py's geomodel taxonomy aliases (different taxonomy
# versions on each side). Add new mappings here AFTER confirming the Perch
# target name exists in labels.csv via:
#   grep -c "^<Perch name>$" /home/birder/BirdNET-Pi/perch/labels.csv
PERCH_TAXONOMY_ALIASES = {
    "Accipiter cooperii":      "Astur cooperii",          # Cooper's Hawk
    "Psilorhinus morio":       "Cyanocorax morio",        # Brown Jay
    "Empidonax occidentalis":  "Empidonax difficilis",    # 2023 AOS Western/Cordilleran lump
    "Ixobrychus exilis":       "Botaurus exilis",         # 2024 AOS bittern reclassification
}
AUDIO_ROOT   = "/home/birder/BirdSongs/Extracted/By_Date"
LOCK_PATH    = "/tmp/perchpi-embedding-worker.lock"
LOG_PATH     = "/home/birder/BirdNET-Pi/perch/worker.log"

MAX_PER_INVOCATION       = 60
MAX_ATTEMPTS             = 3
CLEARLY_TP_LOGIT_DEFAULT = 8.0
CLEARLY_FP_LOGIT_DEFAULT = 0.0
DB_TIMEOUT_S             = 30.0


# --- Logging ---
def log(msg):
    try:
        with open(LOG_PATH, "a") as f:
            f.write(f"{datetime.datetime.now().isoformat(timespec='seconds')} {msg}\n")
    except Exception:
        pass


# --- Locking ---
def acquire_lock_or_exit():
    fd = open(LOCK_PATH, "w")
    try:
        fcntl.flock(fd.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        return fd
    except BlockingIOError:
        sys.exit(1)


# --- Labels ---
def load_labels(path):
    with open(path) as f:
        next(f)
        labels = [line.strip() for line in f if line.strip()]
    name_to_idx = {sp: i for i, sp in enumerate(labels)}
    # perchpi:worker-taxonomy-alias-v1
    # Apply BirdNET->Perch aliases. Each alias entry points to the same
    # numeric index as the Perch canonical name, so any downstream
    # `name_to_idx[birdnet_name]` lookup just works.
    for birdnet_name, perch_name in PERCH_TAXONOMY_ALIASES.items():
        if perch_name in name_to_idx:
            name_to_idx[birdnet_name] = name_to_idx[perch_name]
    return labels, name_to_idx


# --- Audio ---
def prep_audio_5s_32k(wav_path):
    audio, sr = sf.read(wav_path)
    if audio.ndim > 1:
        audio = audio.mean(axis=1)
    want = int(5.0 * sr)
    if len(audio) > want:
        start = (len(audio) - want) // 2
        audio = audio[start:start + want]
    if sr != 32000:
        g = gcd(int(sr), 32000)
        audio = resample_poly(audio, 32000 // g, int(sr) // g)
    if len(audio) > 160000:
        audio = audio[:160000]
    else:
        audio = np.pad(audio, (0, 160000 - len(audio)))
    return audio.astype(np.float32)


def build_audio_path(date_s, com_name, file_name):
    com_under = com_name.replace(chr(39), "").replace(" ", "_")  # perchpi:embedding-worker-apostrophe-fix-v1
    return os.path.join(AUDIO_ROOT, date_s, com_under, file_name)


# --- DB helpers ---
def fetch_pending_batch(cur, limit):
    cur.execute(
        "SELECT detection_id, attempts FROM embedding_pending "
        "WHERE attempts < ? ORDER BY queued_at LIMIT ?",
        (MAX_ATTEMPTS, limit),
    )
    return cur.fetchall()


def fetch_detection(cur, det_id):
    cur.execute(
        "SELECT Date, Sci_Name, Com_Name, File_Name FROM detections WHERE ROWID = ?",
        (det_id,),
    )
    return cur.fetchone()


def fetch_threshold(cur, perch_idx):
    cur.execute(
        "SELECT clearly_tp_logit, clearly_fp_logit "
        "FROM species_embedding_thresholds WHERE perch_class_idx = ?",
        (perch_idx,),
    )
    row = cur.fetchone()
    # perchpi:fetch-threshold-decouple-v1 — independent per-field fallback so a per-species TP
    # threshold activates even while clearly_fp_logit is still NULL (and vice versa).
    tp = row[0] if (row and row[0] is not None) else CLEARLY_TP_LOGIT_DEFAULT
    fp = row[1] if (row and row[1] is not None) else CLEARLY_FP_LOGIT_DEFAULT
    return tp, fp


def write_embedding_result(cur, det_id, logit, verdict, latency_ms):
    """Pin embedding info to the earliest ai_decisions row for this detection."""
    cur.execute(
        "UPDATE ai_decisions SET embedding_logit = ?, embedding_verdict = ?, "
        "embedding_latency_ms = ? "
        "WHERE id = (SELECT MIN(id) FROM ai_decisions WHERE detection_id = ?)",
        (logit, verdict, latency_ms, det_id),
    )


def mark_attempt_failed(cur, det_id, err_msg, now):
    cur.execute(
        "UPDATE embedding_pending SET attempts = attempts + 1, "
        "last_error = ?, last_attempt_at = ? WHERE detection_id = ?",
        (err_msg[:500], now, det_id),
    )


def remove_pending(cur, det_id):
    cur.execute("DELETE FROM embedding_pending WHERE detection_id = ?", (det_id,))


# --- Main ---
def main():
    started = time.time()
    lock_fd = acquire_lock_or_exit()
    log("worker start")

    try:
        conn = sqlite3.connect(DB_PATH, timeout=DB_TIMEOUT_S)
        cur = conn.cursor()
    except Exception as e:
        log(f"FATAL: DB open failed: {e}")
        return 2

    cur.execute(
        "SELECT COUNT(*) FROM embedding_pending WHERE attempts < ?", (MAX_ATTEMPTS,)
    )
    pending_total = cur.fetchone()[0]
    if pending_total == 0:
        conn.close()
        log(f"queue empty (idle) elapsed={time.time()-started:.2f}s")
        return 0

    log(f"queue depth: {pending_total}")

    try:
        labels, name_to_idx = load_labels(LABELS_PATH)
        sess = ort.InferenceSession(ONNX_PATH, providers=["CPUExecutionProvider"])
        # perchpi:onnx-output-by-name-v1 — resolve output index by name to survive model upgrades
        _pp_out_names = [o.name for o in sess.get_outputs()]
        if "label" not in _pp_out_names:
            raise RuntimeError(
                f"Perch ONNX model has no output named 'label'. "
                f"Outputs present: {_pp_out_names}. Model may have been upgraded; "
                f"update perchpi:onnx-output-by-name-v1 patch with the new output name."
            )
        _PP_LOGITS_IDX = _pp_out_names.index("label")
    except Exception as e:
        log(f"FATAL: model/labels load failed: {e}\n{traceback.format_exc()}")
        conn.close()
        return 2

    pending = fetch_pending_batch(cur, MAX_PER_INVOCATION)
    stats = {"ok": 0, "skip_missing_file": 0, "skip_no_label": 0,
             "skip_orphan": 0, "errored": 0, "retry": 0}

    for det_id, attempts in pending:
        try:
            row = fetch_detection(cur, det_id)
            if not row:
                remove_pending(cur, det_id)
                conn.commit()
                stats["skip_orphan"] += 1
                continue
            date_s, sci_name, com_name, file_name = row
            wav_path = build_audio_path(date_s, com_name, file_name)
            if not os.path.exists(wav_path):
                write_embedding_result(cur, det_id, None, "skipped", None)
                remove_pending(cur, det_id)
                conn.commit()
                stats["skip_missing_file"] += 1
                continue

            t0 = time.time()
            audio = prep_audio_5s_32k(wav_path)
            outputs = sess.run(None, {"inputs": audio[None, :]})
            latency_ms = int((time.time() - t0) * 1000)

            perch_idx = name_to_idx.get(sci_name)
            if perch_idx is None:
                write_embedding_result(cur, det_id, None, "skipped", latency_ms)
                remove_pending(cur, det_id)
                conn.commit()
                stats["skip_no_label"] += 1
                continue

            logits = outputs[_PP_LOGITS_IDX][0]  # perchpi:onnx-output-by-name-v1
            logit = float(logits[perch_idx])
            tp_thresh, fp_thresh = fetch_threshold(cur, perch_idx)

            if logit >= tp_thresh:
                verdict = "accept"
            elif logit <= fp_thresh:
                verdict = "reject"
            else:
                verdict = "ambiguous"

            write_embedding_result(cur, det_id, logit, verdict, latency_ms)
            remove_pending(cur, det_id)
            conn.commit()
            stats["ok"] += 1

        except Exception as e:
            tb = traceback.format_exc()
            err_msg = str(e)
            log(f"ERROR det_id={det_id}: {err_msg}\n{tb}")
            new_attempts = attempts + 1
            now = datetime.datetime.now().isoformat(timespec="seconds")
            try:
                mark_attempt_failed(cur, det_id, err_msg, now)
                if new_attempts >= MAX_ATTEMPTS:
                    write_embedding_result(cur, det_id, None, "errored", None)
                    remove_pending(cur, det_id)
                    stats["errored"] += 1
                else:
                    stats["retry"] += 1
                conn.commit()
            except Exception as e2:
                log(f"ERROR: failed to record failure for det_id={det_id}: {e2}")

    conn.close()
    elapsed = time.time() - started
    log(
        f"done elapsed={elapsed:.2f}s ok={stats['ok']} retry={stats['retry']} "
        f"errored={stats['errored']} skip_missing_file={stats['skip_missing_file']} "
        f"skip_no_label={stats['skip_no_label']} skip_orphan={stats['skip_orphan']}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
