---
title: Модель робочого простору
description: Операційна модель workspace COS, яка поєднує read models, commands, context і дії, якими володіють Domains.
status: active
updated: 2026-09-16
kind: ui
---

# Модель робочого простору

Workspace (робочий простір) COS не є просто набором CRUD-екранів. Його задача — показати людині **поточний операційний контекст, стан, наступні рішення та дозволені дії**.

## Будова workspace

```text
Context / tenant / role
        ↓
Read model / projection
        ↓
Operational state
        ↓
Available actions
        ↓
Command / Use Case
        ↓
Result / event
        ↓
Refresh projection
```

## Сторона читання

Workspace може потребувати denormalized projection, aggregate metrics, history timeline або search index. Це не причина перетворювати write repository на універсальний BI query object.

Read model (модель читання) оптимізується під завдання інтерфейсу й не отримує через це права визначати канонічний бізнес-стан.

## Сторона дій

UI Action повинна відповідати змістовній операції застосунку:

```text
Change stage
Assign owner
Schedule follow-up
Moderate submission
Publish listing
Approve action
```

Кнопка не повинна означати «оновити три таблиці цими полями». Це вже не UI, а база даних у плащі.

## Контекст

Workspace явно працює в active organization/tenant context.

Додатковий context може включати:

- Domain entity;
- case/deal;
- Property Asset;
- Diagnostic Session;
- selected module;
- operational filter.

## Стани та зворотний зв’язок

UI має відрізняти:

- успішну мутацію;
- validation rejection;
- permission або Policy deny;
- `APPROVAL_REQUIRED`;
- асинхронний queued state;
- external failure/retry;
- stale або concurrent update.

Це різні системні стани, а не один червоний toast `Something went wrong`, який традиційно пояснює приблизно нічого.

## Cross-domain композиція

Workspace може показувати дані кількох Domains, але mutation authority не змішується.

Sales screen може показувати Property reference, не отримуючи права напряму редагувати canonical Property storage. Композиція інтерфейсу не змінює ownership даних.

## Пов’язані сторінки

- [Інтерфейсні поверхні](./interface-surfaces.md)
- [Навігація та дозволи](./navigation-and-permissions.md)
- [Життєвий цикл виконання](../05-runtime/execution-lifecycle.md)

## Інваріант

> Workspace збирає контекст і дозволені операції для людини. Він не створює паралельну бізнес-модель поверх Domains.
