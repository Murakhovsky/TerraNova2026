-- Finance-owned final allocation, distinct from provider payment or accounting Expense.
ALTER TABLE cos_finance_budget_reservations DROP CHECK chk_finance_budget_reservation_state;
ALTER TABLE cos_finance_budget_reservations ADD CONSTRAINT chk_finance_budget_reservation_state CHECK (state IN ('RESERVED','RELEASED','SETTLED'));
CREATE TABLE IF NOT EXISTS cos_finance_paid_settlements (
    organization_id VARCHAR(190) NOT NULL,
    reservation_id VARCHAR(40) NOT NULL,
    envelope_id VARCHAR(40) NOT NULL,
    action_id VARCHAR(32) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    candidate_hash CHAR(64) NOT NULL,
    currency CHAR(3) NOT NULL,
    reserved_minor BIGINT UNSIGNED NOT NULL,
    committed_minor BIGINT UNSIGNED NOT NULL,
    released_minor BIGINT UNSIGNED NOT NULL,
    evidence_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,reservation_id),
    UNIQUE KEY uq_finance_settlement_action (organization_id,action_id),
    CONSTRAINT fk_finance_settlement_claim FOREIGN KEY (organization_id,reservation_id) REFERENCES cos_finance_paid_exposure_claims(organization_id,reservation_id),
    CONSTRAINT fk_finance_settlement_envelope FOREIGN KEY (organization_id,envelope_id) REFERENCES cos_finance_budget_envelopes(organization_id,id),
    CONSTRAINT chk_finance_settlement_amount CHECK (reserved_minor>0 AND committed_minor+released_minor=reserved_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
