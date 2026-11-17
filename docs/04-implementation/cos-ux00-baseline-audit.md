---
title: UX-00 — Аудит базового стану COS Unified Workspace
description: Перевірка існуючого інтерфейсу, покриття можливостей та критеріїв приймання COS.
status: preacceptance
updated: 2026-11-17
kind: implementation
source_spec: COS Unified Workspace Master Specification v1.0
baseline_main_sha: 3a8640593cfd819b0c8b7e562968eacce8ee5270
---

# UX-00 — Аудит, покриття функціоналу та контроль релізу

**Status: PREACCEPTANCE / NOT READY FOR UX-01 CODE MERGE.**
The current main code is NOT claimed production-certified. This report is a static repository + GitHub metadata audit, not a live Symfony/MySQL/2-tenant/approval/agent E2E witness.

## 1. Зафіксований вихідний стан

- Repository: Murakhovsky/TerraNova2026.
- Current main after documentation-only PR #412: **3a8640593cfd819b0c8b7e562968eacce8ee5270**.
- Preacceptance rollback/ref branch: **baseline/ux00-preacceptance-20261117** at the exact SHA above.
- Program issue: #428.
- Preserved unmerged PR ledger: #429.
- Former open queue: 20 PRs. One documentation-only PR (#412) was squash-merged; 19 were closed without merge (source branches and head SHAs preserved); last search returned **0 open PR**. No source branch deletions.
- Existing 411 branch refs were enumerated prior to the two UX-00 refs; naming alone does not prove they are stale or safe to delete.
- Risk: the current runtime workflow deploys AWS dev after qualifying pushes to main for selected source paths. The proposal to deploy on *every* main merge (#391) was rejected.

## 2. Кількісна інвентаризація

Repository tree and source-manifest census:

| Item | Observed | Acceptance meaning |
|---|---:|---|
| Domain module manifests | 13 | Source declaration inventory, not activated tenant availability |
| Declared capability IDs | 190 | 16 typed, 174 non-typed declarations |
| Typed capabilities | 16 | Exact current manifest contracts, most experimental |
| Symfony named routes | 632 | Static routes.yaml declarations, not successful requests |
| Web PHP source files | 404 | Static tree only |
| Experience Twig/PHP components | 67 PHP component classes | Reuse candidates, component-level E2E pending |
| Domain Web providers | 5 | Sales, Property, Growth, Diagnostic, CapitalMarkets |
| Tracked files under tests tree | 909 | Includes historical tests; not number of green tests |
| Open PRs after cleanup | 0 | Branches retained intentionally |

**Canonical machine-readable inventory:** [cos-ux00-experience-coverage.csv](./cos-ux00-experience-coverage.csv), 190 rows from the exact 13 current module.php manifests. The CSV separates typed definition from legacy declaration, and does not promote source files into runtime readiness. Baseline typed counts: Property 5, Sales 2, Growth 4, Documents 3, Federation 1, CapitalMarkets 1. No typed contracts declared in seven remaining modules.

**Important:** PR #419 documents a further legacy split of 75 feature surfaces vs 99 contract candidates. That classification is **NOT in main** and is deliberately not asserted by this report. The current baseline is 16 typed / 174 declarative.

## 3. Карта архітектури та повторне використання

| Layer / boundary | Main source | UX-00 disposition |
|---|---|---|
| Canonical authenticated Shell | symfony/templates/experience/workspace_shell.html.twig; WorkspaceShellFactory | ADAPT; no second business shell |
| Portal/reading surface | portal_shell.html.twig | REUSE for public/reading only; consolidate authenticated operations |
| Navigation & search | WebExtensionProviderSet; five Providers; GlobalSearchController | REUSE/ADAPT |
| Canonical UI actions | UIActionRegistry; UIActionResolver; TenantUIActionPermissionChecker | REUSE; prove real handler/UI action readiness |
| Business workspace composition | WorkspaceCompositionResolver; WorkspaceSlot; CosWorkspace | REUSE |
| Adaptive modes | AdaptiveExperienceResolver + ExperienceContext | ADAPT; stronger semantic trigger & evidence composition |
| Design components | 67 Experience/Component PHP classes; /dev/ui | REUSE; test responsiveness and accessibility |
| Sales reference | SalesWebProvider; Sales controllers / templates | P0 reference read/action vertical |
| Property reference | PropertyWebProvider; Inventory, Catalog, Bulk, create form | P0 reference read/action vertical |
| Canonical Capability Gateway | CanonicalCapabilityGateway + Domain module manifests | REUSE; DO NOT create new registry/executor |
| Goals | GoalsWorkspaceController; federation/goals.html.twig | ADAPT human metric entry and proof |
| Async/Operation | ActivityCenterController; ControlCenterPageController; RetryAsyncOperationController | ADAPT/UNIFY read projections |
| AI | AIPanelController; UIContextFactory; structured Agent views | ADAPT, Hermes delegated execution unverified |
| External integrations | Shopify read-only smoke; integration contracts | BLOCKED until real permissions, receipts and tenant-scoped E2E |

**Specific evidence-based issues:**
1. WorkspaceShellFactory currently fills connectionState = Live and aiAvailable = true, not verified from runtime health; do not show unproven readiness as fact.
2. Goals UI demands a machine criterion identifier (e.g. sales.won_deals). A semantic Metric Picker with unit/source/freshness is a missing UX requirement.
3. AdaptiveExperienceResolver already preserves critical sections and scopes UIActions but limits primary actions by mode 2/4/all; it is not yet full semantic context adaptation.
4. Capability Library currently shows technical IDs and contracts. It is an operator tool, not a business-friendly action discovery surface.
5. Shopify read-only smoke at current application code SHA c3c747a (run 42528976715) failed with GraphQL error **Access denied for products field.** OAuth/transport alone does not prove read-products entitlement; investigate actual app scopes and reauthorization before pilot.

## 4. Покриття інтерфейсу бізнес-операціями P0

Six readiness dimensions are independent:
defined / registered / available_for_tenant / authorized / ui_ready / execution_verified.

| Domain operation | Kind | Existing UI relation | UX-00 status | Gap before claim |
|---|---|---|---|---|
| property.asset.create | Command | Direct quick-create form /workspace/capabilities/property/create | PARTIAL static | Actual Command + domain readback, tenant/CSRF/idempotency E2E |
| property.asset.search | Query | /property/manage related inventory UI | PARTIAL static | Verify same Query binding, search/filter/error/freshness/tenant E2E |
| property.asset.get | Query | /property/manage related page | PARTIAL static | Verify canonical typed Query, entity scope & detail |
| property.asset.update | Command | Property inventory related page | UNKNOWN invoke UI | Verified form descriptor, policy and receipt |
| property.bulk.create | Async Command | /workspace/property/bulk stage/propose | PARTIAL static | approve → actual worker → dedup → per-item native evidence |
| sales.create_task | Async Command | Sales follow-up actions and /sales/deals | PARTIAL static | Resolve UI action to exact typed Action binding and task readback |
| sales.deal.won.observation | Query/read binding | Goals evidence / Sales related views | UNKNOWN invoke UI | Prove source/metric freshness, no Goal auto-dispatch |
| federation.plan.approval | Command | Goals plan approval UI | PARTIAL static | independent human approval + real receipt, negative tests |
| Growth typed 4 | Commands | Growth UI surfaces | UNKNOWN | Explicit presentation contract + runtime and tenant evidence |
| Documents typed 3 | Commands | No dedicated Web provider verified | MISSING direct UI | Governed action UX + receipts/proof |
| Capital Markets typed 1 | Command | CM decision UI exists | UNKNOWN | Separate P2 gate; no production promotion |

**Status semantics:** PARTIAL = source UI/controller/contract exists, not all six runtime states passed; UNKNOWN = linkage or runtime not proven; MISSING direct UI = no exact UI contract demonstrated by the audit. No P0 typed operation is marked execution_verified.

## 5. Перевірки середовища виконання та CI

- Successful full CI + Runtime on main was observed for the earlier application SHA a4498c4530ad1fcf24051cd32483146b4dd4f555, not for final main. Do not apply the old pass to the current commit.
- Current application's latest push run for Shopify read-only smoke (42528976715) failed with a GraphQL permission error (not general network failure). See issue #428 evidence comment.
- Docs-only PR #412 passed its own CI/Runtime/Frontend/Documentation workflows on its original HEAD, then was merged as 3a864059...; separate current-main end-to-end certification remains required.
- Runtime tests requiring MySQL, multiple users, real operator approval, external integration secrets or live Hermes have **not been executed by this UX-00 audit**.
- The source repository was not present as a local executable checkout in the audit environment; GitHub API gave read/write metadata and source access. This is a limitation, not a passed test.

## 6. Мінімальний бізнес-пілот P0

1. R1: tenant-authorized Property asset search/get or Sales Leads/Deals read, including search/filter, empty, denied and mobile.
2. R2: human Property asset create with CSRF, real Capability/Command, idempotent retry, native readback, activity and audit; alternatively verified Sales follow-up create.
3. R3: Property staged bulk import (limited batch) → preview/dedup → approval → canonical workflow → status/results/reconciliation.
4. UX-01 starts only after the above P0 readiness targets are explicitly known; UX-01 Shell read-only slice can be developed independently of complete Golden 50.
5. No fake Shopify/Meta/Hermes success or unseen provider data.

## 7. Послідовність критеріїв приймання

| Gate | Requirement | Current |
|---|---|---|
| G0 PR cleanup | 0 old open PRs, preservation ledger, main SHA | DONE |
| G1 Baseline freeze | baseline branch / exact SHA / no uncontrolled deploy | DONE (preacceptance) |
| G2 Main CI | fresh CI/Runtime/Frontend/Docs on exact integrated code | NOT VERIFIED |
| G3 Build/runtime | Symfony container boot, route:debug, services lint, schema migrations dry-run | NOT RUN |
| G4 Security | 2 tenant negative tests, actor permissions, CSRF, idempotency, no side-effect read | NOT RUN |
| G5 R1 | confirmed read-only business read with real data desktop/mobile | NOT RUN |
| G6 R2 | verified human mutation and native receipt/readback | NOT RUN |
| G7 R3 | approved async/bulk, evidence, partial-failure/reconcile | NOT RUN |
| G8 Integrations | Shopify scope fix, separate Hermes/Meta sandbox gates | BLOCKED / NOT VERIFIED |
| G9 Sign-off | accepted UX00 coverage, release gate and next UX-01 slice | PENDING |

## 8. Завдання для усунення прогалин

- **UX00-B01 P0:** CI on frozen exact source SHA; publish workflow links and failures.
- **UX00-B02 P0:** real Symfony DI/route, DB migration preflight and two tenant negative runtime suite.
- **UX00-B03 P0:** run R1 Property/Sales query acceptance; map exact Query/handler/UI/realtime states to CSV.
- **UX00-B04 P0:** run R2 property.asset.create or Sales follow-up idempotency, native readback and policy.
- **UX00-B05 P0:** R3 Property bulk approved workflow with retry/reconciliation.
- **UX00-B06 P0:** replace Shell hardcoded availability display with server-owned connection/AI readiness state as later UX-01 code, not UX-00 mutation.
- **UX00-B07 P0:** UX-01 design acceptance ADR: Tabler licensing/dependencies/Turbo compatibility vs existing 67 reusable components; no second JS app.
- **UX00-B08 P0:** UI action descriptor coverage, business labels, errors, confirmation and receipts. No arbitrary JSON form generator.
- **UX00-B09 P0:** integrate Operations status mapping and unknown-outcome evidence (UX-05).
- **UX00-B10 P1:** human Semantic Metric Catalog for Goals (UX-07).
- **UX00-B11 gated:** reconcile Capability Library #415–#422 as a tested isolated integration candidate, restore the two missing #415 changed paths; never merge cumulative branch as-is.
- **UX00-B12 gated:** restart isolated Hermes, Z.AI, Shopify, CM changes only with exact policy/secret/release approval.

## 9. Архітектурні рішення та незмінні правила

Proposed ADRs: unified authenticated Shell migration; UIAction presentation descriptor compatibility; adaptive semantic triggers; aggregation of existing operations projections; agent runtime adapter boundary; Tabler component integration. Preserve Wave 12 Web technical decisions and existing Kernel / Application / Capability / Policy / Goal / Workflow contracts.

**No UX-01 code or production write integration is authorized by this document.** UX-00 signoff requires R1/R2/R3 verified against real staging and an operator-approved exact SHA, with remaining unknown states called out explicitly.

Master Specification: COS Unified Workspace v1.0 (2026-11-17), sections 17–21 and 25.
