---
title: Tokenized Equity Vertical Slice V0.5
description: "Опис першого фінансового vertical slice Capital Markets для H1/H2 research, deterministic risk та guarded paper execution."
domain: capital_markets
status: implemented
updated: 2026-10-06
kind: domain
contract: domain-v1
---

# Вертикальний зріз Tokenized Equity V0.5

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
→ Hypothesis Observation
→ Edge Funnel
→ Deterministic Research Verdict
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

## Реалізований результат V0.5

Система зберігає candidates, opportunities, risk assessments, paper executions, paper portfolio, venue balances та immutable ledger transactions. Кожен H1/H2 scan тепер також створює окремий hypothesis observation, включно зі спостереженнями без знайденого edge.

Research layer агрегує Edge Funnel `observed scans → detected → executable → attempted → completed / invalidated`, realized P&L, completion rate та середній edge capture. Invalidated attempts не зникають зі статистики, а збиткові paper fills зберігаються як completed outcomes і входять у realized P&L. Детермінована policy повертає один із verdicts: `INSUFFICIENT_SAMPLE`, `EDGE_NOT_OBSERVED`, `EDGE_OBSERVED_NOT_EXECUTABLE`, `EDGE_EXECUTABLE_UNVALIDATED`, `EDGE_VALIDATED`, `EDGE_NOT_VALIDATED`.

За замовчуванням остаточний verdict вимагає щонайменше 30 observations, 10 paper execution attempts і completion rate не нижче 50%. Пороги доступні в research API як параметри читання, а не як прихована AI-оцінка.

API: `GET /api/v1/capital-markets/tokenized-equities/research`. Workspace показує funnel, realized evidence та verdict окремо для H1 і H2.

## Наступний етап

Залишаються automated scanning universe, historical deterministic replay/backtesting, richer execution lifecycle/legging failure recovery та operational observability. Live execution не входить у цей етап.
