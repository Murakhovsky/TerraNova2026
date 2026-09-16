---
title: Додавання Module
description: Як додати runtime module contribution без витоку бізнесової відповідальності в Kernel або Interfaces.
status: active
updated: 2026-09-16
kind: how-to
---

# Додавання Module

Module (модуль) є межею пакування та внесків у runtime. Він не створює новий бізнесовий Domain автоматично й не повинен переносити Domain semantics у Kernel.

## Послідовність

1. Визначте owner: існуючий Domain, supporting capability чи справді новий bounded context.
2. Створіть або оновіть `module.php`: `id`, version/schema, сумісність із Kernel, capabilities і runtime contributions.
3. Зареєструйте module-owned services та contributors у Bootstrap, а не в конкретному Web controller.
4. Додавайте routes, navigation, jobs та event contributions лише через відповідні extension points.
5. Додайте migrations, якщо module володіє persistence schema.
6. Визначте capability identifiers для activation, permission і runtime checks.
7. Додайте architecture та smoke tests для напряму залежностей і bootability.
8. Оновіть generated reference та перевірте появу module у [Modules & Capabilities](../12-reference/module-capabilities.md).

## Перевірка меж

```text
Domain semantics → Domain
Generic execution → Kernel
Provider details → Infrastructure adapter
HTTP/UI delivery → Interfaces
Composition → Bootstrap / Module contribution
```

Якщо manifest починає пояснювати, коли Lead є qualified або квартира має стан `SOLD`, бізнесова відповідальність уже опинилась не там.

## Документація

Оновлюйте Domain overview, System Map або workflow лише тоді, коли module змінює людську модель системи.

Точні capabilities, routes і version не дублюйте вручну там, де вони вже генеруються в Reference.

## Перевірка

Після зміни module щонайменше виконайте:

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
```

Для змін runtime також запустіть відповідні architecture/smoke tests репозиторію.
