CREATE TABLE IF NOT EXISTS tn_capital_market_research_hypotheses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    hypothesis_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_research_hypothesis (organization_id,hypothesis_id),
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
