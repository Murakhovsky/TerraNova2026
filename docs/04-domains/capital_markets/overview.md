---
title: Огляд домену Capital Markets
description: CM-FOUNDATION + CM-MARKET-INTELLIGENCE + CM-TOKENIZED-EQUITY для фінансових інструментів, trusted MarketState, H1/H2 research та guarded paper execution.
status: active
updated: 2026-10-06
kind: domain
contract: domain-v1
---

# Огляд домену Capital Markets

Capital Markets `0.4.0` поєднує **CM-FOUNDATION**, executable **CM-MARKET-INTELLIGENCE** та перший фінансовий vertical slice **CM-TOKENIZED-EQUITY**. Домен уже вміє не тільки зберігати структуру фінансових інструментів і venues, а й приймати raw market observations, нормалізувати їх, оцінювати якість та підтримувати current MarketState.

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
version: 0.4.0
runtime: Foundation + Market Intelligence + Tokenized Equity H1/H2 research/paper
persistence: tenant-scoped structural + raw/canonical/current-state data
process: capital-markets.market-data-to-trusted-state
data modes: LIVE / DELAYED / HISTORICAL / REPLAY
provider adapters: Bybit Spot REST + Massive Stocks REST
provider polling: CLI + operator API/UI
operator workspace: /capital-markets/market-data + /capital-markets/tokenized-equities
streaming: disabled / next wave
execution: guarded H2 paper only; H1 research-only; live disabled
```

Модуль вимкнений за замовчуванням. Bybit Spot REST та Massive Stocks REST adapters уже реалізовані за provider-neutral contracts. Операторський Market Data workspace керує Sources, Subscriptions, Health та ручним Poll. Нові sources створюються disabled. Provider-specific flags і streaming flag залишаються вимкненими до tenant source configuration та production cutover.

## Межі

Capital Markets володіє фінансовою предметною моделлю, market-data semantics, data quality та current market state. Він не дублює authentication, IAM, Audit storage, Feature Flag runtime, Agent Runtime, Queue або Approval.

`Kernel\Shared\Domain\Money` лишається канонічним Money. Інші фінансові значення використовують explicit decimal strings без binary float.

Provider-specific adapters, WebSocket/HTTP transport і credential material не потрапляють у Domain Core. Generic normalizer та quality engine не знають назв бірж або data providers.

Market Intelligence сам по собі не створює trading decisions. Окремий CM-TOKENIZED-EQUITY layer споживає trusted MarketState, створює H1/H2 candidates/opportunities, застосовує deterministic risk і дозволяє лише guarded paper execution для H2.

- [Foundation Architecture](./foundation-architecture.md)
- [Market Intelligence Architecture](./market-intelligence-architecture.md)
- [Market Intelligence workflow](../../02-workflows/capital-markets-market-data-to-trusted-state.md)
- [Tokenized Equity workflow](../../02-workflows/capital-markets-tokenized-equity-paper-cycle.md)
- [Tokenized Equity vertical slice](./tokenized-equity-vertical-slice.md)
- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Дозволи та capabilities](../../12-reference/permissions-capabilities.md)
