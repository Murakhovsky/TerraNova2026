---
title: Capital Markets Foundation Architecture
status: active
updated: 2026-10-05
kind: architecture
---

# Capital Markets Foundation Architecture

## Scope and bounded context

Package \`CM-FOUNDATION\` creates the autonomous \`Domains\\CapitalMarkets\` bounded context. It owns instrument identity/classification, economic relationships, venue definitions and financial value semantics. It does not own COS users, tenants, authentication, global audit storage, global feature-flag storage, queues, approvals or agent runtime.

The package deliberately contains no exchange SDK, HTTP/WebSocket market feed, strategy, opportunity engine, portfolio, order, trade, backtest, paper execution or live execution.

## Structure

\`\`\`text
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
\`\`\`

Folders are added only when executable code exists.

## Domain model and invariants

Instrument internal IDs are stable and provider-independent. External identifiers are typed (\`TICKER\`, \`ISIN\`, \`CUSIP\`, \`FIGI\`, \`EXCHANGE_SYMBOL\`, \`CONTRACT_ADDRESS\`, \`PROVIDER_ID\`) and can be many-to-one with an instrument.

Relationships are directed. \`AAPLx REPRESENTS AAPL\` never implies the reverse edge. Self-relations are rejected. Graph traversal is bounded to depth 1–5 and tracks visited nodes to survive cycles.

Financial core uses explicit base-10 string decimals. Binary floating point is forbidden under \`Domains\\CapitalMarkets\\Domain\`. Rates are stored as decimal fractions, so 8% is \`0.08\`, never an ambiguous \`8\`.

Instrument and venue metadata are JSON-compatible and capped at 16 KiB in the domain model.

## Persistence and tenant isolation

Every mutable Capital Markets table is organization-scoped. Repository contracts always require \`organizationId\`, and MySQL implementations must include \`organization_id\` in reads, writes, unique constraints and foreign-key identity.

Canonical tables:

\`\`\`text
tn_capital_market_instruments
tn_capital_market_instrument_identifiers
tn_capital_market_relationships
tn_capital_market_pairs
tn_capital_market_venues
tn_capital_market_venue_capabilities
tn_capital_market_venue_instruments
capital_market_user_capabilities
\`\`\`

No ORM/query builder crosses into Domain code.

## Platform integration

Authentication and tenant context come from \`Kernel\\Tenant\\Contract\\TenantContextProviderInterface\`. Permissions are exposed through \`CapitalMarketsAccessControlInterface\`. Audit records are written through \`Platform\\Audit\\Service\\AuditRecorder\`. Feature evaluation uses Platform Feature Flag contracts; Capital Markets owns only its flag vocabulary and seed definitions.

Mutating HTTP surfaces require tenant context, module enabled state, the granular Capital Markets capability and CSRF validation.

## API and UI

The canonical API follows the existing COS strategy and therefore uses \`/api/v1/capital-markets/*\`. The workspace entry point is \`/capital-markets\`, with Instruments, Relationships and Venues as Foundation sections. No trading dashboard or fake market metrics exist.

## Migration and extraction strategy

Foundation is an additive schema migration from module schema \`0.1.0\` to \`0.2.0\`. No existing business-domain table is repurposed. Capital Markets can therefore be extracted later by moving its organization-scoped tables and preserving repository/platform contracts.

## Tests and acceptance

Architecture tests enforce domain isolation and NO FLOAT. Unit tests cover value objects, controlled taxonomies, status rules, graph direction/cycles and event schemas. Integration/contract tests verify migration constraints, tenant-scoped repository SQL, permissions, feature flags, routes and UI empty states.

The acceptance slice is: create AAPL, create AAPLx, create \`AAPLx REPRESENTS AAPL\`, create a venue, register AAPLx at the venue, and read the same structure through UI and API. Market data remains intentionally unavailable.
