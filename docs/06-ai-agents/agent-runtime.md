---
title: Середовище виконання Agent
description: Як AI Agent приймає рішення в COS без прямого доступу до шару мутацій.
status: active
updated: 2026-10-04
kind: agent
---

# Середовище виконання Agent

Agent у COS є **компонентом прийняття рішень**, а не автономним root user системи.

Реалізація знаходиться в `app/Kernel/Agent` і включає `AgentDefinition`, `AgentInvocation`, `AgentExecution`, `AgentResult`, `AgentRuntime`, маршрутизований context builder, `SensitiveContextRedactor`, `StructuredDecisionValidator` та adapter до керованого LLM runtime.

## Потік виконання

```text
Event / Job
   ↓
AgentDefinition
   ↓
Domain context builder
   ↓
SensitiveContextRedactor
   ↓
StructuredAgentLlmClient
   ↓
GovernedStructuredLlmClient
   ↓
Budget + Routing + Provider/Fallback
   ↓
StructuredDecisionValidator
   ↓
AgentResult
   ↓
ActionProposal(s)
   ↓
normal Action → Policy → Approval → Queue → Execution
```

## AgentDefinition

Definition описує стабільний контракт Agent:

- ідентичність;
- призначення;
- конфігурацію model/runtime;
- очікуваний структурований результат;
- дозволений словник proposal/action;
- Domain-власника.

Специфічні для бізнесу Agent definitions належать Domain. Загальний механізм виконання належить Kernel.

## Власність на контекст

Agent не повинен самостійно ходити по database або integrations за будь-якими даними, які йому захотілися.

Domain context builder визначає:

- які факти потрібні;
- як вони обмежуються tenant;
- які поля дозволено передати LLM;
- як формується компактний контекст рішення.

`RoutedAgentContextBuilder` дозволяє Kernel направити запит до правильного builder, яким володіє Domain, без знання внутрішньої логіки Sales, Finance чи інших Domains.

## Чутливий контекст

Перед передачею та збереженням вхідні дані Agent проходять redaction (вилучення чутливих даних).

`SensitiveContextRedactor` є межею безпеки й приватності, а не косметичним preprocessing.

Принцип:

```text
minimum necessary context
> full database dump
```

## Керована межа LLM

Agent runtime не звертається напряму до конкретного provider client.

`StructuredAgentLlmClient` переводить Agent request у `StructuredLlmRequest`, який проходить через спільний шар LLM governance.

Для такого виклику важливі:

- `organizationId` — область бюджету й обліку tenant;
- `useCase` — область routing та metrics;
- `correlationId` — трасування;
- requested model — підказка сумісності, якщо немає явної політики маршрутизації.

Явна routing policy для `useCase` має пріоритет над model hint Agent.

Детальніше: [Керування LLM](./llm-governance.md).

## Структурований результат

LLM response не вважається валідним рішенням лише тому, що JSON успішно розібрався.

`StructuredDecisionValidator` повинен відхиляти:

- невідомий тип Action;
- malformed payload;
- відсутні обов’язкові поля;
- заборонений або непідтримуваний proposal;
- невідповідність schema;
- інші порушення контракту.

Невалідна відповідь не створює мутацію.

## Правило proposal-only

Agent може запропонувати Action, але не може викликати executor напряму.

```text
LLM says “send message”
≠
message sent
```

Між ними стоять:

```text
validated proposal
→ Action
→ Policy
→ optional Approval
→ Queue
→ Handler
```

Routing або fallback LLM не змінюють цього правила. Інший provider може допомогти отримати рішення, але не отримує права обійти Policy.

## Детерміновані й agentic-рішення

Якщо рішення надійно виражається Rule, воно не повинно автоматично ставати LLM task.

Agent доречний для:

- інтерпретації;
- пріоритизації;
- синтезу;
- розуміння природної мови;
- міркування в неоднозначному контексті;
- рекомендації серед обмеженого набору варіантів.

Rule доречне для:

- порогів;
- точних станів;
- обов’язкових полів;
- детермінованої відповідності;
- жорстких обмежень.

## Модель помилок

Agent runtime має розрізняти щонайменше:

