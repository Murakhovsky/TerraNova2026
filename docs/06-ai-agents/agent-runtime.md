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
