---
title: Огляд домену Capital Markets
description: CM-FOUNDATION + CM-MARKET-INTELLIGENCE для фінансових інструментів, venues, raw/canonical market data, quality і trusted MarketState.
status: active
updated: 2026-10-06
kind: domain
contract: domain-v1
---

# Огляд домену Capital Markets

Capital Markets `0.3.0` поєднує **CM-FOUNDATION** та перший executable **CM-MARKET-INTELLIGENCE** runtime. Домен уже вміє не тільки зберігати структуру фінансових інструментів і venues, а й приймати raw market observations, нормалізувати їх, оцінювати якість та підтримувати current MarketState.

## Призначення

```text
Instrument / Relationship / Venue Registry
                ↓
Market Source Configuration
                ↓
RawMarketEvent
                ↓
CanonicalMarketEvent
                ↓
Quality / Freshness / Trust
                ↓
MarketState / ReferenceMarketState
```

Мета Market Intelligence: система повинна бачити ринок і детерміновано знати, чи можна довіряти конкретному observation/state.

## Поточний стан

```text
id: capital_markets
version: 0.3.0
runtime: Foundation + Market Intelligence core
persistence: tenant-scoped structural + raw/canonical/current-state data
process: capital-markets.market-data-to-trusted-state
data modes: LIVE / DELAYED / HISTORICAL / REPLAY
provider adapters: next wave
execution: none
```

Модуль вимкнений за замовчуванням. Market-data master/history flags seed-яться керовано, streaming і provider-specific flags залишаються вимкненими до реального source cutover.

## Межі

Capital Markets володіє фінансовою предметною моделлю, market-data semantics, data quality та current market state. Він не дублює authentication, IAM, Audit storage, Feature Flag runtime, Agent Runtime, Queue або Approval.

`Kernel\Shared\Domain\Money` лишається канонічним Money. Інші фінансові значення використовують explicit decimal strings без binary float.

Provider-specific adapters, WebSocket/HTTP transport і credential material не потрапляють у Domain Core. Generic normalizer та quality engine не знають назв бірж або data providers.

Market Intelligence не створює opportunity, strategy, position, order, portfolio або execution runtime.

- [Foundation Architecture](./foundation-architecture.md)
- [Market Intelligence Architecture](./market-intelligence-architecture.md)
- [Market Intelligence workflow](../../02-workflows/capital-markets-market-data-to-trusted-state.md)
- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Дозволи та capabilities](../../12-reference/permissions-capabilities.md)
