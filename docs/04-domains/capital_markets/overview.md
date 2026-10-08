---
title: Огляд домену Capital Markets
description: CM-FOUNDATION + CM-MARKET-INTELLIGENCE + CM-TOKENIZED-EQUITY для фінансових інструментів, trusted MarketState, H1/H2 research та guarded paper execution.
status: active
updated: 2026-10-06
kind: domain
contract: domain-v1
version: 0.9.0
---

# Огляд домену Capital Markets

Capital Markets `0.9.0` поєднує **CM-FOUNDATION**, executable **CM-MARKET-INTELLIGENCE** та перший фінансовий vertical slice **CM-TOKENIZED-EQUITY**. Домен уже вміє не тільки зберігати структуру фінансових інструментів і venues, а й приймати raw market observations, нормалізувати їх, оцінювати якість та підтримувати current MarketState.

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
version: 0.9.0
runtime: Foundation + Market Intelligence + Tokenized Equity H1/H2 + Crypto H4/H5/H6 + Research & Strategy Lab + Capital Allocation & Risk
persistence: tenant-scoped structural + raw/canonical/current-state data
process: capital-markets.market-data-to-trusted-state
data modes: LIVE / DELAYED / HISTORICAL / REPLAY
provider adapters: Bybit Spot REST + Massive Stocks REST
provider polling: CLI + operator API/UI
operator workspace: canonical /capital-markets Decision Workspace + advanced registries/vertical-slice execution surfaces
streaming: disabled / next wave
execution: guarded H1/H2 paper; H1 requires explicit executable hedge venue; live disabled
```

Модуль вимкнений за замовчуванням. Bybit Spot REST, Kraken public Spot/xStocks та Massive Stocks REST adapters уже реалізовані за provider-neutral contracts. Операторський Market Data workspace керує Sources, Subscriptions, Health та ручним Poll. Нові sources створюються disabled. Provider-specific flags і streaming flag залишаються вимкненими до tenant source configuration та production cutover.

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
- [Research & Strategy Lab](./research-strategy-lab.md)
- [Decision Workspace Architecture](../../01-architecture/capital-markets-decision-workspace-architecture.md)
- [Decision Workspace workflow](../../02-workflows/capital-markets-decision-workspace.md)
- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Дозволи та capabilities](../../12-reference/permissions-capabilities.md)


## Лабораторія досліджень і стратегій

Версія 0.8.0 додала керовану Research & Strategy Lab. Версія 0.9.0 включає Capital Allocation & Risk Pack і schema-neutral canonical Decision Workspace: portfolio state, economic exposure, risk envelopes/headroom, constrained allocation, stress/rebalance та governed Portfolio Agent.

- формальний lineage ResearchHypothesis, ResearchExperiment, ResearchDataset та ResearchResult;
- immutable StrategyVersion;
- historical replay та orchestration backtest;
- жорстке розділення TRAIN / VALIDATION / OUT_OF_SAMPLE;
- walk-forward analysis та overfit warnings;
- deterministic StrategyScorecard та promotion gates;
- demotion policy, пам’ять rejected hypotheses та reusable ResearchKnowledge;
- Research Agent із draft-only authority;
- операторський workspace `/capital-markets/research`.

Research Agent не може активувати Live Trading, змінювати risk limits, мутувати completed results або обходити deterministic promotion gates. Historical replay H4/H5/H6 повторно використовує той самий RelativeValue economics та evaluator stack, що й production paper vertical slice.
