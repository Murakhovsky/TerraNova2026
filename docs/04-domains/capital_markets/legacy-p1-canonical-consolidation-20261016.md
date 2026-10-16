# Консолідація застарілих PR: Engineering V2 і Tokenized Equity v0.6
Date: 2026-10-16
Canonical baseline: `34a6694d55b43939f89387ca0b1b01159f509d38`

## #282: інженерна система Engineering Runtime V2

- **Canonical owner:** Symfony Engineering manager and Platform Workflow / Agent/Tool execution; existing `AgentRole` has 14+ specialist roles, vs original eight.
- **Unique portable element:** `EngineeringDomainContextCompressor`, with repository-index bounded projections, source hash/provenance and original unit test; forward-ported unchanged and tested.
- **Intentionally not copied:** legacy `EngineeringDomainHumanGateService`, alternative orchestrator, migrations and additional store implementation. Legacy HumanGate calls `humanDecisionHistory/createHumanDecision/openHumanDecisions/answerHumanDecision`, which do not exist on the *current* `EngineeringDomainStoreInterface`. Copying it would give two incompatible authorities.
- **Source preservation:** GitHub PR #282 head `487be5e845416d3c64d9e23c679030f5890f9122`, with all unported files and original intent retained for audit. Claims of feature parity must be proven by canonical acceptance, not by copying an obsolete file.

## #315: історичні перевірки train/OOS

- **Canonical owner:** `ResearchBacktestService` + `ResearchLabService` + `StrategyPromotionGate`, using existing research backtest/OOS tables and receipts.
- **Portable unique logic:** `HistoricalBacktestPromotionPolicy` (minimum train/OOS sample, independently positive train/OOS expected net, minimum executable ratio) and its original six assertions are restored **as a research policy and diagnostic**, not as a second execution service.
- **Intentionally not copied:** `TokenizedEquityHistoricalBacktestService` / `MysqlTokenizedEquityBacktestRepository`, because `tn_capital_market_backtest_runs` is already owned by the canonical Research Lab, with a different layout. No conflicting `20261006_000130` migration or duplicate writer was accepted.
- **Source:** PR #315 head `05ba24486498ab5a6e3b7c3944e5f94823b10dbb`.

## #318: плановий пошук можливостей

- **Canonical owner:** `TokenizedEquityUniverseScanner`, `RunInstrumentPoolSnapshotScansHandler`, `InstrumentPoolScheduleTargets` and canonical Instrument Pool approvals.
- **Recovered intent:** operator-curated target configuration, repeatable schedule, bounded per-tenant discovery, idempotent scan, and controlled handling of failed/partial results. Their original implementation is **not** promoted to production without a Platform Workflow authority decision and MySQL gate.
- **Intentionally not copied:** parallel `TokenizedEquityScannerService` / scanner runs store, old scheduler activation and SQL `trigger VARCHAR(64)` (MySQL reserved identifier, old Runtime SQL 1064). Replaying scanner code unreviewed would duplicate discovery authority.
- **Source:** PR #318 head `c641bf47800926d38e94eb721ff965963cbe4834`.
- **Remaining functional difference:** a tenant-owned scheduled H1/H2 target registry is not identical to the currently deployed provider/venue Instrument Pool scheduler. It requires a **separate canonical capability design**, not a legacy service merge. Do not claim this old UX is live merely because the obsolete PR is closed.

## Критерії приймання та захист від регресій

- One canonical scanner and Research Lab execution path in `main`.
- No secondary `tn_capital_market_backtest_runs` migration or unfenced old scanner RUNNING receipts.
- The historical OOS policy and Engineering compressor are exercised by CI.
- The old source commits remain addressable, and the complete old feature behavior remains traceable without inventing a second workflow.

## Збереження вихідних гілок

Close PRs as *superseded*, not as *merged*. Preserve the old source branch refs for #282, #315, #318 until feature-equivalence review is accepted. Branch cleanup must never delete their original head refs in this cycle.
