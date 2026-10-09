import glob
import json
import logging
import os
import sqlite3
import subprocess
import tempfile
import io
import soundfile
from time import sleep
from datetime import datetime, timezone  # perchpi:utc-mig-worker-write-v1

import requests
from PIL import Image, ImageDraw, ImageFont

from .helpers import get_settings, get_font, DB_PATH
from .classes import Detection, ParseFileName
from .notifications import sendAppriseNotifications
import numpy as np
import librosa

log = logging.getLogger(__name__)


# perchpi:e6-import-helpers-v1 — AI helpers moved to utils/ai_check.py
try:
    from utils.ai_check import (
        _pp_load_ai_fp_config,
        _pp_load_filter_config,
        _pp_log_ai_decision,
        _pp_should_check_with_ai,
        _pp_check_rate_limits,
        _pp_check_with_ai,
    )
except ImportError:
    import os as _pp_e6_os
    import sys as _pp_e6_sys
    _pp_e6_sys.path.insert(
        0, _pp_e6_os.path.dirname(_pp_e6_os.path.abspath(__file__))
    )
    from ai_check import (
        _pp_load_ai_fp_config,
        _pp_load_filter_config,
        _pp_log_ai_decision,
        _pp_should_check_with_ai,
        _pp_check_rate_limits,
        _pp_check_with_ai,
    )


def extract(in_file, out_file, start, stop):
    result = subprocess.run(['sox', '-V1', f'{in_file}', f'{out_file}', 'trim', f'={start}', f'={stop}'],
                            check=True, capture_output=True)
    ret = result.stdout.decode('utf-8')
    err = result.stderr.decode('utf-8')
    if err:
        raise RuntimeError(f'{ret}:\n {err}')
    return ret


def extract_safe(in_file, out_file, start, stop):
    conf = get_settings()
    # This section sets the SPACER that will be used to pad the audio clip with
    # context. If EXTRACTION_LENGTH is 10, for instance, 3 seconds are removed
    # from that value and divided by 2, so that the 3 seconds of the call are
    # within 3.5 seconds of audio context before and after.
    try:
        ex_len = conf.getint('EXTRACTION_LENGTH')
    except ValueError:
        ex_len = 6
    spacer = (ex_len - 3) / 2
    safe_start = max(0, start - spacer)
    safe_stop = min(conf.getint('RECORDING_LENGTH'), stop + spacer)

    extract(in_file, out_file, safe_start, safe_stop)


# perchpi:specgram-v2-tier2-constants-v1
# Pre-computed once at module import; used by spectrogram() below.
# See pp_spectrogram_v2 design doc (Google Drive, 2026-05-25) section 4.4.
from roseus.cmap.roseus import rgb_data as _PP_ROSEUS_RGB_DATA

_PP_SPECGRAM_N_FFT = 512
_PP_SPECGRAM_HOP_LENGTH = 128
_PP_SPECGRAM_WIN_LENGTH = 512
_PP_SPECGRAM_WINDOW = 'hann'
_PP_SPECGRAM_N_MELS = 64
_PP_SPECGRAM_GAIN_DB = 20
_PP_SPECGRAM_RANGE_DB = 80
_PP_SPECGRAM_DB_FLOOR = -_PP_SPECGRAM_RANGE_DB
_PP_SPECGRAM_DB_CEILING = -_PP_SPECGRAM_GAIN_DB
_PP_SPECGRAM_DISPLAY_W = 640
_PP_SPECGRAM_DISPLAY_H = 360

_PP_ROSEUS_LUT_U8 = np.clip(
    np.asarray(_PP_ROSEUS_RGB_DATA) * 255, 0, 255).astype(np.uint8)
_PP_MEL_FB_48K = librosa.filters.mel(
    sr=48000, n_fft=_PP_SPECGRAM_N_FFT, n_mels=_PP_SPECGRAM_N_MELS,
    fmin=0, fmax=24000, htk=False)


