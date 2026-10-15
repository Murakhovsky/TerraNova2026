---
title: COS Federation — фактичний аудит виконання
description: Перевірка актуального коду COS, дублювання технічних Run/Step, джерел стану та перешкод безпечній консолідації.
status: active
updated: 2026-10-15
kind: architecture
---

# Фактичний аудит джерел виконання COS

**Історичний baseline першого аудиту:** `a8b2cd5cb921ef96dacaf648af7e5f5767937e4a`. **Повторна перевірка:** `d52eec68af964eb7f20e2e6ac7181b42ff61b531`, PR #346. [Runtime (success)](https://github.com/Murakhovsky/TerraNova2026/actions/runs/38750264374), зокрема Federation canonical Goal E2E та Engineering technical SQL shadow MySQL. Це CI-доказ саме попереднього HEAD, не доказ production cutover.

**Важливо:** нижче базові спостереження про старий прямий Engineering SQL writer та активний legacy Federation entrypoint замінені актуальними фактами. Жоден новий статус реєстру не означає fleet acceptance.

**Межа перевірки:** вихідний код GitHub, реєстрація залежностей, контракти та результати наявного CI. Прямої перевірки production-БД, реального трафіку і deployment не проводилося. Це не повний динамічний граф викликів.

## Підтверджений фактичний стан

1. **Спільний Workflow:** `DoctrineWorkflowExecutionStore` записує `cos_workflow_runs/steps` і реалізує `WorkflowExecutionStoreInterface` та `WorkflowTechnicalExternalStoreInterface`. Нові canonical Federation Runs та дозволені Engineering AgentRuns використовують його. Універсальний runtime для всіх Capability і всієї історії ще не доведений.
2. **Goal / Federation:** `FederationRunController` тепер запускає нові tenant-enabled Runs через `FederationCanonicalWorkflowBridge`; `start` делегує до Platform Store, `advance` використовує shared claim/checkpoint. Виконання історичних legacy Runs заблоковано, але їхні `cos_federation_runs/steps` ще зберігаються для shadow/reconciliation. Goal, Plan і Outcome залишаються бізнес-сутностями.
3. **Часткова консолідація:** `FederationStepDependencyGraph` делегує `WorkflowDependencyGraph`; `FederationStepCursor` делегує `WorkflowStepScheduler`; `ExecutionRunPolicy` делегує `DurableWorkflowTransitionPolicy`. Це повторне використання вже працює, але життєвий цикл Run/Step не об'єднано.
4. **Engineering:** `EngineeringCanonicalTechnicalExecutionGateway` викликає `beginGuardedExternal`, `completeGuardedExternal`, `quarantineGuardedExternal` через `WorkflowTechnicalExternalStoreInterface`; він більше не пише напряму `cos_workflow_runs/steps`. Тільки explicitly approved tenant+Feature AgentRun допускається до pilot. Engineering business stages/QA/Review/Human gates залишаються Domain owned.
5. **Безпека виконання:** наявні `DoctrineWorkflowActionWorkerAdmission`, `WorkflowPredecessorReceiptVerifier` та адаптери нативних доказів. Типові загальні реалізації claim/receipt у `services.yaml` залишаються fail-closed. Їх не можна вилучати під час міграції.
6. **Порівняння даних:** `DoctrineWorkflowShadowParityReader` і `cos:workflow:shadow-audit` виконують read-only звірення. Реєстр тепер фіксує 2 bounded `IN_PROGRESS` із 20 concerns (`EX-002`, `EX-003`), а CP-20 залишається `IN_PROGRESS` із 25 parity cases. Повний доказ паритету всіх tenant/Run, Engineering human gates і Golden 50 відсутній.
7. **Перевірки:** на базовому коміті аудит встановив успішні `CI`, `Runtime`, `Frontend`, `Documentation`. Це не доказ перенесення production-даних чи відсутності подвійних writer.

**Додатковий дефект ownership:** раніше `TableOwnership` помилково відносив `cos_federation_*` та `cos_experience_*` до `CapitalMarkets`. Карту виправлено: ці тимчасові Goal/Experience записи та canonical `cos_workflow_*` належать Platform. Це зміна декларації володіння, не фізична SQL-міграція.

## Матриця відповідальності за виконання

| Контур | Наявні записи | Цільове рішення |
| --- | --- | --- |
| Kernel Workflow | Переважно in-memory об'єкти `WorkflowExecution` | Adapter або зняття з виконання після перевірки consumers |
| Федеративні Goal | `cos_federation_runs/steps`, writer `FederationGoalStore`, `FederationExternalActionSubmission` та `FederationRunFinalizer` | Лише Goal/Plan/Outcome у Goal Management; технічні Run/Step у спільному Workflow |
| Canonical Workflow | `cos_workflow_runs/steps`, `DoctrineWorkflowExecutionStore` і `DoctrineWorkflowRecoveryManager` | Shared writer для нових Federation і allowlisted Engineering; повний fleet cutover не підтверджено |
| Engineering AgentRun | Canonical Run/Step через shared Store port; окремий бізнес-Workflow | Проведено bounded технічне підключення; consumer/fleet parity і human gates залишаються поза acceptance |

