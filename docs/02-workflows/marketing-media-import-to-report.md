---
title: Meta Read Import → Media Analytics
description: Повний read-only імпорт медіаспостережень та порівняння періодів.
status: active
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: marketing.media-import-to-report
---

# Meta Read Import → Media Analytics

## Бізнес-мета

Для одного Meta акаунта оператор обирає точне вікно та один рівень account/campaign/adset/ad. Повний результат перевіряється за provider account, датами, унікальними ID та точними decimal amounts. MySQL транзакція фіксує нативні спостереження і незмінний run receipt. Звіт не змішує зерна та валюти; reach і provider action categories не сумуються як унікальні конверсії. Порівняння вимагає однакового облікового запису, його ревізії, зерна та неперетинних вікон однакової тривалості. Відсутній звіт не замінюється нулем.

## Процес

<ProcessDiagram process-id="marketing.media-import-to-report" />

## Відповідальність

<ProcessDiagram process-id="marketing.media-import-to-report" view="ownership" direction="LR" />

## Можливості

<ProcessDiagram process-id="marketing.media-import-to-report" view="capability" direction="LR" />

## Межі приймання

Модель описує код поточного checkout. Вона не є доказом D3/D4, фактичної роботи Meta чи визнаної бухгалтерської виручки. Source-verified не означає production acceptance.
