---
title: Контракти й карта коду Property
description: Міждоменні порти, мережеві адаптери, канонічні межі середовища виконання та карта реалізації Property.
status: active
updated: 2026-09-16
kind: domain
---

# Контракти й карта коду Property

## Основне правило залежності

> Домен володіє власним станом. Інші домени можуть запитувати, посилатися й реагувати, але не змінюють цей стан напряму.

Тому Sales споживає Property через явні контракти посилань і читання. Бізнес-логіка Sales не звертається до канонічних таблиць Property напряму і не змінює їх.

## Ключові межі

### Межа посилань на Property

`PropertyReferencePort` відкриває стабільні посилання та представлення Property для інших споживачів без передачі права власності на стан Property.

```text
Sales / інший домен
        ↓
PropertyReferencePort
        ↓
Property read model / reference
```

### Зовнішня Property Network

`PropertyNetworkConnectorInterface` ізолює MLS, API девелоперів, фіди порталів та інших постачальників від ядра Property.

Облікові дані та конфігурація постачальника залишаються поза Property за посиланнями на конфігурацію та ін’єктованим транспортом.

### Довідник локацій

Матеріалізація довідкових локацій залишається відповідальністю Reference і доступна через `LocationReferenceInterface`.

Property не повинен напряму змінювати сховище довідкових локацій.

### Канонічне середовище виконання

`PropertyCanonicalRuntimeService` є основною операційною межею запису для Asset, Inventory, Listing і Publication у V0.12.

Це означає, що новий запис не повинен обходити сервіс лише тому, що стара таблиця технічно все ще доступна з PHP.

## Правило сумісності

Старі `tn_properties`, історичний код презентації та Telegram-моделі Realty/Object можуть залишатися ізольованими поверхнями читання або проєкціями сумісності.

Вони не можуть визначати канонічну істину Asset, Inventory або Listing.

```text
Канонічна модель
      ↓
проєкція сумісності
      ↓
старий інтерфейс / читання
```

Напрям має бути саме таким, а не навпаки.

## Карта коду

```text
app/Domains/Property/
├─ Model / словник домену
├─ Application / варіанти використання та сервіси
├─ Infrastructure / збереження, мережа, моделі читання, адаптери
├─ Bootstrap / внесок модуля
└─ module.php

app/Bootstrap/PropertyServices.php
app/Bootstrap/PropertyNetworkServices.php
app/Interfaces/Api/Controller/PropertyCanonicalController.php
```

Назви директорій і класів не перекладаються, оскільки це точні ідентифікатори коду.

## Міждоменні сценарії

Типовий шлях із боку Sales:

```text
Sales use case
    ↓
PropertyReferencePort
    ↓
стабільне представлення Property
```

Типовий шлях із зовнішньої мережі:

```text
зовнішній постачальник
    ↓
PropertyNetworkConnectorInterface
    ↓
PropertySubmission
    ↓
перевірка / ідентичність
    ↓
канонічний Property Asset
```

Жоден із цих шляхів не передає зовнішньому учаснику право напряму змінювати доменне сховище.

## Точні виконувані факти

- [Модулі та можливості](../../12-reference/module-capabilities.md)
- [Варіанти використання застосунку](../../12-reference/application-use-cases.md)
- [Команди](../../12-reference/commands.md)
- [Події](../../12-reference/event-types.md)
- [Маршрути](../../12-reference/module-routes.md)
