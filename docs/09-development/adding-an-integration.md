---
title: Додавання інтеграції
description: Як підключити зовнішнього provider через Domain-owned ports, adapters, надійну доставку та межі конфігурації.
status: active
updated: 2026-09-16
kind: how-to
---

# Додавання інтеграції

Integration не повинна перетворювати API зовнішнього provider на внутрішню Domain model.

## 1. Визначте власника

Спочатку визначте Domain, який володіє бізнес-операцією.

Наприклад:

- CRM provider для Sales реалізує Sales-owned contract;
- property portal або feed реалізує Property Network boundary;
- generic transport mechanism не вигадує Domain semantics.

## 2. Визначте port

Contract описує **що потрібно Domain**, а не SDK конкретного provider.

```text
Domain / Application
      ↓
Outbound or inbound port
      ↑
Provider adapter
```

## 3. Перекладайте vocabulary на межі

External statuses, event names, IDs та payload fields перетворюються в canonical vocabulary на adapter boundary.

Provider не визначає внутрішні Domain Events або state transitions напряму.

## 4. Зробіть доставку надійною

Для inbound webhooks і feed records визначте:

- stable external event/record identity;
- payload hash;
- idempotency semantics;
- durable processing там, де можлива redelivery.

Для outbound side effects визначте:

- timeout;
- retry policy;
- idempotency key;
- error classification;
- audit і result event.

## 5. Тримайте credentials поза Domain

Tokens та secrets належать configuration/secret ownership.

Domain model може мати opaque `configuration_reference`, але не повинен зберігати provider secret.

## 6. Проводьте мутацію через Use Case

Adapter не пише бізнесові таблиці напряму.

```text
External input
→ Adapter
→ Application Use Case / Port
→ Domain validation
→ Transaction
→ Event / Outbox
→ Result
```

Так Domain rules залишаються однаковими для Web, API, messaging та integration paths.

## 7. Перевірка

Перевірте:

- invalid signature і malformed payload;
- duplicate delivery;
- provider timeout/error;
- retry та idempotency;
- tenant scope;
- vocabulary mapping;
- secret redaction;
- audit/correlation;
- generated routes/capabilities/reference, якщо integration додає runtime contribution.

## Пов’язана архітектура

- [Модель інтеграцій](../07-api-integrations/integration-model.md)
- [Cross-Domain Contracts](../03-architecture/cross-domain-contracts.md)
- [Події та Outbox](../05-runtime/events-and-outbox.md)
- [Надійність зовнішніх інтеграцій](../07-api-integrations/external-reliability.md)
