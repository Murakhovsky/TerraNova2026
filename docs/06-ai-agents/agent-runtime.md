---
title: Середовище виконання Agent
description: Як AI Agent приймає рішення в COS без прямого доступу до шару мутацій.
status: active
updated: 2026-09-16
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

Обов’язковий шлях V0.1:

```text
Engineering Manager
→ FEATURE_SPEC + CONTEXT_MAP
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

Engineering roles можуть мати окремі model hints через `COS_ENGINEERING_MANAGER_MODEL`, `COS_ENGINEERING_ARCHITECT_MODEL`, `COS_ENGINEERING_DEVELOPER_MODEL`, `COS_ENGINEERING_REVIEWER_MODEL` і `COS_ENGINEERING_QA_MODEL`. Порожнє значення означає використання загального LLM routing/default model. Docker runtime передає ці змінні явно, тому production deployment не втрачає role-specific routing.

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

Маршрути: `APPROVED → QA_PENDING → QA`; `REQUEST_CHANGES → CHANGES_REQUESTED → DEVELOPMENT_RUNNING → Developer`; `ARCHITECTURE_REVIEW_REQUIRED → ARCHITECTURE_PENDING → Principal Architect`; `HUMAN_REVIEW_REQUIRED → HUMAN_DECISION_REQUIRED`.

`APPROVED` заборонений при неповному preflight, architecture non-compliance, unresolved blocking issues, BLOCKER/MAJOR, failed required CI або acceptance criterion без PASS evidence.
