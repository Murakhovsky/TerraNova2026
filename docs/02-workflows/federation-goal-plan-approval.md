---
title: "Federation Goal Plan Approval"
description: "Канонічний процес погодження незмінного Goal Plan через чинні Action, Policy та Approval."
status: active
updated: 2026-10-08
kind: workflow
contract: workflow-v2
process_id: federation.goal-plan-approval
process_state: as-is
---

# Федеративне погодження плану

Канонічний Action handler із модуля Federation перевіряє поточну
версію Goal, цілісність плану, рішення Policy та незалежне погодження.
Лише після цього він атомарно переводить план у стан approved.
Запуск Workflow окремий та вимагає власної перевірки.

<ProcessDiagram process-id="federation.goal-plan-approval" />

## Хто відповідає за рішення

<ProcessDiagram process-id="federation.goal-plan-approval" view="ownership" />

## Межі можливостей

<ProcessDiagram process-id="federation.goal-plan-approval" view="capability" />

Модуль вимкнений для організацій за замовчуванням. Саме погодження
не запускає Agent, Tool, зовнішні чи фінансові операції.