def spectrogram(in_file, title, comment, raw=0):
    """Generate a librosa+roseus mel-spectrogram PNG for in_file.

    Marker: perchpi:specgram-v2-tier2-librosa-v1

    Writes `{in_file}.png` at
    _PP_SPECGRAM_DISPLAY_W x _PP_SPECGRAM_DISPLAY_H (default 640x360).

    The `title`, `comment`, and `raw` arguments are accepted for
    backward compatibility with the previous sox-based implementation
    but are intentionally unused. Title/path overlay dropped 2026-05-25
    per pp_spectrogram_v2 design; `raw` was sox-specific.
    """
    del title, comment, raw  # accepted for back-compat; intentionally unused
    y, sr = soundfile.read(in_file)
    if y.ndim > 1:
        y = y[:, 0]

    if sr == 48000:
        mel_fb = _PP_MEL_FB_48K
    else:
        mel_fb = librosa.filters.mel(
            sr=sr, n_fft=_PP_SPECGRAM_N_FFT,
            n_mels=_PP_SPECGRAM_N_MELS,
            fmin=0, fmax=sr / 2, htk=False)

    D = librosa.stft(
        y, n_fft=_PP_SPECGRAM_N_FFT,
        hop_length=_PP_SPECGRAM_HOP_LENGTH,
        win_length=_PP_SPECGRAM_WIN_LENGTH,
        window=_PP_SPECGRAM_WINDOW, center=True)
    P = np.abs(D) ** 2
    S = mel_fb @ P

    S_db = librosa.power_to_db(S, ref=np.max, top_db=None)
    img01 = np.clip(
        (S_db - _PP_SPECGRAM_DB_FLOOR) /
        (_PP_SPECGRAM_DB_CEILING - _PP_SPECGRAM_DB_FLOOR),
        0.0, 1.0)
    img_idx = (img01 * 255).astype(np.uint8)
    rgb = _PP_ROSEUS_LUT_U8[img_idx]
    rgb = np.flipud(rgb)

    Image.fromarray(rgb).resize(
        (_PP_SPECGRAM_DISPLAY_W, _PP_SPECGRAM_DISPLAY_H),
        Image.LANCZOS,
    ).save(f'{in_file}.png', optimize=False, compress_level=1)


def extract_detection(file: ParseFileName, detection: Detection):
    conf = get_settings()
    new_file_name = f'{detection.common_name_safe}-{detection.confidence_pct}-{detection.date}-birdnet-{file.RTSP_id}{detection.time}.{conf["AUDIOFMT"]}'
    new_dir = os.path.join(conf['EXTRACTED'], 'By_Date', f'{detection.date}', f'{detection.common_name_safe}')
    new_file = os.path.join(new_dir, new_file_name)
    if os.path.isfile(new_file):
        log.warning('Extraction exists. Moving on: %s', new_file)
    else:
        os.makedirs(new_dir, exist_ok=True)
        extract_safe(file.file_name, new_file, detection.start, detection.stop)
        spectrogram(new_file, detection.common_name, new_file.replace(os.path.expanduser('~/'), ''), conf['RAW_SPECTROGRAM'])
    return new_file


def write_to_db(file: ParseFileName, detection: Detection):
    conf = get_settings()
    # Connect to SQLite Database
    for attempt_number in range(3):
        try:
            con = sqlite3.connect(DB_PATH)
            cur = con.cursor()
            # perchpi:reporting-insert-columns-v1
            # perchpi:utc-mig-worker-write-v1
            _pp_utc_now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
            cur.execute("INSERT INTO detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Detected_At_UTC) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        (detection.date, detection.time, detection.scientific_name, detection.common_name, detection.confidence,
                         conf['LATITUDE'], conf['LONGITUDE'], conf['CONFIDENCE'], str(detection.week), conf['SENSITIVITY'],
                         conf['OVERLAP'], os.path.basename(detection.file_name_extr), _pp_utc_now))
            # perchpi:quarantine-call-v1
            try:
                _pp_did = cur.lastrowid
                if _pp_did is not None:
                    _pp_maybe_quarantine_by_rowid(cur, _pp_did)
            except Exception:
                pass  # Quarantine never breaks main detection write
            # (Date, Time, Sci_Name, Com_Name, str(score),
            # Lat, Lon, Cutoff, Week, Sens,
            # Overlap, File_Name))

            con.commit()
            con.close()
            break
        except BaseException as e:
            log.warning("Database busy: %s", e)
            sleep(2)


def summary(file: ParseFileName, detection: Detection):
    # Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap
    # 2023-03-03;12:48:01;Phleocryptes melanops;Wren-like Rushbird;0.76950216;-1;-1;0.7;9;1.25;0.0
    conf = get_settings()
    s = (f'{detection.date};{detection.time};{detection.scientific_name};{detection.common_name};'
         f'{detection.confidence};'
         f'{conf["LATITUDE"]};{conf["LONGITUDE"]};{conf["CONFIDENCE"]};{detection.week};{conf["SENSITIVITY"]};'
         f'{conf["OVERLAP"]}')
    return s


