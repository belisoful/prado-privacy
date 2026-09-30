-- prado-privacy schema for sqlite; generated from TPrivacySchema.
CREATE TABLE IF NOT EXISTS privacy_consent_log (id INTEGER PRIMARY KEY AUTOINCREMENT, consent_id VARCHAR(32) NOT NULL, created_at INTEGER NOT NULL, version VARCHAR(64) NOT NULL, categories TEXT NOT NULL, source VARCHAR(16) NOT NULL, region VARCHAR(8) NULL, gpc SMALLINT NOT NULL DEFAULT 0, ip_hash CHAR(64) NULL, subject_hash CHAR(64) NULL, user_agent_hash CHAR(64) NULL);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_consent ON privacy_consent_log (consent_id);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_created ON privacy_consent_log (created_at);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_subject ON privacy_consent_log (subject_hash);
CREATE TABLE IF NOT EXISTS privacy_consent_state (consent_id VARCHAR(32) NOT NULL PRIMARY KEY, subject_hash CHAR(64) NULL, categories TEXT NOT NULL, version VARCHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, updated_at INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_state_subject ON privacy_consent_state (subject_hash);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_state_expires ON privacy_consent_state (expires_at);