## Реалізований захист від нових дублікатів

У цьому інкременті впроваджено правило `FED-INV-04`, реєстр `resources/architecture/cos-execution-authority-freeze.json` і CI-перевірку `tests/architecture/cos_federation_execution_authority_freeze.php`. Перевірка шукає нових прямих SQL/DBAL writer для чотирьох таблиць Run/Step та нові загальні механізми Federation execution у runtime-коді. Існуючі writer внесені до зафіксованого переліку технічного боргу. Окремо виключена одна транзакційна smoke-команда, яка створює синтетичні тестові записи.

Це **статичний регресійний бар'єр**, а не автоматична міграція, не доведений повний call graph і не дозвіл видаляти legacy таблиці.

## Обов'язкова послідовність завершення консолідації

1. Довести всі runtime consumers та єдиний capability lifecycle (Action/Agent/Tool/Wait/Signal), зберігши існуючий shared Store і без нового Federation worker.
2. Перевірити всіх runtime consumers, web/CLI/queue, власників DB writes та єдину прив'язку tenant+Plan до Run.
3. Зробити additive ID mapping, read-only backfill старих Run/Step, незалежну перевірку receipts, точну звірку станів і карантин сумнівних записів.
4. Передати право запису через fence/drain для окремих груп tenant/Plan. Жодних dual writes, подвійних Action dispatch чи автоматичних повторів невизначеного зовнішнього ефекту.
5. Замінити `FederationSequentialOrchestrator` тонким Goal-to-Workflow adapter, вимкнути legacy writer, прибрати інертний міст лише після доказового cutover.
6. Для вже делегованого Engineering AgentRun провести fleet rollout/read-only shadow integrity та перевірити бізнес-етапи, human approval, review/QA і transition history.
7. Перевірити Golden 50 та інші Domains через реальні зареєстровані capabilities і підтверджені бізнес-факти. Перед production acceptance потрібні MySQL failure-injection, fleet-wide parity та процедура відновлення.

**Статус:** консолідація триває; нормативну федеративну модель ще не можна вважати впровадженою повністю.

## Підсумок повторної перевірки (HEAD `d52eec68`)

| Scope | Source authority | CI acceptance | Remaining gate |
| --- | --- | --- | --- |
| New Federation Run | `FederationCanonicalWorkflowBridge` → `WorkflowExecutionStoreInterface` → `DoctrineWorkflowExecutionStore` | Real SQL canonical Goal/Action MySQL PASS | Live tenant rollout, whole-fleet mapping and Golden 50 |
| Engineering opted-in AgentRun | `EngineeringCanonicalTechnicalExecutionGateway` → `WorkflowTechnicalExternalStoreInterface` → `DoctrineWorkflowExecutionStore` | Real SQL Engineering shadow MySQL PASS | Full history, all consumers, human QA/Release approvals |
| Historical Federation | Legacy tables remain read-only for audit, with controlled migration CLI | Migration authorization/shadow MySQL PASS | Staging cutover parity, receipt audit, operator signoff |
| Kernel Workflow / generic capabilities | Separate existing infrastructure | Partial isolated unit/DB coverage | All capabilities, consumers, wait/signal and failure injection |

**Епістемічна межа:** green MySQL fixtures доводять перевірені code paths, але не production стан tenant або відсутність невідомих consumers. `EX-002/EX-003=IN_PROGRESS`, а не `VERIFIED`. Уся PR #346 залишається без merge.

## P0. Інвентаризація федеративного виконання та міграцій

Новий операторський CLI: `php symfony/bin/console cos:federation:execution-fleet TENANT --limit=100`.

Він окремо рахує legacy Runs, canonical Runs та migration ledger; перевіряє історичні Run через наявний `FederationHistoricalRunCutover::inspect()`, post-cutover integrity та конфлікти Plan authority. Усі SQL-запити прив'язані до одного tenant, результати обмежені. Truncated cohort, відсутній link, невідомий canonical Plan чи подвійний execution ownership завжди відображаються як неповнота/дрейф. Команда **завжди повертає nonzero**, навіть для здорового технічного зрізу; ніякого automatic cutover, recovery або Action replay.

CI регресія: `tests/integration/federation_historical_cutover_mysql.php` перевіряє read-only audit на реальних MySQL fixture records та foreign-tenant isolation. `tests/architecture/cos_federation_fleet_audit.php` фіксує відсутність side-effect path. Це не замінює fleet-wide staging evidence чи operator signoff.

## P0. Виконуваний аудит джерел стану та споживачів

