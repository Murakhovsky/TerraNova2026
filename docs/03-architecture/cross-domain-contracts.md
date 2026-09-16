---
title: Міждоменні контракти
description: Правила взаємодії bounded contexts у COS без розмивання ownership та прямого доступу до чужого стану.
status: active
updated: 2026-09-16
kind: architecture
---

# Міждоменні контракти

Domains у COS не є ізольованими островами. Вони взаємодіють, але **інтеграція не скасовує ownership**.

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

Прямий доступ іноді виглядає швидшим, аж поки одна зміна schema не перетворює bounded contexts на комунальну квартиру.

## Що захищає межа

Cross-domain boundary має зберігати:

- data ownership;
- vocabulary ownership;
- lifecycle ownership;
- mutation authority;
- tenant isolation;
- compatibility semantics;
- failure semantics.

## Канонічні способи взаємодії

### Synchronous contract / port

Використовується, коли consumer потребує конкретну відповідь у межах поточного use case.

```text
Consumer Domain
   ↓ requires contract
Port / Interface
   ↓
Adapter / provider boundary
   ↓
Provider capability / read model
```

Contract має віддавати рівно те, що потрібно consumer-у, а не весь provider aggregate «про всяк випадок».

### Domain Event

Використовується, коли consumer реагує на вже здійснений business fact.

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

### Read projection

Для dashboards/search/analytics може існувати dedicated projection, сформована з provider-owned data/events.

Projection може дублювати read data, але не переносить business authority до consumer-а.

### Integration adapter

External providers, legacy tables або чужі APIs проходять translation boundary.

```text
External vocabulary
   ↓
Adapter
   ↓
COS contract / canonical vocabulary
```

## Executable authority

Поточні synchronous cross-domain dependencies декларуються в `contributions.cross_domain_contracts` у `app/Domains/*/module.php`.

Kernel зберігає typed declaration через `CrossDomainContract`; manifests залишаються джерелом істини для того, хто **requires** або **provides** contract і з яким counterpart Domain він пов’язаний.

Це важливіше за ручну діаграму: architecture view може застаріти, manifest має пройти executable checks.

## Поточні AS-IS межі

### Sales → Property

Sales `0.8.6` декларує:

```text
Domains\Property\Contract\PropertyReferencePort
role: requires
counterpart: property
kind: synchronous_port
```

Призначення: отримувати canonical Property references усередині Sales workflows без ownership над Property state.

```text
Sales process
    ↓ requires
PropertyReferencePort
    ↓
Property / property.reference
    ↓
Sales-owned case / match / activity
```

Саме цей boundary використовується в cross-domain process `sales.request-to-property-match`.

### Property → Sales

Property `0.12.0` декларує:

```text
Domains\Property\Application\Contract\PresentationSalesInterface
role: requires
counterpart: sales
kind: synchronous_port
```

Призначення: використовувати Sales-owned client-case/share context у Property presentation workflows.

Property не копіює ClientCase model у себе і не стає власником Sales lifecycle.

### Property → Spatial

Property також декларує:

```text
Domains\Spatial\Application\Contract\PropertyTourPublisherInterface
role: provides
counterpart: spatial
kind: integration_adapter
```

Це explicit boundary для publication canonical Property tour data через Spatial-owned integration surface.

## Ownership rule

Contract не створює shared ownership.

```text
Consumer intent
    ↓
Declared contract
    ↓
Provider-owned operation / facts
    ↓
Consumer-owned result or relationship
```

Sales може використати Property facts, але Asset / Inventory / Listing не стають Sales entities. Property може використати Sales context, але ClientCase не стає Property entity.

## Mutation rule

Consumer не мутує provider persistence напряму.

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

Перед додаванням contract method потрібно відповісти:

1. Який consumer use case його потребує?
2. Хто володіє даними?
3. Чи потрібен full object, чи projection/reference?
4. Які tenant/security constraints?
5. Що відбудеться, якщо provider недоступний або entity не знайдена?
6. Чи не створюється duplicate canonical model?

## Events чи synchronous call

Synchronous call доречний, коли:

- відповідь потрібна зараз;
- use case не може продовжитися без provider result.

Event доречний, коли:

- факт уже стався;
- consumer може реагувати окремо;
- coupling у часі не потрібний.

Не треба перетворювати Event Bus на повільний RPC лише тому, що слово `event-driven` добре виглядає на схемі.

## Заборонені залежності

За замовчуванням неприпустимі:

```text
Domain A → Domain B concrete repository
Domain A → Domain B SQL tables
Domain A → Domain B framework model
Domain A → Interface/Web controller
Domain A → external provider SDK directly
```

Допустима залежність оформлюється через contract, event vocabulary, projection або explicit integration adapter.

## Composition root

Concrete cross-domain composition належить Bootstrap/composition layer.

```text
Domain contract
    ↓
Bootstrap binds adapter
    ↓
Runtime consumer receives dependency
```

Domain не шукає concrete implementation через глобальний container і не тягне Web/session context у background runtime.

## Documentation contract

Коли додається cross-domain interaction, документація має зафіксувати:

```text
Consumer Domain
Provider / authority owner
Business purpose
Contract / Event
Data exposed
Mutation authority
Failure behavior
Tenant semantics
Code map
```

А Process Registry для реального cross-domain step додатково має підтвердити foreign Domain capability та verified `requires` boundary.

## Карта коду

```text
app/Kernel/Module/CrossDomainContract.php
app/Kernel/Module/ModuleContributions.php
app/Domains/Sales/module.php
app/Domains/Property/module.php
app/Domains/Property/Contract/PropertyReferencePort.php
app/Domains/Property/Application/Contract/PresentationSalesInterface.php
app/Domains/Spatial/Application/Contract/PropertyTourPublisherInterface.php
app/Bootstrap
```
