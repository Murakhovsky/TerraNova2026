INSERT IGNORE INTO cos_feature_flags
    (flag_key,description,enabled,rollout_percentage,rollout_salt)
VALUES
    ('capital_markets.market_data.kraken.enabled','Capital Markets Kraken public market-data adapter',0,0,'cm-mi-kraken-disabled');

UPDATE cos_module_installations
SET installed_version='0.6.0',
    schema_version='0.6.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.5.0'
  AND schema_version='0.5.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000131_capital_markets_kraken_market_data');
