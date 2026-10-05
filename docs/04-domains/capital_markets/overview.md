---
title: Огляд домену Capital Markets
description: Межа V0.1 Capital Markets для фінансових інструментів, економічних зв'язків, venues та детермінованих фінансових primitives.
status: active
updated: 2026-10-05
kind: domain
contract: domain-v1
---

# Огляд домену Capital Markets

Capital Markets `0.1.0` є **Architecture Foundation** автономного фінансового bounded context COS. Поточний slice фіксує предметну мову, identity, invariants і platform contracts, але навмисно не отримує market data, не виконує paper/live trades і не володіє ledger persistence.

## Призначення

```text
InstrumentDescriptor
├─ Instrument Family
├─ Economic Relationship Graph
├─ MarketPair / InstrumentBasket
│
VenueDescriptor
│
Money (Kernel)
Price / Quantity / Rate
```

Домен готує спільний фундамент для Tokenized Securities, Crypto Spot, Crypto Perpetuals і Stablecoins без створення універсальної фінансової God Entity.

## Поточний стан

```text
id: capital_markets
version: 0.1.0
runtime: disabled
persistence: none
market data: none
execution: none
routes: none
process model: explicitly deferred to Market Intelligence
```

Модуль вимкнений за замовчуванням. Окремі `paper` і `live` permissions/feature flags існують як vocabulary майбутніх promotion gates, але жоден execution runtime у V0.1 не зареєстрований.

## Межі

Capital Markets володіє фінансовою предметною моделлю: instruments, economic relationships, venues, research/risk/execution vocabulary та майбутнім portfolio state. Він не дублює authentication, IAM, Queue, Workflow, Approval, Audit storage, Feature Flag runtime, Agent Runtime або іншу COS infrastructure.

`Kernel\Shared\Domain\Money` залишається канонічним money value object. `Price`, `Quantity` і `Rate` використовують explicit decimal strings, щоб фінансовий core не залежав від floating-point арифметики.

Зовнішні біржі, брокери, CEX/DEX та data providers у наступних slices підключаються через adapters; V0.1 не містить provider SDK, HTTP clients або secrets.

- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Дозволи та capabilities](../../12-reference/permissions-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
