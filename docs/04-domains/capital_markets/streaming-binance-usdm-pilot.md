---
title: Потокові котирування Binance USD-M Perpetual
description: Пілот отримання найкращих bid та ask на Binance USD-M Futures з контролем типу контракту.
status: draft
updated: 2026-10-12
kind: engineering
---

# Binance USD-M Perpetual: пілот WebSocket BBO

Підключено публічний WebSocket Binance USD-M через `wss://fstream.binance.com/public/stream` до одного активного контракту типу `perpetual` із налаштованим символом і торговим майданчиком.

Канал `{symbol}@bookTicker` передає найкращі ціни купівлі й продажу. **Це не повний ордербук, не mark price і не funding rate.** Новий код нічого з цих показників не домислює.

Після об'єднання публічних потоків USD-M та COIN-M провайдер надсилає ознаку сімейства `st`. Система приймає лише `st=1`, а інші або відсутні значення відхиляє. Так ми не переплутаємо USDT-маржинальні та coin-маржинальні деривативи.

Підписка використовує унікальний рядковий `id` згідно з Binance Futures WebSocket API. Перевіряються підтвердження підписки, символ, назва події `bookTicker`, монотонність ідентифікатора BBO, часи `E` та `T`, свіжість події, десяткова точність і зіставлення інструмента з Venue. Відновлення після збою проходить через існуючий supervisor та нове підтвердження підписки.

## Окремий запуск

Необхідні чотири прапорці `capital_markets.enabled`, `market_data.enabled`, `market_data.binance.enabled`, `market_data.streaming.enabled` та `capital_markets.crypto_spot_perpetual.enabled`. Додатково потрібна явно встановлена змінна `COS_CM_STREAMING_BINANCE_USDM_PILOT=1`.

```bash
COS_CM_STREAMING_BINANCE_USDM_PILOT=1 php symfony/bin/console cos:capital-markets:market-data:stream:binance-usdm ORG_ID SOURCE_ID --seconds=60
```

Для джерела повинен бути зареєстрований тип адаптера `binance.usdm.perpetual.bbo.v1`, активна рівно одна BBO-підписка і реальний інструмент сімейства `perpetual`. Лише після перевірки міграції lease, прав джерела та інтеграційного середовища можливий тестовий запуск.

## Обмеження

Це **лише один публічний BBO-інструмент**, без фінансування, mark/index price, профілю дериватива та повного L2. Не завершені multi-symbol, REST/WS source-mode coordination, сукупне управління shard та промисловий зовнішній моніторинг. Промисловий запуск і жива торгівля заборонені до приймання.

Джерела протоколу: https://developers.binance.com/en/docs/catalog/core-trading-derivatives-trading-usd-s-m-futures/api/ws-streams/public та https://developers.binance.com/en/docs/catalog/core-trading-derivatives-trading-usd-s-m-futures/api/rest-api/market-data
