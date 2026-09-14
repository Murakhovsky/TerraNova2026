---
title: ADR-0002 — MySQL state plus Event and Outbox, not Event Sourcing
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

COS потребує надійно запускати automation після бізнес-змін, мати auditability і replayable delivery, але не потребує відновлювати весь business state виключно з event stream.

Прямий pattern `commit state -> publish message` створює dual-write failure: database commit може пройти, а publication — ні.

# Decision

**MySQL є canonical source of current business state. COS не використовує Event Sourcing.**

Для бізнес-операцій, що породжують подію, в одній transaction boundary зберігаються:

```text
business state
+ immutable business Event
+ Outbox row
```

Після commit durable consumer обробляє Outbox. Delivery семантика — at-least-once, тому downstream side effects та consumers мають бути idempotent.

Replay працює через Outbox/consumer checkpoints, а не через перебудову всіх aggregates з event history.

# Rationale

Модель дає потрібну reliability без operational та modeling вартості повного Event Sourcing:

- current state читається напряму з owner tables;
- event delivery не губиться між DB і worker;
- automation можна повторно доставляти;
- transaction boundaries залишаються явними;
- legacy/data migration простіші.

# Alternatives considered

## Full Event Sourcing

Відхилено як default architecture. Воно додало б event-versioning, aggregate rehydration, snapshot/projection complexity та складнішу міграцію без достатньої користі для поточного COS.

## Synchronous publish after commit

Відхилено через dual-write gap.

## Database polling without explicit events

Відхилено: зміни state не дають достатньо чіткої business semantics і ускладнюють traceability.

# Consequences

Позитивні:

- простіша persistence model;
- durable automation trigger;
- можливість replay delivery;
- бізнес-події залишаються явними.

Негативні/обмеження:

- event log не є повною canonical history для reconstruction state;
- schema migrations все одно потрібні;
- idempotency є обов'язковою частиною consumer design.

# Compatibility / Migration

Domain persistence adapters мають використовувати спільну transaction boundary. Нові asynchronous side effects не повинні додавати окремий «publish після commit» path.

# Verification

Перевіряються:

- atomic state + Event + Outbox write;
- durable retry;
- consumer idempotency;
- replay/checkpoint behavior;
- tenant isolation.

# Related

- `docs/architecture/cos-kernel.md`
- `docs/architecture/persistence.md`
- `docs/05-runtime/execution-lifecycle.md`
