-- Finance collection evidence only; no payment-provider writes or accounting revenue.
CREATE TABLE IF NOT EXISTS cos_finance_order_balances (
 organization_id VARCHAR(40) NOT NULL, order_id CHAR(32) NOT NULL, currency CHAR(3) NOT NULL,
 order_total_minor BIGINT UNSIGNED NOT NULL, captured_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 refunded_minor BIGINT UNSIGNED NOT NULL DEFAULT 0, chargeback_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 0, evidence_reference CHAR(32) DEFAULT NULL,
 PRIMARY KEY (organization_id,order_id),
 CONSTRAINT chk_fin_order_balance CHECK (refunded_minor + chargeback_minor <= captured_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS cos_finance_order_operations (
 organization_id VARCHAR(40) NOT NULL, id CHAR(32) NOT NULL, order_id CHAR(32) NOT NULL,
 kind VARCHAR(32) NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL, parent_id CHAR(32) DEFAULT NULL,
 source_account VARCHAR(120) NOT NULL, source_reference VARCHAR(191) NOT NULL,
 actor_id VARCHAR(190) NOT NULL, action_id VARCHAR(190) NOT NULL, correlation_id VARCHAR(190) NOT NULL,
 idempotency_key VARCHAR(191) NOT NULL, request_hash CHAR(64) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,id),
 UNIQUE KEY uq_fin_order_source (organization_id,source_account,source_reference),
 UNIQUE KEY uq_fin_order_registration (organization_id,idempotency_key),
 KEY idx_fin_order_operations (organization_id,order_id,parent_id),
 CONSTRAINT fk_fin_order_operation_balance FOREIGN KEY (organization_id,order_id)
 REFERENCES cos_finance_order_balances(organization_id,order_id),
 CONSTRAINT chk_fin_order_operation CHECK (amount_minor > 0 AND kind IN ('capture','refund','chargeback','chargeback_reversal'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS cos_finance_order_reconciliations (
 organization_id VARCHAR(40) NOT NULL, id CHAR(32) NOT NULL, order_id CHAR(32) NOT NULL,
 operation_id CHAR(32) NOT NULL, statement_reference VARCHAR(191) NOT NULL, source_account VARCHAR(120) NOT NULL,
 actor_id VARCHAR(190) NOT NULL, action_id VARCHAR(190) NOT NULL, correlation_id VARCHAR(190) NOT NULL,
 idempotency_key VARCHAR(191) NOT NULL, request_hash CHAR(64) NOT NULL, revision INT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,id),
 UNIQUE KEY uq_fin_order_confirm_operation (organization_id,operation_id),
 UNIQUE KEY uq_fin_order_statement (organization_id,source_account,statement_reference),
 UNIQUE KEY uq_fin_order_reconcile_key (organization_id,idempotency_key),
 UNIQUE KEY uq_fin_order_revision (organization_id,order_id,revision),
 CONSTRAINT fk_fin_order_confirmation FOREIGN KEY (organization_id,operation_id)
 REFERENCES cos_finance_order_operations(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
