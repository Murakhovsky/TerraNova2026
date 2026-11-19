---
title: Marketing Domain
description: Marketing planning and Meta Ads read-only foundation.
status: draft
updated: 2026-11-19
kind: domain
---

# Marketing Domain

**Runtime status:** M0 code foundation, not yet staging-tested or accepted. The manifest is disabled by default.

Marketing is the single owner of campaign briefs, advertising audiences and creatives, provider campaign mapping and provider-reported spend/insight snapshots. Growth retains OpportunityCandidate and pre-handoff outreach. Product and Sales facts are only read through explicit contracts. Finance owns monetary envelope, reservations and financial expenses.

A Marketing Campaign is an internal plan. PaidMedia Campaign is a provider-specific execution of that plan; imported Meta objects remain EXTERNALLY_MANAGED. Reported Meta spend is evidence, not an accounting Expense. A paid write is forbidden until Finance F1, independent approval, canonical Platform Workflow and native provider readback are accepted.

Initial capabilities: campaign draft create/get/search and provider read-model account/insights queries. Inactive or unbound declarations must remain unavailable in Capability Library, not silently fall back to direct SQL or agent execution.

Current tenant gate: the existing Kernel cos.tenant.access and cos.tenant.manage permissions. Dedicated marketing.* RBAC is a follow-up migration and must be accepted before wider delegation. No raw Meta tokens in domain tables; only opaque credential references.

## Delivery levels

- M0: foundation SQL and pure services, D1 code (not D3/D4).
- M1: Meta read-only adapter and ingestion, requires real authorized account and ad-manager reconciliation for D3.
- M2: Product draft linkage and human-reviewed AI proposal (pending accepted Product API).
- F1/M3: Finance-owned reservations and governed Meta writes are **not enabled**.
- M4/M5: Attribution optimization and more providers are not implemented.

See the Marketing ADR and COS-MKT-TZ-001 for full acceptance criteria.

