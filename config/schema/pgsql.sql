-- prado-privacy schema for pgsql; generated from TPrivacySchema.
CREATE TABLE IF NOT EXISTS privacy_consent_log (id BIGSERIAL PRIMARY KEY, consent_id VARCHAR(32) NOT NULL, created_at BIGINT NOT NULL, version VARCHAR(64) NOT NULL, categories TEXT NOT NULL, source VARCHAR(16) NOT NULL, region VARCHAR(8) NULL, gpc SMALLINT NOT NULL DEFAULT 0, ip_hash CHAR(64) NULL, subject_hash CHAR(64) NULL, user_agent_hash CHAR(64) NULL);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_consent ON privacy_consent_log (consent_id);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_created ON privacy_consent_log (created_at);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_subject ON privacy_consent_log (subject_hash);
CREATE TABLE IF NOT EXISTS privacy_consent_state (consent_id VARCHAR(32) NOT NULL PRIMARY KEY, subject_hash CHAR(64) NULL, categories TEXT NOT NULL, version VARCHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, updated_at BIGINT NOT NULL, expires_at BIGINT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_state_subject ON privacy_consent_state (subject_hash);
CREATE INDEX IF NOT EXISTS idx_privacy_consent_state_expires ON privacy_consent_state (expires_at);
CREATE TABLE IF NOT EXISTS privacy_request (id BIGSERIAL PRIMARY KEY, type VARCHAR(16) NOT NULL, subject_hash CHAR(64) NOT NULL, requested_by VARCHAR(255) NULL, requested_at BIGINT NOT NULL, due_at BIGINT NOT NULL, completed_at BIGINT NULL, status VARCHAR(16) NOT NULL, outcome TEXT NULL);
CREATE INDEX IF NOT EXISTS idx_privacy_request_subject ON privacy_request (subject_hash);
CREATE INDEX IF NOT EXISTS idx_privacy_request_status ON privacy_request (status);
CREATE INDEX IF NOT EXISTS idx_privacy_request_requested ON privacy_request (requested_at);
