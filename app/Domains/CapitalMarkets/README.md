# Capital Markets Domain

\`CapitalMarkets\` is an autonomous COS bounded context for canonical financial instrument identity, economic relationships and venue registry.

Version \`0.2.0\` is the **CM-FOUNDATION Architecture Foundation Pack**. It contains executable registry runtime, tenant-scoped persistence, permissions, feature flags, audit, domain-event outbox, API and operator UI. It deliberately does **not** fetch market data, run strategies, calculate opportunities, manage portfolios, place orders or execute paper/live trades.

## Foundation ownership

Capital Markets owns:

- canonical financial instrument identity through \`InstrumentDescriptor\`;
- typed external identifiers and controlled instrument families;
- explicit status/lifecycle rules for instruments;
- directed economic relationships between instruments;
- bounded relationship graph traversal;
- \`MarketPair\` and \`InstrumentBasket\` primitives;
- venue identity, venue capabilities and venue/instrument mappings;
- deterministic decimal primitives for \`Price\`, \`Quantity\`, \`Rate\` and \`Percentage\`;
- Capital Markets capability and feature-flag vocabularies;
- tenant-scoped repositories and Foundation application boundary;
- audited mutations and durable versioned domain-event envelopes;
- Foundation API and UI for Instruments, Relationships and Venues.

Capital Markets does not own authentication, tenant identity, generic audit storage, global feature-flag storage, queues, approvals, notifications, agent runtime or other COS platform capabilities.

## Deterministic finance rule

\`Kernel\Shared\Domain\Money\` is reused for three-letter money values. Capital Markets does **not** create another Money class.

\`Price\`, \`Quantity\`, \`Rate\` and \`Percentage\` use explicit decimal strings and typed asset/currency codes. Binary floating-point arithmetic is not allowed in the Capital Markets Domain layer.

Rates use one canonical representation: **decimal fraction**. For example, 8% is represented as \`0.08\`.

## Instrument model

\`InstrumentDescriptor\` provides provider-independent identity. External identifiers are typed and stored separately.

Supported families:

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

Economic equivalence is modeled separately by \`EconomicRelationship\`; two instruments never collapse into one merely because they share exposure.

## Venue model

\`VenueDescriptor\` identifies Brokers, Stock Exchanges, CEXs, DEXs, AMMs, Perpetual DEXs, Tokenized Securities Venues, RWA Platforms and Data Providers.

Venue-specific connectivity belongs to adapters. Foundation defines \`VenueAdapterInterface\`, but contains no exchange SDK, market-data HTTP client, WebSocket feed or secrets.

## Runtime and persistence

Foundation persists only structural financial metadata:

- \`tn_capital_market_instruments\`
- \`tn_capital_market_instrument_identifiers\`
- \`tn_capital_market_relationships\`
- \`tn_capital_market_pairs\`
- \`tn_capital_market_venues\`
- \`tn_capital_market_venue_capabilities\`
- \`tn_capital_market_venue_instruments\`
- \`capital_market_user_capabilities\`

Every business row and permission row is organization-scoped.

The module remains disabled by default. Foundation flags can be enabled for an installed tenant, while paper trading, live trading and auto-execution remain disabled.

## API and UI

Canonical endpoints live under \`/api/v1/capital-markets/*\`.

The operator workspace lives under:

- \`/capital-markets\`
- \`/capital-markets/instruments\`
- \`/capital-markets/relationships\`
- \`/capital-markets/venues\`

The UI intentionally contains no fake prices, PnL, charts or order controls.

## Safety posture

Foundation creates no \`Order\`, \`Trade\`, \`Position\`, \`Portfolio\` or \`Backtest\` runtime. There is no live-trading permission in CM-FOUNDATION. Future execution authority must be introduced by a later pack with separate promotion gates and controls.

## Next packs

The next implementation packs build on this foundation:

1. Market Intelligence and normalized MarketState.
2. Tokenized Equity vertical slice.
3. Spot/Perpetual vertical slice.
4. Research Lab and hypothesis registry.
5. Portfolio, ledger and capital allocation.
6. Governed runtime agents.
7. Decision Workspace.
8. Limited Live only after promotion gates.
