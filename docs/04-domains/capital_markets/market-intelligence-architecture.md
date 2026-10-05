---
title: Архітектура Market Intelligence
description: Канонічний pipeline CM-MARKET-INTELLIGENCE для source adapters, raw/canonical events, data quality, Market State, persistence та high-frequency runtime.
status: active
updated: 2026-10-06
kind: architecture
---

# Архітектура Market Intelligence

## Мета та межі

`CM-MARKET-INTELLIGENCE` будує trusted real-time market state поверх `CM-FOUNDATION`. Пакет приймає зовнішні observations, зберігає raw evidence, нормалізує provider semantics у canonical events, оцінює quality/freshness і детерміновано оновлює поточний Market State.

Пакет не містить Opportunity Engine, Strategy Engine, Risk Engine, Portfolio, Orders, Paper Trading, Live Trading, Execution або profit calculation.

## Канонічний pipeline

```text
Venue / Provider
        ↓
Transport
        ↓
Infrastructure/MarketData/Adapter
        ↓
RawMarketEvent
        ↓
MarketDataNormalizer
        ↓
CanonicalMarketEvent
        ↓
MarketDataQualityEngine
        ↓
MarketStateEngine / ReferenceMarketStateEngine
        ↓
Trusted / Degraded / Stale / Untrusted / Unavailable state
        ↓
Current State persistence + historical persistence + API/UI
```

Provider names та provider payload semantics не можуть потрапляти в `Domains\CapitalMarkets\Domain`.

## Source model

Кожен source має:

- `MarketSourceId`;
- optional `VenueId`;
- `adapterType`;
- enabled/priority;
- одну або декілька ролей: `TRADING_SOURCE`, `REFERENCE_SOURCE`, `VALIDATION_SOURCE`, `HISTORICAL_SOURCE`, `FX_SOURCE`;
- `credentialsReference`, але не secret material;
- typed rate-limit, reconnect та health policies;
- metadata.

Credential material вирішується тільки через Platform Credential Vault.

## Adapter contracts

Foundation `VenueAdapterInterface` не роздувається. Market Intelligence додає окремі contracts:

```text
MarketDataAdapterInterface
StreamingMarketDataAdapterInterface
HistoricalMarketDataAdapterInterface
ReferenceDataAdapterInterface
ConversionRateProviderInterface
```

Capability discovery є обов'язковим. Application layer не припускає, що source підтримує BBO, ORDER_BOOK або STREAMING.

## Deterministic financial math

Binary floating-point arithmetic у Capital Markets Domain заборонена. `DecimalMath` виконує add/subtract/multiply/divide/midpoint/basis-points над explicit base-10 strings.

`MarketQuote` обчислює:

```text
mid_price
spread_absolute
spread_bps
```

без float.

## Time semantics

Кожен canonical event містить три різні timestamps:

```text
source_timestamp
received_timestamp
processed_timestamp
```

Внутрішній standard — UTC. Latency та age обчислюються з microsecond timestamps як integer milliseconds.

## Quality та trust

`MarketDataQualityEngine` використовує лише deterministic rules.

Основні inputs:

- source connection health;
- clock reliability;
- event age;
- processing latency;
- duplicate fingerprint;
- source timestamp ordering;
- sequence ordering;
- crossed BBO;
- spread plausibility;
- price jump plausibility;
- optional converted reference deviation.

Основний trust status:

```text
TRUSTED
DEGRADED
STALE
UNTRUSTED
UNAVAILABLE
```

Quality score 0–100 існує для UI, але decision logic не повинна використовувати score замість flags/status.

## Order book

`ORDER_BOOK_SNAPSHOT` та `ORDER_BOOK_DELTA` є різними event types.

Delta ніколи не застосовується без валідного snapshot. При sequence gap або invalid book current book скидається і потребує resync. Zero quantity у delta означає видалення price level.

Provider-specific sequence semantics задаються policy. Наприклад, feed з monotonic-but-not-consecutive sequence не можна помилково трактувати як gap.

## Market State

Trading `MarketState` keyed мінімально:

```text
venue_id + instrument_id
```

і містить:

- source provenance;
- last trade;
- best quote;
- derived mid/spread;
- current order book;
- volume;
- market status;
- source/update timestamps;
- latency;
- quality/trust;
- `state_version`;
- last sequence/fingerprint.

Reference feeds мають окремий `ReferenceMarketState` із session context, last regular quote, last extended quote, reference age та reference type.

## Currency comparison

Native quote asset ніколи не втрачається. AAPLX/USDT не порівнюється напряму з AAPL/USD.

Cross-currency comparison дозволена тільки після valid `ConversionRateProviderInterface` result. Якщо conversion rate відсутній або untrusted, comparison layer повинна повернути `NOT_COMPARABLE`, а не fake spread.

## Backpressure

High-frequency market data не маршрутизується tick-for-tick у глобальний COS Event Bus.

При queue pressure:

- BBO/quote/current scalar state може coalesce до latest;
- trades зберігаються;
- order-book delta відхиляється, якщо continuity більше не можна гарантувати, після чого потрібен snapshot resync;
- business-significant state transitions можуть публікуватися через Kernel EventBus.

## Persistence decision V1

Поточний COS runtime канонічно використовує MySQL 8.4. Для Market Intelligence V1 не додається окремий PostgreSQL/Timescale cluster лише заради самого факту існування time-series.

Persistence буде розділена на окремі таблиці:

```text
market sources / subscriptions
raw events
canonical events
current trading states
current reference states
quality metrics
market snapshots
historical candles / aggregates
```

High-volume extraction у PostgreSQL/TimescaleDB залишається дозволеним майбутнім scaling step через repository contracts.

## Retention policy

Retention не є Domain invariant. V1 runtime config підтримує hot/warm/archive policy окремо для raw і canonical events.

Operational defaults для першого production slice:

- raw events: короткий hot retention для debugging/replay;
- canonical events: довший research/backtest retention;
- current state: latest only;
- aggregates: довгострокові 1s/1m/5m/1h series за потреби.

Точні durations задаються deployment/configuration, а не hardcode у Domain Core.

## Перший production slice

Перший target після core runtime:

```text
Bybit xStocks Spot
AAPLX/USDT
        ↕
AAPL/USD
Massive U.S. Stocks
```

Bybit є `TRADING_SOURCE`, Massive — `REFERENCE_SOURCE`.

Provider adapters живуть тільки в `Infrastructure/MarketData/Adapter`. Реальна streaming connectivity та source credentials увімкнені окремими feature flags/config і не потрібні для pure-domain tests.

## Acceptance core

Core вважається готовим, коли automated tests підтверджують:

1. exact decimal mid/spread/bps;
2. source/capability vocabularies;
3. freshness і latency;
4. crossed-market detection;
5. duplicate/out-of-order handling;
6. snapshot/delta order-book rebuild;
7. MarketState versioning;
8. ReferenceMarketState session preservation;
9. backpressure decisions;
10. відсутність provider-specific коду та float у Domain.
