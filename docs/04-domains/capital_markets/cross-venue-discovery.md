---
title: Пошук торгових інструментів між біржами
description: Операторський цикл bStocks Universe, перевірки лістингу на Binance, Bybit і Kraken.
status: active
updated: 2026-10-09
kind: domain
---

# Пошук інструментів на різних біржах

## Бізнес-мета

Ми перевіряємо історичний список кандидатів через поточні біржові каталоги, перш ніж збирати ринкові котирування та оцінювати можливості. Схожий тикер не підтверджує тотожність токенів, права погашення або прибутковість.

## Вихідні дані

Файл resources/capital-markets/universes/binance-bstocks-2026-09-27.csv містить 80 рядків з книги bStocks Binance — 2026-09-30.xlsm. Внутрішня дата джерела: 2026-09-27T17:53:00Z. У каталозі 66 акцій, 14 ETF і дев'ять leveraged/inverse кандидатів. Немає котирувань, цін або виконаних угод. Список не є авторитетним поточним лістингом.

## Операторський сценарій

1. Відкрити /capital-markets/discovery. Для читання потрібен MarketDataView, для сканування MarketDataManage.
2. Натиснути сканування 10 або 80 кандидатів. Сервер надсилає три read-only запити до Binance Spot exchangeInfo, Bybit Spot instruments-info та Kraken AssetPairs. CSRF обов'язковий, повторне сканування не частіше ніж раз на 90 секунд.
3. Перевірити незалежний стан кожного джерела, дату і SHA-256 доказ відповіді. Недоступна біржа отримує UNAVAILABLE, а не NOT_LISTED.
4. LISTED_EXACT_TOKEN_SYMBOL означає лише підтверджений символ у поточному каталозі. Економічний зв'язок лишається UNVERIFIED, а executable завжди false.
5. DISTINCT_TOKEN_REVIEW_REQUIRED означає інший токен на той самий underlying. Наприклад AAPLB і AAPLx не є одним активом.
6. Після незалежної перевірки емітента, співвідношення одиниць, правил погашення і quote currency створити Instrument, Venue, Venue Instrument Mapping.
7. Налаштувати джерела /capital-markets/market-data, підписки на BBO/Volume/OrderBook, виконати Poll now.
8. Перевірити Data Quality і лише тоді перейти до Opportunity Engine. Жоден результат Discovery не є торговою рекомендацією.

Результати зберігаються в tenant-scoped таблиці tn_capital_market_cross_venue_discovery_snapshots. Кожне сканування фіксує організацію, оператора, час, provider health і дані кандидатів.

## Binance Spot Market Data

Реалізовано адаптер binance.spot.rest для публічних HTTPS адрес data-api.binance.vision:
- /api/v3/ticker/bookTicker для Bid/Ask;
- /api/v3/ticker/24hr для Volume;
- /api/v3/depth для OrderBook.

Точність цін і обсягів зберігається десятковими рядками. Точний venue_symbol обов'язковий. RawMarketEvent проходить канонічні Decoder, Normalizer, MarketDataQuality та MarketState. У Binance Spot BBO відсутній власний provider timestamp, тому використовується received_at з explicit RECEIVED_AT authority.

Для використання: створити Binance Venue та підтверджені інструменти, потім disabled Market Data source з adapter_type binance.spot.rest, ввімкнути feature flag capital_markets.market_data.binance.enabled, додати subscriptions, активувати source і виконати Poll now. У разі невдачі джерело лишається непридатним.

## Важлива межа

Stocks Trading/tokenized-assets API Binance відрізняється від публічного Spot API. Для equity metadata, зокрема /sapi/v1/equity/market/tokenized-assets, потрібен X-MBX-APIKEY. Цей адаптер поки не реалізовано, тому відсутність bStocks у Spot exchangeInfo не доводить їхню відсутність у інших продуктах Binance.

Наступні пакети до повного виробничого приймання: авторизований Binance equity metadata, issuer/redemption review, автоматизація підписок і market data ingestion, контроль rate limits та scheduler, реальні Paper E2E угоди, monitor/replay і незалежне NAV reconciliation. Live Trading залишається вимкненим.

Документація API: https://developers.binance.com/en/docs/products/spot/rest-api та https://developers.binance.com/en/docs/catalog/advanced-trading-stocks-trading/api/rest-api/market-data
