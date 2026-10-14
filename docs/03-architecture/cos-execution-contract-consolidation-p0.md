---
title: P0 — Контракти та консолідація механізмів виконання COS
description: Нормативні інтерфейси, DTO, інваріанти, реєстр дублювання, карта міграції та parity-тести для Kernel, Federation і Engineering.
status: proposed
updated: 2026-10-15
kind: architecture
---

# P0: контракти та консолідація виконання COS

**Package ID:** COS-FED-EXEC-CONTRACT-CONSOLIDATION-P0. **Блокує:** розробку або cutover нового durable Workflow.  
**Обсяг:** Kernel Workflow + Federation Execution + Engineering AI Developer Team Workflow.  
**Принцип:** централізовані технічні контракти і governance, незалежні власники бізнес-процесів. Не розробляти додатковий Federation Engine.  
**Залежить від:** [ADR-0015](../11-decisions/ADR-0015-cos-federation-architecture.md), [загальної специфікації](./cos-federated-architecture-implementation-spec.md) та [тристороннього аудиту](./federation-execution-consolidation-audit.md).  
**Статус:** нормативний **проєкт контрактів**. Інтерфейси нижче описують майбутній API і **ще не є готовим PHP-кодом**. Машинозчитувані реєстри нижче містять тільки початкові приклади consumers, не повний call graph.

## 1. Мета і вихідні матеріали

Узгодити семантичну та технічну модель до написання нового durable runner, забезпечити одне джерело істини на рівні execution і не втратити safety controls. Поточна реалізація Kernel Workflow має in-memory стан та умовні переходи, Federation має tenant-scoped persisted Run/Step, DAC/claims і receipts, Engineering має окреме ORM-сховище та власні бізнес-стадії.

Чотири обов'язкові результати:
1. **Contract Specification:** конкретні функції, DTO, статуси, errors та інваріанти.
2. **Duplication Registry:** машинозчитуваний реєстр [JSON](../../resources/architecture/cos-execution-duplication-registry.json), доповнений verified runtime references.
3. **State Migration Map:** [окрема специфікація](./cos-execution-state-migration-map.md) з old→new mapping, cutover safety та rollback.
4. **Parity Tests:** [машинозчитувані сценарії](../../resources/architecture/cos-execution-parity-cases.json), baseline tests і майбутні executable adapters для нового runtime.

**Не входить у цей пакет:** production dispatch, нові workflow SQL-таблиці, перенесення діючих Runs, видалення класів/таблиць, увімкнення external mutations, автоматичний merge. Це наступні implementation packs.

## 2. Контракти та межі їх володіння

Нижче конкретизовано **логічні** контракти. Розташування рекомендується `Platform\Workflow\Contract` для orchestration/persistence, `Kernel\Action\Contract` для side-effect admission, `Platform\Goal\Contract` для outcome. Наявний `Kernel\Workflow\Contract\WorkflowEngineInterface` не вилучати без deprecation adapter.

```php
<?php
// Proposal, NOT committed PHP API.
namespace Platform\Workflow\Contract;

interface WorkflowExecutionStoreInterface
{
    public function create(WorkflowRunDraft $draft): WorkflowRunSnapshot;
    public function get(string $organizationId, string $runId): ?WorkflowRunSnapshot;
    /** @return list<WorkflowStepSnapshot> */
    public function steps(string $organizationId, string $runId): array;
    public function compareAndTransition(WorkflowTransitionCommand $command): WorkflowRunSnapshot;
    public function claim(WorkflowStepClaimCommand $command): WorkflowStepSnapshot;
    public function checkpoint(WorkflowCheckpointCommand $command): WorkflowStepSnapshot;
}

interface WorkflowStepSchedulerInterface
{
    /** Pure deterministic selection: no I/O, no dispatch. */
    public function select(WorkflowDefinitionSnapshot $definition, WorkflowRunSnapshot $run, array $steps): WorkflowStepDecision;
}

interface WorkflowExecutionCoordinatorInterface
{
    public function start(StartWorkflowCommand $command): WorkflowRunSnapshot;
    public function advance(AdvanceWorkflowCommand $command): WorkflowRunSnapshot;
    public function resume(ResumeWorkflowCommand $command): WorkflowRunSnapshot;
    public function cancel(CancelWorkflowCommand $command): WorkflowRunSnapshot;
}

interface WorkflowActionBindingInterface
{
    public function resolve(WorkflowActionIntent $intent): AuthorizedActionBinding;
    /** Must use canonical Policy/Approval/ActionService. */
    public function submit(AuthorizedActionBinding $binding): WorkflowActionReference;
}

interface WorkflowReceiptVerifierInterface
{
    public function attest(WorkflowReceiptRequest $request): WorkflowReceiptAttestation;
}

interface WorkflowRecoveryInterface
{
    /** No side effects. */
    public function inspect(WorkflowRunIdentity $identity): WorkflowRecoveryAssessment;
    /** Only trusted reconciliation, never blind external replay. */
    public function reconcile(ReconcileWorkflowCommand $command): WorkflowRecoveryAssessment;
}
```

