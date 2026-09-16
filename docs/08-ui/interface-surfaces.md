---
title: Інтерфейсні поверхні
description: Web, API, Telegram і CLI як шар доставки COS.
status: active
updated: 2026-09-16
kind: ui
---

# Інтерфейсні поверхні

`app/Interfaces` є delivery layer (шаром доставки) COS. Він приймає зовнішню взаємодію, формує контекст і передає її до прикладної межі, але не стає власником бізнес-семантики.

## Поверхні

- `Web` — доставка HTML та UI;
- `Api` — програмна HTTP-взаємодія;
- `Telegram` — взаємодія через messenger;
- `Cli` — точки входу для workers, commands та operations;
- `Shared` — спільні допоміжні засоби рівня доставки.

## Web

Поточний Web interface має окремі `Controller`, `Routing`, `Navigation`, `Page`, `Rendering`, `View`, `Assets`, `Security`, `Tenant`, `Service` та `Module.php`.

Це відділяє інтерфейс від старої моделі, де великий frontend module поступово перетворюється на фактичне application core лише тому, що «вже був під рукою».

## Правило Controller

Controller має:

```text
parse request
→ auth/tenant/capability check
→ build DTO/command
→ call application service/use case
→ map result to response/view
```

Controller не має містити:

- логіку Domain transitions;
- SQL;
- каталог Policy;
- provider routing;
- LLM prompt або бізнесову інтерпретацію.

## Tenant

Delivery layer встановлює active organization/tenant context, але Domain operations усе одно мають явно бути tenant-safe. Session сама по собі не є security boundary.

## Навігація і модулі

UI navigation може залежати від active modules і capabilities. Вимкнений Domain module не повинен залишати мертві меню та routes.

## Read models

Складні workspace/dashboard screens повинні читати projections або read models. Не потрібно змушувати write repository одночасно бути аналітичним запитом із чотирнадцятьма JOIN лише тому, що SQL це дозволяє.

## Спільна поведінка каналів

Web, API, Telegram та CLI повинні викликати однакові application boundaries.

Якщо Telegram має інше бізнес-правило, ніж Web, це майже завжди дефект архітектури, а не «особливість каналу».

## Інваріант

> Interface адаптує взаємодію до каналу. Application оркеструє операцію. Domain визначає бізнесовий сенс і правила.
