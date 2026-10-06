# Capital Markets Domain

`CapitalMarkets` is an autonomous COS bounded context for canonical financial instruments, economic relationships, venues and trusted market intelligence.

Version `0.3.0` contains **CM-FOUNDATION + CM-MARKET-INTELLIGENCE runtime**. The module remains disabled by default. It can persist raw market evidence, normalize provider observations into canonical events, evaluate deterministic data quality and maintain trading/reference MarketState. It still does not run strategies, calculate opportunities, manage portfolios, place orders or execute Paper/Live Trading.

## Domain ownership

Capital Markets owns:

- canonical financial instrument identity through `InstrumentDescriptor`;
- typed external identifiers and controlled instrument families;
- directed economic relationships and graph traversal;
- `MarketPair` and `InstrumentBasket` primitives;
- venue identity, venue capabilities and venue/instrument mappings;
- deterministic decimal primitives for `Price`, `Quantity`, `Rate` and `Percentage`;
- provider-neutral market-data source/configuration vocabulary;
- raw and canonical market event contracts;
- deterministic quality, freshness, ordering and trust rules;
- trading `MarketState` and session-aware `ReferenceMarketState`;
- tenant-scoped persistence for structural and market intelligence data;
- audited structural mutations and significant versioned domain events.

Capital Markets does not own authentication, tenant identity, generic audit storage, global feature-flag storage, queues, approvals, notifications, agent runtime or other COS platform capabilities.

## Deterministic finance rule

`Kernel\Shared\Domain\Money` is reused for three-letter money values. Capital Markets does **not** create another Money class.

`Price`, `Quantity`, `Rate`, `Percentage` and Market Intelligence arithmetic use explicit decimal strings. Binary floating-point arithmetic is forbidden in the Domain layer.

## Market Intelligence pipeline

```text
Venue / Provider
        ↓
provider-specific adapters
        ↓
RawMarketEvent
        ↓
Decode + Instrument Resolution + Normalization
        ↓
CanonicalMarketEvent
        ↓
MarketDataQualityEngine
        ↓
MarketState / ReferenceMarketState
```

Raw provider evidence is stored before normalization. Unknown instruments and malformed provider values do not mutate current state.

Duplicate canonical fingerprints are idempotent. Out-of-order events may remain in history but cannot regress current state. Order-book continuity is controlled by explicit per-event sequence policies instead of assuming every provider sequence is contiguous.

Supported data modes are `LIVE`, `DELAYED`, `HISTORICAL` and `REPLAY`.

## Provider boundary

Provider-specific adapters, transport clients, credential resolution and payload decoding belong outside the Domain model.

Generic `MarketDataNormalizer`, quality rules and MarketState engines do not branch on provider names.

Source configuration stores only `credentials_reference`; secret material remains in the Platform credential boundary.

## Persistence

Structural tables:

- `tn_capital_market_instruments`
- `tn_capital_market_instrument_identifiers`
- `tn_capital_market_relationships`
- `tn_capital_market_pairs`
- `tn_capital_market_venues`
- `tn_capital_market_venue_capabilities`
- `tn_capital_market_venue_instruments`
- `capital_market_user_capabilities`

Market Intelligence tables:

- `tn_capital_market_data_sources`
- `tn_capital_market_source_health`
- `tn_capital_market_subscriptions`
- `tn_capital_market_raw_events`
- `tn_capital_market_canonical_events`
- `tn_capital_market_quality_metrics`
- `tn_capital_market_states`
- `tn_capital_market_reference_states`
- `tn_capital_market_snapshots`
- `tn_capital_market_data_gaps`

Every business row and permission row is organization-scoped.

## API and UI

Foundation endpoints remain under `/api/v1/capital-markets/*`, with the operator workspace under `/capital-markets/*`.

Market Intelligence now adds:

- operator workspace: `/capital-markets/market-data`;
- read dashboard: `GET /api/v1/capital-markets/market-data`;
- source create/enable/disable;
- source subscriptions;
- manual provider poll;
- source health, trading MarketState and ReferenceMarketState visibility.

New sources are created disabled. Mutations require tenant context, Capital Markets capabilities, Market Data feature gates and CSRF. The UI contains no order, position or execution controls.

## Safety posture

The module contains no `Order`, `Position`, `Portfolio` or execution runtime.

Paper Trading, Live Trading and Auto Execution feature flags remain separate promotion gates and stay disabled. Market Intelligence is read-only with respect to capital and order placement.

## Canonical process

The first executable Capital Markets process is:

```text
Market Source
  → Raw Evidence
  → Canonical Event
  → Quality / Trust
  → Current MarketState
```

See `resources/processes/capital-markets-market-data-to-trusted-state.json`.

## Provider slice

The first external read-only connectors are implemented:

- Bybit V5 Spot REST ticker adapter for trading-source BBO + 24h volume;
- Massive U.S. Stocks REST NBBO reference adapter;
- tenant-safe credential resolution through Platform Credential Vault;
- provider-specific decoders behind the generic raw → canonical pipeline;
- generic polling service and CLI entrypoint.

Provider flags remain disabled by default. Streaming remains disabled until the WebSocket lifecycle/resubscription/recovery wave is implemented.

## Next packs

1. Replay/backfill/gap recovery.
2. Tokenized Equity comparison slice.
3. Bybit/Massive streaming connectors.
4. Spot/Perpetual vertical slice.
5. Research Lab and hypothesis registry.
6. Portfolio, ledger and governed agents.
7. Limited Live only after promotion gates.
