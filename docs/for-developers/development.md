---
title: Розробка і перевірка COS
description: "Практичний цикл зміни COS від визначення власника поведінки до тестів, документації, pull request і production readiness."
status: active
updated: 2026-10-01
kind: development
---

# Розробка і перевірка COS

Мета розробника — не просто зробити код зеленим. Зміна має зберегти архітектурні межі, tenant isolation, runtime semantics і зрозумілу документацію.

## Перед кодом

Сформулюйте:

1. яку поведінку змінюємо;
2. хто нею володіє;
3. який процес або use case зачіпається;
4. які дані змінюються;
5. які зовнішні контракти змінюються;
6. як довести правильність.

Якщо на перші два питання немає відповіді, рано створювати нову папку Shared.

## Локальний цикл

Базова послідовність:

~~~text
зміна
  ↓
syntax / static gates
  ↓
contract + unit
  ↓
integration за потреби
  ↓
runtime / smoke
  ↓
docs
  ↓
PR
~~~

## Єдина точка запуску перевірок

Основний runner:

~~~bash
bash bin/verify fast
bash bin/verify contract
bash bin/verify unit
bash bin/verify integration
bash bin/verify smoke
bash bin/verify full
~~~

Активні тести перевіряють поточні invariants і behavior. Історичні release gates не є активною test suite.

## Який тест додавати

**Contract test** потрібен для довгоживучої архітектурної межі.

**Unit test** перевіряє локальну поведінку без повного runtime.

**Integration test** потрібен там, де важлива реальна взаємодія з persistence, transport або framework infrastructure.

**Smoke test** доводить короткий критичний vertical slice.

**Browser / E2E** перевіряє production-like interaction через реальний інтерфейс.

Не потрібно доводити одну й ту саму річ п'ятьма однаковими тестами з різними назвами.

## Міграції та persistence

Зміна schema повинна мати:

- чітку ownership;
- migration;
- безпечний порядок deployment;
- план для existing data;
- перевірку rollback або forward recovery, якщо rollback неможливий.

## Документація

Якщо зміна впливає на поведінку, оновлюється відповідний рівень документації.

Наратив не повинен бути dependency прикладного тесту. Generated reference має формуватися з executable source.

## Перед merge

Перевірте:

- boundaries;
- tenant context;
- authorization;
- idempotency;
- failure path;
- observability;
- tests;
- docs;
- migrations;
- backward compatibility зовнішніх контрактів.

## Куди йти далі

- [Локальний запуск](../09-development/local-setup.md)
- [Тестування](../09-development/testing.md)
- [Каркас перевірки V2](../09-development/verification-framework.md)
- [Правила документації](../09-development/documentation-rules.md)
