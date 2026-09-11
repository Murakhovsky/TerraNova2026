---
title: LLM Boundary
description: Provider-neutral structured LLM contract та його відмінність від Agent runtime.
status: active
updated: 2026-09-11
kind: architecture
---

# LLM Boundary

Починаючи з актуального Kernel V0.8, COS явно розділяє **structured LLM transport** і **Agent runtime**.

Це важлива архітектурна межа: не кожен виклик LLM є Agent.

## Kernel LLM contract

`Kernel\Llm` містить provider-neutral structured LLM abstraction, зокрема:

- `StructuredLlmClientInterface`;
- `StructuredLlmRequest`;
- `StructuredLlmResponse`.

Його задача — дати Domain/Application можливість запросити structured generation без залежності від OpenAI/Anthropic/HTTP SDK та без маскування звичайного model call під Agent lifecycle.

## Infrastructure

Concrete HTTP/provider implementation живе в Infrastructure.

```text
Domain/Application
   ↓
Kernel\Llm contract
   ↓
Infrastructure LLM client
   ↓
Provider API
```

Provider metadata, retry, circuit breaker, token/cost telemetry можуть бути реалізовані adapter-ом, не витікаючи в Domain.

## Agent runtime is higher-level

`Kernel\Agent` вирішує іншу задачу:

```text
AgentDefinition
+ Domain Context
+ redaction
+ LLM call
+ structured decision validation
→ ActionProposal
```

Тобто Agent runtime використовує LLM як частину decision lifecycle, але `Kernel\Llm` може використовуватися і без Agent.

## Diagnostic example

Diagnostic domain володіє:

- prompts;
- response schema;
- business context;
- token budget;
- interpretation of structured result.

Він залежить від `Kernel\Llm` contract, а не від concrete OpenAI gateway і не від `Kernel\Agent`, якщо йому не потрібен Agent semantics.

## Compatibility

Поточний Agent runtime має legacy/compatibility LLM contract path. Це допустимий перехідний стан, але нові provider-neutral structured generation use cases не повинні автоматично залежати від Agent contract.

## Decision rule

Використовуйте `Kernel\Llm`, коли потрібно:

- structured extraction;
- classification;
- report generation;
- bounded analysis;
- schema-constrained generation;
- Domain-owned AI operation без ActionProposal lifecycle.

Використовуйте `Kernel\Agent`, коли потрібно:

- побудувати decision context;
- отримати автономне/bounded рішення;
- сформувати ActionProposal;
- передати proposal у Policy/Approval/Execution runtime.

## Why this matters

Без цієї межі будь-яка AI-функція поступово починає називатися Agent, а Agent runtime стає сумішшю HTTP client, prompt templates, business workflow і mutation engine. Саме так системи отримують модні назви та дуже немодний coupling.

## Invariant

> LLM contract надає intelligence primitive. Agent runtime надає controlled decision lifecycle. Domain визначає business meaning обох.
