---
title: Федеративна архітектура COS — нормативне ТЗ впровадження та консолідації
description: Архітектурні принципи, межі відповідальності, єдине виконання процесів, міграція дублювання, контроль legacy та критерії приймання COS.
status: active
updated: 2026-10-15
kind: architecture
---

# Федеративна архітектура COS: технічне завдання впровадження та консолідації

**Ідентифікатор:** COS-FED-ARCH-CONSOLIDATION-2026-10.  
**Пріоритет:** P0 для архітектурних інваріантів і консолідації виконання; P1 для поступового розширення покриття Domains та інтерфейсу.  
**Статус:** нормативне ТЗ для наступних ітерацій; **не твердження про завершену реалізацію**.  
**Обсяг:** Kernel, Platform, Domains, інфраструктура, процеси, Agent/Action/Workflow, Goal Management, Experience, legacy, архітектурні перевірки.  
**Базовий код:** PR #346; первинний аудит — [Узгодження виконання](./federation-execution-consolidation-audit.md).  
**Базове рішення:** [ADR-0015](../11-decisions/ADR-0015-cos-federation-architecture.md). При конфлікті з описами попередніх фаз чинною цільовою моделлю є це ТЗ, а поточний код залишається фактичним джерелом стану AS-IS.

## 1. Мета, предмет та критерій успіху

**Мета:** привести **всю COS** до послідовної федеративної моделі з розподіленим володінням бізнес-функціями та спільним governance й execution foundation. Не створювати додаткового координатора лише тому, що архітектура називається Federation.

Федерація — **центральний принцип організації** всієї COS, а не центральний Domain, runtime, диспетчер або новий шар між Kernel і Domains. Вона реалізується наявними або уніфікованими контрактами й правилами їх застосування.

Потрібний результат:

1. Один власник для кожної бізнес-сутності, стану, таблиці та операції запису.
2. Одні канонічні механізми Policy, Approval, Action, Agent, Tool, Queue, Event/Outbox, Audit.
3. **Один універсальний Platform Workflow Execution runtime** для технічного Run/Step; доменні state machines зберігають власну бізнес-семантику.
4. Версіоновані, виконувані та перевірювані Domain Capabilities і міждоменні контракти.
5. Існуюча Goal Management функціональність зберігає Goal, Plan, критерії та Outcome, але **не створює власний execution engine**.
6. Наявні Legacy/Phalcon/історичні Symfony адаптери класифіковані, ізольовані й мають керований шлях заміни.
7. Новий Domain підключається стандартними внесками без змін універсального Workflow Engine й без hard-coded переліку доменів у центральних сервісах.
8. Golden 50 проходить через **єдиний** runtime із підтвердженими нативними фактами; не є доказом усієї федеративності сам по собі.

## 2. Непорушні принципи

### 2.1. Розподіл влади та відповідальності

- **Спільне governance:** Kernel/Platform забезпечує identity, tenant, permission, policy, approval, capability contracts, audit, transport, execution safety.
- **Розподілена бізнес-відповідальність:** кожен Domain сам визначає aggregates, інваріанти, бізнес-стани, міграції, бізнес-команди та authoritative results.
- **Без прихованих зв'язків:** один Domain не пише в таблиці іншого, не викликає його private services, не виводить його стан із чужої SQL-схеми.
- **Горизонтальна взаємодія:** Domain → опублікований контракт / команда / подія → Domain-власник. Федерація не є посередником для кожного читання чи команди.
- **Власник правди один:** одна операція, один canonical writer; кеші та projections не стають джерелом бізнес-правди.
- **Незалежний розвиток:** версії контрактів, сумісні зміни, tenant activation, bounded failures і no hidden global transactions.
- **Доказовість:** декларація capability, зелений unit test або завершений Action не дорівнюють доведеному бізнес-результату.
- **Спрощення за замовчуванням:** не додавати сервіс, рівень абстракції, чергу, таблицю або engine без gap analysis та перевірки на дублювання.

### 2.2. Що прямо заборонено