Назви є чернеткою API; **не створювати окремий компонент, якщо існуючий Kernel/Platform contract уже надає еквівалентну семантику**. Вимагати ADR або compatibility decision для зміни чинних інтерфейсів.

### 2.1. DTO та їхні обов'язкові поля

| DTO | Поля | Вимоги |
| --- | --- | --- |
| `WorkflowRunIdentity` | `organizationId`, `runId` | nonempty, tenant bound, immutable |
| `WorkflowRunDraft` | identity, `definitionId`, `definitionVersion`, `inputDigest`, `ownerActorId`, `correlationId`, `causationId`, `sourcePlanRef?`, `approvalRef?` | frozen source/definition, no user-supplied grants |
| `WorkflowRunSnapshot` | identity, `status`, `revision`, `definitionVersion`, `planDigest?`, `currentStepId?`, `createdAt`, `updatedAt` | immutable read projection; revision >= 1 |
| `WorkflowStepSnapshot` | run identity, `stepId`, `state`, `capabilityId`, `capabilityVersion`, `sideEffectLevel`, `dependencies`, `attempts`, `idempotencyKey`, `resultRef?`, `checkpoint?`, `revision` | step identity + immutable binding; preserve legacy idempotency |
| `WorkflowDefinitionSnapshot` | `id`, `version`, `nodes`, `edges`, `inputSchemaRef`, `digest` | version pinning and validated DAG |
| `WorkflowTransitionCommand` | identity, expected revision/state, next state, trusted actor, reason, causation | optimistic concurrency; fail closed |
| `WorkflowStepClaimCommand` | identity, step ID, expected state/revision, worker identity, lease, claim token | exactly one authoritative claim, token fenced |
| `WorkflowCheckpointCommand` | identity, step ID, expected revision/claim token, outcome status, verified receipt, timestamp | atomic receipt/state update |
| `WorkflowStepDecision` | `kind` (ready/wait/complete/blocked/ambiguous), optional step ID, completed predecessor IDs, reason | deterministic; no I/O |
| `WorkflowActionIntent` | identity, step ID, input digest, capability/version, approved plan reference | no raw arbitrary handler resolution |
| `AuthorizedActionBinding` | owner module, handler binding, policy/approval evidence, idempotency key, input reference | non-forgeable server-side evaluation |
| `WorkflowReceiptAttestation` | provider reference, action ID, proof type, attempt evidence, verified flag, verification timestamp, source digest | `completed` flag alone insufficient |
| `WorkflowRecoveryAssessment` | identity, status, blocked reason, safe choices, trusted receipts, operator decision required | read-only inspection |
| `ResumeWorkflowCommand` | identity, expected revision, signal/decision reference, verified actor/capability, correlation | arbitrary resume payload is NOT authorization |
| `ReconcileWorkflowCommand` | identity, expected revision, actor + scope, authoritative receipt references, reason | idempotent, audited |

**Типи:** identifiers та digests як validated `string`; timestamps як UTC immutable timestamps; status як типізовані enum/VO; serialized JSON only through versioned DTO serializer. Secrets and policy grants are never trusted from a browser payload.

