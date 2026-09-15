---
title: Adding a Module
description: How to add a runtime module contribution without leaking business ownership into Kernel or interfaces.
status: active
updated: 2026-09-15
kind: how-to
---

# Adding a Module

Module є runtime packaging/contribution boundary. Він не створює новий business Domain автоматично і не повинен переносити domain semantics у Kernel.

## Sequence

1. Визначте owner: існуючий Domain, supporting capability чи справді новий bounded context.
2. Створіть/оновіть `module.php` manifest: id, version/schema, Kernel compatibility, capabilities та runtime contributions.
3. Зареєструйте module-owned services/contributors у Bootstrap, не в конкретному Web controller.
4. Додайте route/navigation/job/event contributions лише через відповідні extension points.
5. Додайте migrations, якщо module володіє persistence schema.
6. Додайте capability identifiers, потрібні для activation/permission/runtime checks.
7. Додайте architecture/smoke tests для dependency direction та bootability.
8. Запустіть generated documentation reference і перевірте, що module з'явився в [Modules & Capabilities](../12-reference/module-capabilities.md).

## Boundary check

```text
Domain semantics → Domain
Generic execution → Kernel
Provider details → Infrastructure adapter
HTTP/UI delivery → Interfaces
Composition → Bootstrap / Module contribution
```

Якщо module manifest починає пояснювати, коли Lead qualified або квартира SOLD, ownership уже поїхав не туди.

## Documentation

Оновіть Domain overview, System Map або workflow лише якщо module змінює human mental model. Exact capabilities/routes/version не дублюйте вручну там, де їх генерує Reference.
