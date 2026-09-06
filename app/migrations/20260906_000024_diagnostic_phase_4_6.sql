CREATE TABLE IF NOT EXISTS diagnostic_ai_audit (
 organization_id VARCHAR(40) NOT NULL, audit_id CHAR(32) NOT NULL, diagnostic_id VARCHAR(100) NOT NULL,
 operation VARCHAR(80) NOT NULL, model VARCHAR(120) NOT NULL, prompt_version VARCHAR(80) NOT NULL, schema_version VARCHAR(40) NOT NULL,
 input_hash CHAR(64) NOT NULL, output_hash CHAR(64) NULL, tokens_input INT UNSIGNED NOT NULL DEFAULT 0,
 tokens_output INT UNSIGNED NOT NULL DEFAULT 0, estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
 duration_ms INT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (organization_id,audit_id), KEY idx_diagnostic_ai_session (organization_id,diagnostic_id,created_at),
 CONSTRAINT fk_diagnostic_ai_org FOREIGN KEY (organization_id) REFERENCES cos_organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS diagnostic_interview_turns (
 organization_id VARCHAR(40) NOT NULL, diagnostic_id VARCHAR(100) NOT NULL, turn_id CHAR(32) NOT NULL,
 question_id VARCHAR(160) NOT NULL, answer_hash CHAR(64) NOT NULL, status VARCHAR(30) NOT NULL,
 extracted_fact_ids_json JSON NOT NULL, evidence_ids_json JSON NOT NULL, state_revision INT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (organization_id,diagnostic_id,turn_id), CONSTRAINT fk_diagnostic_turn_session FOREIGN KEY (organization_id,diagnostic_id) REFERENCES diagnostic_sessions(organization_id,session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS diagnostic_contradictions (
 organization_id VARCHAR(40) NOT NULL, diagnostic_id VARCHAR(100) NOT NULL, contradiction_id VARCHAR(100) NOT NULL,
 statement_a TEXT NOT NULL, statement_b TEXT NOT NULL, evidence_a_json JSON NOT NULL, evidence_b_json JSON NOT NULL,
 severity VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL, required_clarification TEXT NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (organization_id,diagnostic_id,contradiction_id), CONSTRAINT fk_diagnostic_contradiction_session FOREIGN KEY (organization_id,diagnostic_id) REFERENCES diagnostic_sessions(organization_id,session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO tn_migrations (migration) VALUES ('20260906_000024_diagnostic_phase_4_6');
