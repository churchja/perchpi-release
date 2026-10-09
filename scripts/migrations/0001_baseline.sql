CREATE TABLE detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL, archived_at TEXT, archive_location TEXT, gdrive_archived_at TEXT, Detected_At_UTC TEXT);
CREATE INDEX "detections_Com_Name" ON "detections" ("Com_Name");
CREATE INDEX "detections_Sci_Name" ON "detections" ("Sci_Name");
CREATE INDEX "detections_Date_Time" ON "detections" ("Date" DESC, "Time" DESC);
CREATE TABLE quarantined_detections (
  detection_id            INTEGER PRIMARY KEY,
  quarantined_at          TEXT    NOT NULL,
  reason                  TEXT    NOT NULL,
  geomodel_probability    REAL,
  geomodel_week           INTEGER,
  threshold_at_quarantine REAL,
  FOREIGN KEY (detection_id) REFERENCES detections(ROWID) ON DELETE CASCADE
);
CREATE INDEX idx_quarantined_at ON quarantined_detections(quarantined_at);
CREATE TABLE ai_decisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
        detection_id INTEGER NOT NULL,
        species_sci TEXT NOT NULL,
        species_com TEXT,
        confidence REAL,
        geomodel_prob REAL,
        cluster_5min INTEGER,
        cluster_30min INTEGER,
        species_today INTEGER,
        action TEXT NOT NULL,
        skip_reason TEXT,
        verdict TEXT,
        verdict_confidence REAL,
        verdict_reasoning TEXT,
        latency_ms INTEGER,
        http_status INTEGER,
        model_used TEXT,
        prompt_tokens INTEGER,
        response_tokens INTEGER,
        error_message TEXT
    , species_total_count INTEGER, species_max_confidence REAL, count_in_last_14_days INTEGER, distinct_days_in_last_14_days INTEGER, embedding_logit REAL, embedding_verdict TEXT CHECK(embedding_verdict IN ('accept','reject','ambiguous','skipped','errored') OR embedding_verdict IS NULL), embedding_latency_ms INTEGER, notes TEXT);
CREATE TABLE sqlite_sequence(name,seq);
CREATE INDEX idx_ai_decisions_created_at ON ai_decisions(created_at);
CREATE INDEX idx_ai_decisions_detection_id ON ai_decisions(detection_id);
CREATE INDEX idx_ai_decisions_action ON ai_decisions(action);
CREATE INDEX idx_ai_decisions_species_sci ON ai_decisions(species_sci);
CREATE TABLE ai_pending_checks (detection_id INTEGER PRIMARY KEY, queued_at TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT, last_attempt_at TEXT, geomodel_prob REAL, geomodel_week INTEGER, threshold_used REAL, priority INTEGER DEFAULT 2);
CREATE INDEX idx_ai_pending_queued_at ON ai_pending_checks(queued_at);
CREATE INDEX idx_detections_sci_conf ON detections (Sci_Name, Confidence);
CREATE INDEX idx_detections_sci_date ON detections (Sci_Name, Date);
CREATE TABLE embedding_pending (
          detection_id INTEGER PRIMARY KEY,
          queued_at TEXT NOT NULL,
          attempts INTEGER NOT NULL DEFAULT 0,
          last_error TEXT,
          last_attempt_at TEXT
        );
CREATE TABLE species_embedding_thresholds (
          perch_class_idx INTEGER PRIMARY KEY,
          scientific_name TEXT NOT NULL,
          clearly_tp_logit REAL,
          clearly_fp_logit REAL,
          source TEXT NOT NULL DEFAULT 'default',
          n_samples INTEGER DEFAULT 0,
          updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
CREATE INDEX idx_sp_thresholds_name ON species_embedding_thresholds(scientific_name);
CREATE TABLE quarantine_review_log (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    detection_id              INTEGER NOT NULL,
    decision_at               TEXT    NOT NULL,
    decision                  TEXT    NOT NULL CHECK (decision IN ('delete', 'unhide')),
    species_sci               TEXT,
    species_com               TEXT,
    confidence                REAL,
    embedding_logit           REAL,
    embedding_verdict         TEXT,
    geomodel_probability      REAL,
    quarantine_reason         TEXT,
    quarantine_age_minutes    INTEGER,
    source                    TEXT DEFAULT 'ui',
    notes                     TEXT
);
CREATE INDEX idx_qrl_decision_at ON quarantine_review_log(decision_at);
CREATE INDEX idx_qrl_decision    ON quarantine_review_log(decision);
CREATE INDEX idx_qrl_species_sci ON quarantine_review_log(species_sci);
CREATE INDEX idx_qrl_detection   ON quarantine_review_log(detection_id);