1. Новий Federation Engine, Scheduler, Event Bus, Broker, Workflow Runner або універсальний Federation Domain як «центр» системи.
2. Другий загальний Run/Step lifecycle у Goal Management після приймання canonical durable Workflow.
3. Повторення Policy/Approval/Action logic у Domain або Scenario; приватні спрощені механізми авторизації.
4. Прямий SQL/ORM write між доменами; сценарна логіка Sales/Growth/Documents у Kernel execution code.
5. Оголошення Goal успішною тільки зі статусу Workflow/Action COMPLETED.
6. Подвійний dispatch або dual writes у старий та новий execution runtime.
7. Blind retries при невідомому результаті зовнішнього ефекту; обіцянка exactly-once без зовнішнього idempotency/evidence.
8. Рефакторинг із видаленням таблиць/станів до доказового перенесення даних, consumer cutover і плану відкату.
9. Використання адаптивного UX чи AI як джерела дозволів і прав.
10. Винесення Domain у мікросервіс без виміряної технічної або організаційної потреби.

## 3. Цільова архітектура

~~~text
COS — federated modular monolith (архітектурний принцип для всієї системи)
|
+-- Kernel / Platform Foundation
|   +-- Identity / Tenant / Authorization
|   +-- Module + Capability + Cross-Domain Contract Registry
|   +-- Policy / Approval / Action / Tool / Agent
|   +-- Command / Query / Domain Event / Outbox / Queue
|   +-- Workflow Execution [ОДИН технічний Run/Step runtime]
|   |   +-- versioned definition / DAG / human waiting
|   |   +-- claims / concurrency / checkpoint / recovery
|   |   +-- receipt evidence / trace / failure semantics
|   +-- Audit / Observability / Settings / Integration ports
|
+-- Platform Applications
|   +-- Goal Management [Goal / immutable Plan / OutcomeEvaluation]
|   +-- Architecture Intelligence [static/runtime graph + CI governance]
|   +-- Search / Documents platform capability / Experience composition
|
+-- Business Domains [кожен незалежний власник бізнес-правди]
|   +-- Growth  +-- Sales  +-- Property  +-- Real Estate
|   +-- Service +-- Capital Markets +-- решта installed Domains
|   +-- domain-specific aggregates / public contracts / evidence
|
+-- Experience / API / CLI / Messenger / Integrations
    +-- лише через опубліковані use cases і canonical authorization
~~~

**Примітка:** Goal Management є прикладною функцією і може фізично жити в Platform Application, але її business concepts не переносяться в низькорівневий Kernel. Engineering Workflow має спеціалізовані етапи; не потрібно стирати їхню семантику. Спільними мають бути універсальні технічні primitives, а не всі бізнес-стани.

## 4. Матриця володіння

| Об'єкт або механізм | Єдиний власник | Чого власник НЕ робить |
| --- | --- | --- |
| Tenant identity, authentication, permissions | Kernel / Platform Identity | Не визначає бізнес-ролі домену наосліп |
| Модуль, capability schema, activated binding | Module / Capability Registry | Не виконує команд і не видає права декларацією |
| Policy decision, Approval record | Kernel Policy / Approval | Не підміняється Goal або UI |
| Action state, attempts, idempotency / execution receipts | Kernel Action | Не вимірює бізнес-успіх Goal |
| Async job lease, backoff, retry/dead-letter | Kernel Queue / канонічна інфраструктура | Не знає бізнес-послідовності |
| Workflow definition, execution Run/Step, dependency scheduling | Platform Workflow Execution | Не знає конкретних Growth/Property правил |
| Goal, specification, immutable approved Plan, business OutcomeEvaluation | Goal Management | Не dispatch-ить side effects приватним механізмом |
| Entity, бізнес-стан і нативний результат | Відповідний Domain | Не змінює чужу таблицю |
| Trusted outcome read model / attribution | Domain-власник факту | Не вірить статусу Action як заміні факту |
| Process semantics / сценарний профіль | Власник Process / Application | Не вшивається в generic Kernel |
| UI mode / workspace state | Experience Platform | Не авторизує бізнес-дію |

## 5. Аудит фактичного стану перед рефакторингом

Огляд PR #346 підтвердив:

