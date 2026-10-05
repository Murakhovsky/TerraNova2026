# Capital Markets Domain

`CapitalMarkets` is an autonomous COS bounded context for researching, validating and eventually executing capital-allocation opportunities across financial markets.

Version `0.1.0` is the **Architecture Foundation Pack**. It deliberately does not fetch market data, place orders, persist a ledger, run strategies or expose live-trading actions.

## V0.1 ownership

Capital Markets owns:

- canonical financial instrument identity through `InstrumentDescriptor`;
- explicit instrument families rather than one universal financial God Entity;
- economic relationships between instruments;
- market pair and instrument basket primitives;
- venue identity and venue types;
- deterministic decimal primitives for `Price`, `Quantity` and `Rate`;
- Capital Markets capability, feature-flag and audit vocabularies;
- domain facts for instrument, venue and relationship registration.

Capital Markets does not own authentication, authorization runtime, generic audit storage, feature-flag runtime, queues, approvals, notifications or agent infrastructure. Those remain COS platform capabilities.

## Deterministic finance rule

`Kernel\Shared\Domain\Money` is reused for three-letter money values. Capital Markets does **not** create another Money class.

`Price`, `Quantity` and `Rate` are decimal-string based. They never accept floating-point values. This is intentional: accounting, PnL, fees, balances, sizing and hard limits must remain deterministic.

## Instrument model

`InstrumentDescriptor` provides shared identity. Instrument-specific behavior belongs to families:

- Equity
- Tokenized Security
- Crypto Asset
- Stablecoin
- Spot Market
- Perpetual
- Future
- Option
- Fixed Income
- Tokenized Fixed Income
- RWA
- FX
- Commodity
- Index
- Fund

Economic equivalence is represented separately by `EconomicRelationship` and `EconomicRelationshipGraph`; instrument identity must never be collapsed merely because two instruments share exposure.

## Venue model

`VenueDescriptor` identifies Brokers, Stock Exchanges, CEXs, DEXs, AMMs, Perpetual DEXs, Tokenized Securities Venues, RWA Platforms and Data Providers.

Venue-specific connectivity belongs to future adapters. V0.1 contains no exchange SDK or HTTP client.

## Safety posture

The module is disabled by default. The feature vocabulary includes separate paper and live execution flags, and live execution has a distinct permission. V0.1 does not register a runtime module service, action handler or withdrawal capability.

## Next packs

The next implementation packs build on this foundation rather than bypassing it:

1. Market Intelligence and normalized MarketState.
2. Tokenized Equity vertical slice.
3. Spot/Perpetual vertical slice.
4. Research Lab and hypothesis registry.
5. Portfolio, ledger and capital allocation.
6. Governed runtime agents.
7. Decision Workspace.
8. Limited Live only after promotion gates.
