ALTER TABLE cos_policies
    ADD COLUMN decision_reason VARCHAR(500) NULL AFTER decision;

ALTER TABLE cos_policy_evaluations
    MODIFY COLUMN decision ENUM('AUTO','APPROVAL_REQUIRED','DENIED','HUMAN_ONLY') NOT NULL;

UPDATE cos_policies
SET decision_reason = CONCAT('Policy ', name, ' decided ', decision, '.')
WHERE action_type LIKE 'sales.%' AND decision_reason IS NULL;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260911_000033_sales_v075_actions_policies');
