---
title: Goal Runtime та Golden 50 — аудит залежностей і стандартизації
description: AS-IS за main на 2026-10-25, TO-BE операторський UI і contract/acceptance matrix.
status: draft
updated: 2026-10-25
kind: architecture
---

# Goal Runtime / Golden 50: аудит і стандартизація

> **Межа доказів:** source review GitHub `main`, 2026-10-25. Під час підготовки цього документа НЕ виконувалися live tenant, CI, MySQL fault injection, operator acceptance, production deploy. Наявність класу, архітектурного контракту або mock fixture не підтверджує production readiness. Запропонований cockpit є **першим UI-зрізом**, не повним Golden 50 flow.

## 1. Рішення

Має бути один користувацький Goal Control Center, а не одна форма з машинним кодом метрики. Один canonical Registry контрактів і **один technical Run/Step writer**. Різні ініціатори (`UI / AI / API / CRON / Event`) можуть **пропонувати намір**, але всі мутації мають проходити серверний `Capability Contract → Policy/Approval → Platform Workflow → native Domain handler → receipt`. Read-only Queries мають окремий, слабший режим виконання. Event сам по собі не надає повноважень.

Ціль `federation.verified_proposals >= 50` означає **50 native candidates + 50 linked native Sales Leads + 50 linked Documents + independent evaluation**, а не 50 успішних POST або Steps. Документи *підготовлені*, не відправлені і не підписані.

## 2. Dependency inventory та AS-IS

| Вузол | Source-of-truth / реалізація | Оцінка готовності | Доказ / gap |
| --- | --- | --- | --- |
| Goal і метрика | `GoalsWorkspaceController`, `FederationGoalStore`, Goal Specification | Є для вузького сценарію | UI зараз пропонує машинні коди; метрики без trusted Domain source залишаються unverifiable |
| Людський конструктор Plan | `FederationSmallGoalPlanProposalService`, `FederationDiscoveryPlanProposalService` | Частково | Golden Discovery і 1–3 Sales tasks; нема підтвердженого універсального довільного AI Planner |
| Golden fan-out | `FederationCandidateFanoutService` | Backend-bound, UI gap | Preview/propose через operator API із native provenance, policy/template; у Goals UI немає керованого списку 50 child Plans |
| Typed Capability Library | `CanonicalCapabilityCatalog`, `CapabilityContract` | Реалізована основа | Single metadata projection: identity, version, owner, schema, permission, side-effect, approval, timeout, retry, tests, binding |
| Capability runtime binding | `CanonicalCapabilityGateway`, `FederationCapabilityBindingResolver` | Частково | Read-only Query та bounded human Command; governed Actions вимагають Workflow; довільна AI/CRON мутація заборонена |
| Request validation | `CapabilityJsonInputValidator`, Plan hash/version/pinned bindings | Реалізовано у підтриманих paths | Потрібний загальний typed Plan compiler + contract-aware AI planning / dependency preflight |
| Authorization | Tenant, Module, Action/Policy/Approval, independent approver | Реалізована основа | Safety recheck на Action worker; обхід approval з UI/agent/event неприпустимий |
| Canonical execution | `FederationCanonicalWorkflowBridge`, `DoctrineWorkflowExecutionCoordinator`, `DoctrineWorkflowExecutionStore` | Працює на allowlisted нових Run | `cos_workflow_runs/steps`; не доведений весь fleet і кожний тип Capability |
| Step lifecycle / recovery | CAS/claim/checkpoint, Action receipts, recovery inspectors | Частково / fenced | Зовнішній unknown outcome ⇒ AMBIGUOUS, без blind retry; повний fault/failover acceptance незавершений |
| Outcome verification | `FederationVerifiedRunReadModel`, Goal aggregate, native evidence, `cos_federation_evaluations` | Backend реалізовано, acceptance відкритий | `Workflow completed ≠ Goal achieved`; `native_goal_aggregate_v1` вимагає незалежну evaluation |
| Event publishing | Kernel `EventBus`, `DomainEvent`, transaction/outbox | Платформа існує | Немає підтвердженого універсального Goal outcome event mapping + UI subscriptions |
| Event delivery / rules | `DurableEventDispatcher`, `RuleEngineEventHandler`, Action proposal sink | Інфраструктура є, Goal інтеграцію не доведено | Consumer checkpoint/idempotency обов'язкові; event → proposal не означає event → безумовний side-effect |
| AI / CRON initiators | `/workspace/capabilities/intents` + bounded review flow | Вузький адаптер | Немає підтвердженого generic Goal planning+invoke envelope для всіх Domains |
| Operator UI | `/workspace/goals`, `/workspace/capabilities` | Початковий / partial | Декомпозиція сценарію, operator actions, evidence matrix і live 50/50/50 потребують окремих read-model |
| Live Golden 50 | Operator runbook + тести / fixtures | **Не підтверджено** | Немає evidence 50/50/50 live, 200 окремих Action approvals/receipts, негативних кейсів на тому самому deployment SHA, signoff |

