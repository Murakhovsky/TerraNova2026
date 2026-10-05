---
title: Огляд домену Capital Markets
description: CM-FOUNDATION для фінансових інструментів, економічних зв'язків, venues та детермінованих фінансових primitives.
status: active
updated: 2026-10-05
kind: domain
contract: domain-v1
---

# Огляд домену Capital Markets

Capital Markets \`0.2.0\` є **CM-FOUNDATION Architecture Foundation Pack** автономного фінансового bounded context COS. Поточний slice уже має tenant-scoped persistence, application boundary, API та UI для структурної моделі, але навмисно не отримує market data і не виконує paper/live trades.

## Призначення

\`\`\`text
InstrumentDescriptor
├─ typed Instrument Identifiers
├─ Instrument Family / Status
├─ Economic Relationship Graph
├─ MarketPair / InstrumentBasket
│
VenueDescriptor
├─ VenueCapability
└─ VenueInstrument
│
Money (Kernel)
Price / Quantity / Rate / Percentage
\`\`\`

Домен створює спільний фундамент для Tokenized Securities, Crypto Spot, Crypto Perpetuals, Stablecoins та інших класів активів без універсальної фінансової God Entity.

## Поточний стан

\`\`\`text
id: capital_markets
version: 0.2.0
runtime: Foundation registry runtime
persistence: tenant-scoped structural metadata
API: /api/v1/capital-markets/*
UI: /capital-markets/*
market data: none
execution: none
process model: explicitly deferred to Market Intelligence
\`\`\`

Модуль вимкнений за замовчуванням. Foundation feature flags відповідають за Instruments, Relationships і Venues. Paper Trading, Live Trading та Auto Execution flags існують як майбутні promotion gates і seed-яться вимкненими.

## Межі

Capital Markets володіє фінансовою предметною моделлю: instruments, economic relationships, venues та їх structural persistence. Він не дублює authentication, IAM, Queue, Workflow, Approval, Audit storage, Feature Flag runtime, Agent Runtime або іншу COS infrastructure.

\`Kernel\Shared\Domain\Money\` залишається канонічним money value object. \`Price\`, \`Quantity\`, \`Rate\` і \`Percentage\` використовують explicit decimal strings, щоб фінансовий core не залежав від floating-point арифметики.

Зовнішні біржі, брокери, CEX/DEX та data providers у наступних slices підключаються через adapters. CM-FOUNDATION містить adapter contract, але не містить provider SDK, HTTP/WebSocket market-data clients або secrets.

- [Foundation Architecture](./foundation-architecture.md)
- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Дозволи та capabilities](../../12-reference/permissions-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
