#!/usr/bin/env python3
"""
perchpi-retention v1 — local-only retention (parking-lot item ll)

Deletes BirdSongs/Extracted/By_Date/.../*.flac files older than RETENTION_DAYS,
except for the top-N highest-confidence detections per species (which are
protected forever as "Best Recordings" exemplars).

Default mode: DRY RUN. Real deletion requires --apply.

Config (read from /etc/birdnet/birdnet.conf):
  PERCHPI_RETENTION_DAYS  (default 60)
  PERCHPI_PROTECT_N       (default 5)
  RETENTION_REQUIRE_ARCHIVE (default true: only rows with archived_at are deleted)
  PERCHPI_FREE_FLOOR_PCT  (default 15: while free space is below this %, delete the
                          oldest unprotected audio regardless of age)
Never deleted: top-N per species (ranked like Best Recordings), eBird imports, files
locked in the UI (scripts/disk_check_exclude.txt), and any row whose path would land
outside the audio root. perchpi:installer-s19a-retention-doc-v1

Does NOT modify the SQLite DB. UI handling of "audio gone but row remains"
ships in (nn).
"""

import argparse
import logging
import os
import re
import shutil  # perchpi:installer-s19a-retention-floor-v1 (imports)
import sqlite3
import sys
from datetime import date, timedelta
from pathlib import Path

