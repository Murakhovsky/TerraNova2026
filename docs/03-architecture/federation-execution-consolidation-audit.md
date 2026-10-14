---
title: COS Federation Execution — architecture reconciliation audit
description: Фактичне зіставлення Federation Goal Execution з Kernel Workflow, Engineering Workflow, Action/Queue і план усунення паралельної оркестрації.
status: proposed
updated: 2026-10-14
kind: architecture
---

# Architecture Reconciliation: federation is a system property, not an execution domain

**Scope:** PR #346, head `bb99d5161c3cf06f61190acb41f4a51c81e1b196`. Цей документ фіксує архітектурне рішення та інвентаризацію, **не** стверджує, що код уже мігрований, і не замінює runtime acceptance.

## Рішення

1. **Federation** означає архітектуру COS: незалежність Domain ownership, versioned capabilities, централізовані security/governance, стандартизовані cross-domain contracts, аудит взаємодій. Federation не є обов'язковим окремим Domain, service bus або runtime.
2. **Goal Management** є прикладною функцією: Goal, версійна GoalSpecification, погоджений Plan, виміряний Outcome. Вона може існувати як Platform application capability; вона **не володіє** виконанням Domain actions.
3. **Platform Workflow Execution** має бути єдиним власником універсальних Run/Step scheduling, waiting, claims, checkpoints, retries/recovery і finalization. До появи достатньої durable реалізації Federation persistence лишається ізольованим тимчасовим execution adapter, без нового scheduler.
4. **Kernel Action/Policy/Approval/Queue/Agent** залишаються єдиними авторитетними власниками дій, дозволів, погоджень, worker admission, job leasing, retry/dedup і виконання агентів. Перевірка plan provenance має бути boundary guard, а не другий Action lifecycle.
5. **Domains** залишаються власниками бізнес-операцій, business read models, фактичних outcomes; сценарії Golden 50 не потрапляють у універсальний dispatcher.
6. **Engineering Workflow** має легітимну спеціалізовану state machine. Її не слід механічно зливати з загальним Workflow; спільна інфраструктура dispatch/queue/tracing може поступово використовувати спільні контракти.

## AS-IS. Зіставлення реального коду

| Concern | Universal Kernel/Platform | Federation PR #346 | Статус |
| --- | --- | --- | --- |
| Workflow definition / sequencing | `Kernel/Workflow/Model/WorkflowDefinition.php` та `WorkflowEngine.php`: linear current-step flow, conditional outgoing transitions, Human/Wait/Agent/Tool/System steps | `FederationStepDependencyGraph`, `FederationStepCursor`, approved `plan_json.steps[].depends_on` | **Частковий дубль:** модель залежностей друга, але Kernel зараз не виконує загальний durable DAG/fan-out |
| Execution state | `WorkflowExecution` та `StepExecution` як mutable PHP objects; `SymfonyWorkflowStateManager` лише перевіряє state transitions | `FederationGoalStore`, `cos_federation_runs`, `cos_federation_steps`: MySQL транзакції, revision/CAS, step claim | **Функціонально перекривається, технічно НЕ тотожне:** Kernel не має підтвердженого canonical durable store для цього engine |
| Run progression | `WorkflowEngine::start/resume/cancel/drive` | `FederationSequentialOrchestrator::start/advance`, один перехід за виклик менеджера | **Конкуруюча відповідальність** за загальний життєвий цикл; Federation ще не autonomous |
| Completion | `WorkflowEngine::advance` переводить execution у COMPLETED після останнього step | `FederationRunFinalizer` повторно посвідчує всі canonical Action receipts і записує terminal Run | **Частковий дубль:** verification receipts — необхідне посилення, відсутнє у базовому Kernel Workflow |
| Actual side effects | `ActionService`, `ActionExecutor`, `ActionPolicyService`, `ActionExecutionJobHandler`, `QueueWorker` | `FederationExternalActionSubmission` створює канонічний Action; `FederatedActionAdmission` перевіряє plan/approval на worker boundary | **Зберегти інтеграцію**, не створювати другого dispatcher. Provenance guard — окрема корисна функція |
| Job scheduling / retries | `Kernel/Queue/Service/QueueWorker`, queue leases/recovery; Symfony Messenger/Scheduler per docs | Federation не має автоматичного scheduler/retry, має ручне reconcile | **Не створювати окремий scheduler**. Уточнити policy для uncertain external effects |
| Workflow bridge | `Kernel/Workflow/Service/WorkflowEngine` | `FederationReadOnlyWorkflowRunner` викликає його тільки для **одного inert SystemStep**; заборонено Tool/Agent/external | **Тестовий вузький адаптер**, НЕ доказ інтеграції Golden 50 з canonical Workflow |
| Business goal | У Kernel Workflow немає Goal success criteria | `GoalSpecification`, `GoalPlanValidator`, `GoalOutcomeEvaluator`, evidence providers | **Не дубль.** Зберегти в Goal Management поза Kernel business primitives |
| Cross-domain native outcome | Kernel Action COMPLETED означає завершення технічної дії, не бізнес-цілі | Domain-owned read models; `FederationGoalOutcomeAggregationService` має hard-coded Growth/Sales/Documents | **Потрібна декомпозиція**: scenario profile + provider-owned criteria/receipts |
| Engineering workflow | `symfony/src/Engineering/Domain/Workflow/*`, persistent `EngineeringWorkflowStoreInterface`, workflow-specific stages | Не використовується Golden 50 | **Окремий bounded context**, не автоматичний кандидат на видалення |