### 2.2. Семантична модель станів

**Run:**
- `CREATED` → `RUNNING` / `CANCELLED`;
- `RUNNING` → `WAITING` / `AMBIGUOUS` / `COMPLETED` / `FAILED` / `CANCELLED`;
- `WAITING` → `RUNNING` / `FAILED` / `CANCELLED`;
- `AMBIGUOUS` → `WAITING` або інший явно дозволений стан **тільки після trusted reconciliation**;
- terminal `COMPLETED`, `FAILED`, `CANCELLED` незмінні. Визначити policy переходу до RUNNING після reconciliation на етапі contract finalization, заборонити автоматичне перезапускання.

**Step:** `PENDING` → `CLAIMED` → (`WAITING`, `AMBIGUOUS`, `COMPLETED`, `FAILED`); `WAITING` → `CLAIMED` тільки після authorized resume; terminal immutable. Технічний стан `READY` можна обчислювати, не обов'язково зберігати. P0: максимум одна зовнішня claim на Run; паралельність — окремий pack.

**Engineering:** `EngineeringWorkflowState` (`ANALYSIS`, `ARCHITECTURE_PENDING`, `QA_FAILED`, `READY_FOR_HUMAN_APPROVAL` тощо) залишається окремою **семантикою бізнес-процесу**. Не створювати фіктивний Step для кожної історичної transition.

### 2.3. Класифікація помилок

| Клас помилки | Код | Обробка |
| --- | --- | --- |
| Tenant/capability/policy denied | `EXEC_AUTH_DENIED` | Permanent, audit, no dispatch |
| Immutable definition/plan changed | `EXEC_SNAPSHOT_DRIFT` | Fail-closed, manual replanning |
| Concurrent or stale revision | `EXEC_CONCURRENCY_CONFLICT` | Safe reread; **not** side-effect replay |
| Invalid graph/step prerequisites | `EXEC_DEPENDENCY_INVALID` | Reject before claim/dispatch |
| Verified prior effect absent/forged | `EXEC_RECEIPT_UNTRUSTED` | Block completion/next step |
| Unknown external outcome | `EXEC_OUTCOME_AMBIGUOUS` | Manual reconciliation, no blind retry |
| Transient infrastructure failure prior to effect | `EXEC_RETRYABLE_PRE_EFFECT` | Queue backoff under checked budget |
| Incompatible legacy row | `EXEC_LEGACY_MAPPING_UNSAFE` | Quarantine, no mapping assumption |

Спільний Error DTO містить code, immutable run/step/tenant references, classification, safe retry flag, diagnostic reference, correlation. Не змінювати вже наявну `ExecutionFailureKind` без мапи сумісності.

## 3. Інваріанти контрактного пакета

1. **Один technical writer:** persistent Run/Step змінюється лише через спільний Store/Coordinator.
2. **Side effect тільки через canonical action:** Policy/Approval rechecked in worker; Domain handler ownership checked live.
3. **Атомарність на локальній транзакції:** CAS/claim/checkpoint/outbox разом там, де це одна DB; зовнішній API не можна вважати частиною DB transaction.
4. **Безпечний retry:** delivery at-least-once, effect exactly-once НЕ гарантується без remote idempotency/proof; unknown зовнішній стан блокує replay.
5. **Похідний статус ≠ доказ:** completed Step без canonical Action receipt не дозволяє dependent side effect і finalization.
6. **Два різні результати:** `Workflow COMPLETED` та `Goal ACHIEVED` різні факти.
7. **Незмінні входи:** hashes/version of Plan/Graph/Action params; idempotency key/stored action key unchanged during migration.
8. **Engineering бізнес-гейти:** незалежний Review/QA, final merge approval, budget, no self-approval зберегти.
9. **Одна tenant boundary:** every query/command/claim/receipt checks organization.
10. **No dual dispatch:** shadow reads дозволені; shadow write або виконання другого Action заборонені.

## 4. Реєстр дублювання та відповідальності

