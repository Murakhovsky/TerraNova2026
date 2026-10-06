---
title: Tokenized Equity Vertical Slice V0.4
description: "Опис першого фінансового vertical slice Capital Markets для H1/H2 research, deterministic risk та guarded paper execution."
domain: capital_markets
status: implemented
---

# Вертикальний зріз Tokenized Equity V0.4

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

## Реалізований результат V0.4

Система вже вміє зберігати candidates, opportunities, risk assessments, paper executions, paper portfolio, venue balances і immutable ledger transactions; має API та окремий Tokenized Equity workspace; повторно перевіряє ринок перед paper execution та вимірює realized P&L і edge capture.

## Наступний етап

Наступний пакет має розширювати provider coverage, automated scanning universe, historical replay/backtesting та operational observability. Live execution не входить у цей етап.