## 3. Canonical command contract (TO-BE)

Ніяких окремих `functions for AI`. Один каталог Domain-owned Capabilities. Модель запису:

```json
{
  "id": "documents.proposal.prepare",
  "version": "v1",
  "owner_domain": "documents",
  "kind": "command",
  "input_schema_ref": "schema://...",
  "output_schema_ref": "schema://...",
  "execution_binding": "action:<registered-handler>",
  "side_effect_level": "external",
  "permission": "domain.permission",
  "approval_policy": "required",
  "idempotency": "required",
  "timeout_policy": {"seconds": 60},
  "retry_policy": {"max_attempts": 1},
  "failure_contract": "schema://...",
  "tests": [],
  "documentation": "..."
}
```

Це **ілюстративна нормативна схема**, не фактичний зареєстрований JSON саме цього handler. Ключові правила: semver/version pin; кожне поле contract-owned; registered owner; доступність для tenant; schema validation; known binding; resolution лише на сервері; no policy/permission grant from model output.

Уніфікований invocation envelope:

```text
request_id, correlation_id, causation_id
organization_id, goal_id, plan_id, run_id, step_id
capability_id, contract_version, input_digest, input_reference
initiator_type = human_ui | human_api | ai | cron | event
trusted_actor, idempotency_key, expected_revision
approval_reference [when governed], policy_snapshot_reference
```

**Важливо:** `initiator_type` не дає дозволу. Для AI/CRON/Event спершу intent/proposed plan; потім незалежна policy/approval. Не переносити клієнтські `actor`, `policy_grant`, `receipt_verified` як істину.

Єдиний execution receipt:

```text
status = completed | failed | waiting | ambiguous
canonical_action_id, attempt_id, native_domain_reference
verified_at, verifier_identity, output_schema_version, output_digest
input_digest, plan_hash, organization_id
error_code, retry_safe, recovery_required
correlation_id, causation_id
```

Результат Step перевіряє **Action receipt**, результат Goal перевіряє **окремий Domain-owned evidence provider**. Successful HTTP 200 або LLM answer не є business evidence. Existing DTO interfaces не слід ламати: вводити лише versioned adapters та backward-compatible projection.

## 4. Event-driven contract (TO-BE)

```text
Domain state change + DomainEvent + Outbox commit
     → at-least-once delivery
     → registered consumer/checkpoint
     → Rule matching + tenant module check
     → proposed intent / follow-up Plan
     → Policy + Approval
     → one canonical Workflow Run
     → native receipts → independent Goal evaluation
```

Не робити безпосередньо `event → execute uncontrolled Action`. Пропозиція мінімального event vocabulary (ще **не підтверджені фактичними producer bindings**):
- `cos.goal.created.v1` після durable Goal write;
- `cos.goal.plan_approved.v1` після independent authoritative approval;
- `cos.workflow.run_completed.v1` після durable terminal Run checkpoint;
- `cos.goal.evaluated.v1` після незалежного збереження evaluation;
- `cos.goal.achieved.v1` лише якщо verified criteria satisfied;
- `cos.goal.blocked.v1` / `cos.workflow.recovery_required.v1` як факти стану (не зовнішні retry commands).

