-- 0002_add_geomodel_prob.sql
-- perchpi:geomodel-prob-persist-v1
-- Additive, nullable. Existing rows = NULL (pre-persistence).
-- Idempotency comes from migrate.py schema_migrations tracking, NOT the SQL
-- (SQLite ALTER TABLE ADD COLUMN has no IF NOT EXISTS). Matches 0001 handling.
ALTER TABLE detections ADD COLUMN Geomodel_Prob REAL;
ALTER TABLE detections ADD COLUMN Geomodel_Week INTEGER;
