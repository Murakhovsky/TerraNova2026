---
title: Capability Debt Backlog
description: Generated backlog of unresolved Domain capability vocabulary gaps linked to canonical COS business-process steps.
status: generated
updated: 2026-09-15
kind: reference
contract: reference-v1
generated: true
---

# Capability Debt Backlog

Generated from `docs/.vitepress/capability-debt.json`, Process Registry gap steps and current-checkout module capability authority. Do not edit this page manually.

Capability debt means the business step is real but the owning Domain does not yet expose a sufficiently semantic discoverable capability. It does **not** mean the runtime implementation is absent.

## Summary

- **Open debt items:** 15
- **High severity:** 13
- **Medium severity:** 2
- **Affected Domains:** 2

| Domain | Open | High | Medium | Low |
| --- | ---: | ---: | ---: | ---: |
| `diagnostic` | 7 | 7 | 0 | 0 |
| `sales` | 8 | 6 | 2 | 0 |

## Prioritized backlog

| Severity | Domain | Process / step | Runtime evidence | Target capability | Resolution |
| --- | --- | --- | --- | --- | --- |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `complete` · Complete coherent session | `source` | `diagnostic.session.complete` | `declare-domain-capability` |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `decision` · Review / accept recommendation | `source` | `diagnostic.recommendation.accept` | `declare-domain-capability` |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `evaluation` · Evaluate structured evidence | `source` | `diagnostic.evaluation.run` | `declare-domain-capability` |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `evidence` · Capture evidence | `source` | `diagnostic.evidence.capture` | `declare-domain-capability` |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `methodology` · Draft and publish methodology version | `source` | `diagnostic.methodology.manage` | `declare-domain-capability` |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `result` · Record findings and recommendation | `source` | `diagnostic.result.record` | `declare-domain-capability` |
| `high` | `diagnostic` | [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md) · `session` · Start version-pinned session | `source` | `diagnostic.session.start` | `declare-domain-capability` |
| `high` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `assign-owner` · Assign owner | `runtime` | `sales.deal.owner.assign` | `declare-domain-capability` |
| `high` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `canonical-state` · Create or update canonical Sales state | `source` | `sales.lead.state.manage` | `declare-domain-capability` |
| `high` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `followup` · Schedule next action | `runtime` | `sales.followup.schedule` | `declare-domain-capability` |
| `high` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `intake` · Lead intake | `source` | `sales.lead.intake` | `declare-domain-capability` |
| `high` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `outcome` · Record business outcome | `runtime` | `sales.deal.outcome.record` | `declare-domain-capability` |
| `high` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `pipeline` · Manage pipeline stage | `source` | `sales.pipeline.stage.manage` | `declare-domain-capability` |
| `medium` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `activity` · Record activity / call | `source` | `sales.activity.record` | `declare-domain-capability` |
| `medium` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `crm-inbox` · Process durable CRM inbox | `source` | `sales.crm.inbox.process` | `declare-domain-capability` |

## Resolution contract

A debt item is resolved only when all of the following become true:

1. The owning Domain declares the target capability in its canonical module `contributions.capabilities`.
2. The matching Process Registry step replaces `capability: null` + `capability_gap` with that declared capability.
3. The matching item is removed from `capability-debt.json`.
4. Documentation generation and checks pass from the same checkout.

`check-capability-debt.mjs` enforces the 1:1 relation between Process Registry gaps and debt items. It also rejects stale debt whose target capability already exists, wrong Domain ownership, invalid severity, or target names outside the owning Domain namespace.

## Severity policy

- `high` — the capability gap is attached to a critical process step.
- `medium` — the gap is attached to a non-critical but canonical process step.
- `low` — reserved for future non-canonical/optional debt classes; current workflow debt does not use it.

Severity describes architecture-model debt, not operational incident severity.

## Authority and limitations

- Process Registry owns the fact that a capability gap exists.
- Capability Debt Registry owns the remediation metadata for that gap.
- Domain module manifests remain the authority for capabilities that actually exist.
- Runtime Evidence Resolver remains the authority for source/runtime verification.
- Generated Markdown is a projection only and is never used as executable authority.
- Resolving debt may require a Domain patch release; this documentation layer does not silently mutate Domain manifests.
