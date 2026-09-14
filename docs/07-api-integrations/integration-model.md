---
title: Integration Model
description: Правила інтеграції CRM, Telegram, LLM, webhooks та зовнішніх сервісів у COS.
status: active
updated: 2026-09-12
kind: architecture
---

# Integration Model

## Основне правило

External system не визначає внутрішню business model.

```text
Domain
→ outbound port
→ Infrastructure adapter/router
→ external provider
```

Inbound:

```text
provider/webhook/channel
→ Interface/Infrastructure validation
→ durable inbox або application DTO
→ Domain use case
```

## CRM

Sales володіє canonical CRM semantics через contracts (`CrmGatewayInterface`, inbound/provider interfaces, organization resolver, secret resolver).

Provider selection і HTTP/provider details належать Infrastructure.

Inbound CRM flow має HMAC/secret validation, durable inbox, idempotency, retry/dead-letter і external reference mapping.

## Telegram

Telegram — delivery channel, не Domain.

Новий Telegram code належить `Interfaces/Telegram` та викликає ті самі use cases, що Web/API/CLI.

Legacy Telegram ActiveRecord models у Sales/Property persistence є compatibility adapters і не повинні розростатися.

## LLM

Є дві різні абстракції:

- `Kernel\\Llm` — provider-neutral **governed structured LLM runtime**;
- `Kernel\\Agent` — controlled decision runtime, який може створювати `ActionProposal`.

Не кожен structured LLM call є Agent.

Поточний LLM integration path:

```text
Domain / Agent / Diagnostic
    ↓
StructuredLlmRequest
    ↓
GovernedStructuredLlmClient
    ↓
budget + routing policy
    ↓
provider registry
    ↓
Infrastructure/Llm client
    ↓
external LLM provider
    ↓
usage accounting + metrics
```

Concrete provider transport належить `Infrastructure/Llm`.

### Routing і fallback

Provider/model selection централізується через `LlmRoutingPolicy` і `LlmProviderRegistry`.

Explicit `useCase` policy має пріоритет над request/domain model hint. Fallback дозволений лише на retryable provider failure і лише якщо policy містить наступний route.

### Tenant governance

Коли request має `organizationId`, shared runtime може:

- перевірити monthly budget;
- записати usage/cost;
- tenant-scope-ити metrics;
- корелювати request за `correlationId`;
- групувати telemetry за `useCase`.

Деталі: [LLM Governance](../06-ai-agents/llm-governance.md).

## Webhooks

Webhook endpoint повинен:

1. перевірити authenticity;
2. нормалізувати transport input;
3. забезпечити idempotency/inbox, якщо подія asynchronous;
4. передати canonical command/use case;
5. не виконувати business SQL самостійно.

## Module-owned integration surfaces

Kernel V0.9 дозволяє modules декларувати integration/delivery contributions через Extension Runtime.

Поточні приклади:

- `api.routes`;
- `tenant.configuration`;
- `web.navigation`.

Shared Bootstrap не повинен містити hardcoded список Domain-specific contributors там, де surface може бути module-owned.

Деталі: [Extension Runtime](../03-architecture/extension-runtime.md).

## n8n

n8n може бути orchestration/integration adapter, але не місцем canonical business rules.

Якщо правило впливає на domain state і має бути однаковим для Web/API/Telegram/Agent, воно належить Domain/Kernel, не n8n workflow.

## External side effects

External calls повинні мати, де це застосовно:

- organization-scoped idempotency keys;
- retry policy;
- timeout/error classification;
- circuit breaker або еквівалентний failure protection;
- audit/telemetry;
- correlation id;
- explicit provider boundary.

Для LLM retry/fallback classification уже централізована в governed runtime. Інші integrations не повинні копіювати LLM-specific abstractions, але мають дотримуватися того самого принципу: transport failure ≠ business decision.

## Ownership test

Якщо завтра CRM/Telegram/LLM provider зміниться, Domain business behavior має залишитися тим самим. Якщо для заміни провайдера треба переписати rules/use cases, boundary проведений погано.