### Шість обговорюваних складових

| Entity / class | Функція зараз | Ціль |
| --- | --- | --- |
| `FederationDomainModule` | Реєструє `cos.federation.plan.approval` handler; opt-in | Вивести governance-функцію у Platform Goal Management; **не видаляти**, доки немає сумісного action owner/manifest/tenant cutover |
| `FederationSequentialOrchestrator` | Ручний serialized Run state machine | **Замінити** загальним durable Workflow coordinator після паритету authorization, CAS, receipt, recovery |
| `FederationGoalStore` | Goals/specs/plans та окремі Runs/Steps/evaluations | **Розділити**: Goal/Plan/Outcome store зберігається, Run/Step persistence переходить до Platform Workflow після міграції |
| `FederationRunFinalizer` | Finalize із repeated Action receipt admission | **Поглинути** в canonical Workflow completion guard; не прибирати receipt validation |
| `FederationStepCursor` | Детерміноване DAG/step selection + pending/claimed/ambiguous | **Поглинути** в один Workflow scheduler; спочатку підтримати durable DAG semantics у ньому |
| `cos_federation_plans/runs/steps` | Approved immutable plan + plan-specific run/steps | Plan = application artifact, Run/Step = canonical Workflow execution; **ніякого DROP або destructive rename на першому етапі** |

## Потенційний зайвий код (кандидати, НЕ наказ видалити негайно)

- **Після parity та міграції:** `FederationSequentialOrchestrator`, `FederationStepCursor`, `FederationStepDependencyGraph`, `FederationActionSequenceGate`, `FederationRunFinalizer`, частини `FederationGoalStore` для Run/Step, `FederationReadOnlyWorkflowRunner`, `FederationWorkflowPreflight`.
- **Після винесення safety guard у canonical execution:** `FederationExternalActionReceiptReconciler`, `FederationRunRecoveryService`, `FederationRecoveryClassifier` можуть бути перетворені на спільні contracts/adapters. Не видаляти жодних proof/integrity checks.
- **Перемістити з generic center:** `FederationGoalOutcomeAggregationService`, `FederationGolden50GoalProfile`, `FederationCandidateFanoutPlanner`, `FederationDiscoveryPlanFactory` у scenario-owned workflow/profile package. Не переносити бізнес-семантику в Kernel.
- **Зберегти за змістом, перейменування необов'язкове на цьому етапі:** `GoalSpecification`, `GoalPlanValidator`, `GoalOutcomeEvaluator`, `GoalOutcomeEvidenceProviderInterface`, Domain-owned outcome providers, `FederatedActionAdmission` (як verified-plan worker guard), immutable approvals, policies, consent/tenant checks, Experience data contracts.
- **Не чіпати без окремого доказу:** Engineering-specific state machine, existing Kernel Action/Queue, Sales legacy read adapters. Старі SQL-таблиці можуть залишитись за ports; приховані direct writes треба аудитувати.

## TO-BE

```text
Federated COS = topology + contracts + ownership + governance (architecture property)

User/API/Agent -> Goal Management (Goal, success criteria, immutable Plan)
                    |
                    v
          Platform Workflow Execution
          versioned workflow definition + single durable Run/Step authority
          claims / dependency handling / waiting / reconciliation
                    |
                    v
      Kernel Policy + Approval + Action + Queue + Agent
                    |
                    v
        Domain-owned handlers / native data
                    |
                    v
      Domain-owned outcome evidence providers
                    |
                    v
       Goal Management OutcomeEvaluation
```

**Критично:** не заміняти Workflow engine наново написаним Federation scheduler. Спершу додати потрібні durable capabilities до canonical Platform Workflow (rehydration, per-step claims/CAS, DAG scheduling, resume, correlation, receipt validation, failure uncertainty). `WorkflowEngine` наразі in-memory і сам по собі цього не забезпечує. Не зводити бізнес-Goal і Workflow status до одного стану.

