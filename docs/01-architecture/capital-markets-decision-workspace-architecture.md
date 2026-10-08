---
title: CM-DECISION-WORKSPACE — архітектура Decision Workspace
description: Канонічний UI/read-model шар Capital Markets, який збирає capital, profit, risk, opportunities, market trust та recommended actions у decision-first workspace.
status: implementation
updated: 2026-10-08
kind: architecture
---

# Архітектура CM-DECISION-WORKSPACE

Пакет перетворює Capital Markets із набору окремих operator screens у єдиний **Decision Workspace**.

Основне правило:

\`\`\`text
SUMMARY
  ↓
SIGNALS
  ↓
DECISIONS
  ↓
DETAILS
\`\`\`

Користувач не повинен вручну складати фінансову картину з Market, Portfolio, Risk, Logs і калькулятора.

## Архітектурний принцип

\`DecisionWorkspaceReadService\` є read-only composition layer.

Він повторно використовує авторитетні джерела:

- \`CapitalRiskService\` для Portfolio, Capital State, Exposure, Risk, Allocation, Stress і Performance attribution;
- \`ResearchLabService\` для hypotheses, experiments, strategy versions, scorecards, promotion/rejection knowledge;
- \`MarketDataAdministrationService\` для MarketState, ReferenceMarketState і source health;
- \`CapitalMarketsTradingRepositoryInterface\` для Opportunities, Executions, Orders, Fills і Ledger projections;
- \`CapitalMarketsFoundationBoundary\` для instruments, relationships і venues;
- \`OperationsSectionReader\` для governed Agent Run projections;
- \`DomainModuleRegistry\` для canonical agent definitions та authority.

Read model **не перераховує** P&L, risk, allocation, basis, funding economics або exposure. Якщо авторитетного значення немає, UI показує \`—\` / \`UNAVAILABLE\`.

## Відомий gap, який не маскується

Поточний Performance engine має canonical portfolio Net P&L attribution, але не має окремих validated read models для:

- Today Net P&L;
- 30D Net P&L.

Decision Workspace не перейменовує lifetime/aggregate P&L у Today/30D. Ці поля показуються як unavailable до появи авторитетної часової проєкції.

## Інвентаризація інтерфейсу

| Existing surface | Decision |
| --- | --- |
| \`/capital-markets\` foundation overview | **MERGE / REPLACE** canonical Decision Overview |
| \`/capital-markets/instruments\` | **KEEP** advanced registry |
| \`/capital-markets/relationships\` | **KEEP** advanced canonical registry |
| \`/capital-markets/venues\` | **KEEP** advanced venue registry |
| \`/capital-markets/market-data\` | **KEEP** market-data administration |
| \`/capital-markets/tokenized-equities\` | **KEEP** vertical-slice operator/execution surface |
| \`/capital-markets/crypto-spot-perpetual\` | **KEEP** vertical-slice operator/execution surface |
| old Research dashboard | **IMPROVE / MERGE** into Research + Strategies |
| old Capital Risk pages | **MERGE** into Portfolio / Allocation / Risk / Performance |
| Opportunity detail | **IMPROVE** with economics, portfolio impact, evidence, trace |
| Execution read surface | **NEW** |
| Agent Center | **NEW** |
| Data Quality Center | **NEW** |

## Канонічні routes

\`\`\`text
/capital-markets
/capital-markets/opportunities
/capital-markets/opportunities/{id}
/capital-markets/markets
/capital-markets/research
/capital-markets/research/hypotheses/{id}
/capital-markets/strategies
/capital-markets/strategies/{id}
/capital-markets/portfolio
/capital-markets/allocation
/capital-markets/execution
/capital-markets/execution/{id}
/capital-markets/risk
/capital-markets/performance
/capital-markets/agents
/capital-markets/data-quality
\`\`\`

Advanced registries і vertical-slice execution pages залишаються доступними через global search/commands та context links, але не роздувають primary Capital Markets navigation.

## Глобальний стан робочого простору

Кожний canonical screen отримує:

- workspace mode: \`RESEARCH\` або \`PAPER\`;
- explicit \`LIVE · DISABLED\`;
- Portfolio Equity;
- Available Capital;
- canonical Net P&L;
- Risk State;
- Market Data Health;
- Critical Alerts;
- Last Updated.

Color не є єдиним signal. Status завжди має текстову назву.

## Пріоритет можливостей

Default Opportunity Board order використовує \`priority\`, уже створений детермінованим Allocation Plan.

UI не створює власний “AI score” або фінансовий ranking. Якщо allocation priority відсутній, opportunity не отримує вигаданий score.

Server-side filters дозволяють звужувати view за типом, risk, decision, status, strategy, instrument, venue, мінімальним expected net/return та максимальним capital. Decimal comparison використовує Capital Markets \`Decimal\`, не binary float.

## Довіра до даних

Data Quality Center і global header розрізняють:

\`\`\`text
HEALTHY
DEGRADED
STALE
UNAVAILABLE
\`\`\`

Market rows показують:

- \`updated_at\`;
- event age;
- trust status;
- \`LIVE / DELAYED / HISTORICAL / REPLAY\`.

Відсутня або stale інформація не підміняється cached value з “live” виглядом.

## Семантика помилок і часткових даних

Composition layer ізолює read-side failures. Якщо, наприклад, Market Data недоступна, Portfolio може продовжити рендеритися як partial page.

UI явно показує:

- \`PARTIAL DATA\`;
- source, який не прочитався;
- відсутні canonical values як \`—\`;
- execution-sensitive warning, якщо data state не дозволяє довіряти opportunity.

## Безпека режимів Paper / Live

Live Trading лишається вимкненим.

Decision Workspace:

- не рендерить \`Execute Live\`;
- не підміняє Paper action Live action;
- показує \`LIVE · DISABLED\` текстом;
- делегує actual Paper Execution існуючим vertical-slice services;
- не дублює execution або risk business logic у Twig/JavaScript.

## Інтерфейс агентів

Agent Center показує:

- agent name/profile/version;
- authority та execution mode;
- recent Agent Runs і task/subject;
- decision, recommendation, confidence, findings та limitations;
- requested tools і фактично audited tool calls;
- tool input/output з tenant-scoped Activity History;
- structured result, token/cost/duration/error telemetry.

Hidden chain-of-thought не показується. UI працює лише зі збереженим structured result, evidence, tool/result telemetry та audit-compatible runtime records.

## Експорт

Read-only exports:

\`\`\`text
/capital-markets/export/opportunities.csv|json
/capital-markets/export/performance.csv|json
/capital-markets/export/research-results.csv|json
/capital-markets/export/executions.csv|json
\`\`\`

Exports проходять ті самі tenant/module/capability checks, що й workspace.

## Mobile та accessibility

Desktop залишається primary, але critical views використовують:

- responsive table containers;
- stacked metrics;
- text status;
- explicit labels;
- keyboard-native links/buttons/forms;
- no color-only meaning.

Mobile priority: status → money → risk → action.

## Економіка результативності

Performance Center окремо показує Portfolio Net P&L і realized economics: Gross P&L → canonical costs → Net P&L, плюс coverage/status та cost breakdown. Completed executions без повної canonical cost evidence не домислюються: coverage стає PARTIAL/UNAVAILABLE, а missing categories лишаються `—`.

## Продуктивність

SSR отримує coherent read-model payload замість клієнтського складання 20 API calls. Heavy historical charts не є blocking dependency першого viewport.

Таблиці та read-side projections залишаються server-controlled. Майбутня virtualization потрібна лише тоді, коли фактичний dataset перевищить practical SSR page size.

## Межа realtime-взаємодії

Decision Workspace не створює окремий WebSocket/runtime.

Канонічний транспорт COS залишається Turbo/Mercure. Поки Capital Markets не має окремих presentation publishers для агрегованих decision projections, screen показує explicit \`updated_at\`/age/trust та не симулює realtime через aggressive polling.

Наступне realtime розширення повинно публікувати **relevant state changes**, а не raw market ticks, у tenant-scoped workspace topic.

## Критерії завершення

Pack закритий лише якщо:

1. Overview за короткий час показує capital, profit, risk, top opportunity, data health і action.
2. Opportunity detail пояснює WHY, MONEY, RISK, portfolio impact, evidence і action.
3. Gross + Net exposure видимі разом.
4. Risk breach видно без logs/devtools.
5. Partial execution має explicit warning.
6. Rejected research доступний.
7. Strategy version evidence можна порівнювати без зовнішньої таблиці.
8. Stale data не маскується під Live.
9. Viewer/role permissions не отримують execution mutation controls.
10. Live action не виглядає harmless, бо Live execution взагалі не рендериться, доки policy його не дозволяє.


## Локальні налаштування відображення

Density і видимість optional columns зберігаються в browser `localStorage` тільки як presentation state.

Там не зберігаються:

- financial calculations;
- risk decisions;
- allocation decisions;
- server-owned filters;
- permissions;
- canonical business state.

Scenario Simulator викликає існуючий backend endpoint `/api/v1/capital-markets/portfolio/simulate-opportunity`; browser лише передає opportunity + proposed capital і відображає deterministic response.


## Доповнення: детальні екрани та приймання

Decision Workspace також містить канонічні екрани Market Detail та Relationship Detail.

- Market Detail використовує канонічні MarketState, інструменти та зв'язки.
- Relationship Detail показує семантику зв'язку й порівняння цін на підставі канонічних даних.
- За відсутності достатніх зіставних ринкових даних інтерфейс показує `NOT COMPARABLE`, а не вигадує премію.
- Strategy Detail показує порівняння версій однієї стратегії.
- Окремий тест `capital_markets_decision_workspace_manager_acceptance.php` перевіряє контракт WHAT → WHY → MONEY → RISK → ACTION під час Runtime CI.

## Історичні ринкові дані

Market Detail читає фактичні канонічні події за останні сім діб через `CanonicalMarketEventRepositoryInterface::history()`. Доступ до історії контролюється окремою capability `capital_markets.market_data.history.view`: за відсутності дозволу запит до репозиторію не виконується, а історія не потрапляє до HTML.

Таблиця зберігає вихідні timestamp, venue, source, event type, mode, quality flags, quote asset та десяткові значення. Візуалізація в браузері використовує числа лише для розміщення точок SVG; вона не перераховує фінансові величини. Ряди Price, quoted spread і Funding розділені за типом події, джерелом, venue, одиницею та режимом. Порожні й неповні серії відображаються як недоступні.

Відомі прогалини: канонічні Today/30D P&L, time-aligned міжінструментний Basis та повна агрегація історичних серій залишаються невиконаними. Наявність графіка спостережень не означає готовність торгового сигналу.
