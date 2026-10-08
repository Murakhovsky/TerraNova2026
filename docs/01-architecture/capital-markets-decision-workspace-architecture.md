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

Контроль доступу на спільних екранах незалежний: `RelationshipView` потрібен для графів та переліку зв'язків інструментів, `MarketDataView` потрібен для котирувань і порівняння цін. Якщо роль має доступ лише до однієї з цих можливостей, дані іншої не включаються до HTML-відповіді.

Market Detail читає фактичні канонічні події за останні сім діб через `CanonicalMarketEventRepositoryInterface::history()`. Доступ до історії контролюється окремою capability `capital_markets.market_data.history.view`: за відсутності дозволу запит до репозиторію не виконується, а історія не потрапляє до HTML.

Таблиця зберігає вихідні timestamp, venue, source, event type, mode, quality flags, quote asset та десяткові значення. Візуалізація в браузері використовує числа лише для розміщення точок SVG; вона не перераховує фінансові величини. Ряди Price, quoted spread і Funding розділені за типом події, джерелом, venue, одиницею та режимом. Порожні й неповні серії відображаються як недоступні.

Відомі прогалини: канонічні Today/30D P&L, time-aligned міжінструментний Basis та повна агрегація історичних серій залишаються невиконаними. Наявність графіка спостережень не означає готовність торгового сигналу.


## Реалізований часовий зріз фактичного прибутку

