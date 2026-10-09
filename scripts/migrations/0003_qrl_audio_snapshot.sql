-- perchpi:qrl-audio-snapshot-v1
-- Phase 1 of the review-log integrity fix.
--
-- quarantine_review_log.detection_id is a bare SQLite rowid, and rowids are
-- REUSED after a detection is deleted. Any join detection_id -> detections.ROWID
-- can therefore silently resolve to a DIFFERENT bird. This snapshots a stable
-- audio pointer (File_Name + Date) into the log AT DECISION TIME, when the
-- detection row still exists, so the log stops depending on that fragile join.
--
-- Two nullable columns. Populated going forward by pp_log_quarantine_decisions()
-- (writer patch, same marker) and backfilled for the provably-safe subset
-- (kept rows whose detection still exists and whose species still matches).
--
-- Applied once via scripts/migrate.py (the schema_migrations ledger is the
-- idempotency guard; SQLite has no ADD COLUMN IF NOT EXISTS).

ALTER TABLE quarantine_review_log ADD COLUMN audio_file TEXT;
ALTER TABLE quarantine_review_log ADD COLUMN audio_date TEXT;
