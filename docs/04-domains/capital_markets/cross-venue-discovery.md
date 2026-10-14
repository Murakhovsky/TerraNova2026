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
2. Натиснути сканування 10 або 80 кандидатів. Сервер надсилає три read-only запити до Binance Spot exchangeInfo, Bybit Spot instruments-info та Kraken AssetPairs. CSRF обов'язковий, повторне сканування не частіше ніж раз на 90 секунд завдяки атомарному резервуванню спроби до зовнішніх запитів, навіть якщо вони завершилися помилкою.
3. Перевірити незалежний стан кожного джерела, дату і SHA-256 доказ відповіді. Недоступна біржа отримує UNAVAILABLE, а не NOT_LISTED.
4. LISTED_EXACT_TOKEN_SYMBOL означає лише підтверджений символ у поточному каталозі. Економічний зв'язок лишається UNVERIFIED, а executable завжди false.
5. DISTINCT_TOKEN_REVIEW_REQUIRED означає інший токен на той самий underlying. Наприклад AAPLB і AAPLx не є одним активом.
6. Після незалежної перевірки емітента, співвідношення одиниць, правил погашення і quote currency створити Instrument, Venue, Venue Instrument Mapping.
7. Налаштувати джерела /capital-markets/market-data, підписки на BBO/Volume/OrderBook, виконати Poll now.
8. Перевірити Data Quality і лише тоді перейти до Opportunity Engine. Жоден результат Discovery не є торговою рекомендацією.

Результати зберігаються в tenant-scoped таблиці tn_capital_market_cross_venue_discovery_snapshots. Кожне сканування фіксує організацію, оператора, час, provider health і дані кандидатів.

## Котирування Binance Spot

Реалізовано адаптер binance.spot.rest для публічних HTTPS адрес data-api.binance.vision:
- /api/v3/ticker/bookTicker для Bid/Ask;
- /api/v3/ticker/24hr для Volume;
- /api/v3/depth для OrderBook.

Точність цін і обсягів зберігається десятковими рядками. Точний venue_symbol обов'язковий. RawMarketEvent проходить канонічні Decoder, Normalizer, MarketDataQuality та MarketState. У Binance Spot BBO відсутній власний provider timestamp, тому використовується received_at з explicit RECEIVED_AT authority.

Для використання: створити Binance Venue та підтверджені інструменти, потім disabled Market Data source з adapter_type binance.spot.rest, ввімкнути feature flag capital_markets.market_data.binance.enabled, додати subscriptions, активувати source і виконати Poll now. У разі невдачі джерело лишається непридатним.

## Порівняння котирувань

Після Discovery можна натиснути «Отримати актуальні Bid/Ask» на тій самій
сторінці /capital-markets/discovery. Сканер працює **лише** зі свіжим
Discovery (до 15 хвилин) та обмежує повторні спроби до одного запуску
на організацію за 60 секунд. Результат пишеться в tenant-scoped таблицю
tn_capital_market_cross_venue_quote_snapshots з evidence SHA-256.

Binance Spot best bid/ask та Bybit Spot tickers читаються пакетно, Kraken
отримує тільки вже знайдені внутрішні коди торгових пар. Це зменшує
кількість REST-запитів і уникає вгадування позначень майданчика.

Відхиляються неповні Bid/Ask, нульова кількість і перехрещений стакан;
помилка одного джерела не стирає спостереження інших. Якщо джерело
недоступне, вказується UNAVAILABLE, якщо ціна непридатна, NO_VALID_BBO.
Час відображає **отримання локальним сервером**, а не точну біржову мітку.

Значення є лише діагностичними спостереженнями, **не** канонічними
MarketState. Немає підтвердженої економічної тотожності, зіставлення USD
із USDT, реальних комісій та ліквідності для виконання угоди.
Тому система не обчислює net edge, не створює Opportunity і не
запускає Paper Execution.

## Важлива межа

