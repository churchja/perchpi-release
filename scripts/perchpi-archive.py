#!/usr/bin/env python3
"""
perchpi-archive v2 — rsync detections to UNAS, optionally rclone protected
detections to Google Drive (parking-lot items mm + oo).

Two independent phases, each gated by config:

Phase 1 (default ON, PERCHPI_ARCHIVE_TO_UNAS):
  For every detection older than PERCHPI_RETENTION_DAYS with archived_at IS NULL,
  rsync the audio to UNAS at the mirrored By_Date path. On success, set:
    archived_at      = datetime('now')  -- perchpi:utc-mig-archive-writers-v1 (UTC)
    archive_location = 'unas'

Phase 2 (default OFF, PERCHPI_GDRIVE_BACKUP):
  For every TOP-N PROTECTED detection (rn <= PERCHPI_PROTECT_N) older than
  PERCHPI_RETENTION_DAYS with gdrive_archived_at IS NULL, rclone copyto
  Google Drive at PERCHPI_GDRIVE_REMOTE/<date>/<species>/<file>.
  On success, set:
    gdrive_archived_at = datetime('now')  -- perchpi:utc-mig-archive-writers-v1 (UTC)

The two phases are independent; failure of one does not block the other.

Default mode: DRY RUN. Real uploads require --apply.

Configuration (read from /etc/birdnet/birdnet.conf):
  PERCHPI_RETENTION_DAYS     (default 45)
  PERCHPI_PROTECT_N          (default 5)
  PERCHPI_UNAS_ARCHIVE_PATH  (default /mnt/unas-pro/PerchPi/Archive/By_Date)
  PERCHPI_ARCHIVE_TO_UNAS    (default true)
  PERCHPI_GDRIVE_BACKUP      (default false)
  PERCHPI_GDRIVE_REMOTE      (default gdrive:PerchPi/Archive/By_Date)
"""

import argparse
import logging
import os
import re
import sqlite3
import subprocess
import sys
import time
from datetime import date, timedelta
from pathlib import Path

DEFAULT_DB = "/home/birder/BirdNET-Pi/scripts/birds.db"
DEFAULT_AUDIO_ROOT = "/home/birder/BirdSongs/Extracted/By_Date"
DEFAULT_UNAS_ARCHIVE = "/mnt/unas-pro/PerchPi/Archive/By_Date"
DEFAULT_GDRIVE_REMOTE = "gdrive:PerchPi/Archive/By_Date"
DEFAULT_CONF = "/etc/birdnet/birdnet.conf"
DEFAULT_RETENTION_DAYS = 45
DEFAULT_PROTECT_N = 5
DEFAULT_MAX_ARCHIVE = 5000
SQLITE_BUSY_TIMEOUT_MS = 30000


# ─── Config ──────────────────────────────────────────────────────────────

def load_config(conf_path):
    cfg = {
        "RETENTION_DAYS": DEFAULT_RETENTION_DAYS,
        "PROTECT_N": DEFAULT_PROTECT_N,
        "UNAS_ARCHIVE": DEFAULT_UNAS_ARCHIVE,
        "ARCHIVE_TO_UNAS": True,
        "GDRIVE_BACKUP": False,
        "GDRIVE_REMOTE": DEFAULT_GDRIVE_REMOTE,
    }
    if not os.path.exists(conf_path):
        return cfg
    try:
        with open(conf_path) as f:
            for line in f:
                line = line.strip()
                if line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                key = key.strip()
                value = value.strip().strip('"').strip("'")
                if key == "PERCHPI_RETENTION_DAYS":
                    try: cfg["RETENTION_DAYS"] = int(value)
                    except ValueError: pass
                elif key == "PERCHPI_PROTECT_N":
                    try: cfg["PROTECT_N"] = int(value)
                    except ValueError: pass
                elif key == "PERCHPI_UNAS_ARCHIVE_PATH":
                    cfg["UNAS_ARCHIVE"] = value
                elif key == "PERCHPI_ARCHIVE_TO_UNAS":
                    cfg["ARCHIVE_TO_UNAS"] = value.lower() in ("true", "1", "yes", "on")
                elif key == "PERCHPI_GDRIVE_BACKUP":
                    cfg["GDRIVE_BACKUP"] = value.lower() in ("true", "1", "yes", "on")
                elif key == "PERCHPI_GDRIVE_REMOTE":
                    cfg["GDRIVE_REMOTE"] = value
    except OSError as e:
        logging.warning("Could not parse %s: %s", conf_path, e)
    return cfg


