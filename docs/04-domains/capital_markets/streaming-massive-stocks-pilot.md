---
title: Потокові NBBO котирування американських акцій Massive
description: Пілот reference-only stock quotes від Massive для зіставлення із токенізованими акціями.
status: draft
kind: engineering
updated: 2026-10-13
---

# Потокові NBBO котирування Massive Stocks

Реалізовано обмежений пілот каналу **Q (NBBO)** через WebSocket Massive для одного справжнього американського біржового символу, попередньо зареєстрованого у Capital Markets. Джерело повинне мати роль **ReferenceSource**, без TradingSource і без Venue. Потік не створює фіктивної позиції, біржового токена або торгової пари xStocks. Зв'язок underlying ↔ tokenized equity працює через існуючі механізми Economic Relationship та Market Intelligence.

## Протокол

Окремо від ринкових повідомлень worker надсилає WebSocket Ping кожні 25 секунд та приймає тільки реальний Pong як підтвердження transport liveness. Без нього сесія переходить у недовірену. Після TLS-підключення Massive надсилає статус connected. Клієнт передає AUTH, чекає **auth_success**, лише потім підписується на **Q.{symbol}** і чекає підтвердження subscribed to: Q.{symbol}. Повідомлення надходять як **масиви JSON**, можуть містити кілька об'єктів. Довільні статуси auth_failed/error, неправильний символ і котирування до ACK зупиняють сесію.

Ціни з числового JSON зберігаються як точні десяткові рядки, без округлення через PHP float. Зберігається SIP timestamp `t`, ідентифікатор `q` (монотонний за символом протягом торгової доби, але **не безперервний**), режим LIVE/DELAYED, NBBO provenance. Зміна торгової дати Нью-Йорка дозволяє нову сесію нумерації. Одностороннє котирування без bid або ask **не отримує статус довіреного BBO**.

Для подальшого приймання використовуються звичайний MassiveMarketDataDecoder, reference-state engine і MySQL lease/fencing gateway. Аварійний sweeper також знає технічний shard massive-stocks-single-bbo.

## Доступ і ліцензія

**API-ключ зберігається лише у наявному Credential Vault**, а не в CLI, URL чи вихідному JSON. Джерело має явно задати metadata.data_mode як LIVE або DELAYED і відповідний licenseProfile: MASSIVE_STOCKS_WS_Q_LIVE або MASSIVE_STOCKS_WS_Q_DELAYED. Це **внутрішня декларація адміністратора**, а не автоматичне підтвердження постачальником прав на потік.

Тариф Massive повинен дійсно містити канал Stocks Quotes. За офіційною сторінкою Massive поточний індивідуальний **Stocks Advanced** включає real-time Quotes; дешевші індивідуальні тарифи можуть не містити цей WS-канал. Можливість delayed feed теж потрібно підтвердити в акаунті. Наявність REST-ключа **не гарантує** WebSocket NBBO.

## Контрольований запуск

Вимоги: увімкнені DomainEnabled, MarketData, MarketDataMassive, MarketDataStreaming, TokenizedEquity; рівно одна BBO-підписка, налаштований underlying, правильні дані ліцензії. Додатково встановити дозвіл процесу:

```bash
COS_CM_STREAMING_MASSIVE_STOCKS_PILOT=1 php symfony/bin/console cos:capital-markets:market-data:stream:massive-stocks ORG_ID SOURCE_ID --seconds=60
```

Для LIVE застосовується wss://socket.massive.com/stocks, для DELAYED wss://delayed.massive.com/stocks. Режими не можна змішувати для арбітражу без явного врахування давності даних.

## Не виконано

Це лише пілот одного ticker. Ще відсутні підтверджений зовнішній запуск із платною підпискою та MySQL, корпоративна перевірка ліцензії/дозволів на повторне поширення даних, багатосимвольні підписки, provider-aware календар фондового ринку й 24-годинний soak. Реальна торгівля не активована.

Документація Massive:
- https://massive.com/docs/websocket/stocks/quotes
- https://massive.com/docs/websocket/quickstart