Машинозчитуваний документ: [реєстр дублювань](../../resources/architecture/cos-execution-duplication-registry.json). Реєстр початково містить конкретні перевірені класи і **одного перевіреного прикладного споживача**, не стверджує повне покриття call graph. Для кожного пункту зафіксовані `implementation`, `known_consumer`, `target_owner`, `decision`, `guardrail`, `baseline_test`, `migration_status`, `runtime_consumers_verified`. Порожня runtime verification повинна блокувати RETIRE, а не автоматично дозволяти видалення.

**KEEP**: спеціалізована бізнес-семантика Engineering, Goal/Plan/Outcome, trusted evidences.  
**ADAPT**: Engineering stage orchestration, policy guard, наявна інфраструктура locks.  
**CONSOLIDATE**: загальне просування Run/Step, DAG/cursor, generic recovery, completion.  
**MIGRATE**: джерело технічного стану з Federation/Engineering у canonical durable persistence після parity.  
**RETIRE**: тільки після consumer cutover і rollback; не означає видалення зараз.  
**UNKNOWN**: доказів недостатньо, жодного destructive cleanup.

Не нав'язувати універсальний scheduler Engineering business state machine: його `EngineeringWorkflowDefinition` описує, **що робити**, а спільний runtime має визначати, **як безпечно виконувати**.

## 5. Міграція станів

Обов'язковий [State Migration Map](./cos-execution-state-migration-map.md) визначає source fields, ключі/унікальності, mappings, алгоритм phased cutover, Engineering transitional adapter та unsafe cases. У P0 map НЕ є migration SQL.

Обов'язково зберегти:
- `cos_federation_runs.organization_id + plan_id` unique і зв'язок legacy Run до Goal/Plan.
- `cos_federation_steps.idempotency_key` SHA256 для `org\0run\0step`, зовнішній Action `fed:` + hash; **не переобчислювати зі зміненого Run ID**.
- `cos_engineering_workflows.id`, `feature_id`, optimistic `version`, resume state і transition history.
- Engineering AgentRun logical attempt, task and repository/GitHub side-effect receipts; без цього resume автоматизувати не можна.

## 6. Parity Tests: дві незалежні фази

Джерело сценаріїв: [Parity Test Registry](../../resources/architecture/cos-execution-parity-cases.json).

**Фаза 0, зараз:** перевірити що сценарій зареєстрований, baseline test anchor існує, AS-IS інваріанти не втрачені; провести на поточному коді окремі baseline unit/architecture/MySQL tests. `baseline_anchor` не дорівнює повному доказу зазначеного test case.

**Фаза 1, лише після canonical runtime:** запустити ті самі golden fixtures через старий (read-only/shadow) і новий executors, порівняти decisions/states/receipts/tenant/side-effect counts. Для side effects запускати тільки controlled fakes або дозволений staging, **ніколи два production dispatchers**. `target_status` лишається `NOT_IMPLEMENTED`, доки реальний новий runtime не існує.

Приклади блокувальних тестів: cycle/injected DAG, CAS race, forged approval, stale Policy, duplicate Action receipt, crash before/after external effect, ambiguous remote result, tenant isolation, Engineered QA/rework loop, human merge, Golden50 native evidence, old↔new link compatibility й rollback.

**Тест `tests/architecture/cos_execution_consolidation_pack.php`** лише перевіряє формат і source anchors цього контрактного пакета. Його green output **не означає green runtime parity**.

## 6.1. Реалізований інкремент P0: спільний DAG selector

У поточній гілці реалізовано `Platform\\Workflow\\Service\\WorkflowStepScheduler`, `WorkflowDependencyGraph`, `WorkflowStepSchedulerInterface`, DTO `WorkflowDefinitionSnapshot`, `WorkflowStepSnapshot`, `WorkflowStepDecision` і `WorkflowStepSelectionState`. `FederationStepCursor` і `FederationStepDependencyGraph` стали compatibility adapters та використовують **той самий** pure algorithm. Action, Approval, Claim, Receipt, CAS і Worker gates не переписані; це не новий execution engine. Додатково `ExecutionRunPolicy` делегує спільній технічній `DurableWorkflowTransitionPolicy`, збережено існуючі дозволені та заборонені переходи для Federation.