# ─── Path helpers ────────────────────────────────────────────────────────

def com_name_to_folder(com_name):
    return re.sub(r"\s+", "_", com_name.replace("'", ""))


def source_path(audio_root, row):
    folder = com_name_to_folder(row["Com_Name"])
    return Path(audio_root) / row["Date"] / folder / row["File_Name"]


_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def safe_source_path(audio_root, row):
    """STAGE-2 S3-1 (perchpi:installer-s19a-path-guard-v1 (helper)): DB values become path
    parts, so a row holding '../' or an absolute name could reach outside the audio
    root. Return the source path only if it stays inside audio_root, else None."""
    name = row["File_Name"] or ""
    folder = com_name_to_folder(row["Com_Name"] or "")
    for part in (name, folder):
        if not part or "/" in part or "\\" in part or ".." in part:
            return None
    if not _DATE_RE.match(row["Date"] or ""):
        return None
    path = source_path(audio_root, row)
    if path.is_symlink():
        return None
    try:
        if not path.resolve().is_relative_to(Path(audio_root).resolve()):
            return None
    except (OSError, ValueError):
        return None
    return path


def unas_dest_path(unas_archive, row):
    folder = com_name_to_folder(row["Com_Name"])
    return Path(unas_archive) / row["Date"] / folder / row["File_Name"]


def gdrive_dest_path(gdrive_remote, row):
    folder = com_name_to_folder(row["Com_Name"])
    # rclone remote paths use forward slashes regardless of host OS
    return f"{gdrive_remote}/{row['Date']}/{folder}/{row['File_Name']}"


# ─── DB ──────────────────────────────────────────────────────────────────

def get_db_stats(db_path):
    conn = sqlite3.connect(db_path)
    conn.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    total = conn.execute(
        "SELECT COUNT(*) FROM detections WHERE File_Name IS NOT NULL AND File_Name != ''"
    ).fetchone()[0]
    archived_unas = conn.execute(
        "SELECT COUNT(*) FROM detections WHERE archived_at IS NOT NULL"
    ).fetchone()[0]
    archived_gdrive = conn.execute(
        "SELECT COUNT(*) FROM detections WHERE gdrive_archived_at IS NOT NULL"
    ).fetchone()[0]
    n_species = conn.execute(
        "SELECT COUNT(DISTINCT Com_Name) FROM detections "
        "WHERE File_Name IS NOT NULL AND File_Name != ''"
    ).fetchone()[0]
    conn.close()
    return {"total": total, "archived_unas": archived_unas,
            "archived_gdrive": archived_gdrive, "n_species": n_species}


def get_unas_candidates(db_path, retention_days):
    cutoff = (date.today() - timedelta(days=retention_days)).isoformat()
    sql = """
    WITH ranked AS (
      SELECT
        rowid, Date, Time, Com_Name, File_Name, Confidence, archived_at,
        ROW_NUMBER() OVER (
          PARTITION BY Com_Name
          ORDER BY Confidence DESC, Date DESC, Time DESC
        ) AS rn
      FROM detections
      WHERE File_Name IS NOT NULL AND File_Name != ''
    )
    SELECT rowid, Date, Time, Com_Name, File_Name, Confidence, rn
    FROM ranked
    WHERE archived_at IS NULL AND Date < ?
    ORDER BY Date ASC, Com_Name ASC, Time ASC
    """
    conn = sqlite3.connect(db_path)
    conn.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    conn.row_factory = sqlite3.Row
    rows = [dict(r) for r in conn.execute(sql, (cutoff,)).fetchall()]
    conn.close()
    return rows


