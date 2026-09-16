---
title: Межа LLM
description: Незалежний від провайдера контракт структурованого LLM та його відмінність від Agent runtime.
status: active
updated: 2026-09-16
kind: architecture
---

# Межа LLM

COS явно розділяє **structured LLM transport (структурований виклик LLM)** і **Agent runtime (середовище виконання Agent)**.

Це принципова архітектурна межа: не кожен виклик LLM є Agent.

## Контракт Kernel LLM

`Kernel\Llm` містить незалежну від провайдера абстракцію структурованого LLM, зокрема:

- `StructuredLlmClientInterface`;
- `StructuredLlmRequest`;
- `StructuredLlmResponse`.

Його задача — дати Domain/Application можливість запросити структуровану генерацію без залежності від OpenAI, Anthropic або конкретного HTTP SDK і без маскування звичайного model call під Agent lifecycle.

## Інфраструктура

Конкретна HTTP/provider реалізація живе в Infrastructure.

```text
Domain/Application
   ↓
Kernel\Llm contract
   ↓
Infrastructure LLM client
   ↓
Provider API
```

Provider metadata, retry, circuit breaker та token/cost telemetry можуть бути реалізовані adapter, не витікаючи в Domain.

## Agent runtime є вищим рівнем

`Kernel\Agent` вирішує іншу задачу:

```text
AgentDefinition
+ Domain Context
+ redaction
+ LLM call
+ structured decision validation
→ ActionProposal
```

Тобто Agent runtime використовує LLM як частину життєвого циклу рішення, але `Kernel\Llm` може використовуватися і без Agent.

## Приклад Diagnostic

Diagnostic domain володіє:

- prompts;
- response schema;
- business context;
- token budget;
- інтерпретацією структурованого результату.

Він залежить від `Kernel\Llm` contract, а не від конкретного OpenAI gateway і не від `Kernel\Agent`, якщо йому не потрібна Agent semantics.

## Правило вибору

Використовуйте `Kernel\Llm`, коли потрібні:

- structured extraction;
- classification;
- report generation;
- bounded analysis;
- schema-constrained generation;
- AI-операція, якою володіє Domain, без життєвого циклу `ActionProposal`.

Використовуйте `Kernel\Agent`, коли потрібно:

- побудувати decision context;
- отримати автономне, але обмежене рішення;
- сформувати `ActionProposal`;
- передати proposal у Policy / Approval / Execution runtime.

## Чому ця межа важлива

Без неї будь-яка AI-функція поступово починає називатися Agent, а Agent runtime перетворюється на суміш HTTP client, prompt templates, business workflow і mutation engine. Назва стає моднішою, coupling — ні.

## Інваріант

> LLM contract надає примітив інтелекту. Agent runtime надає контрольований життєвий цикл рішення. Domain визначає бізнесовий сенс обох.
