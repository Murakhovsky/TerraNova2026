---
title: Багатосимвольний стримінг та незалежна довіра
description: Контроль підписок BBO, місткості провайдерів, sequence, freshness і recovery кожного інструмента.
status: draft
kind: operations
updated: 2026-10-15
---

# Багатосимвольний стримінг і незалежна довіра

## Основні інваріанти

Canonical Instrument Registry і MarketSubscription залишаються єдиним джерелом ідентичності. Кожна транзакція запису ринкової події повинна мати дійсний lease та fencing token. Жодна помилка sequence, quality чи freshness інструмента A не повинна позбавити довіри інструмент B. Торгівля та неатестовані mappings не вмикаються автоматично.

## Спільний механізм підписок

Сервіс MultiSymbolStreamingCore читає активні наміри підписки, перевіряє точні canonical mappings, source venue, допустимий event type, дублікати, обмеження та reconnect policy. Профілі з реальною реалізацією багатосимвольного worker:

- Binance Spot BBO: 2–100 інструментів на source.
- Massive Stocks NBBO: 2–25 інструментів на source.

Невідомі профілі, змішані L2 delta/BBO або перевищені ліміти відхиляються. Bybit, Kraken та одиночний L2 worker не маскуються під готовий багатосимвольний потік. Паралельні shards одного source заборонено lease-контрактом; сотні BBO плануються через окремо зареєстровані й атестовані sources, а не через непідтверджені конкурентні записи.

Попередній перегляд, який НЕ відкриває WebSocket:

    php symfony/bin/console cos:capital-markets:market-data:stream:multi-plan ORG ACTOR --sources=SOURCE_A,SOURCE_B

Результат PLANNED_NOT_RUNNING вказує кількість, headroom, символи, ключі, reconnect limits. Це не підтвердження відкритих сокетів або біржових прав.

## Незалежний стан інструмента

Додаткова таблиця tn_capital_market_instrument_stream_trust з ключем organization/source/instrument/channel зберігає стан TRUSTED, UNTRUSTED або STALE, останню sequence, freshness, quality score, recovery_required, shard_key та fencing_token. Додається один агрегат на інструмент, не паралельний реєстр інструментів.

Fenced ingestion оновлює цей стан у тій самій MySQL-транзакції, що й canonical event. L2 delta без snapshot заборонений. Sequence rollback, quality failure або прострочений інструмент вимагають окремого відновлення; здорові siblings залишаються незалежними.

Перевірка:

    php symfony/bin/console cos:capital-markets:market-data:stream:instrument-trust status ORG ACTOR --source=SOURCE_A

Один stale sweep із явним дозволом:

    COS_CM_INSTRUMENT_TRUST_SWEEPER_ENABLED=1 php symfony/bin/console cos:capital-markets:market-data:stream:instrument-trust sweep ORG ACTOR --source=SOURCE_A --stale-seconds=120 --limit=500

Для автоматичної перевірки раз на 60 секунд передбачено окремий вимкнений за замовчуванням Docker Compose profile cm-instrument-trust-sweeper. Потрібно задати COS_CM_TRUST_SWEEP_ORGANIZATION, COS_CM_TRUST_SWEEP_SOURCE, COS_CM_TRUST_SWEEP_ACTOR та активувати профіль на затвердженому вузлі. Один профіль перевіряє одне джерело, для інших sources потрібне окреме supervisor-конфігурування.

## Межі виробничого приймання

CI покриває 300 BBO намірів на 12 незалежних sources, відмови при перевищенні лімітів і змішуванні каналів, незалежні sequence/quality/recovery та MySQL stale sweep. Фікстури не є доказом 24-годинного реального live soak. Необхідні підтверджені біржові права, запущені воркери, VPS-метрики, reconnect fault injection та production acceptance.

До закриття COS Federation пакет залишається в окремій гілці без merge.