def get_gdrive_candidates(db_path, retention_days, protect_n):
    """Top-N protected rows (rn <= protect_n) older than cutoff,
    not yet uploaded to GDrive."""
    cutoff = (date.today() - timedelta(days=retention_days)).isoformat()
    sql = """
    WITH ranked AS (
      SELECT
        rowid, Date, Time, Com_Name, File_Name, Confidence, gdrive_archived_at,
        ROW_NUMBER() OVER (
          PARTITION BY Com_Name
          ORDER BY Confidence DESC, Date DESC, Time DESC
        ) AS rn
      FROM detections
      WHERE File_Name IS NOT NULL AND File_Name != ''
    )
    SELECT rowid, Date, Time, Com_Name, File_Name, Confidence, rn
    FROM ranked
    WHERE gdrive_archived_at IS NULL AND Date < ? AND rn <= ?
    ORDER BY Com_Name ASC, rn ASC
    """
    conn = sqlite3.connect(db_path)
    conn.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    conn.row_factory = sqlite3.Row
    rows = [dict(r) for r in conn.execute(sql, (cutoff, protect_n)).fetchall()]
    conn.close()
    return rows


def mark_unas_archived(db_path, rowid):
    conn = sqlite3.connect(db_path)
    conn.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    conn.execute(
        "UPDATE detections SET archived_at = datetime('now'), /* perchpi:utc-mig-archive-writers-v1 */ "
        "archive_location = 'unas' WHERE rowid = ?",
        (rowid,)
    )
    conn.commit()
    conn.close()


def mark_gdrive_archived(db_path, rowid):
    conn = sqlite3.connect(db_path)
    conn.execute(f"PRAGMA busy_timeout = {SQLITE_BUSY_TIMEOUT_MS}")
    conn.execute(
        "UPDATE detections SET gdrive_archived_at = datetime('now') /* perchpi:utc-mig-archive-writers-v1 */ "
        "WHERE rowid = ?",
        (rowid,)
    )
    conn.commit()
    conn.close()


# ─── Reachability ────────────────────────────────────────────────────────

def check_unas_reachable(unas_archive):
    p = Path(unas_archive)
    try:
        p.mkdir(parents=True, exist_ok=True)
        test = p / f".perchpi-archive-probe-{os.getpid()}"
        test.write_text("ok")
        test.unlink()
        return True
    except Exception as e:
        logging.error("UNAS unreachable at %s: %s", unas_archive, e)
        return False


def check_gdrive_reachable(gdrive_remote):
    """Probe by listing the rclone remote's root prefix."""
    prefix = gdrive_remote.split(":")[0] + ":"
    try:
        result = subprocess.run(
            ["rclone", "lsf", prefix, "--max-depth", "1"],
            capture_output=True, text=True, timeout=30,
        )
        if result.returncode != 0:
            logging.error("rclone lsf %s failed: %s",
                          prefix, result.stderr.strip())
            return False
        return True
    except FileNotFoundError:
        logging.error("rclone binary not found. Install: sudo apt install rclone")
        return False
    except subprocess.TimeoutExpired:
        logging.error("rclone timed out probing %s", prefix)
        return False
    except Exception as e:
        logging.error("rclone probe error: %s", e)
        return False


# ─── Transfer ops ────────────────────────────────────────────────────────

def rsync_to_unas(src, dst):
    dst.parent.mkdir(parents=True, exist_ok=True)
    # perchpi:rsync-checksum-v1 — was size-only equality (audit #9 false-skip risk)
    if dst.exists():
        try:
            s_src = src.stat()
            s_dst = dst.stat()
            if s_src.st_size == s_dst.st_size and int(s_src.st_mtime) == int(s_dst.st_mtime):
                return (True, "already-on-unas")
        except OSError:
            pass
    result = subprocess.run(
        ["rsync", "-t", "--checksum", str(src), str(dst)],  # perchpi:rsync-checksum-v1
        capture_output=True, text=True, timeout=60,
    )
    if result.returncode != 0:
        try:
            if dst.exists() and dst.stat().st_size != src.stat().st_size:
                dst.unlink()
        except OSError:
            pass
        return (False, result.stderr.strip() or f"rsync exit {result.returncode}")
    try:
        if dst.stat().st_size == src.stat().st_size:
            return (True, "copied")
    except OSError as e:
        return (False, f"post-copy stat failed: {e}")
    return (False, "size-mismatch-after-copy")


def rclone_to_gdrive(src, remote_path):
    result = subprocess.run(
        ["rclone", "copyto",
         "--retries", "2",
         "--low-level-retries", "3",
         "--timeout", "120s",
         str(src), remote_path],
        capture_output=True, text=True, timeout=180,
    )
    if result.returncode != 0:
        return (False, result.stderr.strip() or f"rclone exit {result.returncode}")
    return (True, "uploaded")


