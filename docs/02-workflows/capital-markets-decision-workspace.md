---
title: Capital Markets Decision Workspace
description: Як оператор проходить від 30-second portfolio check до opportunity, risk, execution, research і data-quality decision.
status: implementation
updated: 2026-10-08
kind: workflow
---

# Робочий процес Capital Markets Decision Workspace

## Бізнес-мета

Дати оператору одну канонічну точку для швидкого рішення щодо капіталу: побачити гроші, чистий результат, головний ризик, найкращу можливість, стан даних і наступну дію без ручного складання картини з технічних екранів.

## Учасники

- користувач Capital Markets читає загальний стан і можливості;
- дослідник веде hypotheses, experiments і strategy evidence;
- Portfolio Manager аналізує allocation та portfolio impact;
- Risk Manager контролює limits, headroom і stress;
- Executor працює з дозволеними Paper execution flows;
- AI agents можуть читати, рекомендувати й пропонувати в межах наданої authority;
- backend engines залишаються фінансовим джерелом істини.

## Карта коду

- `app/Domains/CapitalMarkets/Application/Service/DecisionWorkspaceReadService.php` — композиція канонічних read models;
- `symfony/src/Web/CapitalMarkets/DecisionWorkspacePageController.php` — tenant/capability guarded web surface та exports;
- `symfony/templates/experience/capital_markets/decision_workspace.html.twig` — Decision Workspace presentation;
- `symfony/assets/controllers/capital_markets_workspace_controller.js` — presentation-only table preferences;
- `symfony/config/routes.yaml` — stable workspace/deep-link/export routes;
- `resources/experience/pages/capital_markets/foundation.yaml` — Page Contracts;
- `tests/integration/capital_markets_decision_workspace_acceptance.php` — acceptance contract.

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


## Межі NAV і фінансової достовірності

- Порожній Paper Portfolio: симульований NAV відображається лише після явної ініціалізації та збереження окремого `SIMULATED` snapshot. Це не Live Equity.
- Paper Today/30D P&L: розраховується тільки за двома збереженими valuation boundaries однієї `portfolio_epoch`. Повторна ініціалізація портфеля починає нову епоху навіть при незмінному initial capital.
- Кожен Paper NAV capture зчитує portfolio, positions, balances, executions і ledger з однієї MySQL REPEATABLE READ revision. Відсутня можливість узгодженого читання блокує snapshot.
- Історія 31 дня має максимум 5000 rows. Scheduler допускає інтервал від 10 до 60 хв, щоб 30D projection не стала неповною.
- Незалежні зовнішні фінансові джерела зберігаються тільки як `PENDING_RECONCILIATION`. Кожний cash-flow має стабільний `provider_event_id`; повторний імпорт одного provider event відхиляється незалежно від зміненого текстового reference.
- Вік сертифікованого NAV починається від найстаршої використаної актуальної виписки, не від часу натискання approval.
- Старий CLI `cos:capital-markets:nav:reconcile` навмисно повертає `BLOCKED / AUTHENTICATED_NAV_REVIEWER_APPROVAL_NOT_CONFIGURED`. CLI-параметр `--reviewer` не є доказом особи іншого reviewer. Реальна фінансова сертифікація потребує окремого автентифікованого двоособового workflow із перевіреною provenance.
- Відсутність незалежних виписок, повної історії зовнішніх рухів, історичних valuations та надійного approval залишає реальний Portfolio NAV і Today/30D P&L у `UNAVAILABLE`, а не підміняє їх Paper P&L.
- Perpetual, margin, short і FX залишаються за межами long-only spot certification. Наявність таких позицій блокує сертифікацію, поки не з'являться відповідні бухгалтерські політики.

UI Pack приймається окремо від наявності реальних коштів на біржах: відсутня історія у порожньому акаунті повинна виглядати як `UNAVAILABLE`, а не фальшиві нулі чи прибуток.
