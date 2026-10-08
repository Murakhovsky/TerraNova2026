---
title: Огляд Federation COS
description: Відповідальність і межі модуля погодження міждоменних планів COS.
status: active
updated: 2026-10-08
kind: domain
---

# Federation: шар погодження планів

Federation з’єднує бізнес-цілі COS з чинними механізмами
Action, Policy, Approval та Workflow. Це інтеграційний шар, а не дубль
автоматизації або новий Agent Runtime.

## Межі

Модуль володіє лише обробником канонічної дії
`cos.federation.plan.approval`. Той перевіряє незалежне погодження,
версію Goal, власника та контрольну суму незмінного плану, перш ніж
перевести стан `proposed → approved`.

Модуль **не** запускає Action, Agent, Tool чи Workflow під час
погодження. Кожний запуск проходить окремий preflight.

## Стан активації

```text
id: federation
version: 1.0.0
schema: 1.0.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: false
```

Для використання tenant має явно активувати модуль і налаштувати
обов’язкову `APPROVAL_REQUIRED` політику. Поки ці умови не виконано,
запити на погодження блокуються. Повноцінне міждоменне
виконання та валідація outcome залишаються окремими етапами.

## Пов’язані матеріали

- [Канонічний процес погодження](../../02-workflows/federation-goal-plan-approval.md)
- [Збереження та відновлення стану](../../03-architecture/federation-persistence.md)
- [Каталог модулів та можливостей](../../12-reference/module-capabilities.md)