Stocks Trading/tokenized-assets API Binance відрізняється від публічного Spot API. Для equity metadata, зокрема /sapi/v1/equity/market/tokenized-assets, потрібен X-MBX-APIKEY. Цей адаптер поки не реалізовано, тому відсутність bStocks у Spot exchangeInfo не доводить їхню відсутність у інших продуктах Binance.

Наступні пакети до повного виробничого приймання: авторизований Binance equity metadata, issuer/redemption review, автоматизація підписок і market data ingestion, контроль rate limits та scheduler, реальні Paper E2E угоди, monitor/replay і незалежне NAV reconciliation. Live Trading залишається вимкненим.

Документація API: https://developers.binance.com/en/docs/products/spot/rest-api та https://developers.binance.com/en/docs/catalog/advanced-trading-stocks-trading/api/rest-api/market-data

## Збережений кандидатний пул (CM-INSTRUMENT-POOL-RT, етап P0.1)

Після ручного Discovery FOS тепер ідемпотентно оновлює `tn_capital_market_cross_venue_pool_candidates` у межах організації. Ключ запису: `(organization_id,pool_key,seed_token,venue)`; `pool_key=BINANCE_BSTOCKS_SEED`. Стани `LISTED_EXACT_TOKEN_SYMBOL`, `DISTINCT_TOKEN_REVIEW_REQUIRED`, `NOT_LISTED` зберігаються окремо для кожного venue. `UNAVAILABLE` не стирає останнє спостереження. Усі економічні зв'язки лишаються `UNVERIFIED` / `NOT_ESTABLISHED`; дані не створюють canonical Instrument або Opportunity. Окремий блок на сторінці `/capital-markets/discovery` відображає кількості за станами. Перед відкриттям сторінки після оновлення коду застосувати міграцію `20261014_000140_capital_markets_candidate_pool.sql`.

Поточні обмеження: автоматичне розкладення Discovery, перевірка issuer evidence, канонічна registry reconciliation, Subscription Planner і real-time streams є наступними критеріями приймання, а не наявною функціональністю.

## Запуск перевірки через командний рядок

Для керованого повторного сканування доступна команда:

```bash
php symfony/bin/console cos:capital-markets:discovery:run ORGANIZATION_ID ACTOR_ID --limit=80
```

Використовувати тільки з дозволеним service actor та в межах tenant. Результат `RECORDED` означає збереження доказів, але не означає, що всі джерела доступні: `source_health` має бути перевірений окремо. Паралельні виклики обмежуються існуючим reservation gate (90 секунд). Перевірку operator permissions для фонового service actor та розклад у Kernel Scheduler необхідно реалізувати до production-автоматизації; CLI самостійно не надає торгового допуску.

## Автоматичне оновлення пулу

Планувальник `CosScheduleProvider` підтримує повідомлення `RefreshCrossVenueCandidatePool` із явним tenant і service actor. За замовчуванням вимкнено. Після створення та перевірки service actor, налаштування транспорту `async` і застосування міграції встановити `COS_CM_POOL_DISCOVERY_SCHEDULER_ENABLED=1`, `COS_CM_POOL_DISCOVERY_ACTOR_ID=<ідентифікатор>` та `COS_CM_POOL_DISCOVERY_INTERVAL_MINUTES=1440`. Organization ID береться з уже наявного `COS_ORGANIZATION_ID`. Підтримується інтервал 15–1440 хвилин. Повідомлення виконує сканування повного seed-набору, зберігає snapshot і синхронізує candidate pool із cooldown guard; навіть успішний запуск не встановлює VERIFIED і не передає торговий сигнал.

## Безпечний імпорт історичного XLSM

У розділі `/capital-markets/discovery` доступні завантаження XLSM/XLSX/CSV, перевірка без запису та окрема дія імпорту. Вимагаються чинна сесія, CSRF і право Market Data Manage. Оригінальні макроси не виконуються. Імпорт зберігає лише історичні заявлені атрибути; перевірений економічний зв'язок або live listing із нього не виникає. Максимум 5 МіБ і 500 записів. Повторне завантаження не створює нових рядків, суперечливі або старіші ідентифікатори блокуються. Міграція: `20261014_000141_capital_markets_seed_evidence.sql`. Команда для адміністратора:

```bash
php symfony/bin/console cos:capital-markets:instruments:import ORG_ID ACTOR_ID --file=/path/to/catalog.xlsm
php symfony/bin/console cos:capital-markets:instruments:import ORG_ID ACTOR_ID --file=/path/to/catalog.xlsm --apply
```

У CI додано реальний MySQL-тест історичного CSV на 80 записів, повторення, відокремлення організацій, блокування конфліктів і відкат транзакції. Проходження цього тесту перевіряється окремо за відповідним SHA.

## Планування підписок на котирування

Для вже перевірених канонічних інструментів з ACTIVE venue mapping, наявним джерелом і підтримуваним BBO можна попередньо переглянути та застосувати план через наявний сервіс Market Data Administration. План не створює інструментів за схожістю тикера, не оживляє призупинені підписки і не вважає REST polling підтвердженим WebSocket streaming.

```bash
php symfony/bin/console cos:capital-markets:subscriptions:plan ORG_ID ACTOR_ID --source=SOURCE_ID
php symfony/bin/console cos:capital-markets:subscriptions:plan ORG_ID ACTOR_ID --source=SOURCE_ID --apply
```

За замовчуванням команда працює як dry-run. Ліміт планування 250 пар. Непідтверджені ідентифікатори та недоступні джерела не активуються. Підтвердження реального streaming BBO й керованого воркера залишається окремим acceptance gate.

## Заповнення канонічного реєстру без автоматичної активації

Після імпорту XLSM або CSV доступне створення канонічних `DRAFT` через `/capital-markets/discovery` або через команди нижче. Потрібна роль із правом `InstrumentManage`; конфлікти ідентичності блокують пакет до запису. Кожен запис створюється через `CapitalMarketsFoundationBoundary` з аудитом і подією, але без Venue Instrument, економічного зв'язку, підписки на дані та торгового допуску.

```bash
php symfony/bin/console cos:capital-markets:instruments:seed-drafts ORG_ID ACTOR_ID --limit=100
php symfony/bin/console cos:capital-markets:instruments:seed-drafts ORG_ID ACTOR_ID --limit=100 --apply
```

Runtime CI містить MySQL acceptance: створення 80 DRAFT, повторний запуск без нових записів, окремість underlying, аудит/події, відсутність зв'язків/підписок і блокування конфлікту канонічного символу без часткової вставки. Наявність тесту не дорівнює проходженню: перевіряти підсумок на останньому SHA.

## Резервний контрольований збір BBO

У `CosScheduleProvider` є **вимкнений за замовчуванням** фоновий контур тільки для публічних REST Bid/Ask. Він використовує наявні `MarketSourcePollingService`, ingestion і Market State. Для ввімкнення потрібен чинний service actor із `MarketDataManage`, активне джерело, активні BBO-підписки та квоти. Налаштування: `COS_CM_BBO_POLL_SCHEDULER_ENABLED=1`, `COS_CM_BBO_POLL_ACTOR_ID=<ID>`, `COS_CM_BBO_POLL_INTERVAL_MINUTES=15`; організація визначається `COS_ORGANIZATION_ID`. Не більше трьох REST-джерел і десяти BBO-targets на джерело. Джерела з іншими активними типами підписок блокуються для цього контуру. Бюджет обраховується консервативно з урахуванням додаткових REST-запитів і повторів. Для безпеки використовуються MySQL advisory lock, tenant/source-scoped cooldown та запис останнього стану в `tn_capital_market_bbo_poll_gates`; необхідна міграція `20261014_000142_capital_markets_bbo_poll_gate.sql`.

**Цей механізм не є production WebSocket:** стан `OK` означає успішний REST-цикл, а не безперервний L2/orderbook чи гарантію актуальності. Для переходу до масового Opportunity scanning потрібні незалежне приймання WebSocket workers/recovery, timestamp/freshness, book-gap detection, source entitlement і перевірений економічний mapping.