Команда з кореня репозиторію: `php bin/cos-execution-authority-report.php`. Вона сканує PHP-код `app/`, `symfony/src/` і `bin/`, зіставляє SQL/DBAL технічних writers чотирьох таблиць зі списком зафіксованих власників, знаходить посилання на runtime-контракти й ключові споживачі та повертає машинний звіт. Непідтверджені writer або зниклі основні entrypoint виявляє `tests/architecture/cos_execution_authority_inventory.php`. Цей звіт не модифікує дані.

**Реальна межа аудиту:** статичний граф посилань не доводить відсутності викликів через reflection, контейнер, сторонні пакети, cron, зовнішні черги й production traffic. Тому `consumer_fleet_verified=false` та `live_tenant_verified=false` зберігаються свідомо, навіть коли CI пройшов.

## P0. Матриця фактично підтриманого життєвого циклу Capability

| Тип | Підтримуваний технічний шлях | Межа приймання |
| --- | --- | --- |
| Action | Затверджений Goal → спільний coordinator → canonical Store → Action/Policy/Approval/Queue → attested receipt | Реальні tenant, усі handlers і тривалі збої ще потребують перевірки |
| Agent | Engineering AgentRun → tenant/Feature rollout → `WorkflowTechnicalExternalStoreInterface` → fenced claim/output | Лише Engineering pilot, не весь Kernel Agent registry та human gates |
| Tool | Окремий `Kernel\\Tool\\Service\\ToolRuntime` | Немає спільної транзакційної прив'язки до canonical Run і незалежного receipt для довільного Tool; автоматичний dispatch заборонено |
| Wait/Signal | Canonical read-only Step → `waiting` → незалежна перевірка Domain evidence → CAS completion | Лише pinned `read:` із зареєстрованим доказом; arbitrary push Signal не приймається |
| Domain Outcome | Read-model verifier після terminal Run | Завершення Workflow саме по собі не означає успіх Goal |

`DoctrineWorkflowExecutionCoordinator` управляє лише технічними переходами `start/advance/resume/cancel`. Він не створює паралельного worker і не підміняє власників Action, Agent та Tool. Доступ невідомим bindings закритий.

**Поточний статус:** статичний аудит і підтримувані вузькі шляхи реалізовані; повний Package A acceptance, production fleet та універсальні Agent/Tool залишаються незавершеними. Статуси `VERIFIED`/merge без окремих доказів заборонені.

## P0. Загальний Kernel Agent / Tool → canonical Platform Store

Контрольований Kernel Agent/Tool cutover використовує той самий `DoctrineWorkflowExecutionStore`, що й Federation/Engineering. **Тільки цей Store змінює технічні `cos_workflow_runs/steps`**. Новий `DoctrineKernelExecutionFence` забезпечує admission та lifecycle, `DoctrineKernelExecutionEvidence` незалежно читає native `cos_jobs`, `cos_agent_runs`, `cos_audit_log` і погоджений `cos_actions` без запису Run/Step.

**Активація:** таблиця `cos_kernel_execution_rollouts` окремо для `agent` і `tool` на кожен tenant. Відсутність явного `canonical`, `approved_by` та `approved_at` не активує cutover. Проводити активацію можна лише через затверджену операторську процедуру після tenant shadow/fleet parity. Наявні legacy-виклики залишаються незмінними при `off`; `canonical` блокує відсутність необхідних identity і approval.

Agent: лише перший живий `AGENT_RUN` Job lease. Native LLM-виклик перевіряє claim, queue ownership та correlation. Native `cos_agent_runs` зберігає джерельний Job ID; завершення Workflow підтверджує незалежний результат. Жодна повторна генерація LLM після завершеного, простроченого чи неоднозначного claim не дозволяється.

Tool: існуючі permission/schema gates виконуються ДО canonical claim. Потрібен стійкий ключ виклику й користувач. READ-Tool має одну спробу в canonical mode і підтверджується власним успішним `cos_audit_log`. WRITE/EXTERNAL-Tool допускається лише в контексті окремо дозволеного людським Approval канонічного `kernel.tool.invoke` Action зі звіркою pinned input, Policy та першої Action attempt. Для такого Action ще має існувати встановлений реальний handler; сам manifest не дозволяє запуску.

**Recovery:** `php symfony/bin/console cos:workflow:kernel-recovery TENANT --limit=100`. Читає technical state, independent native receipts, expired claims і підозрілий drift. Не робить replay, не видає production дозволів, не виправляє невідомий ефект автоматично. Неповна вибірка або невизначені native outcomes забороняють позитивне приймання.

**Межі приймання:** `tests/integration/workflow_kernel_canonical_mysql.php` перевіряє реальний MySQL queue / AgentRun / Tool-audit / CAS / replay / tenant / quarantine; це не означає, що всі Tool Action handlers наявні, а всі consumer invocations в production активовані. Повний fleet acceptance та незалежний операторський cutover залишаються окремими gates.