- Kernel WorkflowEngine має start/resume/cancel, переходи, Human/Wait/Agent/Tool/System steps, але його WorkflowExecution / StepExecution переважно in-memory; наявний SymfonyWorkflowStateManager перевіряє переходи, не є універсальним durable persistence.
- FederationSequentialOrchestrator + FederationGoalStore + FederationStepCursor + FederationRunFinalizer мають окремий persisted Run/Step lifecycle, CAS, DAG і повторну перевірку Action receipts. **Дублювання відповідальності є**, але наявна реалізація покриває деякі відсутні durable гарантії.
- FederationReadOnlyWorkflowRunner виконує через Kernel Workflow лише один інертний SystemStep. Це не повне підключення Golden 50.
- EngineeringWorkflowCoordinator / EngineeringWorkflowEngine — окрема спеціалізована state machine з власними persistence/locks.
- ActionService, ActionExecutor, ActionPolicyService, ActionExecutionJobHandler, QueueWorker, Outbox уже існують і повинні залишитися canonical execution path.

Тому заборонено бездумно замінити persisted Federation Run на in-memory Kernel Workflow: це регресія безпеки. **Перший крок — матриця фактичних гарантій і gaps, другий — їх перенесення, третій — cleanup.**

Обов'язково створити машинозчитуваний inventory (клас, package, source owner, runtime consumers, DB writes, queues, states, hazards, migration decision) для всіх Workflow/Federation/Engineering/Agent/Queue компонентів.

## 5.1. Третій обов'язковий контур аудиту: AI Engineering Workflow

**Scope P0:** аудит та обов'язкова contract/consolidation матриця охоплюють **одночасно** три механізми: \`Kernel\Workflow\WorkflowEngine\`, \`FederationSequentialOrchestrator\`, \`EngineeringWorkflowEngine\` + \`EngineeringWorkflowCoordinator\` з \`EngineeringOrchestrator\` та \`EngineeringAutonomousProgressionService\`. Неповна матриця тільки Kernel/Federation **не проходить** етап 1.

