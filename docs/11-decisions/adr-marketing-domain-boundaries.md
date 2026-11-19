# MKT-ADR-001: Marketing Domain ownership

Status: accepted for initial code foundation (2026-11-19). Superseded only by an explicit subsequent ADR.

1. MarketingCampaign = a provider-independent planning object. PaidMediaCampaign = one external campaign execution, potentially many per MarketingCampaign.
2. Marketing owns marketing planning, paid-campaign mappings, advertising creatives, audience specs and provider observations. Growth retains market intelligence, candidate experiments, outreach and qualification. No Growth migration or dual-write.
3. Product owns SKU/stock/prices. Marketing stores read-only links. Sales owns leads and deal outcomes. Finance owns envelopes/reservations/Expenses and rate policy.
4. Default state of imported external ads is EXTERNALLY_MANAGED. Account-to-tenant binding is globally unique.
5. All external paid writes fail closed until Finance F1, Policy/Approval and canonical Workflow execution are accepted. Human proposal is never enough. No parallel execution engine or raw token delegation to Hermes.
6. Meta reported spend is a non-accounting observation. Same-grain/timezone/currency attribution views only; never sum account+campaign+adset+ad.
7. Initial permissions are deliberately restrictive to existing tenant managers until dedicated marketing.* RBAC is provisioned.
8. SQL is tenant-scoped by organization_id, and write intents are idempotent and revisioned. Provider secrets resolve only via Platform Credential Vault.