## Етапи безпечної консолідації

**P0. Інвентаризація authority**
- Підтвердити consumer/call graph для Workflow, Federation, Engineering; усі DB writes, queues, message handlers, cron/supervisor.
- Для кожної змінної стану визначити **одне джерело істини**. Заборонити конкурентні записи `cos_federation_steps` та майбутніх canonical Workflow Steps.
- Зняти baseline на PR head: tenant + Action approval + Golden 50 fixture; негативні випадки replay, stale policy, partial external response.

**P1. Durable canonical Workflow Execution** (перед видаленням будь-якого Federation executor)
- Додати persistence/recovery/claim/checkpoint adapter для canonical Workflow, з version-pinned definitions, CAS, tenant, immutable step inputs, tracing.
- Підтримати DAG fan-out/join з bounded concurrency; канонічні Queue/Messenger/Scheduler, без другого worker runtime.
- Дозволити тільки типізовані Domain Action/Agent/Tool handlers через їх чинні admission gates; default-deny для непідтриманих transitions та side effects.
- Перенести repeated receipt attestation з Federation finalizer до shared Workflow completion boundary.

**P2. Goal Management adaptation**
- Goal/Plan/Outcome відділити від technical Workflow Run, зв'язати immutable plan hash і workflow_run_id.
- Golden 50 перетворити на зареєстрований process/scenario profile; Growth/Sales/Documents outcomes залишити за власниками.
- Для approved Plans із живими/незавершеними Runs зберегти старий executor до terminal state або забезпечити доведену одноразову state migration. **Жодних dual writes**.

**P3. Виведення дублювання**
- Зняти веб/API dependence на Federation-specific Run/Step після сумісної read projection.
- Від'єднати дубльовані Orchestrator/Cursor/Finalizer/Recovery класи, лише коли відпрацював golden E2E на спільному runtime.
- `FederationDomainModule` залишати як тимчасову сумісність до canonical owner/route migration; після цього деактивувати без втрати історії.
- НЕ видаляти `cos_federation_*` таблиці до підтверджених retention, migration rollback та потреб аудиту.

## Невідкладні architecture guardrails

1. **One execution authority:** лише Platform Workflow може планувати й змінювати універсальний Run/Step execution state.
2. **One mutation path:** external business effects тільки через зареєстрований Domain-owned Action + Policy/Approval + Queue.
3. **No business logic in Kernel:** Golden50/Growth/Sales/Documents rules не у generic orchestrator.
4. **No automatic uncertain retries:** ambiguous external responses потребують reconciliation або доказово безпечної idempotent retry policy.
5. **No cross-tenant or direct cross-domain writes:** interfaces/events, tenancy every query/command.
6. **Goals are not workflows:** Completed technical Run не дорівнює achieved Goal.
7. **No parallel runtime before proof:** жодного окремого Federation supervisor/scheduler/job stream.
8. **Compatibility first:** контрактна міграція additive, destructive cleanup тільки після заміни consumer і rollback plan.

## Acceptance gates

- Реальний багатоетапний Goal із щонайменше трьома Domain-owned handlers, збереженим independent Outcome та audited evidence.
- Duplicate submission і повтор після crash не створюють другого бізнес-ефекту.
- Tenant switch, forged Action, stale Approval/Policy, changed Plan hash, early terminal mark та race for one Step fail closed.
- Timeout/unknown remote response не запускає blind retry; resumed Run continues from verified checkpoint.
- Engineering workflows і не-Goal Kernel Workflow regression suites без змін у семантиці.
- Одна canonical execution state/projection, відсутній другий production scheduler; CI/Runtime/MySQL/Browser proof та rollout/rollback checklist.
- До green acceptance **ніякого автоматичного merge** та ніяких видалень runtime-класів/таблиць.

## Evidence links

- `app/Kernel/Workflow/Service/WorkflowEngine.php`, `app/Kernel/Workflow/Model/WorkflowExecution.php`, `WorkflowDefinition.php`
- `symfony/src/Infrastructure/Workflow/SymfonyWorkflowStateManager.php`
- `symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php`
- `app/Kernel/Queue/Service/QueueWorker.php`, `app/Kernel/Queue/Handler/ActionExecutionJobHandler.php`
- `symfony/src/Persistence/Federation/FederationReadOnlyWorkflowRunner.php`, `FederationSequentialOrchestrator.php`, `FederationGoalStore.php`, `FederationRunFinalizer.php`, `FederatedActionAdmission.php`
- `docs/11-decisions/ADR-0015-cos-federation-architecture.md`, `docs/03-architecture/federation-persistence.md`

**Limit:** source-code audit, not complete repository-wide dynamic call graph nor production trace. Dead code / active legacy classification requires runtime evidence before deletion.
