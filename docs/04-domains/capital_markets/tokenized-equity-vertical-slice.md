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
- paper execution працює за full-fill policy: якщо будь-яка нога не може бути виконана повністю, execution не стартує;
- Ledger балансується окремо для кожного asset;
- realized paper P&L рахується з фактичних simulated fill prices та fees, без подвійного врахування slippage;
- H1 залишається research-only, доки немає реального executable hedge venue;
- Live Trading і withdrawals залишаються вимкненими.

## Реалізований результат V0.6

Система вже вміє зберігати candidates, opportunities, risk assessments, paper executions, paper portfolio, venue balances і immutable ledger transactions; має API та окремий Tokenized Equity workspace; повторно перевіряє ринок перед paper execution та вимірює realized P&L і edge capture.

V0.5 додає append-only `HypothesisObservation` journal для кожного SCAN, EVALUATION і completed EXECUTION. На його основі детерміновано будується Edge Funnel `theoretical scan → detected → executable → realized`, накопичуються expected/realized P&L samples і формується sample-gated verdict для H1/H2. Research replay не змінює стан: однаковий journal дає однаковий summary та `dataset_hash`.

V0.6 додає historical backtesting без підмішування історичних результатів у live research journal. Backtest читає immutable `MarketSnapshot` у заданому діапазоні, перевіряє ті самі economic relationship/data-quality/spread/net-economics gates, ділить dataset хронологічно на train та out-of-sample, окремо рахує metrics і повертає promotion result `INSUFFICIENT_OOS_SAMPLE / TRAIN_FAIL / OOS_FAIL / OOS_NOT_EXECUTABLE / OOS_PASS`. Конфігурація та склад snapshot dataset входять у SHA-256 `dataset_hash`.

## Наступний етап

Наступний пакет має закрити automated scanning universe, execution lifecycle/partial-fill hardening, position/exposure lifecycle та operational observability. Live execution не входить у цей етап.
