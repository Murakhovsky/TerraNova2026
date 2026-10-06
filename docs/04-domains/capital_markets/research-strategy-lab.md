---
title: Research & Strategy Lab
description: Governed Capital Markets research runtime for hypotheses, experiments, replay, OOS, strategy versioning, scorecards and promotion gates.
status: active
updated: 2026-10-07
kind: domain
contract: domain-v1
---

# Research & Strategy Lab

Capital Markets `0.8.0` adds a governed research runtime above the existing Tokenized Equity H1/H2 and Crypto Spot / Perpetual H4/H5/H6 vertical slices.

The purpose is not to let AI "pick trades". The purpose is to make research reproducible, auditable and reusable.

## Canonical lineage

```text
ResearchHypothesis
    ↓
ResearchDataset (frozen snapshot)
    ↓
ResearchExperiment
    ↓
StrategyVersion
    ↓
Backtest / Replay
    ↓
TRAIN / VALIDATION / OUT_OF_SAMPLE
    ↓
ResearchResult
    ↓
StrategyScorecard
    ↓
Promotion Gate
    ↓
PAPER / LIMITED_LIVE decision boundary
    ↓
ResearchKnowledge / RejectedHypothesisRecord
```

Completed results, frozen datasets and used strategy versions are evidence. They are not silently rewritten after an inconvenient result.

## Historical replay

H4/H5/H6 replay uses historical `MarketSnapshot` data and reuses the production Capital Markets stack:

- `SpotPerpetualMarketStateFactory`;
- `RelativeValueEconomicsCalculator`;
- `RelativeValueOpportunityEvaluator`.

The replay layer does not implement a second P&L formula.

Promotion-valid backtests require explicit fees and slippage. Replay guards reject data whose availability timestamp is after the simulated timestamp.

## Isolation

Research data partitions are explicit:

```text
TRAIN
VALIDATION
OUT_OF_SAMPLE
```

They may not overlap. After an OOS result, tuning cannot reuse the same OOS period as if it were still unseen evidence. A failed or consumed OOS window requires a new strategy version and/or a new OOS period.

## Walk-forward

The Lab can generate rolling train/test windows and aggregate stability across them. Parameter sensitivity analysis raises `OVERFIT_RISK` when performance exists only in a narrow parameter peak.

## Strategy governance

`StrategyVersion` is immutable and carries logic/configuration identity.

`StrategyScorecard` combines versioned dimensions such as profitability, consistency, risk, execution quality, capital efficiency, capacity, robustness, data confidence and operational complexity.

A high score does not bypass promotion criteria.

Supported promotion transitions are deterministic and auditable:

```text
RESEARCH → BACKTEST
BACKTEST → OOS
OOS → PAPER
PAPER → LIMITED_LIVE
LIMITED_LIVE → VALIDATED
VALIDATED → SCALE
```

Live trading remains disabled in Capital Markets 0.8.0.

## Negative research

Rejected hypotheses are retained with reason, evidence, experiments, market conditions, data limitations and optional reopen conditions.

Reusable `ResearchKnowledge` includes both positive and negative findings. Duplicate detection checks new hypotheses against prior research, including rejected hypotheses.

## Research Agent

The Capital Markets Research Agent is registered in the canonical Agent Runtime.

It may:

- inspect formal hypotheses, experiments and knowledge;
- inspect H4/H5/H6 funding and basis evidence;
- search prior research;
- create hypothesis drafts;
- create experiment drafts.

It may not:

- activate Live Trading;
- change risk or capital limits;
- mutate completed results;
- mutate frozen datasets;
- mutate used strategy versions;
- override a promotion gate.

The rule is simple:

```text
AI proposes.
Deterministic engines test.
Data decides.
```

## Operator surface

Workspace:

```text
/capital-markets/research
```

The workspace shows hypotheses, experiments, backtest runtime, OOS runs, scorecards, promotion decisions, rejected hypotheses and reusable knowledge.

Main API surfaces include:

```text
GET  /api/v1/capital-markets/research
POST /api/v1/capital-markets/research/hypotheses
POST /api/v1/capital-markets/research/datasets
POST /api/v1/capital-markets/research/experiments
POST /api/v1/capital-markets/research/backtests
POST /api/v1/capital-markets/research/backtests/run
POST /api/v1/capital-markets/research/walk-forward
POST /api/v1/capital-markets/research/agent/run
POST /api/v1/capital-markets/strategies/{id}/scorecard
POST /api/v1/capital-markets/strategies/{id}/promotion-request
```

Mutations require tenant context, module activation, explicit Capital Markets research capability and CSRF.

## Manual acceptance sequence

1. Open `/capital-markets/research`.
2. Create an H4 or H5 hypothesis with explicit economic reason, edge source, success criteria and failure criteria.
3. Freeze a dataset and verify that a deterministic snapshot hash is produced.
4. Create Strategy v1 and an experiment against the frozen dataset.
5. Queue/run a TRAIN backtest with explicit fees and slippage.
6. Verify a persisted backtest run and immutable result.
7. Create Strategy v2 if parameters or logic change.
8. Run OOS with unchanged frozen criteria/parameters for that strategy version.
9. Verify the OOS run is persisted and cannot be reused after tuning.
10. Create a scorecard and request promotion.
11. Verify deterministic criteria decide PASSED/FAILED/MANUAL_REVIEW_REQUIRED.
12. Record positive or negative knowledge.
13. Run the Research Agent and verify it can create only research drafts, not Live actions.