- невалідне структуроване рішення;
- відмову через бюджет;
- retryable provider failure;
- non-retryable provider failure;
- вичерпані маршрути провайдерів;
- помилку downstream Action або Policy.

Retryable помилка LLM provider може привести до configured fallback route. Non-retryable помилка не повинна тихо маскуватися переходом на іншого provider.

## Тестування Agent

Тестування треба розділяти на:

1. context builder tests;
2. redaction tests;
3. перевірку structured schema;
4. fixture-based LLM decision tests;
5. routing/budget/fallback tests;
6. перевірку Policy для запропонованих Actions;
7. окремі наскрізні тести виконання Action.

Тестувати весь COS одним prompt і радіти, що він «схоже відповів правильно», все ще не є методологією.

## Метрики

Мінімально корисні:

- кількість запусків;
- частка валідних і невалідних результатів;
- затримка LLM;
- input/output tokens;
- вартість LLM;
- кількість fallback;
- відмови за бюджетом;
- розподіл proposals;
- denied actions;
- approval-required actions;
- фактична успішність виконання;
- retries і failures.

## Інваріант

> Agent має право міркувати в межах контракту контексту. Право діяти визначає Kernel Policy runtime.


## Інженерна оркестрація: Principal Architect

Інженерна автоматизація використовує той самий Kernel Agent runtime, а не окремий паралельний фреймворк для агентів.

Feature-level шлях V2.0:

```text
Engineering Manager
→ FEATURE_SPEC + CONTEXT_MAP
→ QA Test Plan
→ Principal Architect
→ ARCHITECTURE_DECISION
→ IMPLEMENTATION_PLAN
→ DEVELOPER_HANDOFF
→ Architecture Gate
→ Developer
```

Principal Architect отримує обмежений набір доказів із repository, read-only snapshot схеми бази даних і, коли ревізія контексту Manager відрізняється від поточного `main`, порівняння ревізій. Runtime сам проставляє в Architecture Decision авторитетні `feature_id` та repository revision, замість того щоб довіряти LLM механічне копіювання цих ідентифікаторів.

Значення Architecture Gate обмежені чотирма варіантами:

- `APPROVED`;
- `APPROVED_WITH_CONDITIONS`;
- `REJECTED`;
- `NEEDS_HUMAN_DECISION`.

Тільки перші два дозволяють перейти до Development. Перед першим запуском Developer повторно перевіряє, що затверджена repository revision усе ще є актуальною. Якщо `main` змінився, orchestration повертає роботу Principal Architect для revalidation замість реалізації за застарілим планом.

Engineering roles мають окремі model hints. Legacy ENV hints зберігаються для Manager, Architect, Developer, Reviewer і QA; V2 ролі `PRODUCT_REQUIREMENTS`, `QA_PLANNER`, `QA_EXECUTOR` та `INTEGRATION_RELEASE` налаштовуються через Platform Settings (`product.model`, `qa_planner.model`, `qa_executor.model`, `integration_release.model`) і за відсутності override успадковують відповідно Manager/QA/Reviewer fallback.

Principal Architect може підготувати зміни архітектурної документації та ADR лише в межах `docs/`. Ці зміни зберігаються як керовані artifacts і застосовуються разом зі змінами Developer, тому repository не отримує окремий технічний commit лише заради документації, а авторство та audit trail залишаються явними.


## Інженерна оркестрація: Developer

Після Architecture Gate runtime переводить workflow через `ARCHITECTURE_APPROVED → DEVELOPMENT_PENDING → DEVELOPMENT_RUNNING` і запускає `DEVELOPER` у тому самому Engineering orchestration runtime.

Developer виконує preflight до мутацій: перевіряє repository revision, наявність потрібних файлів, достатність repository evidence та відповідність approved plan. Він не має права мовчки змінити Architecture Decision або Acceptance Criteria.

Керовані результати: `COMPLETED`, `COMPLETED_WITH_LIMITATIONS`, `BLOCKED`, `ARCHITECTURE_REVIEW_REQUIRED`, `SPECIFICATION_REVIEW_REQUIRED`, `SECURITY_REVIEW_REQUIRED`, `FAILED`.

