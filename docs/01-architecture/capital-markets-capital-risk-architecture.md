---
title: CM-CAPITAL-RISK — архітектура розподілу капіталу та портфельного ризику
description: Архітектурний пакет для портфельного стану, економічної експозиції, ризикових лімітів, детермінованого розподілу капіталу, стрес-тестів і керованого Portfolio Agent.
status: implementation
updated: 2026-10-08
kind: architecture
---

# Архітектура CM-CAPITAL-RISK

Пакет переводить Capital Markets від оцінки окремої угоди до керування спільним портфелем. Потік розробки: Manager → Architect → Developer → Reviewer → QA → Manager Acceptance.

## Матриця повторного використання

| Можливість | Існувала | Архітектурне рішення |
| --- | --- | --- |
| Portfolio / positions | так | розширити як похідний операційний стан |
| Ledger | так | залишити фінансовим джерелом істини |
| Capital reservation | так | повторно використати після схвалення allocation |
| Trade risk | так | розширити портфельним ризиком, не створювати окремий V2 engine |
| Economic exposure | частково | додати перевірене netting і незмінні snapshots |
| Strategy scorecards | так | використовувати як вхід allocator |
| Promotion gates | так | Live allocation лише для дозволеного lifecycle |
| Allocation | ні | новий детермінований constrained allocator |
| Stress | частково | детермінована портфельна stress foundation |
| AI Research runtime | так | Portfolio Agent працює поверх детермінованої simulation |

## Володіння станом

Ledger володіє фінансовою істиною. Portfolio володіє похідним операційним станом. Risk відповідає за envelopes і детерміноване enforcement. Allocation створює proposals та plans, але не виконує orders. Execution споживає схвалені capital reservations.

AI може читати, моделювати, пояснювати й пропонувати. Він не може змінювати hard limits, схвалювати власну пропозицію, переміщати Live capital або змінювати Ledger.

## Правила економічного netting

Gross exposure завжди зберігається. Net exposure дозволяється лише через підтверджений economic relationship. Невідома або непідтверджена еквівалентність стає `UNKNOWN_EXPOSURE` і не бере участі в netting.

Для derivatives допускається delta equivalent. Якщо надійного mapping немає, система використовує консервативний стан, а не нульовий ризик.

## Правила капіталу

Available capital не дорівнює raw cash. Reserved, deployed, locked, margined, unsettled capital, settlement buffer та emergency hedge buffer не можуть повторно використовуватися allocator.

Capital location є явною частиною стану. Cross-venue strategy повинна мати доступний капітал у кожному необхідному location.

## Ієрархія ризикових політик

`SYSTEM → PORTFOLIO → STRATEGY → VENUE → ASSET → INSTRUMENT → POSITION`.

Нижчий рівень може посилити обмеження, але не може послабити hard limit верхнього рівня. Hard limits виконуються детерміновано й не можуть бути overridden AI.

## Детермінований Allocation V1

V1 підтримує `RULE_BASED` та `SCORE_BASED` policies. Ranking враховує expected net return, confidence, execution probability, strategy quality, capacity, risk, concentration та liquidity penalties.

Approved size обмежується available capital, market capacity, physical capital location і hard risk headroom. Однакові Portfolio Snapshot, Opportunity Set і Policy Version повинні давати однаковий allocation fingerprint та однаковий порядок рішень.

## Машина станів ризику

`NORMAL → CAUTION → RESTRICTED → REDUCE_ONLY → HALTED → EMERGENCY`.

`REDUCE_ONLY` та сильніші стани блокують new risk, але дозволяють reduce/close paths. Drawdown, loss budgets, reconciliation mismatch і hard-limit breaches можуть переводити Portfolio у більш суворий стан.

## Перебалансування

Rebalance створює план, а не негайну угоду. Hysteresis і cooldown запобігають allocation thrashing. Estimated costs порівнюються з очікуваним покращенням risk/return; якщо витрати перевищують benefit, рішенням стає `HOLD`.

## Конкурентність та idempotency

Allocation Plan має детермінований input fingerprint з unique constraint. Approval переходить лише `PROPOSED → APPROVED`. Повторне схвалення не створює другу reservation.

Reservation ID детерміновано формується з Allocation Plan та Opportunity. Після restart система може знайти вже створену reservation і продовжити процес без повторного використання capital.

## Межі повноважень AI

Portfolio Agent має тільки read, simulation і proposal tools. Він не отримує tools для:

- зміни hard risk limits;
- approval власного proposal;
- відключення kill switch;
- прямого Live execution;
- зміни Ledger;
- довільного переміщення capital.

Детерміновані Risk Engine та Capital Allocation Engine залишаються фінансовою authority.
