---
title: Розширення провайдерів, аудит відтворення та масштабування
description: Процедури перевірки резервних джерел, незмінності сирих подій та безпечного розподілу воркерів між вузлами.
status: draft
kind: operations
updated: 2026-10-15
---

# Розширення провайдерів, аудит та масштабування

## P1.10 Резервні джерела

Додано OKX Spot REST v5 як окремий adapter type okx.spot.v5. Підтримує BBO, 24-годинний volume і L2 snapshot. Використовує дозволений HTTPS-домен www.okx.com, перевіряє повний інструмент виду BTC-USDT, точний venue mapping, provider ts, рядкові decimal-поля й тип payload. Залишається під feature flag MarketDataOkx та existing source registration, без автопідключення чи торгівлі.

API OKX може повертати дані зі незалежних кешів, тому послідовні REST-відповіді не гарантують монотонного часу. Реальний LIVE stream і provider ACK з OKX Spot **ще не впроваджені**. Джерело можна використовувати як окрему контрольну REST-копію, але не підміняти ним підтверджені WebSocket sessions.

Reconciliation використовує тільки однаковий canonical instrument ID, активні subscription, незалежно схвалені SOURCE_ENTITLEMENT, ролі Trading/Validation, незалежні venues, придатні LIVE BBO, quality >= 80, надійний годинник та свіжість не більше 120 секунд. Схожі тикери токенізованих активів не визнаються економічно ідентичними; USD та USDT не конвертуються без підтвердженого FX-потоку. Порівняння через Decimal, без binary float. При divergence, недостатньому кворумі або різній валюті повертається fail-closed результат, а не альтернативна ціна для виконання.

    php symfony/bin/console cos:capital-markets:market-data:evidence reconcile ORG ACTOR --instrument=INSTRUMENT --max-bps=30

За явної команди --record і права market_data.manage можна зберегти append-only receipt з SHA256 результату. Це лише операційний аудит, не автоматична зміна пріоритетів або routing до execution.

## P1.11 Аудит сирих даних та відтворення

Уже наявний tn_capital_market_raw_events зберігає необроблену структуровану RawMarketEvent payload та transport metadata. Доповнення tn_capital_market_raw_provenance записує SHA256 payload, metadata та цілісного envelope **в одній транзакції** зі створенням raw-події. Події з минулих релізів без такого доказу мають статус NO_PROVENANCE, а не автоматично підтверджуються заднім числом.

Read-only cursor audit перевіряє ці підписи повторним обчисленням. Ніяких секретів Vault, HTTP-заголовків авторизації чи вмісту payload у звичайному звіті.

    php symfony/bin/console cos:capital-markets:market-data:evidence provenance ORG ACTOR --source=SOURCE --cursor=0 --limit=100

Режим replay бере лише збережені записи з валідним provenance, змінює data mode на REPLAY, викликає існуючий adapter decoder і normalizer **без виклику ingestion, EventBus, capital reservation, opportunity чи execution**. Replay додатково порівнює повторно обчислений canonical fingerprint з історично збереженим, виявляючи drift після зміни декодера. Відсутність старого canonical baseline не прирівнюється до успішного parity. Порушення контракту або пошкоджені записи відправляє до REPLAY_QUARANTINED. Діапазон обмежений сімома днями і двомастами подіями за запуск. Перевищення ліміту повертає `REPLAY_PARTIAL`, а порожня історія `NO_EVIDENCE`; обидва випадки не є успішним повним replay. При `REPLAY_PARTIAL` оператор зменшує часовий діапазон. Вхідні transport metadata відхиляються, якщо містять поля авторизації, cookies, ключі або секретні параметри URL; сирі значення облікових даних не можна архівувати.

    php symfony/bin/console cos:capital-markets:market-data:evidence replay ORG ACTOR --source=SOURCE --from=2026-10-15T00:00:00Z --to=2026-10-15T01:00:00Z --limit=100

Це відтворення структурованих RawMarketEvent, а не незмінний повний TCP/WebSocket PCAP. Окремі control frames ACK/PING не зберігаються як raw feed архів. Для юридично значущого аудиту та зовнішнього доказу незмінності ще потрібен незалежний підпис або WORM-архів, provider data retention rights та retention lifecycle. SHA256 у тій самій MySQL захищає від випадкового пошкодження, не від адміністратора, який може переписати дані й checksum разом.

## P2.12 Безпечне горизонтальне масштабування

Розподіляються **цілі source leases**, не окремі нефенсовані частини WebSocket одного source. StreamingFleetPlacementPolicy використовує deterministic rendezvous hashing: одні й ті самі вузли та sources дають однаковий результат. План містить caps на кількість джерел, BBO-підписок і оцінку витрат на вузол у milli-USD на годину. Відсутня місткість призводить до відмови, а не до прихованого відкидання instrument subscriptions.

Dry-run перед запуском:

    php symfony/bin/console cos:capital-markets:market-data:stream:multi-fleet ORG ACTOR --sources=SOURCE_A,SOURCE_B --nodes=worker-a,worker-b --node=worker-a --max-workers=16 --max-subscriptions=450 --budget-milliusd-hour=200000

Результат містить topology_digest, залежний також від кількості та точного складу інструментів у кожному source. На всіх вузлах має бути однаковий перелік nodes/sources, mappings та ідентичні caps. Для actual execute потрібно встановити COS_CM_STREAMING_MULTI_FLEET_ENABLED=1 та COS_CM_STREAMING_FLEET_TOPOLOGY_DIGEST із затвердженого dry-run, після чого явно додати --execute. Декілька supervisor з різними конфігураціями все одно блокуються існуючим MySQL source lease fencing; але різне планування не вважається коректним операційним станом.

Оцінка витрат не є фактичним provider invoice. Для повної оптимізації потрібні реальні тарифні дані ліцензій, CPU/RAM, network egress, витрати зберігання raw events, event latency percentiles та автоматизована ресурсна телеметрія. Жодних автоматичних фінансових транзакцій або масштабування понад визначені limits.

## Приймання

CI перевіряє OKX Spot parser і режим Replay, source quorum та валютну ізоляцію, мутації payload і hash, 40 sources на 3 вузли, повторюваність topology та budget rejection. MySQL інтеграція перевіряє атомарний raw+provenance запис, tenant isolation, cursors, зміни payload та append-only reconciliation receipts.

Ці тести не доводять фактичні права на ринкові дані, мережеві сеанси нового провайдера, фінансову точність зовнішніх feed або тривалий багатоузловий soak. Вони не замінюють production acceptance. Жодних merge до закриття COS Federation.
