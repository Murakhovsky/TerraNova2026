---
title: Межа LLM
description: Незалежний від провайдера контракт структурованого LLM та його відмінність від Agent runtime.
status: active
updated: 2026-10-04
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


## Нативний адаптер OpenAI

COS має native adapter `Infrastructure\Llm\OpenAiResponsesStructuredLlmClient`, який реалізує `StructuredLlmClientInterface` напряму через OpenAI Responses API.

Для активації:

```env
LLM_PROVIDER=openai
LLM_TOKEN=<OpenAI API key>
LLM_MODEL=<model id>
```

`LLM_ENDPOINT` для native OpenAI adapter не використовується. Старий generic HTTP transport залишається доступним через `LLM_PROVIDER=http`.

Native adapter передає `responseSchema` як OpenAI Structured Outputs через `text.format.type=json_schema` з `strict=true`, повертає provider/model/token usage у стандартний `StructuredLlmResponse` і не змінює Domain або Agent contracts.

Таким чином routing лишається:

```text
Domain / Agent
→ StructuredLlmClientInterface
→ GovernedStructuredLlmClient
→ LlmRoutingPolicy
→ LlmProviderRegistry
→ openai | http | fixture
```

Це дозволяє надалі додати Anthropic/Gemini/інший provider як ще один adapter без окремого LLM Gateway.
