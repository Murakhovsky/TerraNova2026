---
title: Capital Markets: Market Source → Trusted Market State
description: AS-IS workflow для raw evidence, canonical normalization, deterministic data quality та current MarketState.
status: active
updated: 2026-10-06
kind: workflow
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

## Інваріанти

Raw evidence зберігається **до** decoding/normalization. Помилка provider payload або unknown instrument не повинна знищувати вхідний evidence і не повинна мутувати current state.

Canonical event використовує internal `InstrumentId`, optional `VenueId`, source/received/processed timestamps, sequence, schema version, data mode і canonical observation payload.

Quality/trust визначаються deterministic rules. AI/LLM не бере участі у freshness, ordering, sequence continuity, crossed market, clock reliability або trust decisions.

Duplicate canonical fingerprint є idempotent. Out-of-order event може залишитися в history, але не має права відкотити current MarketState.

## Runtime boundaries

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
