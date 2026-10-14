---
title: Карта перенесення станів трьох механізмів виконання
description: Відповідність станів, ключів і доказів Kernel Workflow, Federation і Engineering до канонічного Workflow Execution.
status: proposed
updated: 2026-10-15
kind: architecture
---

# Карта перенесення станів до єдиного виконання процесів

**Package:** [P0 Contract & Consolidation](./cos-execution-contract-consolidation-p0.md).  
**Тип документа:** проєкт міграції з перевіреними source columns та запропонованою target schema. **SQL-міграція не реалізована.**

## 1. Source та target

| Система | Наявне джерело | Цільове |
| --- | --- | --- |
| Kernel Workflow | PHP \`WorkflowExecution\`, \`StepExecution\`; \`WorkflowDefinition\` | \`WorkflowRunSnapshot\` + \`WorkflowStepSnapshot\` persisted у Platform |
| Federation | \`cos_federation_runs\`, \`cos_federation_steps\`, Plan JSON у \`cos_federation_plans\` | Canonical Run/Step та legacy ID mapping, Goal/Plan/Outcome у Goal Management |
| Engineering | \`cos_engineering_workflows\`, \`cos_engineering_transitions\`, AgentRun/Task records | Technical Run/Step adapter із **окремими** \`EngineeringWorkflowState\` і незалежною history |

Пропоновані фізичні таблиці: \`cos_workflow_runs\`, \`cos_workflow_steps\`, \`cos_workflow_legacy_links\`, можливі \`cos_workflow_receipts\` і \`cos_workflow_definitions\` після ADR. **Не створювати наперед таблиці з іменами без погодження persistence boundary та schema design.**

## 2. Federation: точний field mapping

| Source | Target DTO / field | Правило |
| --- | --- | --- |
| \`runs.organization_id\` | \`identity.organizationId\` | Байтово тотожний tenant |
| \`runs.run_id\` | \`legacyRunRef\`; target \`runId\` | Можна лишити id, якщо немає collision; інакше (tenant,source,legacyId)→canonicalId immutable map |
| \`runs.goal_id\` | \`source.goalId\` | Лише Goal binding, не технічний статус |
| \`runs.plan_id\` | \`source.planId\` | Зберегти unique (tenant,planId)→один Run згідно чинного constraint |
| \`runs.state\` | \`run.status\` | Перетворення за таблицею станів, не довіряти «completed» без receipts |
| \`runs.revision\` | \`run.revision\` | CAS не скидати до 1 після міграції без контрольованої epoch/fence |
| \`runs.checkpoint_id\` | \`run.checkpointReference\` | Може позначати workflow reference, не загубити |
| \`steps.step_id\` | \`step.stepId\` | Immutable scoped to run |
| \`steps.capability_id\` / \`capability_version\` | \`step.binding\` | pinned, binding live resolve перед новим dispatch |
| \`steps.side_effect_level\` | \`step.sideEffectLevel\` | \`none\` і \`external\` різні trust-gates |
| \`steps.state\` | \`step.state\` | Див. state mapping |
| \`steps.attempts\` | \`step.attempts\` | Не плутати з числом \`cos_action_attempts\` |
| \`steps.idempotency_key\` | \`step.idempotencyKey\` | **Ніколи не перегенеровувати** |
| \`steps.result_reference\` | \`step.resultRef\` | \`action:<id>\` або \`workflow:<id>:<step>\`; перевірити тип і receipt |
| \`steps.checkpoint_json\` | \`step.checkpoint\` | JSON source digest + schema version; opaque until validated |
| \`plan.plan_json\` | \`definition.graph / digest\` | Immutable snapshot; binding із саме approved plan hash |
| \`plan.spec_version\` | \`source.goalSpecificationVersion\` | Must match pinned spec/approval |
| \`runs.created_at/updated_at\` | snapshot times | UTC DATETIME(6), зберегти precision |

**Ідемпотентність:** вихідний key \`hash('sha256', $org . "\0" . $runId . "\0" . $stepId)\`; canonical \`Action.idempotency_key = 'fed:' . $storedStepKey\`. При зміні target Run ID *не* recompute. Потрібний mapping для кореляції, а не повторне створення Action.

## 3. Federation Run/Step transition map

| Старий статус | Новий technical статус | Особлива умова |
| --- | --- | --- |
| Run \`pending\` | \`CREATED\` | Plan має справжнє Approval, запуск ще не dispatch |
| Run \`running\` | \`RUNNING\` | claimed external Action потребує authoritative Action read |
| Run \`waiting\` | \`WAITING\` | cause required (approval, signal, step) |
| Run \`ambiguous\` | \`AMBIGUOUS\` | блок до manual reconciliation |
| Run \`completed\` | \`COMPLETED\` лише після attestation; інакше \`AMBIGUOUS\` / quarantine | terminal не можна прийняти лише за flag |
| Run \`failed\` | \`FAILED\` | без automatic retry |
| Run \`cancelled\` | \`CANCELLED\` | не відновлювати без нового процесу |
| Step \`pending\` | \`PENDING\` | derived READY via DAG |
| Step \`claimed\` | \`CLAIMED\` / \`WAITING\` | worker receipt і independent approval determine actual |
| Step \`waiting\` | \`WAITING\` | no implicit authorized resume |
| Step \`ambiguous\` | \`AMBIGUOUS\` | external uncertainty preserved |
| Step \`completed\` | \`COMPLETED\` лише за verified proof | invalid receipts → quarantine, no next step |
| Step \`failed\` | \`FAILED\` | terminal unless new explicit process |
| Step \`cancelled\` | \`CANCELLED\` | terminal |

Поточний \`Kernel\Workflow\WorkflowStatus\` не має \`AMBIGUOUS\`; \`StepStatus\` не має \`CLAIMED\` чи \`AMBIGUOUS\`. Це явний **contract gap**. Не переписувати історичні стани на \`FAILED\` без окремого decision; додати typed reason/execution status або adapter projection.

## 4. Engineering: не робити хибне зіставлення

Engineering source \`cos_engineering_workflows\` містить \`id\`, \`feature_id\`, \`workflow_type\`, \`current_state\`, \`status\`, \`trace_id\`, \`lock_key\`, ORM optimistic \`version\`, \`resume_state\`, heartbeat/stalled/health, \`current_task_id\`, \`current_agent_run_id\`, \`started_at\`, \`last_activity_at\`, \`finished_at\`. \`cos_engineering_transitions\` зберігає from/to, sequence, actor/reason, agent/human references.

| Engineering field | Target / destination | Правило |
| --- | --- | --- |
| \`id\` | \`source.engineeringWorkflowId\` і canonical linkage | maintain stable link, avoid collision |
| \`feature_id\` | \`source.featureId\` | бізнес-власник Engineering |
| \`current_state\` | \`EngineeringStage\` у Engineering | **НЕ** універсальний RunStatus |
| \`status\` | \`WorkflowRunStatus\` через explicit mapping | verify mapping of WAITING/BLOCKED, not simple cast |
| \`resume_state\` | Engineering stage metadata + authorized resumption reference | зберегти human gate |
| \`version\` | adapter version/epoch + CAS | avoid conflicting old/new writers |
| \`trace_id\`, \`lock_key\` | correlation / origin / scoped lock | don't drop ongoing lock without migration window |
| \`heartbeat_at\`, \`health_status\`, \`stalled_at\`, \`runtime_reason\` | runtime health projection | preserve operational visibility; health ≠ business state |
| \`current_task_id\`, \`current_agent_run_id\` | active worker / AgentRun references | reconcile before retry |
| \`cos_engineering_transitions.*\` | immutable Engineering history projection | **ЗАЛИШИТИ** sequence and decision provenance |

**Engineering stage rules:** \`ANALYSIS\`, \`QA_PLANNING\`, \`ARCHITECTURE_PENDING\`, \`DEVELOPMENT_RUNNING\`, \`REVIEW_PENDING\`, \`QA_FAILED\`, \`HUMAN_DECISION_REQUIRED\`, \`READY_FOR_HUMAN_APPROVAL\` тощо залишаються \`EngineeringWorkflowState\`; technical WAITING доповнюється \`waitingReason\`. \`EngineeringRetryPolicy\` для review/QA/fix budgets не переносити в Queue Retry.

**Відсутність 1:1 Step mapping:** Engineering transitions не є Step executions. Спочатку створити stage-to-execution binding через AgentRun/Task history, довести monotonic order та receipt lineage. Для історичних неповних workflow допустимий Engineered adapter і drain, а не фабрикація нових completed Steps.

## 5. Послідовність безпечного cutover

1. **Inventory та freeze:** зафіксувати snapshot repository SHA, DB schema/version, tenant+module installations, outstanding actions/agent runs, historical ids, approvals, in-flight operations.
2. **Schema additive:** лише нові nullable/link tables, унікальні constraints, контроль codec/tenant, жодного DROP і жодного SQL trigger для dual writes.
3. **Copy/read projection:** backfill з authoritative source по batches з deterministic idempotent mapping, hash контроль, run/step/action receipt attestation, quarantine unsafe rows. Жодних side effects.
4. **Shadow read parity:** стара source projection проти canonical projection; однакові states, steps, keys, approvals, history, tenant. Zero external dispatch від shadow.
5. **Fence and drain:** заборонити нові старі claims через feature gate/lease, дочекатися або reconcile in-flight Action/AgentRun, виконати compare-and-swap handoff; забезпечити **одного writer**.
6. **Promote:** canonical Workflow є єдиним технічним writer; backward-compatible API читає mapping; rollback до старого writer дозволений тільки доки нових non-representable станів/ефектів немає.
7. **Cleanup:** після window/metrics/human approval вилучити старий executor, а історію зберігати згідно retention; фізичне DROP — окреме рішення.

**Rollback cut line:** до нового Action dispatch можливий повернення до legacy read path; після canonical side effect rollback = блокування нових submissions + read-only forensic/reconcile, а не запуск старого engine паралельно. **Never replay external side effect**.

## 6. Проблемні та незворотні випадки

- Одна Plan має два Run IDs; collision чи порушення чинного unique constraint.
- Legacy \`completed\` Step без canonical Action attempt proof.
- Run \`running\` та втрачене claimed Action reference.
- Engineering AgentRun RUNNING при repository mutation без remote commit receipt.
- Спроба визначити Engineering Step тільки за \`WorkflowTransition\`.
- Чужий tenant, невідомий actor, відсутній source Plan hash.
- Action повторена з іншим idempotency key; змінена Policy після Approval.
- Старі інтерфейси ще читають стару таблицю як writer після cutover.
- Migration зменшила revision або використала останній статус без історії.

**Правило:** класифікувати \`QUARANTINED\`, створити audit/issue та manual migration decision. Заборонено підробляти результати або вважати стан без доказу успіхом.

## 7. Обов'язковий звіт міграції

Per batch: source schema/hash, tenant, source/run/step counts, valid/unsafe rows, mappings, receipt verification, invariant errors, elapsed DB locks, checkpoint, consumer read parity, rollback decision. Перший staging cutover приймається лише після evidence по Kernel, Federation і Engineering разом.