## Батьківські акції та ETF окремо від токенів

Кандидати містять два незалежні класи майбутніх інструментів: tokenized-security і reference asset. Кожен базовий тикер з імпортованих рядків можна створити окремим `DRAFT` у канонічному реєстрі. Для `Stock` family=`equity`, для `ETF` family=`fund`; leveraged/inverse ETF ніколи не стають звичайними акціями. Повторне створення ідентифікується за `PROVIDER_ID` з namespace `BINANCE_BSTOCKS_UNDERLYING_SEED`. Користувач має окрему дію на сторінці Discovery або CLI:

```bash
php symfony/bin/console cos:capital-markets:instruments:seed-drafts ORG_ID ACTOR_ID --underlyings
php symfony/bin/console cos:capital-markets:instruments:seed-drafts ORG_ID ACTOR_ID --underlyings --apply
```

Цей шлях не створює автоматичний зв'язок `AAPL↔AAPLB` та не допускає виконання угод. Для отримання NBBO базових акцій потрібно окреме перевірене reference-data venue/provider mapping і доступ до ринкових даних.

## Ручне приймання послідовності 1 → 2 → 3

1. На `/capital-markets/discovery` перевірте, що історичний каталог має 80 кандидатів. Імпортуйте наданий XLSM спочатку через «Перевірити без запису», а потім окремо через «Імпортувати». Повторний імпорт не повинен створити другі записи.
2. Окремо запустіть dry-run і створення 80 tokenized-security DRAFT та 80 DRAFT underlying (66 equity + 14 ETF/fund) у тому самому канонічному реєстрі. У блоці «Стан канонічного пулу» мають бути дві незалежні групи. Обидві групи залишаються DRAFT без торгового допуску.
3. Запустіть Discovery усіх 80 кандидатів і перевірте незалежний стан Binance, Bybit, Kraken. Якщо API недоступне, має бути UNAVAILABLE, а не фальшиве NOT_LISTED. Різні issuer/токени на одному underlying не поєднуються.
4. Для котирувань вручну зареєструйте й підтвердьте Venue + Venue Instrument mapping, налаштуйте окремі REST source і BBO subscription через наявний Market Data UI. До завершення review не позначайте instrument ACTIVE.
5. Лише після окремого підтвердження дозволів та обмежень можна увімкнути опціональний REST BBO scheduler. Блок «Стан контрольованого REST BBO» показує статус останньої спроби, UTC-час і freshness (RECENT/STALE). STALE, FAILED, пропущені джерела чи відсутні subscription не є прийнятними для Opportunity.
6. Production WebSocket/L2/порівняння з базовими акціями приймаються **окремо**, після підключення прав провайдера та завершення streaming PR. Без цього заборонено запускати масовий Opportunity-пошук.

Це інструкція для ручного приймання, а не свідчення, що production ці дії вже виконав.

## Ручне підтвердження лістингу (без підтвердження економічної тотожності)

Для переходу від Discovery до підписки BBO необхідні три незалежні умови: (1) точний venue listing із Discovery не старше 48 годин; (2) явне рішення `REVIEWED_LISTING` від оператора, який має одночасно `MarketDataManage` і `InstrumentManage`, з HTTPS-посиланням офіційної біржі й SHA-256 збереженого доказу; (3) окремо ACTIVE canonical tokenized-security, із відповідним provider identifier і ACTIVE VenueInstrument mapping. Операторський інтерфейс `/capital-markets/discovery` містить список для review, кнопку підтвердження лише лістингу та відкликання. MySQL журнал `tn_capital_market_pool_listing_reviews` незмінно фіксує actor, venue, symbol, quote, source timestamp і evidence digest. При зміні listing/symbol/quote source-ревізії раніше встановлений review скидається. За відсутності підтвердження BBO planner повертає порожній план, а не підключає інструмент.

Це **не** перевірка емітента, базового забезпечення, redemption, equivalence або торгового дозволу. Вони мають окремі acceptance gates. Міграція: `20261015_000143_capital_markets_listing_review.sql`.
