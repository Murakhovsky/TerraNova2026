---
title: Огляд домену Service
description: Межа V1 домену Service для звернень, кейсів, тікетів, SLA, призначень і вирішення.
status: active
updated: 2026-09-17
kind: domain
contract: domain-v1
---

# Огляд домену Service

Service `0.1.0` є **канонічним V1 skeleton-domain** COS. Він фіксує бізнес-словник і Application boundary сервісних операцій, але навмисно не вмикає runtime, persistence або HTTP surface.

## Призначення

```text
Request → ServiceCase / Ticket → SLA → Assignment → Resolution
```

Домен володіє моделями `ServiceCase`, `Request`, `Ticket`, `SLA`, `Assignment` і `Resolution` та контрактами Application layer для майбутніх use cases.

## Поточний стан

```text
id: service
version: 0.1.0
runtime: disabled
persistence: none
routes: none
process model: explicitly deferred
```

Відсутність runtime-процесу є свідомим V1 architecture exemption. Коли Service отримає виконуваний runtime, exemption має бути видалений одночасно з появою канонічної Process definition.

## Межі

Service не знає про конкретні email, Telegram, телефонію або зовнішні helpdesk API. Такі системи підключаються через Platform Integration та Notification boundaries.

- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
