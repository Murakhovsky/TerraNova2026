---
title: Контракти й карта коду Sales
description: Порти застосунку, інфраструктурні межі, композиція середовища виконання та карта коду Sales.
status: active
updated: 2026-09-16
kind: domain
---

# Контракти й карта коду Sales

## Напрям залежностей

```text
Interfaces
   ↓
Application Use Cases
   ↓
Application Contracts ← Domain Model
   ↑
Infrastructure adapters
```

Бізнес-правила Sales не повинні залежати від Web, Telegram, конкретного постачальника CRM або Phalcon ActiveRecord.

## Основні поверхні

| Поверхня | Відповідальність |
| --- | --- |
| `Model/` | типізований словник та бізнес-інваріанти |
| `Application/DTO` | незмінні вхідні й вихідні дані на межах |
| `Application/Contract` | репозиторії, шлюзи та вихідні порти |
| `Application/UseCase` | транзакційна оркестрація |
| `Automation/Event` | факти, якими володіє Sales |
| `Automation/Rule` | детерміновані реакції |
| `Automation/Agent` | визначення агентів, що створюють пропозиції |
| `Automation/Action` | контрольована передача виконання до портів застосунку |
| `Automation/Policy` | `AUTO` / `APPROVAL_REQUIRED` / `DENIED` |
| `Infrastructure/` | збереження даних, адаптери CRM, моделі читання |
| `Bootstrap/` | реєстрація внесків у середовище виконання |

## Корінь композиції

Спільні сервіси Sales збираються в:

```text
app/Bootstrap/SalesServices.php
```

Web, API, Console і робітники повинні отримувати ті самі варіанти використання, а не створювати окрему бізнес-логіку для кожного інтерфейсу.

## Статус старої моделі

Telegram-specific `Infrastructure/Persistence/Phalcon/Telegram` видалено. Sales persistence використовує Application contracts та PDO/MySQL adapters; відновлення ActiveRecord compatibility layer заборонене architecture gates.

## Міждоменні контракти

Sales може посилатися на Property, але не змінює сховище Property напряму.

Універсальні механізми середовища виконання отримуються від Kernel. Синхронізація із зовнішньою CRM реалізується адаптерами за контрактами, якими володіє Sales.

```text
Sales
  ↓ порт / контракт
Infrastructure adapter
  ↓
CRM provider
```

і окремо:

```text
Sales
  ↓ reference/read contract
Property
```

## Точні виконувані факти

- [Модулі та можливості](../../12-reference/module-capabilities.md)
- [Маршрути модулів](../../12-reference/module-routes.md)
- [Варіанти використання застосунку](../../12-reference/application-use-cases.md)
- [Події](../../12-reference/event-types.md)

## Корінь коду

```text
app/Domains/Sales/
app/Bootstrap/SalesServices.php
```

Якщо зміна Sales вимагає редагувати контролер, адаптер CRM і модель домену для одного й того самого правила, варто перевірити, чи правило випадково не розмазалося по шарах. Шари створені саме для того, щоб не грати в архітектурний квест «знайди всі місця, де захована знижка».
