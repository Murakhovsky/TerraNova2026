-- Finance-owned one-shot execution exposure. Funds remain RESERVED until independently reconciled.
CREATE TABLE IF NOT EXISTS cos_finance_paid_exposure_claims (
    organization_id VARCHAR(190) NOT NULL,
    reservation_id VARCHAR(40) NOT NULL,
    envelope_id VARCHAR(40) NOT NULL,
    source_ref VARCHAR(190) NOT NULL,
    action_id VARCHAR(190) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,reservation_id),
    UNIQUE KEY uq_finance_exposure_action (organization_id,action_id),
    UNIQUE KEY uq_finance_exposure_plan (organization_id,source_ref),
    CONSTRAINT fk_finance_exposure_reservation FOREIGN KEY (organization_id,reservation_id)
        REFERENCES cos_finance_budget_reservations(organization_id,id),
    CONSTRAINT fk_finance_exposure_envelope FOREIGN KEY (organization_id,envelope_id)
        REFERENCES cos_finance_budget_envelopes(organization_id,id),
    CONSTRAINT chk_finance_exposure_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
