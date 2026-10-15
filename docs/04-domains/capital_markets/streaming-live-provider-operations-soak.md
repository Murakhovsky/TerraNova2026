---
title: Реальна перевірка котирувань і операційне приймання
description: Підтвердження біржових підписок, моніторинг інцидентів, відновлення потоків і тривале випробування.
status: draft
kind: operations
updated: 2026-10-15
---

# P0.5–P0.7: Live Provider, Operations, Soak, Failover

## Базові гарантії

Ці механізми доповнюють наявні Instrument Pool, source-fenced streaming, Multi-symbol Core і Per-instrument Data Trust. За замовчуванням вони **не запускають WebSocket**, не змінюють підписки, не надають ліцензій і не активують live trading.

Підтримані **фактичні ACK/quote receipts**: Binance Spot (один та batch), Binance USD-M BBO, Bybit Spot, Bybit Linear, Kraken Spot, Massive Stocks (один та batch). ACK записується лише після перевіреного повідомлення конкретного provider protocol, під чинним lease. BBO позначається LIVE_QUOTE лише після отримання канонічного LIVE event з реальним MySQL quality metric, підтвердженим session epoch, поточним fencing token та independent instrument trust.

Подія з іншого session epoch, неіснуючий canonical event, застарілий/відкликаний lease або DELAYED mode **не можуть** пройти production verification. Розрив чи інвалідована підписка встановлюють REVOKED. Стара історія котирувань не використовується як proof повторного підключення.

L2 використовує власні validated snapshots, sequence і source-fenced recovery. Не можна видавати BBO ACK як L2 recovery; для L2 acceptance лишається окремий quality та sequence gate.

## Операційний моніторинг

Operator commands не показують Vault credentials, повні provider payload, секретні worker IDs або токени доступу.

Перевірка стану lease та якісних котирувань:

    php symfony/bin/console cos:capital-markets:market-data:stream:status ORG --source=SOURCE

Перевірка незалежного trust інструмента:

    php symfony/bin/console cos:capital-markets:market-data:stream:instrument-trust status ORG ACTOR --source=SOURCE

Одноразове сканування інцидентів із заздалегідь підтвердженим email оператора:

    COS_CM_STREAM_OPS_MONITOR_ENABLED=1 php symfony/bin/console cos:capital-markets:market-data:stream:incidents scan ORG ACTOR --source=SOURCE --alert-email=operator@example.org

Перегляд інцидентів:

    php symfony/bin/console cos:capital-markets:market-data:stream:incidents list ORG ACTOR --source=SOURCE

Підтвердження, що інцидент побачено, **не дорівнює** усуненню:

    php symfony/bin/console cos:capital-markets:market-data:stream:incidents ack ORG ACTOR --source=SOURCE --incident-id=123

Закриття можливе тільки після поточної незалежної перевірки відсутності відповідної помилки:

    php symfony/bin/console cos:capital-markets:market-data:stream:incidents resolve ORG ACTOR --source=SOURCE --incident-id=123

Інциденти зберігаються у MySQL у станах OPEN, ACKNOWLEDGED, RESOLVED та автоматично відкриваються знову при повторенні. Сигнали охоплюють expired worker lease, недостатнє покриття provider ACK/LIVE quote, revoked subscriptions, ненадійні market states та quality degradation.

Email доставляється через Platform Notification/outbox. QUEUED підтверджує постановку в чергу, а **не** доставку на зовнішню пошту. Для реального production gate слід окремо перевірити доставки, n8n і кінцеві сповіщення.

Автоматичний 60-секундний монітор вимкнений за замовчуванням у Docker Compose. Після окремого дозволу задайте:
- COS_CM_STREAM_OPS_ORGANIZATION
- COS_CM_STREAM_OPS_SOURCE
- COS_CM_STREAM_OPS_ACTOR
- COS_CM_STREAM_OPS_ALERT_EMAIL

Тільки потім:

    docker compose --profile cm-stream-ops-monitor up -d --no-deps cm-stream-ops-monitor

Один profile monitor контролює одне explicit source. Для різних джерел потрібні окремі, контрольовані instances/супервізори. Змішування tenant не допускається.

## Контрольований failover drill

Потрібні active SOAK, LIVE verified feed, один чинний fenced worker, авторизований Market Data operator і окремо активована змінна COS_CM_STREAM_FAILOVER_DRILL_ARMED=1. **Не запускати під час реального execution або без погодженого вікна.**

Після збереження початкового стану:

    COS_CM_STREAM_FAILOVER_DRILL_ARMED=1 php symfony/bin/console cos:capital-markets:market-data:stream:failover inject ORG ACTOR --source=SOURCE --confirm=INJECT_FENCED_FAILOVER

Injection атомарно відкликає чинний token, знецінює старі квоти/ack/market states та не запускає жодного нового worker. Окремий нагляд за worker fleet повинен побачити завершення старого процесу та запустити дозволений worker з **новим** token.

Після реального підключення та нового verified provider ACK:

    php symfony/bin/console cos:capital-markets:market-data:stream:failover verify ORG ACTOR --source=SOURCE

Verify відхиляє старий token, відсутній ACK, відсутній новий LIVE canonical/quality event, недовірений інструмент, подвійну спробу використати той самий challenge. Запис RECOVERED зберігається в окремому MySQL audit ledger. Для кожного джерела у вікні SOAK потрібен хоча б один verified failover cycle до остаточного ACCEPTED/LIVE. CI-тест доводить SQL/логіку на фікстурах, не фактичний біржовий recovery.

## Довгий live soak

Жодний unit/integration CI не може пройти замість реального 24-годинного часу. Для release потрібні активні джерела, 250 окремих BBO та 50 L2, не менше 276 із 288 п’ятихвилинних bucket на кожен інструмент, актуальний per-symbol trust, worker lease, підтверджені issuer/provider rights і відповідні provider receipt. Є окремий durable watcher checkpoint на 5 хвилин. При підозрі на інцидент стан не повинен продовжувати торгувати.

Operator sequence: CANARY → SOAK → фактична добова перевірка → failover verify усіх source → незалежний ACCEPT → PROMOTE. Команди доступні в документації Instrument Pool production acceptance. Поточні phase і причини неприйняття показує статус:

    php symfony/bin/console cos:capital-markets:pool:production status --organization=ORG --actor=ACTOR

Для production також потрібні задокументовані мережева fault injection (реальний TCP drop / reconnect), обмеження provider rate/connection, CPU/RAM, черги й затримки, фактичне отримання алертів, стале очищення інцидентів і операційний rollback.

## Межі пакетного CI

Unit та MySQL integration перевіряють контракти, реальне виконання SQL, ізоляцію tenant, anti-replay і fencing. Це фікстури, не справжні котирування Binance/Bybit/Kraken/Massive. Without upstream credentials, signed data entitlement and real VPS soak no production acceptance can be truthfully declared.

До завершення COS Federation пакет залишається в Draft PR без merge та production deployment.