def write_to_file(file: ParseFileName, detection: Detection):
    with open(os.path.expanduser('~/BirdNET-Pi/BirdDB.txt'), 'a') as rfile:
        rfile.write(f'{summary(file, detection)}\n')


def update_json_file(file: ParseFileName, detections: [Detection]):
    if file.RTSP_id is None:
        mask = f'{os.path.dirname(file.file_name)}/*.json'
    else:
        mask = f'{os.path.dirname(file.file_name)}/*{file.RTSP_id}*.json'
    for f in glob.glob(mask):
        log.debug(f'deleting {f}')
        os.remove(f)
    write_to_json_file(file, detections)


def write_to_json_file(file: ParseFileName, detections: [Detection]):
    conf = get_settings()
    json_file = f'{file.file_name}.json'
    log.debug(f'WRITING RESULTS TO {json_file}')
    dets = {'file_name': os.path.basename(json_file), 'timestamp': file.iso8601, 'delay': conf['RECORDING_LENGTH'],
            'detections': [{"start": det.start, "common_name": det.common_name, "confidence": det.confidence} for det in
                           detections]}
    with open(json_file, 'w') as rfile:
        rfile.write(json.dumps(dets))
    log.debug(f'DONE! WROTE {len(detections)} RESULTS.')


def apprise(file: ParseFileName, detections: [Detection]):
    species_apprised_this_run = []
    conf = get_settings()

    for detection in detections:
        # Apprise of detection if not already alerted this run.
        if detection.species not in species_apprised_this_run:
            try:
                sendAppriseNotifications(detection.scientific_name, detection.common_name, str(detection.confidence), str(detection.confidence_pct),
                                         os.path.basename(detection.file_name_extr), detection.date, detection.time, str(detection.week),
                                         conf['LATITUDE'], conf['LONGITUDE'], conf['CONFIDENCE'], conf['SENSITIVITY'], conf['OVERLAP'])

            except BaseException as e:
                log.exception('Error during Apprise:', exc_info=e)

            species_apprised_this_run.append(detection.species)


def bird_weather(file: ParseFileName, detections: [Detection]):
    conf = get_settings()
    if conf['BIRDWEATHER_ID'] == "":
        return
    if detections:
        try:
            data, samplerate = soundfile.read(file.file_name)
            buf = io.BytesIO()
            soundfile.write(buf, data, samplerate, format='FLAC')
            flac_data = buf.getvalue()
        except Exception as e:
            log.error("Error during FLAC conversion: %s", e)
            return

        # POST soundscape to server
        soundscape_url = (f'https://app.birdweather.com/api/v1/stations/'
                          f'{conf["BIRDWEATHER_ID"]}/soundscapes?timestamp={file.iso8601}')

        try:
            response = requests.post(url=soundscape_url, data=flac_data, timeout=30,
                                     headers={'Content-Type': 'audio/flac'})
            log.info("Soundscape POST Response Status - %d", response.status_code)
            sdata = response.json()
        except BaseException as e:
            log.error("Cannot POST soundscape: %s", e)
            return
        if not sdata.get('success'):
            log.error(sdata.get('message'))
            return
        soundscape_id = sdata['soundscape']['id']

        for detection in detections:
            # POST detection to server
            detection_url = f'https://app.birdweather.com/api/v1/stations/{conf["BIRDWEATHER_ID"]}/detections'

            data = {'timestamp': detection.iso8601, 'lat': conf['LATITUDE'], 'lon': conf['LONGITUDE'],
                    'soundscapeId': soundscape_id,
                    'soundscapeStartTime': detection.start, 'soundscapeEndTime': detection.stop,
                    'commonName': detection.common_name, 'scientificName': detection.scientific_name,
                    'algorithm': '2p4' if conf['MODEL'] == 'BirdNET_GLOBAL_6K_V2.4_Model_FP16' else 'alpha',
                    'confidence': detection.confidence}

            log.debug(data)
            try:
                response = requests.post(detection_url, json=data, timeout=20)
                log.info("Detection POST Response Status - %d", response.status_code)
            except BaseException as e:
                log.error("Cannot POST detection: %s", e)


def heartbeat():
    conf = get_settings()
    if conf['HEARTBEAT_URL']:
        try:
            result = requests.get(url=conf['HEARTBEAT_URL'], timeout=10)
            log.info('Heartbeat: %s', result.text)
        except BaseException as e:
            log.error('Error during heartbeat: %s', e)


