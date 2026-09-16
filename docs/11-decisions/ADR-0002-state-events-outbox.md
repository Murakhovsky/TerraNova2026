---
title: ADR-0002 — MySQL state + Event + Outbox, без Event Sourcing
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

COS має надійно запускати automation після бізнес-змін, зберігати auditability і підтримувати replayable delivery, але не потребує відновлювати весь business state виключно з event stream.

Прямий pattern `commit state → publish message` створює dual-write failure: database commit може пройти, а publication ні.

# Рішення

**MySQL є canonical source of current business state. COS не використовує Event Sourcing як базову persistence model.**

Для бізнес-операцій, що породжують подію, в одній transaction boundary зберігаються:

```text
business state
+ immutable business Event
+ Outbox row
```

Після commit durable consumer обробляє Outbox. Delivery semantics є at-least-once, тому downstream side effects і consumers мають бути idempotent.

Replay працює через Outbox/consumer checkpoints або інший explicit delivery replay, а не через перебудову всіх aggregates з event history.

# Обґрунтування

Модель дає потрібну reliability без operational та modeling вартості повного Event Sourcing:

- current state читається напряму з owner tables;
- event delivery не губиться між DB і worker;
- automation можна повторно доставляти;
- transaction boundaries залишаються явними;
- legacy/data migration простіші;
- Domain Events залишаються бізнесовими фактами, а не єдиним форматом persistence.

# Розглянуті альтернативи

## Повний Event Sourcing

Відхилено як default architecture. Він додав би event versioning, aggregate rehydration, snapshot/projection complexity та складнішу міграцію без достатньої користі для поточного COS.

## Synchronous publish після commit

Відхилено через dual-write gap.

## Database polling без explicit Events

Відхилено: зміни state не дають достатньо чіткої business semantics і ускладнюють traceability.

# Наслідки

Позитивні:

- простіша persistence model;
- durable automation trigger;
- можливість replay delivery;
- бізнес-події залишаються явними;
- recovery може окремо reconcile state, Outbox та queue processing.

Обмеження:

- event log не є повною canonical history для reconstruction state;
- schema migrations усе одно потрібні;
- idempotency є обов’язковою частиною consumer design;
- replay consequential side effects потребує окремого контролю.

# Сумісність і міграція

Domain persistence adapters мають використовувати спільну transaction boundary. Нові asynchronous side effects не повинні додавати окремий «publish після commit» path.

Legacy flows, де state mutation та event delivery розділені, є migration debt, а не альтернативним canonical pattern.

# Перевірка

Перевіряються:

- atomic state + Event + Outbox write;
- durable retry;
- consumer idempotency;
- replay/checkpoint behavior;
- tenant isolation;
- recovery semantics для pending Outbox/queue work.

# Пов’язані матеріали

- `docs/05-runtime/events-and-outbox.md`
- `docs/05-runtime/execution-lifecycle.md`
- `docs/10-operations/data-and-migrations.md`
- `docs/10-operations/backup-and-recovery.md`