Новий unit contract `tests/unit/workflow_shared_dag_contract.php` перевіряє прямий canonical selector та той самий Federation cursor, invalid DAG, injected steps, mixed read-only, ambiguity, exclusion of parallel claims і відсутність side effects. Старий `tests/unit/federation_step_cursor.php` залишається обов'язковим regression gate.

Реальні PHP-контракти для майбутнього persistent Run/Step: `WorkflowExecutionStoreInterface`, `WorkflowExecutionCoordinatorInterface`, `WorkflowReceiptVerifierInterface`, `WorkflowRecoveryInterface`. Додано immutable DTO у `Platform\\Workflow\\Model` та статуси `AMBIGUOUS` / `CLAIMED` до чинних Kernel enum. **Це лише contracts і vocabulary, не реалізація сховища або активна заміна dispatcher.** Дозвіл на операцію в DTO не видається: перевірка Policy/Approval/Action залишається в canonical execution boundary.

**Durable MySQL foundation:** адитивна міграція `Version20261015011500` створює `cos_workflow_runs` і `cos_workflow_steps` окремо від Federation/Engineering. `DoctrineWorkflowExecutionStore` реалізує atomic create, scoped snapshots, CAS Run transitions, serialized DAG step claim, claim tokens/leases/checkpoint та repeated receipt attestation для finalization. Відмовний canonical Claim Admission і Receipt Verifier wired у Symfony за замовчуванням: зовнішні ефекти **не активовано**. `tests/integration/workflow_durable_store_mysql.php` запускається в Docker CI з фактичною MySQL. Це фундамент без queue/dispatch/recovery/cutover.

**Recovery inspection:** новий `DoctrineWorkflowRecoveryInspector` реалізує `WorkflowRecoveryInterface` тільки для tenant-scoped read-only аналізу. Перевіряє очікувану Run revision, активні та expired claims, uncertainty зовнішнього результату й повторно перевіряє completed receipts; `reconcile()` наразі жорстко забороняє зміни. MySQL integration покриває stale revision, foreign tenant, claimed read-only/external, forged receipt і terminal status. Автоматичне requeue/replay, завершення чи відновлення не активовані.

**Поза межами поточного інкременту:** durable storage, typed Run/Step persistence, shared recovery/receipt implementation, migration Engineering, verified production cutover. Вони залишаються відкритими P0 задачами.

## 7. Порядок виконання та критерій закриття P0

| Gate | Артефакт | Умова завершення |
| --- | --- | --- |
| P0-0 | Contract spec reviewed | interfaces/DTO/errors/semantics погоджені; жоден unsafe assumption не залишився невирішеним |
| P0-1 | Registry exhaustive | перевірені реальні production consumers, DI, queues, CLI/supervisor, SQL writers у трьох контурах; `runtime_consumers_verified=true` або explicit waiver |
| P0-2 | State map signed | reversible algorithm, idempotency/CAS, immutable provenance, Engineering staged mapping, rollback points |
| P0-3 | Baseline locked | old tests green на поточному HEAD, нові scenarios мапляться на fixtures; negative tests визначені |
| P0-4 | Architecture review | Manager → Architect → Developer/QA plan → Security/Reviewer → Manager acceptance; ownership confirmed |

**Тільки після P0-0..4 дозволено починати створення durable Workflow executor.** Full target parity буде gate окремого implementation pack перед production cutover. Не ставити parity `PASS` на етапі написання специфікації.

## 8. Обов'язковий checklist для кожного майбутнього PR

- Назвати source writer, canonical writer, migration adapter, consumers, відповідні ID parity scenarios.
- Довести відсутність double dispatch, bypass Approval та можливості повторити невизначений external effect.
- Зафіксувати regression Kernel Workflow, Federation, Engineering, tenant і legacy read projections.
- Переконатися, що загальний Workflow не знає Growth/Sales/Documents/Engineering stage semantics.
- Коміти групувати за цілісним пакетом, не створювати коміт на кожний файл. Merge лише після green CI + реального runtime evidence.