`ARCHITECTURE_REVIEW_REQUIRED` повертає workflow Principal Architect без repository mutation. Specification/security escalation зупиняє автономне виконання на human decision boundary. Лише completion-статуси можуть перейти до bounded repository change set, commit/branch і PR; merge та production deploy залишаються за межами прав Developer.

Developer output зберігає preflight, scope, database/API impact, acceptance-criteria evidence, validation evidence, architecture compliance, security findings, limitations, deviations, risks і follow-up requirements. Відомий failure required validation не може завершитись completion-статусом.


## Інженерна оркестрація: Reviewer

Після `DEVELOPMENT_COMPLETED` workflow переходить у `REVIEW_PENDING`, де Agent №4 (`REVIEWER`) виконує незалежну перевірку фактичного PR/diff. Reviewer отримує Feature Specification, Architecture Decision, Implementation Plan, Developer Handoff, Development Result, changed files, CI evidence та явні coding/security standards.

Reviewer є read-only щодо production implementation: він не виправляє код, не merge-ить PR і не змінює Acceptance Criteria. Runtime окремо підтримує `COS_ENGINEERING_REVIEWER_MODEL`; для незалежності рекомендується route/model family, відмінний від Developer, коли це доступно через LLM governance.

Severity: `BLOCKER`, `MAJOR`, `MINOR`, `SUGGESTION`. BLOCKER/MAJOR завжди блокують; MINOR блокує лише з `blocking=true`; SUGGESTION не блокує. Reviewer decision обмежений `APPROVED`, `REQUEST_CHANGES`, `ARCHITECTURE_REVIEW_REQUIRED`, `HUMAN_REVIEW_REQUIRED`.

Маршрути: `APPROVED → QA_PENDING → QA_EXECUTOR`; `REQUEST_CHANGES → CHANGES_REQUESTED → DEVELOPMENT_RUNNING → Developer`; `ARCHITECTURE_REVIEW_REQUIRED → ARCHITECTURE_PENDING → Principal Architect`; `HUMAN_REVIEW_REQUIRED → HUMAN_DECISION_REQUIRED`.

`APPROVED` заборонений при неповному preflight, architecture non-compliance, unresolved blocking issues, BLOCKER/MAJOR, failed required CI або acceptance criterion без PASS evidence.


## Інженерна оркестрація: QA Planner та QA Executor

`QA_PLANNER` працює до Architecture/Development і відповідає за незалежний план перевірки. `QA_EXECUTOR` працює після Reviewer approval і відповідає за фактичну поведінку feature відносно Feature Specification та Acceptance Criteria. Legacy роль `QA` збережена лише для persisted V0.1 runs.

Після Product / Requirements workflow переходить у `QA_PLANNING`: QA Planner формує незалежний `TEST_PLAN` до Architecture/Development. План покриває positive, negative, edge, permissions, tenant, API, database, UI, regression і performance cases та явно визначає required suites: unit, integration, functional, E2E, smoke.

Після `Reviewer APPROVED` QA Executor запускається у `QA_PENDING` на exact reviewed revision. Кожен Acceptance Criterion має `PASS|FAIL` і concrete evidence; формулювання на кшталт "looks okay" не є доказом. Для релевантних feature окремо перевіряються COS invariants: tenant isolation, auth/authz, invalid input, empty/loading/error states, API errors, migration/rollback і backward compatibility. `NOT_APPLICABLE` вимагає причину.

QA не має права змінювати production implementation. Він може запропонувати й застосувати автоматизовані тести лише в `tests/` або `symfony/tests/`. Якщо QA додає тести, результат `TESTS_UPDATED` створює нову revision і повертає workflow Reviewer; після повторного `APPROVED` QA тестує вже цю revision.

Фінальні QA status: `PASS`, `FAIL`, `BLOCKED`, `HUMAN_TEST_REQUIRED`. `PASS` вимагає zero failed tests, PASS для всіх blocking Acceptance Criteria та всіх applicable COS invariants, відсутність BLOCKER/MAJOR defects/security findings і успішний deterministic CI. `FAIL` повертає Developer, після чого обов'язково повторюються Reviewer і QA. `HUMAN_TEST_REQUIRED` переходить у human decision boundary і ніколи не прирівнюється до PASS.


## Повний автономний цикл Engineering V2.0

Після формалізації запиту Engineering runtime виконує керований цикл:

