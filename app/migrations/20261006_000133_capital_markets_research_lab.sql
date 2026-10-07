CREATE TABLE IF NOT EXISTS tn_capital_market_research_hypotheses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    hypothesis_id VARCHAR(190) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_research_hypothesis_revision (organization_id,hypothesis_id,revision),
    KEY idx_cm_research_hypothesis_status (organization_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_research_datasets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    dataset_id VARCHAR(190) NOT NULL,
    snapshot_hash VARCHAR(64) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_research_dataset (organization_id,dataset_id),
    UNIQUE KEY uq_cm_research_dataset_hash (organization_id,snapshot_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_strategy_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    strategy_id VARCHAR(190) NOT NULL,
    version INT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_strategy_version_id (organization_id,strategy_version_id),
    UNIQUE KEY uq_cm_strategy_version_number (organization_id,strategy_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_research_experiments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    experiment_id VARCHAR(190) NOT NULL,
    hypothesis_id VARCHAR(190) NOT NULL,
    dataset_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_research_experiment (organization_id,experiment_id),
    KEY idx_cm_research_experiment_hypothesis (organization_id,hypothesis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_research_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    result_id VARCHAR(190) NOT NULL,
    experiment_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_research_result (organization_id,result_id),
    UNIQUE KEY uq_cm_research_result_experiment (organization_id,experiment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_strategy_promotion_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    decision_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_strategy_promotion_decision (organization_id,decision_id),
    KEY idx_cm_strategy_promotion_version (organization_id,strategy_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS tn_capital_market_backtest_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    experiment_id VARCHAR(190) NOT NULL,
    dataset_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    partition_name VARCHAR(24) NOT NULL,
    status VARCHAR(32) NOT NULL,
    reproducibility_fingerprint VARCHAR(64) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_backtest_run (organization_id,run_id),
    KEY idx_cm_backtest_experiment (organization_id,experiment_id),
    KEY idx_cm_backtest_fingerprint (organization_id,reproducibility_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_oos_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    experiment_id VARCHAR(190) NOT NULL,
    dataset_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_oos_run (organization_id,run_id),
    KEY idx_cm_oos_experiment (organization_id,experiment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    experiment_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_paper_run (organization_id,run_id),
    KEY idx_cm_paper_run_experiment (organization_id,experiment_id),
    KEY idx_cm_paper_run_strategy (organization_id,strategy_version_id),
    KEY idx_cm_paper_run_status (organization_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_strategy_scorecards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    scorecard_id VARCHAR(190) NOT NULL,
    strategy_version_id VARCHAR(190) NOT NULL,
    composite_score INT UNSIGNED NOT NULL,
    weight_version VARCHAR(64) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_strategy_scorecard (organization_id,scorecard_id),
    KEY idx_cm_strategy_scorecard_version (organization_id,strategy_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_rejected_hypotheses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    rejection_id VARCHAR(190) NOT NULL,
    hypothesis_id VARCHAR(190) NOT NULL,
    reason VARCHAR(48) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_rejected_hypothesis (organization_id,rejection_id),
    KEY idx_cm_rejected_hypothesis_id (organization_id,hypothesis_id),
    KEY idx_cm_rejected_reason (organization_id,reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_research_knowledge (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    knowledge_id VARCHAR(190) NOT NULL,
    knowledge_type VARCHAR(48) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_research_knowledge (organization_id,knowledge_id),
    KEY idx_cm_research_knowledge_type (organization_id,knowledge_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


UPDATE cos_module_installations
SET installed_version='0.8.0',
    schema_version='0.8.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.7.0'
  AND schema_version='0.7.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000133_capital_markets_research_lab');