Показники **Today Realized Net** та **30D Realized Net** обчислюються у серверному класі
\`RealizedPnlWindowProjector\` на основі завершених tenant-scoped paper executions:
\`COMPLETED\`, \`COMPLETED_COMPENSATED\`, \`CLOSED\`. Часові межі визначаються в UTC:
з початку поточної доби та рухомі останні 30 діб.

Для успішного стану \`COMPLETE\` потрібні: первинна часова мітка події,
підтверджена одна валюта розрахунку, повна canonical execution economics та
узгодження net із складовими gross і costs. Якщо даних бракує, історія обрізана
пагінацією, є різні валюти або невідомі часові мітки, сума залишається \`null\`
із явним статусом \`PARTIAL\` / \`UNAVAILABLE\` і кодами причин. Підміна пропусків
нулем заборонена. Облік використовує \`Decimal\`, без обчислення фінансів через float.

Цей показник **не еквівалентний Today / 30D Portfolio Net P&L**:
він не містить переоцінки відкритих позицій, вартості фінансування за межами
зафіксованої execution economics та інших неатрибутованих portfolio cashflows.
Тому глобальні поля \`today_net_pnl\` і \`pnl_30d\` залишаються \`null\`,
доки окрема canonical portfolio valuation + cashflow snapshot projection
не пройде reconciliation і Manager Acceptance.


## Історичний Basis

Клас HistoricalRelationshipBasisProjector бере канонічні події двох інструментів із CanonicalMarketEventRepositoryInterface. Доступ можливий тільки за наявності MarketDataView та MarketDataHistoryView. Порівняння допускається для активного економічного зв'язку лише з явною metadata.conversion_verified = true і позитивним metadata.target_units_per_source_unit. Співвідношення 1:1 не припускається автоматично.

Підтримуються QUOTE, BBO, REFERENCE_PRICE, MARK_PRICE, INDEX_PRICE у режимі LIVE, зі статусом OPEN і без quality flags. Різниця source timestamps не більше 30 секунд, quote assets однакові. Значення Basis і bps розраховуються через DecimalMath на сервері; браузер розміщує готові точки на графіку.

За невідомої валюти, відсутньої економічної еквівалентності, некоректних чи несинхронізованих даних або обрізаної історії результат NOT COMPARABLE. Спостереження не вважаються торговим сигналом чи дозволом на виконання угоди.


## Архітектура повного Portfolio NAV Today / 30D

Фінансовий backend отримав окремий механізм повного NAV P&L:
- PortfolioNavWindowProjector виводить результат із двох підтверджених оцінок вартості портфеля та зміни накопичених зовнішніх грошових потоків.
- MysqlPortfolioValuationSnapshotRepository зберігає append-only записи з окремим organization_id / portfolio_id, валютою, часовою міткою та provenance_id.
- Для допуску snapshot до розрахунку обов'язкові valuation_status COMPLETE, ledger_reconciled, marks_reconciled та external_flows_reconciled.
- Немає початкової оцінки, кінцевого NAV, підтвердженої валюти або узгодженої історії: результат UNAVAILABLE, а не вигаданий нуль.
- Вкладеність часового вікна Today і 30D перевіряється за UTC; гранична давність оцінок наразі 900 секунд, фактичні часові мітки показуються разом із результатом.

Формула: Net Portfolio P&L = NAV_close - NAV_open - (CumulativeExternalFlows_close - CumulativeExternalFlows_open).
Формула застосовується тільки до підтверджених valuation snapshots, які враховують переоцінку відкритих позицій. Окремий Realized Execution P&L не може підміняти Portfolio NAV P&L.

Важливе обмеження поточного пакета: система має канонічні сховище, projection, DI, UI та перевірки, але **регулярний producing workflow повністю звірених NAV snapshots з даними broker/ledger/position mark-to-market ще необхідно підключити**. До цього Today/30D Portfolio NAV P&L можуть показувати UNAVAILABLE; повну готовність фінансового циклу на підставі самого сховища не підтверджуємо.

## Контрольований запис NAV

Клас PortfolioNavSnapshotProducer тепер записує NAV лише за повного набору звірених складових: cash, marked positions, liabilities і cumulative external flows. Потрібні прапори узгодження ledger, marks та external flows, fingerprints походження кожної групи й provenance ID. Усі валюти повинні відповідати базовій валюті портфеля; автоматичну FX-конвертацію або нуль замість пропущених полів заборонено. Обчислення проводяться через DecimalMath.

**Незакрита інтеграція:** це захищений producer з контрактом вхідних доказів, але ще не автоматична система отримання звірених даних. До повного приймання необхідні автоматичні постачальники account balances, position marks, liabilities, external flow ledger, перевірка їх походження та планувальник формування snapshots. Після цього потрібні перевірки 24-годинного й 30-денного ряду на реальному paper portfolio. Без таких даних показник залишається UNAVAILABLE.

## Попередня перевірка доказових даних NAV

Додана CLI-команда `php bin/console cos:capital-markets:nav:collect --organization=<ID> --portfolio=paper-master`. Вона збирає tenant-scoped дані з paper portfolio, venue balances, positions, ledger transactions та актуальних Market State й повертає структурований JSON: статус, перелік джерел і блокери. Команда повертає ненульовий код завершення, якщо фінансова звірка не готова. Snapshot не записується.

Поточні явні блокери: підтверджений ledger зовнішніх поповнень/виведень, облік зобов'язань, підтвердження cash balances на майданчиках, reconciliation позицій і курсів. Ці джерела треба підключити окремо, перш ніж запускати автоматичний NAV writer. Планувальник із періодичним записом COMPLETE valuations без такої звірки заборонений.

Для підтвердження актуальності балансових документів попередня звірка перевіряє `effective_at` у UTC. Виписки щодо залишків майданчиків і зобов'язань старші за 15 хвилин відхиляються як `BALANCE_STATEMENT_STALE`, майбутні часові мітки блокуються як `SOURCE_EFFECTIVE_TIME_FUTURE`. Історичні зовнішні рухи коштів не мають такого ж 15-хвилинного обмеження, але залишаються спостереженнями без незалежного затвердження. Жодна з цих перевірок не дає дозволу на автоматичне створення `COMPLETE` NAV.

## Перевірка цілісності фінансових доказів

Trading Ledger проходить окремий аудит збалансованості дебетів і кредитів для кожного asset, ідентичності транзакцій, коректності часу та Decimal-сум. Цей аудит **не підтверджує** зовнішні депозити, зобов'язання чи cash-залишки майданчиків, тому загальне рішення про NAV залишається заблокованим за відсутності цих джерел.

Для формування NAV Snapshot Producer звіряє SHA-256 fingerprints із фактичними наборами cash/liabilities, position marks і зовнішніх flows. Відбитки разом із provenance ID зберігаються в append-only snapshot. Portfolio NAV time-window projector не використовує історичні записи, у яких немає валідного provenance та fingerprints. Це перевірка **цілісності переданих доказів**, а не автоматичне підтвердження їхньої зовнішньої достовірності: інтеграції з фінансовими контрагентами та reconciliation, як і раніше, обов'язкові.

Performance Workspace показує поточні блокери NAV, статус перевірки Ledger, обсяг перевірених позицій та ринкових оцінок. Для mark потрібні LIVE/OPEN, чисті quality flags та актуальна source timestamp не старіша за 30 секунд; неправильні суми, дублікати залишків та некоректні закриті позиції блокують подальшу сертифікацію.

## Оцінка активів для NAV

Розрахунок кандидатної ринкової вартості spot-позиції винесено до PortfolioNavSpotMarkEvidence. Для підтвердженого LONG SPOT або TOKENIZED_EQUITY потрібні коректна кількість, валюта, TRUSTED/LIVE/OPEN BBO, стан без quality flags, SHA-256 fingerprint та ринкова мітка не старша за 30 секунд. Арифметика quantity × mid виконується через DecimalMath. Для SHORT, PERPETUAL, невідомого instrument_kind і контрактів із нестандартним multiplier розрахунок відхиляється: їм потрібна окрема модель маржі, застави та зобов'язань.

Результат має статус CANDIDATE, а прапор mark_reconciled завжди false: він не є підтвердженим NAV. Collector не пише snapshot, доки незалежно не звірено position/venue balances, зовнішні потоки й зобов'язання. У нових токенізованих equity-позиціях зберігається явний instrument_kind, щоб legacy-записи не проходили valuation за припущенням.


## Попереднє звіряння виписок та внутрішніх залишків

PortfolioNavStatementReconciliationPreview порівнює баланси грошових коштів за кожним майданчиком із записаними первинними фінансовими виписками. Внутрішня сума складається з available_amount і reserved_amount, арифметика виконується через DecimalMath. Відхилення, відсутні виписки, різні валюти, повторені джерела та незвірені негрошові активи показуються окремими причинами блокування.

Кандидатні підсумки зовнішніх грошових потоків і зобов'язань виводяться лише як діагностичні дані. Записи зі статусом PENDING_RECONCILIATION не можуть самостійно отримати статус бухгалтерського підтвердження. Навіть однакові суми з різних таблиць не дозволяють створити COMPLETE NAV: потрібні незалежна звірка джерел, повний облік активів, зобов'язань, грошових потоків і перевірена авторизація.

Вкладка Performance відображає розбіжності за майданчиками, а NAV preflight зберігає fail-closed поведінку. Автоматичного підтвердження та запису оцінки за відсутності доказів немає.


## Операторське завантаження первинних фінансових документів

Для первинної інтеграції виписок доступна консольна команда:

`php bin/console cos:capital-markets:nav:evidence:import --organization=ORG --portfolio=paper-master --file=/path/to/source.json --source-file=/path/to/original-statement.pdf`

Вхідний файл містить один об'єкт JSON із типом EXTERNAL_CASH_FLOW, LIABILITY_BALANCE або VENUE_BALANCE, числовим значенням у форматі десяткового рядка, валютою, часом спостереження, provider_id, source_reference, source_document_sha256, collected_by та evidence_id. Параметр `--source-file` обов'язковий: COS перевіряє SHA-256 **фактичних байтів** незалежної виписки проти `source_document_sha256` із JSON. Відсутній, порожній, підмінений або надто великий файл (понад 20 МБ) відхиляється до запису в базу. Збіг хешів доводить відповідність байтів, але **не доводить справжності виписки та не є фінансовим погодженням**. Система перевіряє JSON через PortfolioNavFinancialEvidencePolicy, записує його append-only із tenant scope та повертає RECORDED_PENDING_RECONCILIATION. Повторне використання source identity відхиляється. Команда не змінює Ledger, не підтверджує залишки й не створює NAV snapshot.

Runtime-тест виконує імпорт, повторний імпорт того самого запису та NAV preflight: перший запис мусить пройти, дублікат мусить бути відхилений, а невиконана незалежна бухгалтерська звірка залишає статус BLOCKED. Первинний документ є доказом для перевірки, але не автоматичним правом записати Portfolio P&L.

## Повнота історичних NAV-вікон

При обчисленні Today / 30D Portfolio NAV розрахунок має fail-closed семантику не лише на кінцевих точках. Якщо всередині періоду є snapshot із непідтвердженим Ledger, відсутнім provenance, неповним mark-to-market чи некоректним значенням, він не вилучається мовчки: весь відповідний період отримує `UNAVAILABLE` з причиною `UNVERIFIED_VALUATION_IN_WINDOW`. Snapshot із невідомим часом блокує будь-яке вікно, оскільки неможливо довести, що він поза ним. Недостовірний запис, який точно старший за межі періоду, не псує незалежний результат. Вимога покрита регресійними unit-тестами.

## Історія первинних виписок майданчиків

Під час попереднього порівняння залишків VENUE_BALANCE є знімком на конкретний момент, а не грошовим потоком. Для кожного venue береться найновіший за `effective_at` документ. Старі записи лишаються в append-only сховищі для аудиту, але не додаються до поточного залишку й не позначаються як дубль актуальної виписки. Кількість ігнорованих історичних виписок видно в `historical_venue_statements_ignored`. Два останні документи з однаковим часом для одного venue вважаються неоднозначними й блокують звірку.

Аналогічно LIABILITY_BALANCE повинен містити стабільний `liability_account_id`. Для кожного рахунку використовується лише остання виписка за `effective_at`, а попередні залишаються доступними для аудиту. Дві останні виписки одного рахунку з однаковою міткою часу дають `DUPLICATE_LIABILITY_STATEMENT` і блокують суму. Відкинуті історичні записи підраховуються в `historical_liability_statements_ignored`.

EXTERNAL_CASH_FLOW залишається послідовністю підписаних операцій, а не snapshot. Правила відбору виписок **не підтверджують** джерело та не знімають статус `PENDING_RECONCILIATION`.