# ─── Phases ──────────────────────────────────────────────────────────────

def run_unas_phase(args, cfg, log):
    candidates = get_unas_candidates(args.db, cfg["RETENTION_DAYS"])
    cutoff = (date.today() - timedelta(days=cfg["RETENTION_DAYS"])).isoformat()
    log("─" * 72)
    log("PHASE 1: UNAS archive")
    log("─" * 72)
    log("  Candidates (Date < %s, archived_at IS NULL): %s",
        cutoff, f"{len(candidates):,}")

    if not candidates:
        log("  Nothing to do.")
        return {"archived": 0, "skipped": 0, "failed": 0, "bytes": 0,
                "elapsed": 0.0, "dry_run_count": 0}

    found, missing, total_size = [], [], 0
    for row in candidates:
        src = safe_source_path(args.audio_root, row)  # perchpi:installer-s19a-path-guard-v1 (loops)
        if src is None:
            log("  SKIP unsafe path in DB row: %r / %r", row["Com_Name"], row["File_Name"])
            continue
        if src.exists():
            try: size = src.stat().st_size
            except OSError: size = 0
            found.append((row, src, size))
            total_size += size
        else:
            missing.append(row)

    log("  Found:   %s files (%.1f MB)", f"{len(found):,}",
        total_size / 1024 / 1024)
    log("  Missing: %s (DB row -> non-existent Pi file)", f"{len(missing):,}")

    if len(found) > args.max_archive:
        log("  ⚠ Capping at --max-archive=%s (of %s found)",
            f"{args.max_archive:,}", f"{len(found):,}")
        found = found[: args.max_archive]

    if not args.apply:
        log("  DRY RUN: would archive %s files (~%.1f MB) to %s",
            f"{len(found):,}",
            sum(s for _, _, s in found) / 1024 / 1024,
            cfg["UNAS_ARCHIVE"])
        return {"archived": 0, "skipped": 0, "failed": 0, "bytes": 0,
                "elapsed": 0.0, "dry_run_count": len(found)}

    log("  APPLY: rsyncing %s files...", f"{len(found):,}")
    t_start = time.time()
    archived, skipped, failed, bytes_sent = 0, 0, 0, 0
    for i, (row, src, size) in enumerate(found, 1):
        dst = unas_dest_path(cfg["UNAS_ARCHIVE"], row)
        ok, msg = rsync_to_unas(src, dst)
        if ok:
            if msg == "already-on-unas":
                skipped += 1
            else:
                bytes_sent += size
                archived += 1
            try:
                mark_unas_archived(args.db, row["rowid"])
            except sqlite3.Error as e:
                failed += 1
                logging.error("    [%d/%d] DB UPDATE FAILED rowid=%s: %s",
                              i, len(found), row["rowid"], e)
                continue
            if args.verbose:
                logging.debug("    [%d/%d] %s (%s)",
                              i, len(found), src.name, msg)
        else:
            failed += 1
            logging.error("    [%d/%d] FAILED %s: %s",
                          i, len(found), src.name, msg)
    return {"archived": archived, "skipped": skipped, "failed": failed,
            "bytes": bytes_sent, "elapsed": time.time() - t_start,
            "dry_run_count": 0}


