---
title: Tokenized Equity Vertical Slice V0.6
description: "Опис першого фінансового vertical slice Capital Markets для H1/H2 research, deterministic risk та guarded paper execution."
domain: capital_markets
status: implemented
updated: 2026-10-06
kind: domain
contract: domain-v1
---

# Вертикальний зріз Tokenized Equity V0.6

Цей пакет реалізує детерміноване фінансове ядро для **H1 Tokenized Equity Dislocation** та **H2 Cross-Venue Tokenized Equity Arbitrage**.

## Потік

```text
MarketState
→ SpreadCandidate
→ Net Economics
→ Opportunity
→ Risk
→ Paper Fill
→ Asset-Aware Ledger
→ Position / P&L
→ Execution Performance
```

Реалізація повторно використовує Foundation і Market Intelligence V0.3/V0.4 та не створює паралельний market-data stack.

## Інваріанти

- фінансові обчислення використовують `Decimal`, без `float/double`;
- BUY оцінюється по ask, SELL по bid;
- для більших обсягів використовується order-book VWAP;
- stale, untrusted або closed market state не створює candidate;
- snapshot age та skew мають жорсткі межі;
- однаковий symbol не є доказом economic equivalence;
- різні quote currencies не порівнюються без trusted conversion rate;
- gross spread не вважається profit;
- explicit costs віднімаються до розрахунку expected net P&L;
- H2 paper execution вимагає pre-funded cash на buy venue та token inventory на sell venue;
- paper execution підтримує deterministic partial-fill handling: partial/rejected second leg переходить у compensation policy `ABORT_AND_COMPENSATE` з `EMERGENCY_CLOSE`; успішна компенсація повинна залишати zero residual unhedged exposure;
- Ledger балансується окремо для кожного asset та пишеться idempotently;
- execution lifecycle зберігає persisted checkpoints і після restart відновлюється з `LEG1_FILLED`, `LEG2_PARTIAL/REJECTED`, `COMPENSATION_FILLED` або `LEDGER_POSTED` без повторного settlement;
- realized paper P&L рахується з фактичних simulated fill prices та fees, без подвійного врахування slippage;
- H1 за замовчуванням лишається research-only; якщо явно передано реальний executable hedge venue, candidate перепрайсується по hedge bid/ask і може пройти Risk → Paper Execution;
- Live Trading і withdrawals залишаються вимкненими.

## Реалізований результат V0.6

Система вже вміє зберігати candidates, opportunities, risk assessments, paper executions, execution plans/orders/fills, paper portfolio, venue balances, positions і asset-aware ledger transactions; має API та окремий Tokenized Equity workspace; повторно перевіряє ринок перед paper execution та вимірює realized P&L і edge capture.

V0.6 додає phased crash recovery, partial-fill compensation, idempotent settlement, execution-scoped paper positions, performance/reconciliation read models та operational telemetry через канонічні COS `MetricsRecorderInterface` і `StructuredLoggerInterface`. Критичні стани `UNHEDGED_POSITION`, ledger inconsistency та reconciliation failure піднімають структуровані alerts.

Read API включає executions, positions, ledger, Tokenized Equity performance, hypothesis observations та reconciliation. Workspace показує execution performance, останні execution checkpoints/results, positions/exposure і reconciliation state.

V0.5 додає append-only `HypothesisObservation` journal для кожного SCAN, EVALUATION і EXECUTION attempt. На його основі детерміновано будується Edge Funnel `observable scan → detected → executable → attempted → realized / invalidated`, накопичуються expected/realized P&L samples і формується sample-gated verdict для H1/H2. Stale, untrusted, closed, skewed або otherwise unusable market observations зберігаються як unobservable evidence, але не входять у sample denominator. Failed preflight/capital/inventory attempts також лишаються в journal, а verdict враховує completion rate, тому кілька успішних fills не можуть приховати масову кількість невдалих спроб. Research replay не змінює стан: однаковий journal дає однаковий summary та `dataset_hash`.

## Наступний етап

Historical MarketState replay/backtesting, automated universe scanning, execution recovery/partial-fill hardening, position persistence та operational observability вже входять у V0.6. Наступний етап має бути окремим пакетом для розширення research dataset, venue coverage, production-grade scheduling/monitoring і, лише після окремого risk/legal/release gate, потенційного live execution. Live Trading і withdrawals у VS1 залишаються вимкненими.


## Реальні джерела ринкових даних для H2

H2 може використовувати два незалежні public trading feeds: Bybit Spot та Kraken Spot/xStocks. Обидва adapters підтримують BBO і order-book depth. Trading MarketState не вважається OPEN за замовчуванням: `VenueInstrument.metadata` має містити `market_hours_timezone` + `market_hours` або explicit `always_open=true`; без такого правила статус лишається `UNKNOWN` і detector fail-closed.
