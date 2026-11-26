-- Pre-registration of an immutable single-factor creative hypothesis. No provider experiment or spend.
CREATE TABLE IF NOT EXISTS marketing_experiment_protocols (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    pair_hash CHAR(64) NOT NULL,
    protocol_hash CHAR(64) NOT NULL,
    protocol_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id,id),
    UNIQUE KEY uq_mkt_experiment_key (organization_id,idempotency_key),
    UNIQUE KEY uq_mkt_experiment_pair (organization_id,pair_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
