ALTER TABLE tn_people
    DROP INDEX uq_tn_people_public_id,
    ADD UNIQUE KEY uq_tn_people_org_public_id (organization_id, public_id);

ALTER TABLE tn_client_cases
    DROP INDEX uq_tn_client_cases_public_id,
    ADD UNIQUE KEY uq_tn_client_cases_org_public_id (organization_id, public_id);

INSERT INTO tn_migrations (migration)
VALUES ('20260826_000019_tenant_public_identifiers');
