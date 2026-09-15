---
title: Messaging Channels
description: Telegram and future messaging channels as delivery surfaces over shared COS application boundaries.
status: active
updated: 2026-09-15
kind: architecture
---

# Messaging Channels

Telegram, Viber, email/chat adapters та майбутні messaging surfaces не є окремими бізнес-системами. Вони є каналами доступу до тих самих application use cases.

## Canonical channel path

```text
Incoming message / callback
        ↓
Channel adapter
        ↓
Identity + tenant resolution
        ↓
Intent / transport mapping
        ↓
Application DTO / Use Case
        ↓
Domain result
        ↓
Channel-specific rendering
```

## Channel responsibilities

Channel layer може володіти:

- provider update parsing;
- callback/message addressing;
- formatting, buttons і pagination;
- channel rate limits;
- delivery retry metadata;
- mapping external user/chat identity to COS identity context.

Channel layer не володіє:

- Sales qualification rules;
- Property commercial lifecycle;
- Diagnostic scoring semantics;
- Agent mutation authority;
- canonical business persistence.

## Shared behavior

Якщо одна й та сама операція доступна через Web, API і Telegram, усі три surfaces повинні викликати однаковий application boundary.

```text
Web ─────┐
API ─────┼→ Use Case → Domain
Telegram ┘
```

Різниця має бути в interaction/presentation, а не у правилах бізнесу.

## Legacy boundary

Історичні Telegram ActiveRecord/adapters можуть залишатися compatibility surface під час migration. Нові domain rules або canonical writes не повинні повертатися туди лише тому, що старий bot уже знає назву таблиці.

## Outbound delivery

Domain/Event/Automation формує semantic action, після чого channel adapter виконує provider-specific delivery. External message id, retry/error metadata та correlation повинні залишатися transport facts.

## Related

- [Integration Model](./integration-model.md)
- [External Reliability](./external-reliability.md)
- [Adding an Integration](../09-development/adding-an-integration.md)
