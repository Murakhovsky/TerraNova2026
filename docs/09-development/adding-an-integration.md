---
title: Adding an Integration
description: How to add external providers through Domain-owned ports, adapters, durable delivery and configuration boundaries.
status: active
updated: 2026-09-15
kind: how-to
---

# Adding an Integration

Integration не повинна перетворювати provider API на внутрішню Domain model.

## 1. Choose the owner

Спочатку визначте, який Domain володіє business operation. CRM provider для Sales реалізує Sales-owned contract; property portal/feed реалізує Property Network boundary; generic transport/mechanism не повинен вигадувати domain semantics.

## 2. Define the port

Contract описує **що потрібно Domain**, а не SDK конкретного provider.

```text
Domain / Application
      ↓
Outbound or inbound port
      ↑
Provider adapter
```

## 3. Translate vocabulary at the edge

External statuses, event names, IDs та payload fields перетворюються в canonical vocabulary на adapter boundary. Provider не називає внутрішні Domain Events напряму.

## 4. Make delivery durable

Для inbound webhooks/feed records потрібні stable external event/record identity, payload hash/idempotency semantics та durable processing там, де redelivery можлива.

Для outbound side effects визначте retry policy, idempotency key та audit/result event.

## 5. Keep credentials outside the Domain

Tokens/secrets зберігаються в configuration/secret ownership. Domain model може мати opaque `configuration_reference`, але не provider secret.

## 6. Route mutation through use cases

Adapter не пише business tables напряму. Він викликає application use case/port, після чого Domain validation, transaction, Event/Outbox і Policy semantics залишаються тими самими, що й для інших interfaces.

## 7. Verify

Перевірте:

- invalid signature/payload;
- duplicate delivery;
- provider timeout/error;
- retry/idempotency;
- tenant scope;
- vocabulary mapping;
- secret redaction;
- generated routes/capabilities/reference, якщо integration додає runtime contribution.

## Related architecture

- [Integration Model](../07-api-integrations/integration-model.md)
- [Cross-Domain Contracts](../03-architecture/cross-domain-contracts.md)
- [Events & Outbox](../05-runtime/events-and-outbox.md)