# ════════════════════════════════════════════════════════════════════════════
# perchpi:quarantine-helpers-v1
# Injected by deploy-quarantine-backend-waveQ3.1a.sh
#
# Quarantine logic for geomodel-rare detections.
# Defensive: any failure here results in NO quarantine; main detection write
# is unaffected. Quarantine NEVER breaks the analysis pipeline.
# ════════════════════════════════════════════════════════════════════════════
import json as _pp_json
import datetime as _pp_datetime
import os as _pp_os
from pathlib import Path as _PpPath

# Module-level caches; loaded on first call after process start.
_pp_geomodel_cache = None
_pp_geomodel_loaded = False
_pp_threshold = None
_pp_use_geomodel = None


def _pp_load_quarantine_config():
    """Returns (use_geomodel: bool, threshold: float). Cached after first call.

    Reads /etc/birdnet/birdnet.conf line-by-line (no configparser dependency
    in case birdnet.conf has shell-style quoting).
    """
    global _pp_threshold, _pp_use_geomodel
    if _pp_threshold is not None:
        return _pp_use_geomodel, _pp_threshold
    use_geomodel = False
    threshold = 0.05
    try:
        with open("/etc/birdnet/birdnet.conf") as f:
            for line in f:
                line = line.strip()
                if line.startswith("#") or "=" not in line:
                    continue
                k, _, v = line.partition("=")
                k = k.strip()
                v = v.strip().strip('"').strip("'")
                if k == "USE_GEOMODEL":
                    use_geomodel = (v == "1")
                elif k == "QUARANTINE_THRESHOLD":
                    try:
                        threshold = float(v)
                    except ValueError:
                        pass  # fall back to 0.05
    except (OSError, IOError):
        pass  # fail open: defaults
    _pp_use_geomodel = use_geomodel
    _pp_threshold = threshold
    return use_geomodel, threshold


def _pp_load_geomodel():
    """Returns the geomodel predictions dict, or None if unavailable. Cached."""
    global _pp_geomodel_cache, _pp_geomodel_loaded
    if _pp_geomodel_loaded:
        return _pp_geomodel_cache
    _pp_geomodel_loaded = True
    geomodel_dir = None
    try:
        with open("/etc/birdnet/birdnet.conf") as f:
            for line in f:
                line = line.strip()
                if line.startswith("GEOMODEL_DIR="):
                    geomodel_dir = (
                        line.split("=", 1)[1].strip().strip('"').strip("'")
                    )
                    break
    except (OSError, IOError):
        return None
    if not geomodel_dir:
        geomodel_dir = _pp_os.path.expanduser("~/BirdNET-Pi/geomodel")
    json_path = _PpPath(geomodel_dir) / "predictions.json"
    try:
        with open(json_path) as f:
            _pp_geomodel_cache = _pp_json.load(f)
    except (OSError, IOError, ValueError):
        _pp_geomodel_cache = None
    return _pp_geomodel_cache


