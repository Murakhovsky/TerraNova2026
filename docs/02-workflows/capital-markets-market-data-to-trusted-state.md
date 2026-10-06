---
title: Market Source → Trusted Market State
description: AS-IS workflow для raw evidence, canonical normalization, deterministic data quality та current MarketState.
status: active
updated: 2026-10-06
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: capital-markets.market-data-to-trusted-state
---

# Market Source → Trusted Market State

## Мета

Цей workflow є canonical executable process для `CM-MARKET-INTELLIGENCE`.

```text
Market Source
    ↓
RawMarketEvent
    ↓
Decode / Resolve Instrument / Normalize
    ↓
CanonicalMarketEvent
    ↓
MarketDataQualityEngine
    ↓
MarketState / ReferenceMarketState
```

## Бізнес-мета

Перетворювати зовнішні ринкові спостереження на відтворюваний і контрольований стан ринку, якому система може довіряти або явно не довіряти. Результат процесу використовується наступними аналітичними пакетами, але сам процес не створює торгових рішень і не виконує операцій з капіталом.

## Учасники

- адаптер зовнішнього джерела ринкових даних;
- середовище виконання `CM-MARKET-INTELLIGENCE`;
- оператор Capital Markets, який налаштовує джерела, підписки та ручний запуск отримання даних.

## Карта коду

| Етап | Канонічна реалізація |
| --- | --- |
| Приймання raw evidence | `app/Domains/CapitalMarkets/Application/Service/MarketDataIngestionService.php` |
| Нормалізація | `app/Domains/CapitalMarkets/Application/Service/MarketDataNormalizer.php` |
| Оцінка якості | `app/Domains/CapitalMarkets/Domain/Service/MarketDataQualityEngine.php` |
| Поточний стан торгового ринку | `app/Domains/CapitalMarkets/Domain/Service/MarketStateEngine.php` |
| Поточний reference state | `app/Domains/CapitalMarkets/Domain/Service/ReferenceMarketStateEngine.php` |
| Збереження raw/canonical history | `app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/` |
| Реєстрація процесу | `resources/processes/capital-markets-market-data-to-trusted-state.json` |

## Тригер

Увімкнене джерело Capital Markets повертає або передає ринкове спостереження для налаштованої підписки на інструмент.

## Процес

<ProcessDiagram process-id="capital-markets.market-data-to-trusted-state" />

Діаграма генерується з канонічного Process Registry і відображає фактичний `as-is` шлях від raw evidence до поточного стану ринку.

## Представлення відповідальності

<ProcessDiagram process-id="capital-markets.market-data-to-trusted-state" view="ownership" direction="LR" />

Адаптер володіє лише provider-specific transport і decoding. Market Intelligence runtime володіє нормалізацією, quality/trust та оновленням current state. Оператор керує конфігурацією джерел і підписок, але не підміняє deterministic quality rules.

## Представлення доменів

<ProcessDiagram process-id="capital-markets.market-data-to-trusted-state" view="domain" direction="LR" />

Поточний процес є внутрішнім процесом Capital Markets. Platform надає спільні primitives для credentials, transactions, feature flags та audit, але не володіє фінансовою семантикою.

## Представлення можливостей

<ProcessDiagram process-id="capital-markets.market-data-to-trusted-state" view="capability" direction="LR" />

Кроки процесу прив'язані до окремих capabilities перегляду, керування, quality та history, без надання торгової execution authority.

## Інваріанти

Raw evidence зберігається **до** decoding/normalization. Помилка provider payload або unknown instrument не повинна знищувати вхідний evidence і не повинна мутувати current state.

Canonical event використовує internal `InstrumentId`, optional `VenueId`, source/received/processed timestamps, sequence, schema version, data mode і canonical observation payload.

Quality/trust визначаються deterministic rules. AI/LLM не бере участі у freshness, ordering, sequence continuity, crossed market, clock reliability або trust decisions.

Duplicate canonical fingerprint є idempotent. Out-of-order event може залишитися в history, але не має права відкотити current MarketState.

## Межі середовища виконання

- provider transport і provider payload decoding живуть поза Domain;
- `MarketDataNormalizer` не містить `if provider == ...`;
- current trading state keyed мінімально через `venue + instrument`;
- reference state keyed через `source + instrument`;
- high-frequency tick path не публікує кожен tick у глобальний EventBus;
- EventBus отримує лише business-significant trust transitions та ingestion incidents.

## Режими

Canonical pipeline підтримує:

- `LIVE`
- `DELAYED`
- `HISTORICAL`
- `REPLAY`

Replay повинен проходити через ті самі normalization, quality та state semantics, що й live ingestion.