Кожен event має єдиного owner, schema version, tenant, event_id, occurred_at, causation/correlation, source_reference, evidence_reference, stable idempotency guard. Consumer subscriptions versioned, tenant-scoped, дозволені лише після governance acceptance. Retry only **delivery**; side effects are independently fenced. Fan-out loops мають dedup і bounded recursion / no cycle.

## 5. Golden 50 Cockpit: UI surface map

У першому безпечному зрізі оновлено наявний `/workspace/goals`:
- Golden 50 preset замість ручного вводу `federation.verified_proposals` і `50`;
- чіткий route 1–7: Goal → Discovery → independent approval → fan-out → child Runs → verified 50/50/50 → evaluation;
- links до existing Goals/Plans/Runs, Capability Library, AI/CRON intents;
- показ tenant rollout, список вже існуючих Golden Goal і їх Plan/Run counts;
- чітке розмежування **target count ≠ verified business count**;
- ручне створення довільних метрик збережено в expert disclosure;
- немає нових POST endpoints, executor, permission bypass, event auto-dispatch.

**Наступний UI pack:** окремий `/workspace/goals/{id}` з tabs Overview, Plan graph, Approvals, Execution, Evidence, Events, Recovery, Audit; `/workspace/golden50` portfolio з 50 рядками Candidate→Lead→Document, фільтрами blocked/ambiguous, owner/reviewer, checkpoint і provenance; типізований wizard fan-out preview/propose/independent approval; зрозуміле пояснення blocked transitions. API має повертати server-derived readiness, а не вгадані фронтендом статуси.

## 6. Release gates

| Gate | Доказ для ACCEPT |
| --- | --- |
| G0 | Актуальний SHA, статичний registry/invoker authority audit, no unauthorized parallel writers/dispatch |
| G1 | Contract tests усіх exposed Capabilities, unknown/missing binding fails closed, tenant/perms/schema/approval |
| G2 | Deterministic AI Plan compiler на 1–3 та multi-domain; unsupported function → blocked before execution |
| G3 | MySQL Run/Step/Outbox CAS/retry/failover; crash before/after effect; no duplicate native Actions |
| G4 | Event producer/consumer test, checkpoint/replay/dedup, no event-driven approval bypass |
| G5 | UI functional/mobile/a11y, CSRF, read-only aggregation, correct distinction technical vs verified business counts |
| G6 | Live isolated tenant: 50 distinct native Candidates/Accounts, 50 Sales Leads, 50 Documents, 50 parent/child provenance chains |
| G7 | 50 child Plans + 200 individual Action approvals, verified receipts, `native_goal_aggregate_v1` durable satisfied evaluation |
| G8 | Negative evidence 49/50, duplicates, foreign tenant, stale policy, forged receipt, unknown external outcome; operator signoff on same SHA |

**Go/no-go:** UI branch може бути reviewed незалежно, але Golden 50 production acceptance залишається **NO** до G0–G8. Не підміняти rollout status ефектною статистикою.

## 7. Пріоритетні наступні work packages

**P0:** Goal cockpit read-model + Golden portfolio 50/50/50; єдина Capability-to-Plan compiler boundary; typed fan-out wizard; independent human approval queue; Event source/consumers inventory.

**P0:** Server-issued workflow idempotency, receipt/evaluation projections, blocked reason codes, end-to-end MySQL + isolated operator run; чіткі verified/declarative/blocked states на Capability UI.

**P1:** Generic AI Planner з пояснюваним proposal DAG, reusable Event→Rule→Plan adapters, Domain extension certification, audit/observability UI, removal of legacy writers **тільки після consumer parity**.

### Посилання на чинні джерела

- [Golden 50 operator acceptance](../02-workflows/federation-golden-50-operator-acceptance.md)
- [Goals small scenarios](../02-workflows/federation-goals-small-scenarios-operator.md)
- [Goal canonical execution](./federation-goal-canonical-execution.md)
- [Execution authority audit](./cos-federation-actual-execution-audit-2026-10-15.md)
- [Events and Outbox](../05-runtime/events-and-outbox.md)