# ════════════════════════════════════════════════════════════════════════════
# perchpi:ai-fp-helpers-v1 — AI False Positive Checker via Gemini API
# Activated when AI_FALSE_POSITIVE_ENABLED=1 and GEMINI_API_KEY is set in
# birdnet.conf. Called from _pp_maybe_quarantine_by_rowid for detections that
# PASS the geomodel threshold (i.e., species is plausible at this location +
# week) — checks acoustic/pattern false positives the geomodel cannot catch
# (e.g., wind/insect noise classified as Eastern Screech-Owl).
#
# Failure-open: any error (no key, network fail, bad response) => return
# 'real', no quarantine written. Detection write is NEVER blocked.
# ════════════════════════════════════════════════════════════════════════════
def _pp_maybe_quarantine_by_rowid(cur, detection_id):
    """If detection's species is geomodel-rare, INSERT into quarantined_detections.

    Looks up Sci_Name from the just-inserted ROWID (avoids fragile arg-tuple
    parsing). Defensive: any error => no-op + return False.

    Returns True iff a quarantine row was written.
    """
    try:
        use_geomodel, threshold = _pp_load_quarantine_config()
        if not use_geomodel:
            return False
        data = _pp_load_geomodel()
        if not data:
            return False
        row = cur.execute(
            "SELECT Sci_Name FROM detections WHERE ROWID = ?",
            (detection_id,),
        ).fetchone()
        if not row:
            return False
        sci_name = row[0]
        # perchpi:embedding-pending-hook-quarantine-v1 — preserve BirdNET
        # taxonomy name for ai_decisions logging (downstream uses BirdNET
        # names; the taxonomy alias below is for geomodel lookup only).
        birdnet_sci_name = sci_name
        # perchpi:taxonomy-alias-map-v1 — BirdNET ships with older AOS taxonomy;
        # the geomodel uses post-2024 AOS taxonomy. Without these aliases,
        # affected species silently skip quarantine (Cooper's Hawk, Evening
        # Grosbeak, Hairy Woodpecker, Brown Jay). Future AOS updates: add new
        # mappings here AND in homepage/perchpi_helpers.php (same marker name).
        _PP_TAXONOMY_ALIASES = {
            "Accipiter cooperii":         "Astur cooperii",
            "Coccothraustes vespertinus": "Hesperiphona vespertina",
            "Dryobates villosus":         "Leuconotopicus villosus",
            "Psilorhinus morio":          "Cyanocorax morio",
            "Apus melba":                 "Tachymarptis melba",
            "Basileuterus lachrymosus":   "Euthlypis lachrymosa",
            "Cicinnurus magnificus":      "Diphyllodes magnificus",
            "Dryobates borealis":         "Leuconotopicus borealis",
            "Ixobrychus exilis":          "Botaurus exilis",
            "Ixobrychus minutus":         "Botaurus minutus",
        }
        sci_name = _PP_TAXONOMY_ALIASES.get(sci_name, sci_name)
        # perchpi:quarantine-no-data-v1 — species absent from
        # geomodel (e.g. Old World species like Kentish Plover
        # detected in N America) are auto-quarantined as
        # 'geomodel_no_data'. Reversible via Quarantine page.
        species = data.get("species", {}).get(sci_name)
        prob = species.get("current") if species else None
        # perchpi:geomodel-prob-persist-v1 — hoist week + persist both
        # axes on the detections row for EVERY detection (kept or
        # quarantined), before branching. Confidence (acoustic) is already
        # on the row from the L175 INSERT; this adds the location-
        # probability axis. NULL prob here = no_data species (evaluated,
        # absent from geomodel) vs historical NULL (pre-migration row).
        week = data.get("metadata", {}).get("current_week")
        cur.execute(
            "UPDATE detections SET Geomodel_Prob = ?, Geomodel_Week = ? "
            "WHERE ROWID = ?",
            (prob, week, detection_id),
        )
        if prob is None:
            week = data.get("metadata", {}).get("current_week")
            ts = (
                _pp_datetime.datetime.utcnow()
                .isoformat(timespec="seconds")
                + "Z"
            )
            cur.execute(
                "INSERT INTO quarantined_detections "
                "(detection_id, quarantined_at, reason, geomodel_probability, "
                " geomodel_week, threshold_at_quarantine) "
                "VALUES (?, ?, 'geomodel_no_data', NULL, ?, ?)",
                (detection_id, ts, week, threshold),
            )
            # perchpi:embedding-pending-hook-quarantine-v1 — Phase 2 prep:
            # write ai_decisions placeholder + enqueue embedding so quarantined
            # detections also get a Perch logit. Fail-open: detection write
            # must NEVER break on logging failure.
            try:
                _pp_log_ai_decision(
                    cur,
                    detection_id=detection_id,
                    species_sci=birdnet_sci_name,
                    geomodel_prob=None,
                    action='quarantined',
                    skip_reason='geomodel_no_data',
                )
                cur.execute(
                    "INSERT OR IGNORE INTO embedding_pending "
                    "(detection_id, queued_at) "
                    "VALUES (?, datetime('now','localtime'))",
                    (detection_id,),
                )
            except Exception:
                pass
            return True
        # perchpi:ai-fp-quarantine-branch-v1 — branch by prob vs threshold
        # perchpi:e2-ai-logging-v1 — writes telemetry row per decision
        # perchpi:e3-cluster-filter-v1 — pre-filter via cluster rules
        if prob >= threshold:
            filter_result = _pp_should_check_with_ai(
                cur, detection_id, prob, threshold
            )
            if not filter_result['check']:
                # perchpi:e7-trust-gate-v1 — skipped path logs e7 history fields.
                _pp_log_ai_decision(
                    cur,
                    detection_id=detection_id,
                    species_sci=filter_result.get('species_sci'),
                    species_com=filter_result.get('species_com'),
                    confidence=filter_result.get('confidence'),
                    geomodel_prob=prob,
                    cluster_5min=filter_result.get('cluster_5min'),
                    cluster_30min=filter_result.get('cluster_30min'),
                    species_today=filter_result.get('species_today'),
                    species_total_count=filter_result.get('species_total_count'),
                    species_max_confidence=filter_result.get('species_max_confidence'),
                    count_in_last_14_days=filter_result.get('count_in_last_14_days'),
                    distinct_days_in_last_14_days=filter_result.get('distinct_days_in_last_14_days'),
                    action='skipped',
                    skip_reason=filter_result.get('reason'),
                )
                # perchpi:embedding-pending-hook-v1
                try:
                    cur.execute(
                        "INSERT OR IGNORE INTO embedding_pending "
                        "(detection_id, queued_at) "
                        "VALUES (?, datetime('now','localtime'))",
                        (detection_id,),
                    )
                except Exception:
                    pass  # fail-open: detection write must not break
                return False
            # perchpi:e6-5b-enqueue-v1 — async AI: enqueue + log queued.
            # Rate limiting + AI call + quarantine deferred to the cron worker
            # at scripts/process_ai_queue.py. This branch must not block; the
            # producer thread returns immediately so detection writes stay fast.
            week = data.get("metadata", {}).get("current_week")
            ts = (
                _pp_datetime.datetime.utcnow()
                .isoformat(timespec="seconds")
                + "Z"
            )
            # perchpi:e7-trust-gate-v1 — priority from gate (1=urgent, 2=normal).
            cur.execute(
                "INSERT OR IGNORE INTO ai_pending_checks "
                "(detection_id, queued_at, attempts, geomodel_prob, "
                " geomodel_week, threshold_used, priority) "
                "VALUES (?, ?, 0, ?, ?, ?, ?)",
                (detection_id, ts, prob, week, threshold,
                 filter_result.get('priority', 2)),
            )
            _pp_log_ai_decision(
                cur,
                detection_id=detection_id,
                species_sci=filter_result.get('species_sci'),
                species_com=filter_result.get('species_com'),
                confidence=filter_result.get('confidence'),
                geomodel_prob=prob,
                cluster_5min=filter_result.get('cluster_5min'),
                cluster_30min=filter_result.get('cluster_30min'),
                species_today=filter_result.get('species_today'),
                species_total_count=filter_result.get('species_total_count'),
                species_max_confidence=filter_result.get('species_max_confidence'),
                count_in_last_14_days=filter_result.get('count_in_last_14_days'),
                distinct_days_in_last_14_days=filter_result.get('distinct_days_in_last_14_days'),
                action='queued',
            )
            # perchpi:embedding-pending-hook-v1
            try:
                cur.execute(
                    "INSERT OR IGNORE INTO embedding_pending "
                    "(detection_id, queued_at) "
                    "VALUES (?, datetime('now','localtime'))",
                    (detection_id,),
                )
            except Exception:
                pass  # fail-open: detection write must not break
            return False
        week = data.get("metadata", {}).get("current_week")
        ts = (
            _pp_datetime.datetime.utcnow()
            .isoformat(timespec="seconds")
            + "Z"
        )
        cur.execute(
            "INSERT INTO quarantined_detections "
            "(detection_id, quarantined_at, reason, geomodel_probability, "
            " geomodel_week, threshold_at_quarantine) "
            "VALUES (?, ?, 'geomodel_rare', ?, ?, ?)",
            (detection_id, ts, prob, week, threshold),
        )
        # perchpi:embedding-pending-hook-quarantine-v1 — Phase 2 prep:
        # write ai_decisions placeholder + enqueue embedding so quarantined
        # detections also get a Perch logit. Fail-open: detection write
        # must NEVER break on logging failure.
        try:
            _pp_log_ai_decision(
                cur,
                detection_id=detection_id,
                species_sci=birdnet_sci_name,
                geomodel_prob=prob,
                action='quarantined',
                skip_reason='geomodel_rare',
            )
            cur.execute(
                "INSERT OR IGNORE INTO embedding_pending "
                "(detection_id, queued_at) "
                "VALUES (?, datetime('now','localtime'))",
                (detection_id,),
            )
        except Exception:
            pass
        return True
    except Exception:
        # Quarantine MUST NOT break the main detection write.
        return False
# ════════════════════════════════════════════════════════════════════════════
# end perchpi:quarantine-helpers-v1
# ════════════════════════════════════════════════════════════════════════════
