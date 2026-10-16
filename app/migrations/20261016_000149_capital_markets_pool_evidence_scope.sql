-- Widen scopes on the single #374 production evidence table; no duplicate authority.
ALTER TABLE tn_capital_market_pool_external_evidence DROP CHECK chk_cm_pool_evidence_kind;
ALTER TABLE tn_capital_market_pool_external_evidence
    ADD CONSTRAINT chk_cm_pool_evidence_kind CHECK (kind IN
      ('SOURCE_ENTITLEMENT','VENUE_ENTITLEMENT',
       'MASSIVE_NBBO_ENTITLEMENT','ISSUER_IDENTITY'));
INSERT IGNORE INTO tn_migrations(migration)
VALUES ('20261016_000149_capital_markets_pool_evidence_scope');