Перевірити на рівні методів, моделей, таблиць, DI та runtime:
1. **Життєвий цикл:** start/resume/cancel, persisted state, transitions, waiting/human decision, errors/terminal state, version, retries і continuity.
2. **Технічні primitives:** durable Run/Step, claim/lock/CAS, queue, dispatch, timeout, heartbeat/stale recovery, result/evidence checks, outbox/tracing/correlation.
3. **Engineering business semantics:** Product Requirements → QA Planning → Architect → Developer → Reviewer → QA, specialists, rework loops, agent autonomy budgets, independent review, no-self-approval, human merge/acceptance. Зберегти незалежно від технічної консолідації.
4. **Джерело правди:** \`DoctrineEngineeringWorkflowStore\`, таблиці \`cos_engineering_workflows\`, AgentRun store, transitions та health; встановити власника кожного запису й точку можливого дублювання з canonical Workflow.
5. **Виконання зовнішніх ефектів:** фактичні Engineering stage executor → agent/tool/repository/GitHub operations. Не стверджувати Action parity, доки не порівняні gate, idempotency та remote outcome uncertainty.
6. **План migration:** KEEP для доменних правил Engineering, ADAPT/CONSOLIDATE для доведених generic technical primitives, RETIRE лише після безпечного cutover й regression. Можлива staged adaptation; негайне злиття state machines заборонено.

Результат етапу 1: **трьохстороння** таблиця Kernel / Federation / Engineering → canonical owner → конкретний interface/adapter → duplicated state → risk → test → migration phase. Обов'язкова матриця наведена в [аудиті](./federation-execution-consolidation-audit.md).

**Додаткові критерії приймання Engineering:**
- відновлення stale AgentRun не повторює вже здійснених repository side effects;
- діють незалежні Architect/Reviewer/QA та human approval/merge gates;
- не змінюється рольова модель, логічні бюджети циклів та policy no-self-approval;
- Engineering progress/recovery та audit history після migration не втрачають доказів;
- жодного другого generic worker/queue/scheduler або dual writer для нового universal Workflow state.

## 6. Вимоги до єдиного виконання процесів

### 6.1. Контракти й структура

**Виконання:** WorkflowDefinition → WorkflowInstance → WorkflowRun → WorkflowStepExecution.  
**Зв'язування:** Goal / Plan (optional) → pinned WorkflowDefinition version → WorkflowRun ID → Action/Agent/Tool receipts.  
**Відокремлення:** GoalOutcomeEvaluation читає перевірені бізнес-факти, а не конструює бізнес-успіх із технічного виконання.

Встановити єдині версії схем для:
- definition/graph (nodes, edges, dependencies, bounded fan-out, condition);
- immutable invocation snapshot (tenant, actor, grants, version, input digest, correlation, budgets);
- step checkpoint / result ref / external receipt / uncertainty reason;
- status transitions, timestamps UTC(6), revision/CAS і причин відмов;
- consumed Action/Agent/Tool capability id + schema version.

Кожен Run має organization_id, pinned definition_version, immutable input/plan hash, owner/correlation, revision. Кожен Step має deterministic execution identity, dependency lineage, authoritative state, claim lease/attempt, idempotency reference, result reference. Публічні read projections не розкривають secrets/keys.

### 6.2. Стан виконання та конкурентність

- Один persistent state authority для кожного technical Run/Step. Оновлення через tenant-scoped lock або optimistic compare-and-swap. Terminal state immutable.
- Ready-steps визначаються детерміновано з pinned DAG, без циклів, невідомих залежностей і orphan nodes.
- На першому етапі дозволено **послідовне** виконання; паралельність не потрібна для завершення консолідації. У майбутньому bounded concurrency задається на рівні Run/tenant/capability/side-effect risk.
- WAITING_APPROVAL, WAITING_SIGNAL, BLOCKED, AMBIGUOUS повинні бути відмінними операційними випадками (семантична проєкція може агрегувати стан); не трактувати незрозумілі стани як успіх.
- Scheduler створює/будить тільки canonical queue work; бізнес-команду виконує лише зареєстрований Action/Tool/Agent/Domain handler. Ніякого другого worker або direct HTTP execution із federation layer.
- Recovery після crash: rehydrate Run, перевірити CAS, dependencies, expiry, Action receipt, продовжити з достовірного checkpoint. Принцип: **не перевиконувати підтверджений ефект**.
- Для зовнішньої операції з невизначеним результатом: блокування, reconciliation по remote id/idempotency/receipt або незалежне рішення оператора; не автоматичний повтор «про всяк випадок».
- Якщо крок потребує human Approval, reusable policy evidence перевіряється при submission і безпосередньо у worker admission. Заборонено self-approval там, де policy вимагає незалежного рішення.

### 6.3. Межі інтеграції

- Workflow → CapabilityRegistry → canonical Action/Agent/Tool → Domain owned handler.
- Capability binding resolve: installed+enabled module, одноосібний action owner, version/schema match, authenticated tenant/actor/policy, no manifest-only execution.
- Runtime **не містить** умов виду «if Growth», «if Sales», «if Documents», не робить direct Domain SQL.
- Engineering-специфічні етапи можуть залишатися власною domain/app state machine, а їхні job lease, tracing, execution safety використовують common infrastructure там, де фактично сумісні.

## 7. Стандарт міждоменного контракту

Кожна capability визначає: id, owner_domain, semantic version, kind (command/query/event), input/output JSON schema, execution binding, tenant activation, permissions, policy/approval, idempotency, timeout, retry classification, data classification, audit fields, failure semantics, compatibility tests.

Міждоменний виклик допускається як:
1. **Синхронний порт:** короткий read/use case, tenant-bound, timeouts/explicit errors; жодного прямого чужого persistence.
2. **Асинхронна Domain Event:** факт уже відбувся, state+event+outbox в одній локальній транзакції, at-least-once delivery, dedup/correlation. Подія не прихована команда.
3. **Команда / Action:** явний власник виконання та side effects, default-deny policy, approvals, receipt.
4. **Read projection:** копія даних для читання з provenance/version, але не джерело мутації.

Контракт не створює shared business ownership. Не використовувати глобальні транзакції між доменами. Для довгих процесів: чекпойнти, compensating business actions лише за явною Domain policy, часткові результати й audited manual recovery.

Розривний контракт: additive new version, consumer impact report, тимчасовий adapter, перевірка двох версій, telemetry переходу, retirement window. Manifest declared dependency і реальний runtime binding повинні збігатися.

## 8. Становлення Goal Management

Goal Management **не є Federation Engine**. Він визначає бажаний результат, критерії, обмеження, варіанти плану, схвалену версію, бюджет/ризики, provenance та outcome.

- GoalSpecification versioned immutable after approval; зміна створює нову версію.
- Plan immutable after approval; містить references на capabilities і digest входу.
- Створення/погодження Plan відокремлене від запуску і виконання Action.
- Goal Plan може посилатися на багато Workflow Runs, зокрема fan-out; це зв'язок, а не дубль стану Step.
- Domain Outcome Providers можуть підтверджувати target/observed і джерело доказу. Unverifiable ≠ zero, partial ≠ achieved.
- Attribution: розрізняти факт «запис існує у часовому вікні» та доказ «створено саме цим Run/Action». Для critical outcome потрібні origin/run links.
- Scenario profiles (наприклад Golden 50) конфігурують business graph/fan-out та criteria поза generic Platform Workflow.
- Масові погодження можливі лише через policy-approved scoped batch grant з pinned subject set, risk ceiling, expiry і незалежним approver; один batch не може потай затверджувати майбутні невідомі side effects.
- Існуючі federation.* endpoint/ids повинні мати backward compatibility до завершення consumer cutover.

## 9. Встановлювані модулі та автономність Domains

Для кожного з встановлених Domains провести:
- inventory module manifest (provided/required contracts, dependencies, permissions, runtime handlers, migrations);
- single owner для capabilities і business writes;
- перевірку activation/deactivation на tenant та graceful degradation;
- compatibility consumer tests на кордонах;
- включення до Process Registry там, де реально є процес; документовані exemptions з owner/review date, не безстрокові відписки;
- тест неможливості виклику Domain handler через неавторизований internal route/worker.

Нове підключення Domain **не вимагає** змін generic orchestrator. Його capabilities відкриваються через registry, а orchestration план використовує конкретні контракти. Додавання міждоменного сценарію — через власника Process Profile.

Не вводити окрему бізнес-сутність Federation і не створювати загального «універсального клієнта/об'єкт/угоду» для всіх доменів.

## 10. Legacy та оптимізація коду

### 10.1. Обов'язковий каталог

Для кожного підозрілого класу/таблиці/процесу зберегти evidence: шлях, власник, споживачі, runtime invocation, mutation SQL/ORM, test coverage, зовнішні залежності, безпечна стратегія.

Класифікація: **KEEP** (канонічний); **ADAPT** (існуючий за published port); **CONSOLIDATE** (дубль); **RETIRE** (dead із доказом); **UNKNOWN** (нема достатніх runtime даних, видалення заборонено).

Legacy не є автоматичною помилкою. Заборонені приховані direct writes, shadow authorization, альтернативні tenant contexts та dual writer для того самого бізнес-факту.

### 10.2. Попередня карта рішень щодо Federation

| Складова PR #346 | Цільова дія | Умова |
| --- | --- | --- |
| FederationDomainModule / cos.federation.plan.approval | ADAPT → Goal Management / canonical Action ownership; можлива сумісна назва | Перевірені module activation, grants, action migration |
| FederationSequentialOrchestrator | CONSOLIDATE в Platform Workflow Execution | Є durable Workflow parity та всі gates |
| FederationStepCursor / FederationStepDependencyGraph / FederationActionSequenceGate | CONSOLIDATE в спільне dependency/claim admission | DAG, worker proof, races regression green |
| FederationGoalStore | SPLIT: Goal/Plan/Outcome KEEP; технічні Run/Step MIGRATE | Data + consumer migration з audit/rollback |
| FederationRunFinalizer | CONSOLIDATE completion + receipt proof | Заборона forged/duplicate completions збережена |
| FederationReadOnlyWorkflowRunner / FederationWorkflowPreflight | RETIRE або спільний preflight після canonical binding | Kernel Workflow інтеграція доведена на side-effect кейсі |
| FederationRunRecoveryService / FederationRecoveryClassifier | ADAPT в спільний recovery | Idempotency/ambiguity/tenant semantics не втрачені |
| FederatedActionAdmission / plan approval reader / origin evidence | KEEP як trust-boundary guarantees; поступово generalize | Жодного weakened safety path |
| FederationGolden50GoalProfile / candidate planners / GoalOutcomeAggregationService | MOVE в Scenario/Process ownership; generic evidence provider contracts залишити | Нема hard-coded domain знання в core |
| cos_federation_goals / goal_specs / plans / evaluations | KEEP з керованим physical naming/versioning | Backward compatibility |
| cos_federation_runs / steps та зовнішні посилання | TRANSITION, потім RETIRE/ARCHIVE | No live run loss, retention proof, rollback plan |
| EngineeringWorkflowEngine та спеціальні Engineering стани | KEEP як specialized workflow | Окремий аудит спільної технічної інфраструктури |

**Не виконувати DROP**, масове перейменування таблиць, видалення міграцій або одночасне переключення writer без staging та lineage mapping.

### 10.3. Оптимізація

- Одна canonical domain/process/capability registry декларація за кожним типом факту, інші документаційні views генеруються.
- Скоротити application services, які лише дублюють validation/DTO інших слоїв, якщо safety semantics еквівалентні; зафіксувати до/після dependency graph.
- Не оптимізувати продуктивність ціною порушення ownership; N+1 та expensive projections виправляти read models/caching, не прямим cross-domain SQL.
- Не створювати великі PR з неконтрольованими десятками суміжних рефакторингів: small vertical migrations, один пакет відповідальності на PR, контрольований cleanup окремо.
- Код нового Domain має потребувати лише module manifest, public interfaces, handlers, migrations, тестів; не core if/else.

## 11. Experience та зовнішні API

Result / Process / Expert та майбутній adaptive UX є різними **представленнями тих самих canonical Goal/Workflow/Action/Outcome даних**, не окремими runtimes. UIAction/CSRF не замінюють Policy/Approval/tenant backend.

Operator workspace: Goal → approved Plan → canonical Workflow Run → steps/actions/approvals → verified Outcome → recovery. Показувати ambiguous/partial/unverifiable явно. Жодної заяви «успішно» на основі UI або timer.

Перехідний adapter має підтримати чинні /workspace/goals та /api/v1/federation/... до впорядкованого versioned API cutover. GET не створює side effects; mutation endpoints — authenticated/CSRF+Policy. Виведення старих маршрутів тільки після usage telemetry та оголошеної сумісності.

## 11.1. Обов'язкова передумова: P0 Contract & Consolidation Pack

Перед роботою над новим durable Workflow обов'язково пройти [P0 Contract & Consolidation Pack](./cos-execution-contract-consolidation-p0.md): конкретні interface/DTO/status/error контракти, [машинозчитуваний реєстр дублювань](../../resources/architecture/cos-execution-duplication-registry.json) для **трьох** механізмів Kernel/Federation/Engineering, [карту міграції станів](./cos-execution-state-migration-map.md) і [каталог parity-сценаріїв](../../resources/architecture/cos-execution-parity-cases.json).

Поточний каталог тестів є **планом parity та baseline anchors**, а не підтвердженням роботи ще не існуючого спільного runtime. Розробку нового executor відкривати після контрактного приймання P0, production cutover — лише після реальних parity/MySQL/worker/E2E доказів.

## 12. План впровадження й артефакти

### Етап 0. Замороження дублювання (P0)

**Дії:** погодити цю архітектуру; заборонити нові Federation worker/scheduler/run engines; зафіксувати baseline існуючого PR, routes, consumers, migrations.  
**Артефакти:** ADR-0015 з уточненням, inventory, architecture decision checklist.  
**Приймання:** у наступних PR відсутній новий паралельний універсальний executor.

### Етап 1. Повний dependency та ownership audit (P0)

**Дії:** static import/SQL mapping + DI handlers + running jobs/supervisor/cron + tenant/module activation; перевірити існуючий Engineering та legacy execution, приховані write paths.  
**Артефакти:** machine-readable inventory, graph «owner → consumer», таблиця KEEP/ADAPT/CONSOLIDATE/RETIRE/UNKNOWN; waivers з owner, expiry, reason.  
**Приймання:** кожний активний Run/Step writer і зовнішній dispatch має власника; UNKNOWN блокує видалення, але не обов'язково реліз непов'язаного Domain.

### Етап 2. Durable Workflow foundation (P0)

**Дії:** додати до канонічного Kernel/Platform Workflow persisted Run/Step, plan/version pinning, CAS/claim, checkpoints, scheduled resume через existing queue, deterministic DAG, admission guards, Action receipts. Не переносити бізнес-Goal у Kernel.  
**Артефакти:** versioned contracts/migrations, backfill mapping, unit + transactional integration tests, crash/worker tests.  
**Приймання:** canonical runtime виконує ≥3 різні Domain-owned Action handlers з human Approval, після crash продовжує коректно без дублювання побічних ефектів; непідтверджений remote outcome → AMBIGUOUS.

### Етап 3. Goal Management та сценарії (P0)

**Дії:** під'єднати існуючі Goals/approved Plans/Outcome до спільного Workflow Run; винести Golden 50 і aggregator logic у scenario profiles та Domain outcome providers.  
**Артефакти:** compatibility adapter, tenant-scoped Goal-to-Run/Outcome projection, validated Plan binding, outcome evidence attribution.  
**Приймання:** Golden 50 не використовує самостійний Federation execution scheduler; в одному Goal зберігаються нативні Growth/Sales/Documents факти, часткові помилки й перевірений підсумок.

### Етап 4. Переключення та виведення дублювання (P0)

**Дії:** shadow **read-only** comparison (без другого dispatch), legacy-run drain або доведена одноразова migration, перехід consumer на canonical Run/Step; зняти дубльовані класи лише після parity.  
**Артефакти:** migration ledger, mapping old→new ids, rollback/cutover runbook, deletion manifest.  
**Приймання:** єдиний production writer кожного state; відсутні втрати активних Runs, Approval, evidence й audit; тести старих endpoint проєкцій green. Жодних destructive DB змін у першому cutover.

### Етап 5. Федеративність усіх Domains (P1, інкрементально)

**Дії:** priority-by-value інтеграція Sales/Property/RealEstate/Service/Capital Markets та інших, contract tests, process coverage, ability to disable modules; окремий UX Package B на існуючих projections.  
**Артефакти:** capability/process matrices, per-Domain rollout gates, telemetry, documented exceptions.  
**Приймання:** новий Domain та 2-й міждоменний use case інтегруються без змін generic Workflow Executor; fault isolation і tenant permissions доведені.

## 13. Контроль якості та обов'язкові негативні тести

| Код | Перевірка | Критерій |
| --- | --- | --- |
| FA-01 | Межі Kernel/Domain, dependency cycles і direct cross-domain SQL | Жодних незадокументованих write paths або sync cycles |
| FA-02 | Capability schema/binding/owner/tenant activation | Manifest-only та duplicate handlers fail closed |
| FA-03 | Policy/Approval (expired, revoked, self, mismatched plan) | Жодного виконання без чинного дозволу |
| FA-04 | Worker replay та stale claim | На один logical step немає двох підтверджених side effects |
| FA-05 | Crash до/після dispatch, duplicate event, queue retry | Checkpoint rehydrate, safety, no blind replay |
| FA-06 | DAG cycle, join, fan-out cap, concurrency claim | Deterministic selection, exactly one claim authority |
| FA-07 | Tenant/actor/org substitution, UI mode tamper | Cross-tenant denial, no permission escalation |
| FA-08 | Forged Action receipt, stale Policy, changed Plan JSON | Run/Step completion fail closed |
| FA-09 | Uncertain external response та reconciliation | AMBIGUOUS/blocked, not success or auto resubmit |
| FA-10 | Domain disabled during pending Run | Controlled waiting/failure, no fallback to unrelated handler |
| FA-11 | Goal outcome: unsatisfied/partial/unverifiable, false attribution | No fabricated business success |
| FA-12 | Golden 50 natively persisted lead/document/source lineage | One native Lead per approved handoff; verified document/proposal outcome |
| FA-13 | API compatibility, old Run links, migrations, rollback | No data loss; read-only fallback possible |
| FA-14 | Engineering and non-Goal Workflow regression | No change of their authorized behavior |
| FA-15 | Load, limits, observability, dead-letter incidents | Bounded concurrency, measurable traces, safe degraded mode |

Тести мають проходити на **поточному commit** у CI, Runtime/MySQL, Architecture, Documentation, Frontend/Browser за потреби. Unit green недостатньо; MySQL synthetic rollbacks не замінюють контрольований tenant E2E із реальними нативними фактами.

## 14. Приймання та Definition of Done

Нормативна архітектура приймається після:
1. Узгодженого ownership inventory з доказами й bounded exceptions.
2. Відсутності двох production writer для generic Workflow Run/Step.
3. Повноцінного persisted canonical Workflow execution зі збереженням усіх approval/receipt/tenant safety guards.
4. Перенесення сценарного Golden 50 поза generic core, із незалежно перевіреним Goal Outcome.
5. Перевірки відмов, recovery, stale policy/tenant, partial fan-out, відключення Domain і неможливості повторити зовнішній ефект без доказу.
6. Підтвердження відсутності regression у Kernel Action/Agent/Queue, Engineering Workflow та існуючих бізнес-доменах.
7. Підписаного migration+rollback runbook, перевіреного staging, зелених CI/Runtime/Docs/Frontend, operator sign-off.
8. Окремого cleanup з описом класів/таблиць, які стали справді зайвими, після завершення compatible cutover.

**Не вважати завершенням:** окремий зелений PR, 50 запропонованих Plans без нативних бізнес-результатів, сам факт запуску Workflow, додавання нового Federation module, UI mode demo, розширення кількості capabilities без реального execution evidence.

## 15. Організація подальшої розробки

Для кожного нового feature/Domain інженер перед кодом відповідає:
1. Яка бізнес-функція та який її Domain owner?
2. Які вже існуючі Kernel/Platform primitives її виконують?
3. Чи можна реалізувати через наявний public contract / capability / Action / Workflow / Event?
4. Чи з'являється дубль стану, реєстру, worker або дозволів?
5. Які failure/tenant/legacy/migration/rollback гарантії потрібні?
6. Який один вертикальний тест доводить справжній результат?

Якщо новий загальний механізм все ж потрібний: ADR із виміряним gap, alternatives, запропонованим owner, migration path, сумісністю й способом усунення попереднього дубля. Без цього generic runtime code не додається.

**Порядок Engineering:** Manager → Architect (ownership/alternatives) → Developer → Reviewer (anti-duplication + security) → QA (negative/runtime evidence) → Manager acceptance. Самосхвалення для критичних змін заборонено.

**Релізи:** Package A означає консолідований execution foundation + verified Goal, а не окремий Federation engine. Package B означає Progressive Disclosure поверх тих самих read models і не блокує приймання фундаменту, якщо basic operator controls існують.

## 16. Нормативні джерела та обмеження аудиту

- [ADR-0015](../11-decisions/ADR-0015-cos-federation-architecture.md).
- [Аудит виконання Federation](./federation-execution-consolidation-audit.md).
- [Межі між доменами](./cross-domain-contracts.md).
- [Огляд Kernel](./kernel-overview.md).
- [Пакет A](./cos-federation-goal-execution-foundation.md).
- [Цикл виконання](../05-runtime/execution-lifecycle.md).
- [Збереження, черги, планувальник](./persistence-async-scheduler.md).

Це цільове ТЗ, побудоване на перевірених фрагментах коду PR #346. Воно **не стверджує**, що повний статичний і runtime dependency audit, міграція чи видалення зайвих класів уже відбулися. Рішення KEEP/RETIRE можуть змінитися лише за новими доказами, не за враженням від назви класу.
