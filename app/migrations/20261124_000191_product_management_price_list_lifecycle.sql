-- E7 price list lifecycle; existing lists retain their identities and prices.
ALTER TABLE pm_price_lists ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'active', ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 1, ADD CONSTRAINT ck_pm_price_list_status CHECK (status IN ('active','archived'));
