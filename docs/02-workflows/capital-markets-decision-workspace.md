---
title: Capital Markets Decision Workspace
description: Як оператор проходить від 30-second portfolio check до opportunity, risk, execution, research і data-quality decision.
status: implementation
updated: 2026-10-08
kind: workflow
---

# Робочий процес Capital Markets Decision Workspace

## Ранкова перевірка

\`\`\`text
/capital-markets
  ↓
Capital
Profit
Risk
Data Health
Top Opportunity
Recommended Action
\`\`\`

Якщо жодної дії немає, система показує no-action state. Вона не створює штучні alerts заради “живого dashboard”.

## Рішення щодо можливості

\`\`\`text
Opportunity Board
  ↓
Opportunity Detail
  ↓
WHY
  ↓
Expected Net / Capital
  ↓
Portfolio Impact
  ↓
Risk / Data Trust
  ↓
Paper execution handoff або reject/hold
\`\`\`

Portfolio decision має пріоритет над standalone attractiveness. Profitable opportunity може бути \`REJECT\` або \`ACCEPT_REDUCED_SIZE\`, якщо hard headroom, concentration чи capital location не дозволяють повний size.

## Рішення щодо дослідження

\`\`\`text
Hypothesis
  ↓
Experiment
  ↓
Backtest
  ↓
OOS
  ↓
Paper
  ↓
Scorecard / Promotion Gate
\`\`\`

Rejected result не ховається. Він залишається research knowledge.

## Проблема виконання

\`\`\`text
Execution
  ↓
Orders / Fills
  ↓
Hedge state
  ↓
PARTIAL / FAILED / RECOVERY warning
  ↓
existing governed recovery policy
\`\`\`

Decision Workspace не створює паралельний execution engine.

## Збій даних

\`\`\`text
Source / Market State becomes DEGRADED / STALE / UNAVAILABLE
  ↓
Global data status changes
  ↓
Opportunity evidence exposes age/trust
  ↓
Execution-sensitive handoff is not presented as safe
\`\`\`

Application Health і Market Data Health залишаються різними поняттями.

## Простежуваність

Stable links дозволяють пройти:

\`\`\`text
Hypothesis
  ↓
Strategy Version
  ↓
Opportunity
  ↓
Allocation
  ↓
Risk
  ↓
Execution
\`\`\`

P&L додається до trace тоді, коли canonical execution/performance projection має відповідний attributable value.
