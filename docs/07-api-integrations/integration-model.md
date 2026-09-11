---
title: Integration Model
description: Правила інтеграції CRM, Telegram, LLM, webhooks та зовнішніх сервісів у COS.
status: active
updated: 2026-09-11
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

- `Kernel\\Llm` — provider-neutral structured LLM primitive;
- `Kernel\\Agent` — controlled decision runtime, який може створювати ActionProposal.

Не кожен structured LLM call є Agent.

Concrete provider transport належить `Infrastructure/Llm`.

## Webhooks

Webhook endpoint повинен:

1. перевірити authenticity;
2. нормалізувати transport input;
3. забезпечити idempotency/inbox, якщо подія asynchronous;
4. передати canonical command/use case;
5. не виконувати business SQL самостійно.

## n8n

n8n може бути orchestration/integration adapter, але не місцем canonical business rules.

Якщо правило впливає на domain state і має бути однаковим для Web/API/Telegram/Agent, воно належить Domain/Kernel, не n8n workflow.

## External side effects

External calls повинні мати organization-scoped idempotency keys, retry policy, timeout/error classification та audit/telemetry.

## Ownership test

Якщо завтра CRM/Telegram/LLM provider зміниться, Domain business behavior має залишитися тим самим. Якщо для заміни провайдера треба переписати rules/use cases, boundary проведений погано.