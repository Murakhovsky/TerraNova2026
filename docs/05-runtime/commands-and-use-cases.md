---
title: Commands and Use Cases
description: Межа між application intent, domain transaction та automation Action.
status: active
updated: 2026-09-11
kind: runtime
---

# Commands and Use Cases

У COS є три близькі, але різні поняття: **Use Case**, **Command/intent** і **Action**. Їх не варто зливати в одну універсальну сутність лише тому, що всі вони «щось запускають».

## Application Use Case

Use Case є entry point бізнес-операції Domain.

Він:

- приймає typed input/DTO;
- працює через Domain model та outbound contracts;
- перевіряє application-level preconditions;
- відкриває/використовує transaction boundary;
- змінює business state;
- створює Domain Event;
- не залежить від HTTP/Telegram/UI.

```text
Interface
 → UseCase
 → Domain Model
 → Repository Port
 → Event + Outbox
```

## Command / intent

Command — явне прохання виконати application operation. Він може бути окремим DTO або implicit input use case залежно від складності.

Приклад семантики:

```text
CompleteSalesCall
ChangeDealStage
RecordInvoicePayment
```

Command сформульований у наказовому способі, Event — як факт у минулому.

## Action

Action у COS — інша річ. Це mutation, яка виникла або була сформована в automation/runtime layer і мусить пройти Policy.

```text
sales.send_followup
sales.sync_crm
sales.assign_owner
```

Action може бути результатом Rule або Agent proposal.

## Чому не зливати UseCase і Action

Людина може виконати нормальний transactional use case, який породжує Event. Автоматизація реагує на Event та створює Action.

```text
Manager completes call        ← Use Case
          ↓
sales.call.completed          ← Event
          ↓
Agent proposes follow-up      ← Decision
          ↓
sales.send_followup           ← Action
          ↓
Policy / Approval / Execution
```

Якщо зробити все Action-ами, бізнес-транзакції стають залежними від automation machinery. Якщо зробити все UseCase-ами, Agent/Policy layer втрачає контрольовану mutation unit.

## Interface rule

Controller/API command handler має викликати Use Case, а не писати business state напряму.

```text
HTTP JSON
 → request validation
 → DTO/Command
 → UseCase
 → response mapping
```

## Domain Application layer

Рекомендована структура:

```text
Application/
├── Contract/    outbound ports
├── DTO/         command/result types
└── UseCase/     orchestration
```

UseCase може залежати від Domain objects і Domain-owned contracts, але не від MySQL adapter, Phalcon Controller або конкретного CRM SDK.

## Automation layer

```text
Automation/
├── Event/
├── Rule/
├── Agent/
├── Action/
├── Job/
└── Policy/
```

Це окремий рівень реактивної поведінки навколо бізнес-моделі.

## Rule of thumb

Поставити питання:

- «Користувач/система просить виконати основну бізнес-операцію?» → Use Case/Command.
- «Щось уже сталося?» → Event.
- «Runtime вирішив, що треба зробити mutation?» → Action.

## Invariant

> Use Case змінює Domain згідно бізнес-правил. Event повідомляє про факт. Action проходить контрольований automation execution lifecycle.
