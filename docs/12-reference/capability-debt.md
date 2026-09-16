---
title: Беклог боргу можливостей
description: Згенерований backlog невирішених прогалин vocabulary capabilities, пов’язаних із канонічними кроками бізнес-процесів COS.
status: generated
updated: 2026-09-16
kind: reference
contract: reference-v1
generated: true
---

# Беклог боргу можливостей

Згенеровано з `docs/.vitepress/capability-debt.json`, кроків Process Registry із capability gaps і module capability authority поточного checkout. Не редагуйте цю сторінку вручну.

Capability debt означає, що бізнес-крок реальний, але Domain-власник ще не експонує достатньо семантичну discoverable capability. Це **не** означає відсутність runtime implementation.

## Підсумок

- **Відкритих debt items:** 19
- **High severity:** 16
- **Medium severity:** 3
- **Зачеплених Domains:** 2

| Domain | Відкрито | High | Medium | Low |
| --- | ---: | ---: | ---: | ---: |
| `diagnostic` | 7 | 7 | 0 | 0 |
| `sales` | 12 | 9 | 3 | 0 |

## Пріоритетний backlog

| Severity | Domain | Процес / крок | Runtime evidence | Target capability | Resolution |
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
| `high` | `sales` | [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md) · `create-case` · Create Client Case and link inbound request | `source` | `sales.client-case.create` | `declare-domain-capability` |
| `high` | `sales` | [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md) · `load-request` · Load inbound request and referenced Property context | `source` | `sales.inbound.request.read` | `declare-domain-capability` |
| `high` | `sales` | [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md) · `record-match` · Record Property Match in Sales | `source` | `sales.property-match.record` | `declare-domain-capability` |
| `medium` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `activity` · Record activity / call | `source` | `sales.activity.record` | `declare-domain-capability` |
| `medium` | `sales` | [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md) · `crm-inbox` · Process durable CRM inbox | `source` | `sales.crm.inbox.process` | `declare-domain-capability` |
| `medium` | `sales` | [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md) · `activity-events` · Record Sales activity and publish case/lead events | `source` | `sales.activity.record` | `declare-domain-capability` |

## Контракт закриття боргу

Debt item вважається закритим лише коли виконані всі умови:

1. Domain-власник декларує target capability у канонічному module `contributions.capabilities`.
2. Відповідний крок Process Registry замінює `capability: null` + `capability_gap` на цю задекларовану capability.
3. Відповідний item видалено з `capability-debt.json`.
4. Генерація документації та перевірки проходять з того самого checkout.

`check-capability-debt.mjs` забезпечує зв’язок 1:1 між Process Registry gaps і debt items. Він також відхиляє stale debt, якщо target capability вже існує, неправильний Domain ownership, невалідну severity або target name поза namespace Domain-власника.

## Політика severity

- `high` — capability gap прив’язаний до критичного кроку процесу.
- `medium` — gap прив’язаний до некритичного, але канонічного кроку процесу.
- `low` — зарезервовано для майбутніх неканонічних/optional класів debt; поточний workflow debt його не використовує.

Severity описує architecture-model debt, а не severity operational incident.

## Авторитетність і обмеження

- Process Registry володіє фактом існування capability gap.
- Capability Debt Registry володіє remediation metadata для цієї прогалини.
- Domain module manifests залишаються authority для capabilities, які реально існують.
- Runtime Evidence Resolver залишається authority для source/runtime verification.
- Generated Markdown є лише projection і ніколи не використовується як executable authority.
- Закриття debt може вимагати Domain patch release; documentation layer не мутує Domain manifests мовчки.