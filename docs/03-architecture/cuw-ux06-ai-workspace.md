---
title: CUW UX-06 — AI Workspace first governed vertical
description: Scoped AI Workspace, runtime adapter discovery and Marketing Hermes delegation.
status: draft
updated: 2026-11-27
kind: architecture
---

# CUW-C / UX-06 / #521

Baseline: `10fc649df6aafdca432ec329ebd737f6a4abd1ec` (Wave 2 parent #518).
Normative: COS Unified Workspace Master Spec §§11, 19, 20, 21. This is an isolated implementation, not D3/D4/D5 or LIVE Hermes acceptance.

## Reuse / adapt / new

| Area | Decision |
| --- | --- |
| Shell, UIContext, AgentRunReadModel, UIActionResolver | Reuse, no shared Shell/DI/routes changes |
| AI panel | Extend the existing GET route with `view=workspace`; same server-rendered body, stable DOM/frame identifiers |
| Runtime-neutral experience adapter | New tagged interface; fail closed on ambiguity; inspect only, no transport or mutation |
| First Hermes binding | Marketing Campaign owner services: scoped campaign read, readiness, pinned creative/adset list, native run journal |
| Submission | Existing protected POST `/marketing/campaigns/{id}/analyst-plans`; existing CSRF, stable form idempotency, role and module checks |
| Workflow and Action | Existing Federation Plan, independent Plan and Action approval, approved worker, native run claim and outcome provider |
| Actions from Agent outputs | Existing UIActionResolver projections; no buttons generated from untrusted JSON; link to real Control Center |
| Engineering agents | Separate profile, credentials and administration; no business UI access to those tools |

The current canonical read model has tenant-level run access rather than per-user ACLs; AI history is therefore manager/admin + ACCESS and MANAGE only. Tenant, role, entity type, subject ID and native Action run ID are checked. Invalid explicit scope is not downgraded to organization history. For a collection with no declared entity type, only the explicit Marketing campaign binding allows an entity; unsupported runtime contexts are read-only. A missing adapter is not an error or permission to invoke an alternative provider.

## R4 first vertical

1. Open global AI panel; choose Open AI Workspace.
2. Select Marketing Campaigns and a tenant-owned campaign.
3. Inspect readiness, remote tool allowance (zero), daily turn allowance, cost ceiling and the per-HTTP-call timeout.
4. If the owner service exposes plan availability, choose pinned Creative / optional AdSet and propose an analysis Plan. This is **not** a remote request.
5. Continue in Goals for independent Plan approval and separately authorized Action. The existing worker owns the only remote invocation path.
6. Inspect native Hermes journal, cited source aliases, source revisions and provider response ID in the AI workspace. Native run ID is its remote correlation. Advice is unreviewed until an independent owner review.
7. Open owner journal for review or uncertain recovery. Open Goals to inspect the independently attested Action receipt and verified run-linked outcome, if present. No paid activation is authorized by advice.

Generic Agent runs display structured result, recommendations, untrusted proposed actions, evidence and actual Action/Approval projections. The existing `cos:workspace-action` event has no production consumer on this baseline; the UI now links to the canonical operations surface rather than claiming those buttons execute. Lifecycle completion and native provider response IDs are not independent proof of business outcome.

## Limits and failure semantics

The existing approved Marketing execution adapter enforces scope rebuilding, tenant configuration and isolation acceptance, zero enabled remote tools, a bounded HTTP body, 45 seconds per HTTP request, daily turn allowance and conservative Kernel cost reservation. No automatic retry after external ambiguity; native journal supports independent recovery, not reversal of cost. Provider-side exact token usage/budget and remote cancellation have not been accepted. Do not claim a configured profile is LIVE or introduce an unapproved generic provider call. Operator/credential gates remain mandatory. Manual workflows and Control Center remain available during runtime outage.

No second run/approval/outcome store, direct browser execution, dynamic tool/secret grant, raw HTML or Kernel modification.

## Acceptance and verification

| AC | Covered by this change | Remaining gate |
| --- | --- | --- |
| AC-04 / AC-09 | Existing protected Plan form and governed operation links; structured output separated from executable Action | Browser/real canonical approval replay |
| AC-10 | Missing/unsupported adapter, failed reads and unknown outcomes degrade without remote call | Runtime outage acceptance |
| AC-11 | Manager/permission, tenant, workspace and subject negatives; owner Campaign read | Combined-tree role/tenant E2E |
| AC-16 | Correlation and native journal fields; link to canonical outcome verifier | Independently attested Action receipt in staging |
| AC-12 / AC-13 | SSR same body, labelled controls, native focus/keyboard, responsive wrapping | Desktop/mobile browser screenshots and a11y QA |

Automated verification entry points:
- `php tests/architecture/cos_unified_workspace_ux06_contract.php`
- `php tests/unit/cos_unified_ai_workspace.php`
- Existing runtime workflow: `tests/integration/marketing_analyst_approved_goal_e2e_mysql.php` (mock/isolated provider; not LIVE).
- Existing `cos:web:ai-ui:smoke` (synthetic run and actual Action projection).

Local environment has no PHP, Composer or Docker; do not claim those tests were executed here. CI and runtime acceptance must prove them on the submitted branch, followed by combined-tree review. Exact provider token budget, remote cancel, multi-domain generic delegation and independent live receipt remain open. No production deployment, remote invocation or activation is introduced by this PR.

Rollback: revert this isolated PR; no schema/storage migration. Existing Marketing, Control Center, Goals and AI panel route remain.
