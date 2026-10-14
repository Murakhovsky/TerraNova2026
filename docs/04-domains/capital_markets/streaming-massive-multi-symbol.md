---
title: Багатосимвольний потік NBBO для Massive Stocks
description: Контрольований batch-пілот котирувань кількох базових американських акцій через один авторизований WebSocket.
status: draft
kind: engineering
updated: 2026-10-14
---

# Багатосимвольний WebSocket Massive Stocks

Цей інкремент дозволяє отримувати **NBBO від 2 до 25 базових акцій** через один WebSocket Massive, замість окремого з’єднання для кожного тикера. Масштабування робиться в межах **одного MarketSource** з однією орендою worker. Це відповідає обмеженню Massive на кількість одночасних WebSocket-з'єднань за класом активів.

Потрібні реально зареєстровані в COS Equity-інструменти, рівно 2–25 активних підписок BBO для джерела, роль лише ReferenceSource, відсутність Venue/TradingSource та налаштовані дані про право використання Massive Stocks Quotes.

Після перевірки authentication WebSocket відправляє **одне SUBSCRIBE** для `Q.AAPL,Q.MSFT,...`. Кожен тикер повинен отримати **окреме підтвердження**. До підтвердження всіх підписок жодні котирування не публікуються. У Massive статуси можуть надходити кількома об'єктами в одному JSON-масиві.

## Цілісність даних

- Для кожного тикера окремо відстежується вік останнього повного NBBO. Якщо інші акції оновлюються, а конкретна акція мовчить понад 120 секунд, **лише її ReferenceMarketState** переходить у UNTRUSTED. Поза годинами ринку неактивні тикери залишаються в карантині, а не створюють цикл повторних підписок; календар торгових сесій ще не інтегровано.
- Окремо для кожного тикера перевіряються зростання quote sequence `q`, торговий день Нью-Йорка, timestamp SIP, двосторонність NBBO та десяткова точність.
- `q` не повинен зростати без пропусків. Це **не** L2 orderbook sequence.
- Односторонній NBBO та stale timeout переводять у карантин **лише відповідний тикер** і fenced-інвалідують його state. Підписка залишається відкритою; наступний повний NBBO з новішим sequence відновлює довіру. Sequence rollback, некоректні поля або crossed market потребують жорсткого відновлення лише цього тикера: fenced інвалідація → `UNSUBSCRIBE` → ACK → повторний `SUBSCRIBE` → ACK → перший повний валідний NBBO. Здорові символи продовжують працювати. Неочікуваний символ, помилка авторизації, втрата WebSocket або lease/fencing failure залишаються source-wide помилками.
- Усі дані зберігаються як окремі `ReferenceMarketState` базових Equity, а не як штучні біржові торгові пари. REST та WebSocket координують право запису через наявний source writer gate.

Додатково MySQL lease acquisition тепер забороняє **два живих shards одного source**. Тому старий односимвольний Massive worker не зможе одночасно працювати з новим batch worker на тому самому джерелі. Перевірка включена в Runtime CI.

## Контрольований запуск

Наявні прапорці DomainEnabled, MarketData, MarketDataMassive, MarketDataStreaming, TokenizedEquity та змінна середовища `COS_CM_STREAMING_MASSIVE_STOCKS_PILOT=1` залишаються обов'язковими. В Credential Vault повинен бути реальний Massive API-ключ. Пара `metadata.data_mode` та `licenseProfile` має відповідати LIVE або DELAYED і раніше встановленому профілю Massive Stocks Q.

```bash
COS_CM_STREAMING_MASSIVE_STOCKS_PILOT=1 php symfony/bin/console cos:capital-markets:market-data:stream:massive-stocks-batch ORG_ID SOURCE_ID --seconds=60
```

**Тільки тестовий запуск.** Дозволені за замовчуванням залишаються лише раніше погоджені source та credentials. Реальне підключення вимагає активної оплаченої підписки на Massive Stocks Quotes та дотримання ліцензії на використання даних.

## Невиконані умови

Не виконані зовнішній WebSocket+MySQL canary, batch soak, контроль ліцензій у Massive-акаунті, інтерфейс оператора, окремі shard-настроювання для інших провайдерів і per-channel risk/trust. Per-instrument quarantine для Massive реалізовано; узагальнення такого контролю на інші провайдери, окремі sharding-політики та перевірений календар торгових сесій ще потрібні. Не вмикати live trading.

Специфікація: https://massive.com/docs/websocket/stocks/quotes та https://massive.com/docs/websocket/quickstart
