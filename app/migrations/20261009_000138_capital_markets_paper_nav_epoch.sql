-- Every paper portfolio reinitialization starts a new accounting epoch.
-- Pre-migration portfolios retain a dedicated legacy epoch until their next reset.
ALTER TABLE tn_capital_market_paper_portfolios
    ADD COLUMN epoch_id VARCHAR(36) NOT NULL DEFAULT 'legacy';
