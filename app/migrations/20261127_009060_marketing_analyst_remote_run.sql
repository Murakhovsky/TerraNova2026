-- Extend the existing owner receipt; no new executor or authority.
ALTER TABLE marketing_analyst_runs
    ADD COLUMN remote_submission_started_at DATETIME(6) DEFAULT NULL,
    ADD COLUMN remote_run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD COLUMN remote_status VARCHAR(32) DEFAULT NULL,
    ADD COLUMN remote_stop_requested_at DATETIME(6) DEFAULT NULL,
    ADD UNIQUE KEY uq_mkt_analyst_remote (organization_id,profile_ref,remote_run_id);
