---
title: Events and Outbox
description: Події, metadata, transactional outbox і replay model COS.
status: active
updated: 2026-09-11
kind: runtime
---

# Events and Outbox

Event layer відділяє бізнес-факт від реакцій на нього.

## Event is a fact

`DomainEvent` описує те, що вже відбулося. Назва події має бути в past tense / fact semantics.

Добре:

```text
sales.call.completed
sales.deal.stage_changed
```

Погано:

```text
sales.send_followup_now
```

Друге є Action/Command intent, а не Event.

## Event metadata

`EventMetadata` переносить технічний context, потрібний для tracing та isolation:

- event identity;
- organization / tenant;
- correlation / causation;
- actor/source, де застосовно;
- timestamps та інші transport-neutral metadata.

Business payload і technical metadata не слід змішувати.

## Transactional Outbox

Головна гарантія:

```text
business state
+ domain event
+ outbox row
= one transaction
```

Це прибирає класичну помилку:

```text
DB saved ✓
message publish failed ✗
```

або навпаки.

Після commit окремий worker доставляє Outbox messages durable consumers.

## At-least-once

COS не припускає exactly-once delivery через магію та оптимізм.

Consumer може отримати Event повторно. Тому:

- consumer має durable checkpoint/state;
- Action і integration calls мають idempotency key;
- replay не повинен дублювати side effects;
- external-reference mapping має бути explicit.

## EventBus

`EventBus` є runtime abstraction для publication/subscription semantics. Concrete persistence та delivery implementation належать Infrastructure.

Kernel contract не повинен знати PDO або конкретний broker.

## Replay

Replay потрібен для відновлення automation після defect/configuration change або для повторного обрахунку consumer logic.

Правильна модель:

1. обрати контрольований набір Outbox rows;
2. reset delivery state;
3. reset відповідні consumer checkpoints;
4. повторно доставити;
5. зберегти idempotency guarantees.

Replay не означає «запусти всі webhooks ще раз і подивимось».

## Event ownership

Кожен business Event має одного owning Domain.

`DomainModuleRegistry` перевіряє ownership contributions, щоб два Domains не оголосили себе власниками одного event type.

Kernel володіє лише generic technical events, наприклад Action result lifecycle.

## Naming convention

Рекомендована схема:

```text
<domain>.<entity-or-process>.<fact>
```

Приклади:

```text
sales.call.completed
sales.deal.updated
finance.invoice.paid
inventory.stock.low
```

Kernel technical namespace:

```text
cos.action.completed
cos.action.failed
```

## Event vs Command vs Action

| Concept | Meaning |
| --- | --- |
| Event | факт, який уже стався |
| Command / Use Case | намір виконати application operation |
| Action | контрольована mutation, що проходить Policy |

Не називати всі три «event». Машини терплячі, люди потім плачуть.

## Operational checks

Для кожного event-driven flow документація повинна містити:

- owning Domain;
- producer;
- payload schema;
- metadata requirements;
- consumers;
- idempotency strategy;
- retry/dead-letter behavior;
- replay behavior;
- resulting Actions/Events.
