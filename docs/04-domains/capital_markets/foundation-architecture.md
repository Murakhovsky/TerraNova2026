---
title: Архітектура фундаменту Capital Markets
description: Архітектурні межі CM-FOUNDATION, модель домену, tenant-isolation, persistence, platform integration, API/UI та acceptance-критерії.
status: active
updated: 2026-10-05
kind: architecture
---

# Архітектура фундаменту Capital Markets

## Межі та bounded context

Пакет `CM-FOUNDATION` створює автономний bounded context `Domains\CapitalMarkets`. Він володіє identity та класифікацією фінансових інструментів, економічними зв’язками, описами venues і фінансовими value semantics. Він не володіє користувачами COS, tenants, authentication, глобальним audit storage, глобальним feature-flag storage, queues, approvals або agent runtime.

Пакет навмисно не містить exchange SDK, HTTP/WebSocket market feed, strategy, opportunity engine, portfolio, order, trade, backtest, paper execution або live execution.

## Структура

```text
app/Domains/CapitalMarkets/
├── Domain/
│   ├── Instrument/
│   ├── Venue/
│   ├── Value/
│   ├── Event/
│   └── Contract/
├── Application/
│   ├── Command/
│   ├── Query/
│   ├── Contract/
│   ├── Feature/
│   ├── Audit/
│   └── Service/
├── Infrastructure/
│   └── Persistence/MySql/
└── Model/

symfony/src/Http/Api/V1/Controller/CapitalMarketsController.php
symfony/src/Web/CapitalMarkets/
symfony/templates/experience/capital_markets/
```

Каталоги додаються лише тоді, коли в них існує виконуваний код.

## Доменна модель та інваріанти

Внутрішні ID інструментів стабільні й не залежать від конкретного provider. Зовнішні identifiers типізовані (`TICKER`, `ISIN`, `CUSIP`, `FIGI`, `EXCHANGE_SYMBOL`, `CONTRACT_ADDRESS`, `PROVIDER_ID`) і можуть мати many-to-one зв’язок з інструментом.

Зв’язки є напрямленими. `AAPLx REPRESENTS AAPL` не створює автоматично зворотного edge. Self-relations заборонені. Обхід graph обмежений depth 1–5 і відстежує visited nodes, щоб цикли не створювали нескінченний обхід.

Фінансовий core використовує явні base-10 decimal strings. Binary floating point заборонений у `Domains\CapitalMarkets\Domain`. Rates зберігаються як decimal fractions, тому 8% представлено як `0.08`, а не неоднозначне `8`.

Metadata для instrument і venue мають бути JSON-compatible та обмежені 16 KiB на рівні domain model.

## Persistence та ізоляція tenant

Кожна mutable таблиця Capital Markets має organization scope. Repository contracts завжди вимагають `organizationId`, а MySQL implementations повинні включати `organization_id` у reads, writes, unique constraints та foreign-key identity.

Канонічні таблиці:

```text
tn_capital_market_instruments
tn_capital_market_instrument_identifiers
tn_capital_market_relationships
tn_capital_market_pairs
tn_capital_market_venues
tn_capital_market_venue_capabilities
tn_capital_market_venue_instruments
capital_market_user_capabilities
```

ORM або query builder не перетинають межу Domain.

## Інтеграція з платформою

Authentication і tenant context надходять через `Kernel\Tenant\Contract\TenantContextProviderInterface`. Permissions надаються через `CapitalMarketsAccessControlInterface`. Audit records записуються через `Platform\Audit\Service\AuditRecorder`. Feature evaluation використовує Platform Feature Flag contracts; Capital Markets володіє лише власним vocabulary flags та seed definitions.

Mutating HTTP surfaces вимагають tenant context, enabled state модуля, granular Capital Markets capability та CSRF validation.

Foundation mutations обгорнуті спільним `TransactionManagerInterface`. Business state, Platform Audit і persistence через `Kernel\Event\EventBus` беруть участь в одній database transaction; repository-local transactions поступаються вже активній application transaction.

Domain events адаптуються до канонічного `Kernel\Event\EventBus`, який зберігає їх у COS event storage та durable `cos_event_outbox`. External integration outbox не використовується для внутрішніх domain events.

## API та інтерфейс

Канонічний API використовує наявну COS-стратегію та розміщується під `/api/v1/capital-markets/*`. Основна workspace-точка входу: `/capital-markets`, а Foundation sections: Instruments, Relationships і Venues. Trading dashboard або вигадані market metrics відсутні.

## Стратегія міграції та майбутнього виділення

Foundation є additive schema migration від module schema `0.1.0` до `0.2.0`. Жодна існуюча таблиця інших business domains не перепрофільовується. Capital Markets можна надалі виділити в окремий сервіс, перенісши його organization-scoped tables і зберігши repository/platform contracts.

## Тести та acceptance

Architecture tests перевіряють isolation домену та правило NO FLOAT. Unit tests покривають value objects, controlled taxonomies, status rules, напрямленість і цикли graph та event schemas. Integration/contract tests перевіряють migration constraints, tenant-scoped repository SQL, permissions, feature flags, routes та UI empty states.

Acceptance slice: створити AAPL, створити AAPLx, створити `AAPLx REPRESENTS AAPL`, створити venue, зареєструвати AAPLx на цьому venue і прочитати ту саму структуру через UI та API. Market data навмисно залишається недоступною.