def run_gdrive_phase(args, cfg, log):
    candidates = get_gdrive_candidates(args.db, cfg["RETENTION_DAYS"],
                                       cfg["PROTECT_N"])
    cutoff = (date.today() - timedelta(days=cfg["RETENTION_DAYS"])).isoformat()
    log("─" * 72)
    log("PHASE 2: Google Drive backup (top-N protected only)")
    log("─" * 72)
    log("  Remote:     %s", cfg["GDRIVE_REMOTE"])
    log("  Top-N:      %s per species", cfg["PROTECT_N"])
    log("  Candidates (Date < %s, rn <= %s, gdrive_archived_at IS NULL): %s",
        cutoff, cfg["PROTECT_N"], f"{len(candidates):,}")

    if not candidates:
        log("  Nothing to do.")
        return {"archived": 0, "failed": 0, "skipped_missing": 0,
                "bytes": 0, "elapsed": 0.0, "dry_run_count": 0}

    found, missing, total_size = [], [], 0
    for row in candidates:
        src = safe_source_path(args.audio_root, row)  # perchpi:installer-s19a-path-guard-v1 (loops)
        if src is None:
            log("  SKIP unsafe path in DB row: %r / %r", row["Com_Name"], row["File_Name"])
            continue
        if src.exists():
            try: size = src.stat().st_size
            except OSError: size = 0
            found.append((row, src, size))
            total_size += size
        else:
            missing.append(row)

    log("  Found:   %s files (%.1f MB)", f"{len(found):,}",
        total_size / 1024 / 1024)
    log("  Missing: %s (Pi copy gone; v1 skips these, revisit if frequent)",
        f"{len(missing):,}")

    if len(found) > args.max_archive:
        log("  ⚠ Capping at --max-archive=%s (of %s found)",
            f"{args.max_archive:,}", f"{len(found):,}")
        found = found[: args.max_archive]

    if not args.apply:
        log("  DRY RUN: would upload %s files (~%.1f MB) to %s",
            f"{len(found):,}",
            sum(s for _, _, s in found) / 1024 / 1024,
            cfg["GDRIVE_REMOTE"])
        return {"archived": 0, "failed": 0, "skipped_missing": len(missing),
                "bytes": 0, "elapsed": 0.0, "dry_run_count": len(found)}

    log("  APPLY: rclone copyto %s files...", f"{len(found):,}")
    t_start = time.time()
    archived, failed, bytes_sent = 0, 0, 0
    for i, (row, src, size) in enumerate(found, 1):
        remote = gdrive_dest_path(cfg["GDRIVE_REMOTE"], row)
        ok, msg = rclone_to_gdrive(src, remote)
        if ok:
            bytes_sent += size
            archived += 1
            try:
                mark_gdrive_archived(args.db, row["rowid"])
            except sqlite3.Error as e:
                failed += 1
                logging.error("    [%d/%d] DB UPDATE FAILED rowid=%s: %s",
                              i, len(found), row["rowid"], e)
                continue
            if args.verbose:
                logging.debug("    [%d/%d] %s -> %s (%s)",
                              i, len(found), src.name, remote, msg)
        else:
            failed += 1
            logging.error("    [%d/%d] FAILED %s: %s",
                          i, len(found), src.name, msg)
    return {"archived": archived, "failed": failed,
            "skipped_missing": len(missing),
            "bytes": bytes_sent, "elapsed": time.time() - t_start,
            "dry_run_count": 0}


# ─── CLI ─────────────────────────────────────────────────────────────────

def parse_args():
    p = argparse.ArgumentParser(description="PerchPi archive v2 (UNAS + GDrive).")
    p.add_argument("--apply", action="store_true",
                   help="Actually rsync/rclone + mark DB. Default is DRY RUN.")
    p.add_argument("--db", default=DEFAULT_DB)
    p.add_argument("--audio-root", default=DEFAULT_AUDIO_ROOT)
    p.add_argument("--conf", default=DEFAULT_CONF)
    p.add_argument("--unas-path", default=None,
                   help="Override UNAS archive root.")
    p.add_argument("--gdrive-remote", default=None,
                   help="Override Google Drive remote.")
    p.add_argument("--retention-days", type=int, default=None)
    p.add_argument("--protect-n", type=int, default=None)
    p.add_argument("--archive-to-unas", choices=("true", "false"), default=None,
                   help="Override PERCHPI_ARCHIVE_TO_UNAS.")
    p.add_argument("--backup-gdrive", choices=("true", "false"), default=None,
                   help="Override PERCHPI_GDRIVE_BACKUP.")
    p.add_argument("--max-archive", type=int, default=DEFAULT_MAX_ARCHIVE,
                   help="Per-phase safety cap (default 5000).")
    p.add_argument("--verbose", "-v", action="store_true")
    p.add_argument("--log-file", default=None)
    return p.parse_args()


def setup_logging(verbose, log_file):
    level = logging.DEBUG if verbose else logging.INFO
    handlers = [logging.StreamHandler(sys.stdout)]
    if log_file:
        handlers.append(logging.FileHandler(log_file))
    logging.basicConfig(
        level=level,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=handlers,
    )


