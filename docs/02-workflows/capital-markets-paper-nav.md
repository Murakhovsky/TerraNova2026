---
title: Paper NAV та P&L без реальних біржових балансів
description: Віртуальний капітал, автоматичні оцінки, контроль коштів та Today/30D Performance в FOS Capital Markets.
status: implementation
updated: 2026-10-09
kind: workflow
---

# Автоматизований Paper NAV і P&L

## Бізнес-мета

Мати в FOS наочні результати тестових стратегій без депозиту на біржі.
Власний Paper Ledger і канонічні свіжі біржові котирування формують
оцінку **SIMULATED**, а не реально підтверджений фінансовий NAV.

## Учасники

- Оператор FOS створює тестовий портфель з вибраним капіталом та валютою.
- Paper Execution створює віртуальні операції, резерви, позиції та проводки.
- Market Data Domain отримує фактичні LIVE quotes для оцінки позицій.
- Планувальник COS раз на 10 хвилин створює snapshot за умови ввімкнення.
- Менеджер дивиться результати у Capital Markets → Overview / Performance.

## Карта коду

- `PaperNavValuation` рахує simulated equity та unrealized P&L.
- `PaperNavSnapshotService` підтягує paper-субрахунки, позиції та market states.
- `MysqlPaperNavSnapshotRepository` зберігає незмінні simulation-only snapshots.
- `PaperNavWindowProjector` дає окремі Today/30D результати.
- `CapturePaperNavSnapshotHandler` виконує планові завдання.
- `CosScheduleProvider` додає опціональний запуск за розкладом.
- `DecisionWorkspaceReadService` передає результати в Overview/Performance.
- `tests/unit/capital_markets_paper_nav.php` перевіряє фінансові інваріанти.

## Перше ввімкнення

1. Виконати всі міграції, включно з
   `20261009_000137_capital_markets_paper_nav.sql`.
2. Ініціалізувати paper portfolio наявним авторизованим API:
   `POST /api/v1/capital-markets/tokenized-equities/paper-portfolio`.
   Приклад JSON: `{"currency":"USD","initial_capital":"10000"}`.
   Це **симуляція**, а не переказ на Bybit.
3. Зважати, що чинний API **перезаписує стан paper portfolio при повторній
   ініціалізації**. Після першого snapshot зміна initial capital вимагає
   окремого облікового epoch, інакше нові snapshots блокуються.
4. Запустити ручний preflight у контейнері Symfony:

   ```bash
   php bin/console cos:capital-markets:paper:nav:snapshot \
     --organization=default --portfolio=paper-master
   ```

5. Встановити на сервері **за бажанням**:

   ```dotenv
   COS_CM_PAPER_NAV_SCHEDULER_ENABLED=1
   COS_CM_PAPER_NAV_SCHEDULER_INTERVAL_MINUTES=10
   COS_ORGANIZATION_ID=default
   ```

   Переконатися, що вже запущені Messenger/Scheduler workers для
   `scheduler_cos` і `async`. Без робочих воркерів сам прапорець
   не запускає планове створення snapshots.

6. Переглянути `/capital-markets/performance`: Certified NAV та
   Paper NAV показуються **окремо**.

## Формула симуляції

```text
Paper Capital = available_capital + reserved_capital
Paper Equity  = Paper Capital + Σ(open long spot quantity × (fresh mid − entry))
Paper Net P&L = Paper Equity − initial_capital
Paper Today/30D P&L = closing Paper Equity − opening Paper Equity − external flow delta
```

Paper Capital є **контрольним внутрішнім капітальним рахунком**.
Venue balances звіряють inventory, але не додаються вдруге до Equity.
Ця модель охоплює long-only spot/tokenized equity в одному portfolio
з відомими ціною входу й ринковими LIVE marks. Realized P&L береться
з paper capital book: потрібно, щоб усі комісії, що фактично сплачені,
вже були відображені в ньому. Це не бухгалтерський звіт реального брокера.

## Правила безпеки

- Капітал має бути явно ініціалізований. Запис не створюється автоматично
  з вигаданих стартових $10 000.
- Paper і Certified NAV фізично зберігаються у **різних таблицях**.
  SIMULATED ніколи не має `ledger_reconciled=true`.
- Сталість: історичні snapshots не редагуються. Невідповідність
  `initial_capital` / currency після скидання портфеля блокує запис.
- Якщо позиції мають непідтримувану похідну модель, SHORT, стару ціну,
  недоступне inventory або неузгоджені резерви, статус `UNAVAILABLE`.
- Вихід з позиції, mark-to-market, funding, комісії та модель derivatives
  потребують достатньо повного джерела даних. Відсутні величини не стають
  нулем за замовчуванням.
- Today має справжній snapshot біля 00:00 UTC і поточний snapshot; 30D
  вимагає збережену оцінку 30 днів тому. Перший запуск не вигадує минуле.
- Режим PAPER не створює і не дозволяє live exchange order, API keys
  або депозитів.

## Обмеження й наступні етапи

Для perpetual/short/margin/FX потрібен окремий simulated derivatives
engine з margin collateral, funding, liquidation, кредитами та FX rates.
Нинішня spot-only оцінка повертає `UNAVAILABLE` замість спрощеного
та небезпечного множення position quantity на поточну ціну.

Якщо користувач має нульовий реальний Bybit account, експортувати
реальні account statements не потрібно. Всі API до торгових даних
можуть залишатися тільки для публічних market observations.