DEFAULT_DB = "/home/birder/BirdNET-Pi/scripts/birds.db"
DEFAULT_AUDIO_ROOT = "/home/birder/BirdSongs/Extracted/By_Date"
DEFAULT_CONF = "/etc/birdnet/birdnet.conf"
DEFAULT_RETENTION_DAYS = 60
DEFAULT_PROTECT_N = 5
DEFAULT_MAX_DELETE = 5000  # safety cap per run
DEFAULT_FREE_FLOOR_PCT = 15  # perchpi:installer-s19a-retention-floor-v1 (const)
DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def load_config(conf_path):
    """Read PERCHPI_* keys from birdnet.conf. Returns dict with defaults."""
    cfg = {
        "RETENTION_DAYS": DEFAULT_RETENTION_DAYS,
        "PROTECT_N": DEFAULT_PROTECT_N,
        # D-S16c-1 (perchpi:installer-s19a-retention-require-archive-v1 (cfg)): an ABSENT
        # key means true, so a conf that predates the key (LIVE's) never starts deleting
        # unarchived audio.
        "REQUIRE_ARCHIVE": True,
        "FREE_FLOOR_PCT": DEFAULT_FREE_FLOOR_PCT,
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
                    try:
                        cfg["RETENTION_DAYS"] = int(value)
                    except ValueError:
                        pass
                elif key == "PERCHPI_PROTECT_N":
                    try:
                        cfg["PROTECT_N"] = int(value)
                    except ValueError:
                        pass
                # perchpi:installer-s19a-retention-require-archive-v1 (parse)
                elif key == "RETENTION_REQUIRE_ARCHIVE":
                    cfg["REQUIRE_ARCHIVE"] = value.lower() not in ("false", "0", "no", "off")
                elif key == "PERCHPI_FREE_FLOOR_PCT":
                    try:
                        cfg["FREE_FLOOR_PCT"] = int(value)
                    except ValueError:
                        pass
    except OSError as e:
        logging.warning("Could not parse %s: %s", conf_path, e)
    return cfg


def com_name_to_folder(com_name):
    """Yellow Warbler -> Yellow_Warbler"""
    return re.sub(r"\s+", "_", com_name.replace("'", ""))


def get_db_stats(db_path, retention_days, protect_n):
    """Return overview counts so user can sanity-check before applying."""
    cutoff = (date.today() - timedelta(days=retention_days)).isoformat()
    conn = sqlite3.connect(db_path)

    total = conn.execute(
        "SELECT COUNT(*) FROM detections WHERE File_Name IS NOT NULL AND File_Name != ''"
    ).fetchone()[0]

    n_species = conn.execute(
        "SELECT COUNT(DISTINCT Com_Name) FROM detections "
        "WHERE File_Name IS NOT NULL AND File_Name != ''"
    ).fetchone()[0]

    protected = conn.execute(
        """
        SELECT COUNT(*) FROM (
            SELECT ROW_NUMBER() OVER (
                PARTITION BY Sci_Name ORDER BY Confidence DESC, Date DESC, Time DESC
            ) AS rn
            FROM detections
            WHERE File_Name IS NOT NULL AND File_Name != ''
              AND File_Name NOT LIKE 'ebird_import_%'
              AND ROWID NOT IN (SELECT detection_id FROM quarantined_detections)
              /* perchpi:installer-s19a-retention-rank-v1 (stats) */
        ) WHERE rn <= ?
        """,
        (protect_n,),
    ).fetchone()[0]

    recent = conn.execute(
        "SELECT COUNT(*) FROM detections "
        "WHERE File_Name IS NOT NULL AND File_Name != '' AND Date >= ?",
        (cutoff,),
    ).fetchone()[0]

    conn.close()
    return {
        "total": total,
        "n_species": n_species,
        "protected": protected,
        "recent": recent,
        "cutoff": cutoff,
    }


def get_candidates(db_path, retention_days, protect_n, require_archive=True,
                   age_filter=True):
    """Return unprotected detection rows, oldest first (old ones only unless age_filter
    is False, which the free-space floor uses).

    perchpi:installer-s19a-retention-rank-v1
    - F7: top-N protection ranks like Best Recordings: per Sci_Name, with eBird imports
      and quarantined rows left out of the ranking. Quarantined rows get no rank, so they
      are never protected. eBird imports are never candidates.
    - D-S16c-1: the archived_at guard (perchpi:retention-archive-guard-v1) applies only
      when RETENTION_REQUIRE_ARCHIVE is true.
    """
    cutoff = (date.today() - timedelta(days=retention_days)).isoformat()
    age_clause = "AND d.Date < :cutoff" if age_filter else ""
    archive_clause = "AND d.archived_at IS NOT NULL" if require_archive else ""
    sql = """
    WITH ranked AS (
        SELECT ROWID AS rid,
            ROW_NUMBER() OVER (
                PARTITION BY Sci_Name
                ORDER BY Confidence DESC, Date DESC, Time DESC
            ) AS rn
        FROM detections
        WHERE File_Name IS NOT NULL AND File_Name != ''
          AND File_Name NOT LIKE 'ebird_import_%'
          AND ROWID NOT IN (SELECT detection_id FROM quarantined_detections)
    )
    SELECT d.Date, d.Time, d.Com_Name, d.File_Name, d.Confidence, r.rn
    FROM detections d LEFT JOIN ranked r ON r.rid = d.ROWID
    WHERE d.File_Name IS NOT NULL AND d.File_Name != ''
      AND d.File_Name NOT LIKE 'ebird_import_%'
      AND (r.rn IS NULL OR r.rn > :protect_n)
      {age} {archive}
    ORDER BY d.Date ASC, d.Time ASC, d.Com_Name ASC
    """.format(age=age_clause, archive=archive_clause)
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    rows = [dict(r) for r in conn.execute(
        sql, {"protect_n": protect_n, "cutoff": cutoff}).fetchall()]
    conn.close()
    return rows


def candidate_path(audio_root, row):
    """Build the filesystem path for a candidate row, or None if it isn't safe.

    STAGE-2 S3-1 (perchpi:installer-s19a-path-guard-v1): DB values become path parts,
    so a row holding '../' or an absolute name could reach outside the audio root.
    """
    name = row["File_Name"] or ""
    folder = com_name_to_folder(row["Com_Name"] or "")
    for part in (name, folder):
        if not part or "/" in part or "\\" in part or ".." in part:
            return None
    if not DATE_RE.match(row["Date"] or ""):
        return None
    path = Path(audio_root) / row["Date"] / folder / name
    if path.is_symlink():
        return None
    try:
        if not path.resolve().is_relative_to(Path(audio_root).resolve()):
            return None
    except (OSError, ValueError):
        return None
    return path


def lock_key(row):
    """F6: the form play.php writes to disk_check_exclude.txt: Date/Folder/File_Name."""
    return "%s/%s/%s" % (row["Date"], com_name_to_folder(row["Com_Name"]), row["File_Name"])


def load_locks(path):
    """F6 (perchpi:installer-s19a-retention-locks-v1): files locked in the UI."""
    try:
        with open(path) as f:
            return {ln.strip() for ln in f if ln.strip() and not ln.startswith("##")}
    except OSError:
        return set()


def free_pct(path):
    usage = shutil.disk_usage(path)
    return 100.0 * usage.free / usage.total


def resolve_candidates(audio_root, candidates, locks):
    """Map rows to existing files, skipping unsafe paths (S3-1) and UI locks (F6)."""
    found, missing, total_size, unsafe, locked = [], 0, 0, 0, 0
    for row in candidates:
        path = candidate_path(audio_root, row)
        if path is None:
            unsafe += 1
            logging.warning("  SKIP unsafe path in DB row: %r / %r",
                            row["Com_Name"], row["File_Name"])
            continue
        if lock_key(row) in locks:
            locked += 1
            continue
        if path.exists():
            try:
                size = path.stat().st_size
            except OSError:
                size = 0
            found.append((row, path, size))
            total_size += size
        else:
            missing += 1
    logging.info("  Found:   %s files (%.1f MB)", f"{len(found):,}", total_size / 1024 / 1024)
    logging.info("  Missing: %s (DB row references non-existent file)", f"{missing:,}")
    logging.info("  Skipped: %s locked in the UI, %s unsafe paths", f"{locked:,}", f"{unsafe:,}")
    return found


def delete_files(found, verbose, stop=None):
    """Delete each file and its spectrogram PNG. Returns (deleted, failed, freed, spec_deleted)."""
    deleted, failed, freed, spec_deleted = 0, 0, 0, 0
    for row, path, size in found:
        if stop is not None and stop():
            break
        try:
            path.unlink()
            deleted += 1
            freed += size
            if verbose:
                logging.debug("  deleted: %s", path)
        except OSError as e:
            failed += 1
            logging.error("  FAILED to delete %s: %s", path, e)
            continue
        # perchpi:retention-spec-orphan-v1 -- also delete the spectrogram PNG.
        # BirdNET-Pi generates <audio>.png next to every audio file. The PNG
        # is regenerable from the audio, so safe to delete.
        spec_path = path.with_name(path.name + ".png")
        if spec_path.exists() and not spec_path.is_symlink():
            try:
                spec_size = spec_path.stat().st_size
                spec_path.unlink()
                spec_deleted += 1
                freed += spec_size
            except OSError as e:
                logging.warning("  FAILED to delete spec %s: %s", spec_path, e)
    return deleted, failed, freed, spec_deleted


def parse_args():
    p = argparse.ArgumentParser(description="PerchPi retention v1 (local-only).")
    p.add_argument("--apply", action="store_true",
                   help="Actually delete files. Default is DRY RUN.")
    p.add_argument("--db", default=DEFAULT_DB)
    p.add_argument("--audio-root", default=DEFAULT_AUDIO_ROOT)
    p.add_argument("--conf", default=DEFAULT_CONF)
    p.add_argument("--retention-days", type=int, default=None,
                   help="Override RETENTION_DAYS (default reads conf, fallback 60).")
    p.add_argument("--protect-n", type=int, default=None,
                   help="Override PROTECT_N (default reads conf, fallback 5).")
    p.add_argument("--max-delete", type=int, default=DEFAULT_MAX_DELETE,
                   help="Safety cap on files deleted per run (default 5000).")
    p.add_argument("--verbose", "-v", action="store_true")
    p.add_argument("--log-file", default=None,
                   help="Also write logs to this file.")
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
    if args.retention_days is not None:
        cfg["RETENTION_DAYS"] = args.retention_days
    if args.protect_n is not None:
        cfg["PROTECT_N"] = args.protect_n

    mode = "APPLY (will delete files)" if args.apply else "DRY RUN (no deletions)"
    log = logging.info
    log("=" * 72)
    log("perchpi-retention v1 — %s", mode)
    log("  DB:             %s", args.db)
    log("  Audio root:     %s", args.audio_root)
    log("  Retention:      %s days", cfg["RETENTION_DAYS"])
    log("  Protect top-N:  %s per species", cfg["PROTECT_N"])
    log("  Max delete/run: %s", args.max_delete)
    log("  Require archive: %s", cfg["REQUIRE_ARCHIVE"])  # perchpi:installer-s19a-retention-main-v1 (banner)
    log("  Free floor:     %s%%", cfg["FREE_FLOOR_PCT"])
    log("=" * 72)

    # Sanity checks
    if not os.path.exists(args.db):
        logging.error("DB not found: %s", args.db)
        return 2
    if not os.path.isdir(args.audio_root):
        logging.error("Audio root not found: %s", args.audio_root)
        return 2

    stats = get_db_stats(args.db, cfg["RETENTION_DAYS"], cfg["PROTECT_N"])
    log("DB overview:")
    log("  Total detections w/ File_Name: %s", f"{stats['total']:,}")
    log("  Distinct species:              %s", stats["n_species"])
    log("  Top-%s protected:              %s",
        cfg["PROTECT_N"], f"{stats['protected']:,}")
    log("  Within retention (>= %s):     %s",
        stats["cutoff"], f"{stats['recent']:,}")

    # perchpi:installer-s19a-retention-main-v1: phase 1 = age, phase 2 = free-space floor.
    locks = load_locks(os.path.join(os.path.dirname(os.path.abspath(args.db)),
                                    "disk_check_exclude.txt"))
    candidates = get_candidates(args.db, cfg["RETENTION_DAYS"], cfg["PROTECT_N"],
                                cfg["REQUIRE_ARCHIVE"])
    log("  Deletion candidates:           %s", f"{len(candidates):,}")
    log("  UI-locked files:               %s", f"{len(locks):,}")
    log("")

    log("Filesystem scan (age):")
    found = resolve_candidates(args.audio_root, candidates, locks)
    log("")

    sample_n = min(10, len(found))
    if sample_n:
        log("Sample of %s oldest candidates:", sample_n)
        for row, path, size in found[:sample_n]:
            log("  %s  %-30s  conf=%.2f  rank=%s  %5d KB",
                row["Date"], row["Com_Name"], row["Confidence"],
                row["rn"], size // 1024)
        if len(found) > sample_n:
            log("  ... and %s more", f"{len(found) - sample_n:,}")
        log("")

    if len(found) > args.max_delete:
        logging.warning("Candidate count (%s) exceeds max-delete cap (%s); only the first %s run.",
                        f"{len(found):,}", f"{args.max_delete:,}", f"{args.max_delete:,}")
        found = found[: args.max_delete]

    deleted = failed = freed = spec_deleted = 0
    if not args.apply:
        log("DRY RUN: age pass would delete %s files (~%.1f MB).",
            f"{len(found):,}", sum(s for _, _, s in found) / 1024 / 1024)
    elif found:
        log("APPLYING (age): deleting %s files...", f"{len(found):,}")
        deleted, failed, freed, spec_deleted = delete_files(found, args.verbose)

    # Phase 2 -- F1 free-space floor (audit D3 a'): while free space is under the floor,
    # delete the oldest unprotected audio regardless of age. Same protections apply.
    floor = cfg["FREE_FLOOR_PCT"]
    pct = free_pct(args.audio_root)
    log("Free space: %.1f%% (floor %s%%)", pct, floor)
    if floor > 0 and pct < floor:
        floor_rows = get_candidates(args.db, cfg["RETENTION_DAYS"], cfg["PROTECT_N"],
                                    cfg["REQUIRE_ARCHIVE"], age_filter=False)
        log("Filesystem scan (floor):")
        ffound = resolve_candidates(args.audio_root, floor_rows, locks)
        budget = max(0, args.max_delete - deleted)
        if not args.apply:
            usage = shutil.disk_usage(args.audio_root)
            age_paths = {p for _, p, _ in found}
            need = floor / 100.0 * usage.total - usage.free - sum(s for _, _, s in found)
            n = acc = 0
            for _, p, s in ffound:
                if acc >= need or n >= budget:
                    break
                if p in age_paths:
                    continue
                n += 1
                acc += s
            log("DRY RUN: floor would delete %s more files (~%.1f MB).", f"{n:,}", acc / 1024 / 1024)
        else:
            log("APPLYING (floor): oldest first until free >= %s%%...", floor)
            d, f, fr, sd = delete_files(ffound[:budget], args.verbose,
                                        stop=lambda: free_pct(args.audio_root) >= floor)
            deleted, failed, freed, spec_deleted = (deleted + d, failed + f,
                                                    freed + fr, spec_deleted + sd)
            pct = free_pct(args.audio_root)
            if pct < floor:
                logging.warning("Free space still %.1f%%, under the %s%% floor: nothing else "
                                "is eligible (protected, locked, imported%s).", pct, floor,
                                ", or not archived" if cfg["REQUIRE_ARCHIVE"] else "")

    if not args.apply:
        log("To actually delete: re-run with --apply")
        return 0
    log("")
    log("Results:")
    log("  Deleted: %s (+%s spectrograms)", f"{deleted:,}", f"{spec_deleted:,}")  # perchpi:retention-spec-orphan-v1
    log("  Failed:  %s", f"{failed:,}")
    log("  Freed:   %.1f MB", freed / 1024 / 1024)
    log("  Free now: %.1f%%", free_pct(args.audio_root))
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