def main():
    args = parse_args()
    setup_logging(args.verbose, args.log_file)

    cfg = load_config(args.conf)
    if args.retention_days is not None: cfg["RETENTION_DAYS"] = args.retention_days
    if args.protect_n is not None: cfg["PROTECT_N"] = args.protect_n
    if args.unas_path is not None: cfg["UNAS_ARCHIVE"] = args.unas_path
    if args.gdrive_remote is not None: cfg["GDRIVE_REMOTE"] = args.gdrive_remote
    if args.archive_to_unas is not None:
        cfg["ARCHIVE_TO_UNAS"] = (args.archive_to_unas == "true")
    if args.backup_gdrive is not None:
        cfg["GDRIVE_BACKUP"] = (args.backup_gdrive == "true")

    if not cfg["ARCHIVE_TO_UNAS"] and not cfg["GDRIVE_BACKUP"]:
        logging.info("Both ARCHIVE_TO_UNAS and GDRIVE_BACKUP false — nothing to do.")
        return 0

    mode = "APPLY" if args.apply else "DRY RUN"
    log = logging.info
    log("=" * 72)
    log("perchpi-archive v2 — %s", mode)
    log("  DB:               %s", args.db)
    log("  Audio root:       %s", args.audio_root)
    log("  Retention:        %s days", cfg["RETENTION_DAYS"])
    log("  Protect top-N:    %s per species", cfg["PROTECT_N"])
    log("  Max archive/run:  %s (per phase)", args.max_archive)
    log("  Phase 1 (UNAS):   %s -> %s",
        "ON" if cfg["ARCHIVE_TO_UNAS"] else "OFF", cfg["UNAS_ARCHIVE"])
    log("  Phase 2 (GDrive): %s -> %s",
        "ON" if cfg["GDRIVE_BACKUP"] else "OFF", cfg["GDRIVE_REMOTE"])
    log("=" * 72)

    if not os.path.exists(args.db):
        logging.error("DB not found: %s", args.db); return 2
    if not os.path.isdir(args.audio_root):
        logging.error("Audio root not found: %s", args.audio_root); return 2

    if args.apply:
        if cfg["ARCHIVE_TO_UNAS"] and not check_unas_reachable(cfg["UNAS_ARCHIVE"]):
            logging.error("UNAS pre-flight failed."); return 3
        if cfg["GDRIVE_BACKUP"] and not check_gdrive_reachable(cfg["GDRIVE_REMOTE"]):
            logging.error("GDrive pre-flight failed."); return 4

    stats = get_db_stats(args.db)
    log("DB overview:")
    log("  Total detections w/ File_Name: %s", f"{stats['total']:,}")
    log("  Distinct species:              %s", stats["n_species"])
    log("  Already archived to UNAS:      %s", f"{stats['archived_unas']:,}")
    log("  Already archived to GDrive:    %s", f"{stats['archived_gdrive']:,}")
    log("")

    unas_result, gdrive_result = None, None
    if cfg["ARCHIVE_TO_UNAS"]:
        unas_result = run_unas_phase(args, cfg, log)
        log("")
    if cfg["GDRIVE_BACKUP"]:
        gdrive_result = run_gdrive_phase(args, cfg, log)
        log("")

    log("=" * 72)
    log("SUMMARY")
    log("=" * 72)
    if unas_result:
        if args.apply:
            log("Phase 1 UNAS:   archived=%s skipped=%s failed=%s "
                "sent=%.1f MB in %.1f sec",
                f"{unas_result['archived']:,}",
                f"{unas_result['skipped']:,}",
                f"{unas_result['failed']:,}",
                unas_result['bytes'] / 1024 / 1024,
                unas_result['elapsed'])
        else:
            log("Phase 1 UNAS:   DRY RUN — %s files would be archived",
                f"{unas_result.get('dry_run_count', 0):,}")
    if gdrive_result:
        if args.apply:
            log("Phase 2 GDrive: archived=%s failed=%s missing-pi=%s "
                "sent=%.1f MB in %.1f sec",
                f"{gdrive_result['archived']:,}",
                f"{gdrive_result['failed']:,}",
                f"{gdrive_result['skipped_missing']:,}",
                gdrive_result['bytes'] / 1024 / 1024,
                gdrive_result['elapsed'])
        else:
            log("Phase 2 GDrive: DRY RUN — %s files would be uploaded",
                f"{gdrive_result.get('dry_run_count', 0):,}")

    failed_total = ((unas_result or {}).get("failed", 0)
                    + (gdrive_result or {}).get("failed", 0))
    return 0 if failed_total == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
