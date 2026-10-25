---
title: Goal Capability fingerprint — bounded P0 rollout notes
description: Behavior and acceptance boundaries for pinned Capability contracts in canonical Goal plans.
status: implementation-draft
updated: 2026-10-25
kind: operations
---

# Goal Capability pins: bounded rollout and evidence

## Scope

This increment extends the existing **Domain-owned Module Capability Contract** and **canonical Platform Workflow** only. New `FederationGoalStore::proposePlan` calls `GoalPlanValidator::validate(..., true)`, which places `contract_digest` and `input_digest` in each normalized immutable Step. The Plan JSON is then included in existing approval and Workflow digests.

No new Action dispatcher, database writer, scheduler, EventBus, AI authority or recovery process is introduced.

### Behavior matrix

| Input/Plan state | Result |
| --- | --- |
| New proposed Goal Plan | Exact current-version contract fingerprint and canonical input hash pinned |
| Contract/schema updated before human Plan approval | Original approved Plan cannot be silently repointed |
| Approved pinned Plan, digest/owner/input changed before canonical start | Start denied before creating Run |
| Drift after Run start, before selection of next Step | `manual_reconciliation_required` without new Action |
| Candidate external claim / Action worker admission | Fail-closed pinned fingerprint check in candidate admissions |
| Existing legacy Plan without pins | No invented retrospective digests; existing version/binding/tenant/approval guards still apply |
| Mixed/partial Plan fingerprint | Rejected by `GoalCapabilityPinGuard` |
| Completed native Action with invalid output | Requires independent native reconciliation, never automatic replay |
| Goal achieved | **Still requires independent Domain business outcome evidence** |

## Important production boundary

`symfony/config/services.yaml` currently wires `Kernel\Action\Service\ModuleActionExecutionGate` to `App\Persistence\Workflow\FailClosedWorkflowActionWorkerAdmission`. The candidate `DoctrineWorkflowActionWorkerAdmission` does **not** replace that production gate in this PR. No external Workflow Action worker activation is implied by the new pin checks or green unit tests.

Operator must not flip this DI alias until the separate source-to-worker approval parity, native receipts, recovery, MySQL fault injection, tenant rollout and two-person signoff acceptance package passes.

## Manual validation

From a fresh checkout with root Composer dependencies:
~~~bash
php tests/unit/cos_capability_contract_snapshot.php
php tests/unit/cos_goal_capability_pinning.php
php tests/architecture/cos_goal_capability_pin_authority.php
php tests/unit/canonical_capability_gateway.php
php bin/cos-capability-census.php --strict
~~~

`cos-capability-census.php` is **static definition-level evidence only**. It cannot prove DI registration, tenant role, worker readiness or native outcome.

Operator UI `/workspace/goals` reports `PINS_PRESENT_NOT_ATTESTED`, `LEGACY_NO_PINS` and `INCOMPLETE_PINS_BLOCKED` as presence diagnostics. A displayed pin is not an approved or executable plan by itself.

## Remaining gates

1. Version-addressed immutable historical contract archive and explicit compatible old handler registration. Missing version/digest must stay blocked.
2. Live registered binding inventory across all Domains and tenant policies, checked on deploy SHA.
3. Worker-time Action approval/receipt/failure/recovery parity with production DI explicitly fail-closed until authorized cutover.
4. Output-schema/native-result validation for **governed** Actions (direct Gateway currently has output validation).
5. AI/CRON/Event-to-proposed-Plan adapters, no new authority.
6. Complete Golden 50 with 50 native Candidates, 50 Sales Leads, 50 Documents, 200 approved child Actions and independent Goal evaluation on one accepted SHA.

This page describes implementation boundaries, not production acceptance.
