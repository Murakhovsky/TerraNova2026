---
title: Campaign UTM → Native Sales Attribution
description: Нативна UTM-атрибуція та проєкція Sales/Finance фактів.
status: active
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: marketing.attribution-to-analytics
---

# Campaign UTM → Native Sales Attribution

## Бізнес-мета

Marketing генерує UTM-теги для внутрішньої кампанії або конкретного paid plan. Пошук читає нативні ліди через Sales read port, фіксує тільки точний збіг і не зберігає контактні дані. Повторний пакет повертає незмінну квитанцію. При зміні вихідного UTM доказ залишається в історії, але не зараховується до звіту. Виграна угода не є бухгалтерською виручкою. Finance квитанція та Meta spend є окремими фактами. Збір обмежений захопленими лідами; відсутність доказу не означає нуль конверсій.

## Процес

<ProcessDiagram process-id="marketing.attribution-to-analytics" />

## Відповідальність

<ProcessDiagram process-id="marketing.attribution-to-analytics" view="ownership" direction="LR" />

## Можливості

<ProcessDiagram process-id="marketing.attribution-to-analytics" view="capability" direction="LR" />

## Межі приймання

Модель описує код поточного checkout. Вона не є доказом D3/D4, фактичної роботи Meta чи визнаної бухгалтерської виручки. Source-verified не означає production acceptance.
