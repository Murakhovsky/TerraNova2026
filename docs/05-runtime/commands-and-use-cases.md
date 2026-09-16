---
title: Команди та сценарії використання
description: Межа між наміром застосунку, доменною транзакцією та автоматизованою дією в COS.
status: active
updated: 2026-09-16
kind: runtime
---

# Команди та сценарії використання

У COS є три близькі, але різні поняття: **Use Case (сценарій використання)**, **Command (команда)** і **Action (дія)**. Їх не варто зливати в одну універсальну сутність лише тому, що всі вони «щось запускають».

## Сценарій використання

Use Case є точкою входу в бізнес-операцію Domain (домену).

Він:

- приймає типізовані вхідні дані або DTO;
- працює через модель Domain і вихідні контракти;
- перевіряє передумови рівня застосунку;
- відкриває або використовує межу транзакції;
- змінює бізнес-стан;
- створює Domain Event (доменну подію);
- не залежить від HTTP, Telegram чи UI.

```text
Interface
 → UseCase
 → Domain Model
 → Repository Port
 → Event + Outbox
```

## Команда

Command є явним запитом виконати операцію застосунку. Залежно від складності він може бути окремим DTO або типізованим входом Use Case.

Приклад семантики:

```text
CompleteSalesCall
ChangeDealStage
RecordInvoicePayment
```

Command формулюється як намір щось зробити. Event описує факт, який уже стався.

## Дія

Action у COS є контрольованою мутацією, яка виникла в шарі автоматизації або середовищі виконання й повинна пройти Policy (політику дозволу).

```text
sales.send_followup
sales.sync_crm
sales.assign_owner
```

Action може бути результатом Rule (правила) або пропозиції Agent.

## Чому Use Case і Action не є одним поняттям

Людина може виконати звичайний транзакційний Use Case, який створює Event. Автоматизація реагує на Event і формує Action.

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

Якщо зробити все Action, основні бізнес-транзакції почнуть залежати від механізмів автоматизації. Якщо зробити все Use Case, шар Agent/Policy втратить окрему контрольовану одиницю мутації.

## Правило для інтерфейсів

Controller або обробник API-команди має викликати Use Case, а не змінювати бізнес-стан напряму.

```text
HTTP JSON
 → request validation
 → DTO/Command
 → UseCase
 → response mapping
```

## Приклад структури Application

```text
Application/
├── Contract/    outbound ports
├── DTO/         command/result types
└── UseCase/     orchestration
```

Use Case може залежати від об’єктів Domain і контрактів, якими володіє Domain, але не від MySQL-адаптера, Phalcon Controller чи конкретного CRM SDK.

## Шар автоматизації

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

## Практичне правило

Поставте три питання:

- «Користувач або система просить виконати основну бізнес-операцію?» → Use Case / Command.
- «Щось уже сталося?» → Event.
- «Середовище виконання вирішило, що треба виконати мутацію?» → Action.

## Інваріант

> Use Case змінює Domain відповідно до бізнес-правил. Event повідомляє про факт. Action проходить контрольований життєвий цикл автоматизованого виконання.
