---
title: Глибина ринку L2 та потокові деривативи
description: Операційне приймання відтворення книги заявок і незалежних контрактних даних деривативів із перевіркою статусу біржею.
status: draft
kind: operations
updated: 2026-10-15
---

# P1: глибина ринку та потокові деривативи

## L2: перевірена книга заявок

Існуючі процесори Binance Spot/USD-M, Bybit Spot/Linear та Kraken Spot залишаються єдиними відповідальними за snapshot, delta, реконструкцію й recovery.

- Binance: буферизований diff depth, REST snapshot і доказ мосту початкового update ID; безперервність наступних подій; для futures контроль попереднього update ID.
- Bybit: новий snapshot після reset або падіння. Монотонність update ID і cross-sequence перевіряється без неправдивої вимоги gapless cross-sequence.
- Kraken: snapshot/delta з обов'язковою CRC-перевіркою, інвалідація після checksum mismatch.

Нова інтеграція L2SourceFencedSession дає канонічній перевіреній книзі source lease, fencing token і незалежний recovery epoch. При reconnect старе джерело інвалідовується, а перед кожним записом поновлюється lease. Режим вмикається тільки явним COS_CM_L2_FENCED_ENABLED=1 і FOS_L2_ENABLED=1. Старий ізольований VPS-тестовий шлях не є production-доказом.

Операторська команда:

    php symfony/bin/console cos:capital-markets:l2:stream --organization=ORG --source=SOURCE --venue=BYBIT_LINEAR --symbol=BTCUSDT --base=BTC --quote=USDT --depth=50

## Деривативи: Bybit Linear

Окреме джерело bybit.linear-perpetual.v5 повинно мати чотири active canonical subscriptions на один perpetual Instrument: FUNDING_RATE, MARK_PRICE, INDEX_PRICE, OPEN_INTEREST. Інші одночасні subscriptions на цьому source заборонені.

Провайдерський WebSocket tickers.SYMBOL підтверджує один ACK, після якого необхідний **повний** ticker snapshot. Частковий delta оновлює лише фактично отримані поля. Кожен event перевіряє symbol, provider timestamp, десяткові рядки та стабільність source epoch. Старі значення не переносяться через reconnect.

Funding rate супроводжується наступним settlement timestamp та funding interval, отриманим із реального REST contract metadata. Поточна ставка не є завершеним funding settlement і не формує виплату в Ledger.

### Статус контракту

Окремий REST poll інструментів Bybit зберігає статус Trading, PreLaunch, Delivering, Settling або Closed з аудиторським digest та історією transitions. Тільки актуальний Trading для LinearPerpetual допускає потокові canonical events. При іншому статусі всі чотири capability втрачають довіру. Статус не визначається за зміною котирувань.

Ручне отримання metadata:

    COS_CM_DERIVATIVES_LIFECYCLE_ENABLED=1 php symfony/bin/console cos:capital-markets:market-data:derivatives:lifecycle poll ORG SOURCE ACTOR

Статус та незалежна freshness-перевірка:

    php symfony/bin/console cos:capital-markets:market-data:derivatives:lifecycle status ORG SOURCE ACTOR

    COS_CM_DERIVATIVES_LIFECYCLE_ENABLED=1 php symfony/bin/console cos:capital-markets:market-data:derivatives:lifecycle sweep ORG SOURCE ACTOR --stale-seconds=120

Перевірка перед запуском, без сокета:

    php symfony/bin/console cos:capital-markets:market-data:stream:bybit-derivatives ORG SOURCE --dry-run

Реальний запуск тільки після entitlement review, REST metadata poll і дозволу оператора:

    COS_CM_STREAMING_BYBIT_DERIVATIVES_ENABLED=1 php symfony/bin/console cos:capital-markets:market-data:stream:bybit-derivatives ORG SOURCE --seconds=0

Compose-профілі cm-derivatives-lifecycle та cm-derivatives-stream вимкнені за замовчуванням; потрібні COS_CM_DERIVATIVES_ORG, COS_CM_DERIVATIVES_SOURCE, COS_CM_DERIVATIVES_ACTOR (для оператора poll). WebSocket worker є єдиним власником source lease. Жодної автоматичної торгівлі, виплат чи зміни canonical identity.

## Перевірки та зовнішні умови

CI перевіряє ACK, snapshot, partial delta, timestamp rollback, точність decimals, ізоляцію інструмента, контрактні переходи й MySQL trust. L2 recovery suite окремо перевіряє venue-specific відновлення та контроль помилок. Готовність до production можлива лише після справжніх підписок, прав на дані, реального live soak, розривів мережі, повторного ACK та підтвердженого recovery.

Поточний деривативний streaming pilot працює з Bybit Linear. Інші біржові derivative feeds потребують окремого підключення й acceptance; їх не можна помилково вважати покритими цим пакетом. Merge відкладений до завершення COS Federation.

### Межа ізольованого legacy L2 QA

У production L2-публікація допускається лише з `COS_CM_L2_FENCED_ENABLED=1` та канонічним source lease. Старий direct-ingestion шлях заблокований при `APP_ENV=prod` або `COS_CM_POOL_PRODUCTION_ARMED=1`. Він доступний тільки для ізольованої не-production VPS перевірки з явним `COS_CM_L2_LEGACY_VPS_QA_ENABLED=1`, але жоден результат цього шляху не є production acceptance evidence. Прапорці за замовчуванням вимкнені.
