---
title: Потокові котирування Bybit Linear Perpetual
description: Односимвольний пілот BBO для лінійних безстрокових контрактів із контролем snapshot і відновлення сесії.
status: draft
kind: engineering
updated: 2026-10-13
---

# Bybit Linear Perpetual: WebSocket BBO

Пілот працює з публічним каналом `orderbook.1.{symbol}` на `wss://stream.bybit.com/v5/public/linear`. Для контрактів linear перший рівень книги передається **повними знімками**, зокрема повторним знімком після трьох секунд без змін. Це джерело BBO, а не підтверджена багаторівнева книга заявок.

Пілот використовує наявні тип джерела `bybit.linear-perpetual.v5`, парсер `BybitOrderBookPayloadParser`, decoder деривативів, lease/fencing, recovery gate та canonical ingestion.

Перед прийняттям події перевіряємо ACK поточного покоління, точний символ, тип snapshot, час провайдера, номер оновлення та рівно одну ненульову пару bid/ask. Повторений незмінний знімок підтверджує активність; відкат номера або зміна котирування при тому самому номері вимагає reconnect і нового snapshot. Ідентифікатор `u` **не** вважається послідовністю L2-delta.

## Контрольований запуск

Необхідні справжній `InstrumentFamily::Perpetual`, відповідність майданчика, одне активне BBO-підписання, прапорці Capital Markets, MarketData, Bybit, CryptoSpotPerpetual і MarketDataStreaming, а також окремий дозвіл середовища.

```bash
COS_CM_STREAMING_BYBIT_LINEAR_PILOT=1 php symfony/bin/console cos:capital-markets:market-data:stream:bybit-linear ORG_ID SOURCE_ID --seconds=60
```

За замовчуванням потоковий режим вимкнений. Поточний пілот не створює funding, mark/index price або derivative instrument metadata з BBO. Для таких метрик потрібні окремі канали і перевірені правила часу.

## Що ще не виконано

Немає багатосимвольного fleet, повного L2 snapshot/delta, незалежного Bybit lease sweeper, інтеграційного запуску проти WebSocket і реальної MySQL у staging та тривалих тестів стійкості. Не дозволяти production-трейдинг до приймання.

Офіційна специфікація: https://bybit-exchange.github.io/docs/v5/websocket/public/orderbook та https://bybit-exchange.github.io/docs/v5/ws/connect
