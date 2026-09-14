---
title: Cross-Domain Contracts
description: Правила взаємодії bounded contexts у COS без розмивання ownership.
status: active
updated: 2026-09-14
kind: architecture
---

# Cross-Domain Contracts

Domains у COS не є ізольованими островами. Вони взаємодіють, але **інтеграція не повинна скасовувати ownership**.

## Головний принцип

```text
Consumer Domain
    ↓
explicit contract / event
    ↓
Provider-owned capability
```

Поганий shortcut:

```text
Sales
→ SELECT * FROM property_tables
→ випадково стає співвласником Property model
```

Або навпаки. Прямий доступ іноді виглядає швидким, аж поки одна зміна schema не перетворює bounded contexts на комунальну квартиру.

## Що саме треба захищати

Cross-domain boundary має зберігати:

- data ownership;
- vocabulary ownership;
- lifecycle ownership;
- mutation authority;
- tenant isolation;
- compatibility semantics;
- failure semantics.

## Основні integration patterns

### 1. Synchronous contract / port

Використовуємо, коли consumer потребує конкретну відповідь під час поточного use case.

```text
Consumer
   ↓
Port / Interface
   ↓
Adapter
   ↓
Provider capability / read model
```

Contract має віддавати рівно те, що потрібно consumer-у, а не весь provider aggregate «про всяк випадок».

### 2. Domain Event

Використовуємо, коли consumer реагує на вже здійснений business fact.

```text
Provider state change
   ↓
Provider Event + Outbox
   ↓
Consumer subscription
   ↓
consumer-owned reaction
```

Event не є remote command. `something.changed` не повинно означати приховане «зроби мені ось це».

### 3. Read projection

Для dashboards/search/analytics може існувати dedicated projection, сформована з provider-owned data/events.

Projection може дублювати read data, але не переносить business authority до consumer-а.

### 4. Integration adapter

External providers, legacy tables або чужі APIs проходять translation boundary.

```text
External vocabulary
   ↓
Adapter
   ↓
COS contract / canonical vocabulary
```

## AS-IS example: Property ↔ Sales presentation

Поточний `COS` має explicit contract:

```text
Domains\Property\Application\Contract\PresentationSalesInterface
```

Він дозволяє presentation workflow:

- отримати active client case;
- записати share interaction.

Contract methods:

```text
activeClientCase(caseId)
recordShare(caseId, personId, userId, title, body, propertyId, matchNote)
```

Це хороший приклад того, що інтеграція вже названа й винесена в boundary замість прихованого виклику з presentation code.

Водночас це **не означає**, що поточний Property `0.1.1` вже має універсальну cross-domain platform або Sales-level runtime module. Документуємо рівно те, що існує.

## Ownership example

У цьому сценарії:

```text
Property Presentation
    потребує Sales context

Sales
    володіє ClientCase semantics

Property
    не копіює ClientCase model у себе
```

Так само Sales не повинен ставати власником canonical Property state лише через те, що deal посилається на property.

## Mutation rule

Consumer не повинен напряму мутувати provider persistence.

Preferred path:

```text
Consumer intent
    ↓
Provider-owned contract / command / use case
    ↓
Provider invariants
    ↓
Provider persistence
    ↓
Provider Event
```

## Read rule

Cross-domain read повинен мати explicit purpose.

Питання перед додаванням contract method:

1. Який consumer use case його потребує?
2. Хто володіє даними?
3. Чи потрібен full object, чи достатньо projection/reference?
4. Які tenant/security constraints?
5. Що відбудеться, якщо provider недоступний/не знайде entity?
6. Чи не створюємо ми duplicate canonical model?

## Events vs calls

Вибираємо synchronous call, коли:

- відповідь потрібна зараз;
- операція не може продовжитися без provider result.

Вибираємо Event, коли:

- факт уже стався;
- consumer може реагувати окремо;
- coupling у часі не потрібний.

Не треба перетворювати Event Bus на повільний RPC лише тому, що слово `event-driven` виглядає сучасно.

## Forbidden dependencies

За замовчуванням небажані:

```text
Domain A → Domain B concrete repository
Domain A → Domain B SQL tables
Domain A → Domain B framework model
Domain A → Interface/Web controller
Domain A → external provider SDK directly
```

Допустима залежність має бути оформлена через contract, event vocabulary або explicit integration adapter.

## Bootstrap

Concrete cross-domain composition належить composition root/bootstrap layer.

```text
Domain contract
    ↓
Bootstrap binds adapter
    ↓
Runtime consumer receives dependency
```

Domain не повинен сам шукати concrete implementation через глобальний container.

## Documentation contract

Коли додається cross-domain interaction, documentation повинна зафіксувати:

```text
Consumer
Provider / authority owner
Business purpose
Contract / Event
Data exposed
Mutation authority
Failure behavior
Code map
```

## AS-IS / TARGET

AS-IS examples мають підтверджуватися `COS` code/tests.

TARGET architecture можна описувати окремо, але не можна підміняти нею поточні dependencies. Архітектурна діаграма не стає executable лише тому, що стрілки в ній дуже прямі.

## Code map

Поточний explicit example:

```text
app/Domains/Property/Application/Contract/PresentationSalesInterface.php
app/Domains/Property/Infrastructure/Presentation
app/Domains/Sales
app/Bootstrap
```