```text
Engineering Manager
→ Product / Requirements
→ QA Planner
→ Principal Architect
→ Developer
→ Reviewer
→ QA Executor
→ READY_FOR_HUMAN_APPROVAL
→ human merge
```

State machine визначається deterministic runtime, а не довільним рішенням LLM. Кожний Agent створює структурований artifact, який проходить schema validation і role-specific gate.

### Інваріант ревізії

Після Developer усі downstream-рішення прив'язані до конкретного Git commit:

```text
implementation revision
= current PR head reviewed by Reviewer
= Reviewer reviewed_revision
= current PR head tested by QA
= QA tested_revision
= CI checked revision
```

Reviewer і QA перед запуском перевіряють, що PR залишається відкритим, не merged і його поточний head SHA дорівнює ревізії, на яку посилається попередній artifact. Зовнішній push робить старий evidence невалідним і зупиняє просування workflow.

Reviewer та QA також повинні покрити весь набір Acceptance Criteria з Feature Specification. Відсутній або невідомий criterion є contract failure, а не мовчазним PASS.

### Бюджет автономності

Локальний step limit доповнюється persistent budget по кількості logical AgentRuns для feature. Це захищає від нескінченних циклів після scheduler resume.

Коли бюджет вичерпано:

```text
active stage
→ HUMAN_DECISION_REQUIRED
→ AUTONOMY_BUDGET
```

Людина може вибрати `CONTINUE`, що відкриває наступний budget tranche, або `CANCEL`. Без явного рішення autonomous execution не продовжується.

### Фінальний human gate

`READY_FOR_HUMAN_APPROVAL` вимагає approved Architecture Gate, завершеної Development, Reviewer approval, QA PASS, CI SUCCESS, перевірених blocking Acceptance Criteria, відсутності open critical findings, blocking human decisions і незавершених engineering tasks.

Merge та production deploy у V0.1 залишаються human-only.


## Engineering Domain Development Runtime V2.0

Для великих bounded context користувач не створює вручну десятки Feature workflows. У `/admin/engineering/domains` він створює Domain Initiative, задає goal через Master Specification і за потреби змінює integration branch та concurrency limits. Якщо repository override порожній, Runtime використовує canonical Engineering repository із конфігурації.

Керований шлях:

~~~text
Create Domain
→ Engineering Manager
→ Product / Requirements
→ Domain Specification + Domain Acceptance Criteria
→ QA Planner
→ Principal Architect
→ Domain Architecture + Architecture Constitution
→ Capability / Feature decomposition
→ dependency-driven Feature workflows
→ integration
→ QA Executor
→ Integration & Release
→ Domain Release Manifest
→ human merge / release approval
~~~

Human Control Plane залишається на рівні goal, scope, architecture exceptions, risk і release. Scheduler сам визначає готові Feature за dependency graph, резервує repository paths, створює child Engineering workflows та продовжує їх до Domain integration.

### Concurrency

Domain має чотири незалежні межі:

~~~text
max_parallel_features
max_parallel_developers
max_parallel_reviews
max_parallel_qa
~~~

Default значення приходять із `COS_ENGINEERING_DOMAIN_MAX_PARALLEL_*`. `max_parallel_features` обмежує кількість одночасно активних child workflows, а stage limits застосовуються безпосередньо перед Developer, Reviewer і QA AgentRun. Очікування через concurrency gate не змінює deterministic workflow state і може бути безпечно продовжене scheduler-ом.

### Orchestration event ledger

Domain Runtime зберігає idempotent orchestration events із correlation id, optional feature key та evidence payload. Канонічний lifecycle включає `DomainCreated`, `DomainSpecificationReady`, `DomainDecompositionReady`, `DomainArchitectureApproved`, `CapabilityReady`, `FeatureReady`, `FeatureStarted`, `FeatureCompleted`, `FeatureBlocked`, `ContractChanged`, `ArchitectureChanged`, `DomainIntegrationStarted`, `DomainIntegrationCompleted`, `DomainQaStarted`, `DomainQaCompleted`, `DomainReleaseReady`.

Workspace показує event ledger поряд з Domain Agent executions, тому стан Domain можна відновити не з декоративного status badge, а з persisted runtime evidence.
