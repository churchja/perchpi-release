#!/usr/bin/env python3
# perchpi:schema-migrate-v1
"""
PerchPi schema migrator.

Tracks applied migrations against scripts/birds.db via a schema_migrations
table. Migrations are SQL files in scripts/migrations/, applied in lexical
order of filename. Use NNNN_descriptive_name.sql for filenames.

Subcommands:
    status                  show applied/pending migrations
    up [--to <id>]          apply pending migrations through optional target
    mark <id>               record id as applied without running its SQL
                            (use when DB already has the migration's state)

Examples:
    python3 migrate.py status
    python3 migrate.py up
    python3 migrate.py up --to 0003_add_embeddings
    python3 migrate.py mark 0001_baseline

Conventions:
    - Migration filenames: 0001_*.sql, 0002_*.sql, etc.
    - Migrations should be idempotent (CREATE TABLE IF NOT EXISTS,
      ALTER TABLE with try/except patterns) since executescript is
      not transactional in Python's sqlite3 module. A partial failure
      requires fixing the SQL and re-running.
    - Each migration runs as a single executescript() call. The
      schema_migrations INSERT is a separate statement that commits
      only if the script succeeded.
"""
import argparse
import glob
import hashlib
import os
import sqlite3
import sys

DEFAULT_DB = "/home/birder/BirdNET-Pi/scripts/birds.db"
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
MIGRATIONS_DIR = os.path.join(SCRIPT_DIR, "migrations")


def ensure_table(conn):
    conn.execute("""
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id          TEXT PRIMARY KEY,
            applied_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            checksum    TEXT
        )
    """)
    conn.commit()


def discover_migrations(dir_path):
    """Return sorted [(id, full_path), ...] for *.sql files."""
    if not os.path.isdir(dir_path):
        return []
    paths = sorted(glob.glob(os.path.join(dir_path, "*.sql")))
    return [(os.path.splitext(os.path.basename(p))[0], p) for p in paths]


def applied_ids(conn):
    cur = conn.execute("SELECT id FROM schema_migrations ORDER BY id")
    return {row[0] for row in cur.fetchall()}


def file_checksum(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        h.update(f.read())
    return h.hexdigest()


def cmd_status(args, conn):
    ensure_table(conn)
    migrations = discover_migrations(MIGRATIONS_DIR)
    applied = applied_ids(conn)
    if not migrations and not applied:
        print("no migrations found and none applied. add files to %s/" % MIGRATIONS_DIR)
        return
    print("%-10s %-40s %s" % ("STATUS", "ID", "PATH"))
    print("-" * 90)
    for mid, path in migrations:
        state = "applied" if mid in applied else "pending"
        rel = os.path.relpath(path, SCRIPT_DIR)
        print("%-10s %-40s %s" % (state, mid, rel))
    # Detect ghost rows: applied in DB but no file on disk
    on_disk = {m[0] for m in migrations}
    ghosts = applied - on_disk
    if ghosts:
        print()
        print("WARNING: applied rows missing files: %s" % ", ".join(sorted(ghosts)))


def cmd_up(args, conn):
    ensure_table(conn)
    migrations = discover_migrations(MIGRATIONS_DIR)
    if not migrations:
        sys.exit("no migration files found in %s/" % MIGRATIONS_DIR)
    applied = applied_ids(conn)
    target = args.to
    if target:
        valid_ids = {m[0] for m in migrations}
        if target not in valid_ids:
            sys.exit("--to %s: no such migration. available: %s"
                     % (target, ", ".join(sorted(valid_ids))))
    pending_count = 0
    for mid, path in migrations:
        if mid in applied:
            continue
        if target and mid > target:
            break
        pending_count += 1
        print("applying  : %s" % mid)
        with open(path, "r", encoding="utf-8") as fh:
            sql = fh.read()
        chk = file_checksum(path)
        try:
            conn.executescript(sql)
            # The INSERT below is its own transaction; if executescript
            # succeeded but this INSERT fails (e.g. duplicate id), the
            # migration runs but isn't recorded — that's a partial state
            # to investigate, not a corruption.
            conn.execute(
                "INSERT INTO schema_migrations (id, checksum) VALUES (?, ?)",
                (mid, chk),
            )
            conn.commit()
            print("  applied : %s" % mid)
        except Exception as exc:
            sys.exit("FAILED on %s: %s" % (mid, exc))
    if pending_count == 0:
        if target:
            print("nothing pending through %s." % target)
        else:
            print("nothing pending.")
    else:
        print("done. applied %d migration(s)." % pending_count)


def cmd_mark(args, conn):
    ensure_table(conn)
    mid = args.id
    path = os.path.join(MIGRATIONS_DIR, "%s.sql" % mid)
    if not os.path.isfile(path):
        sys.exit("ABORT: file not found: %s" % path)
    if mid in applied_ids(conn):
        sys.exit("ABORT: %s is already marked applied" % mid)
    chk = file_checksum(path)
    conn.execute(
        "INSERT INTO schema_migrations (id, checksum) VALUES (?, ?)",
        (mid, chk),
    )
    conn.commit()
    print("marked    : %s (no SQL executed)" % mid)


def main():
    parser = argparse.ArgumentParser(
        description="PerchPi schema migrator",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--db", default=DEFAULT_DB, help="path to birds.db")
    sub = parser.add_subparsers(dest="cmd")
    sub.add_parser("status", help="show applied/pending migrations")
    up = sub.add_parser("up", help="apply pending migrations")
    up.add_argument("--to", default=None, help="apply through this id (inclusive)")
    mk = sub.add_parser("mark", help="record id as applied without executing")
    mk.add_argument("id", help="migration id (filename without .sql)")
    args = parser.parse_args()
    if not args.cmd:
        parser.print_help()
        sys.exit(1)
    if not os.path.isfile(args.db):
        sys.exit("ABORT: db not found: %s" % args.db)
    conn = sqlite3.connect(args.db)
    conn.execute("PRAGMA foreign_keys = ON")
    handler = {"status": cmd_status, "up": cmd_up, "mark": cmd_mark}[args.cmd]
    try:
        handler(args, conn)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
