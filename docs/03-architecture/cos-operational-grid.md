---
title: Канонічна операційна таблиця
description: Additive post-freeze UI pattern для mutation-heavy tabular surfaces, який відокремлює read-only DataTable від first-class row actions.
status: active
updated: 2026-09-22
kind: architecture
---

# Канонічна операційна таблиця

Після PHASE 13 у COS лишилися mutation-heavy таблиці, які навмисно не були замасковані під read-only DataTable.

Першим additive post-freeze кроком введено `components/ui/operational_grid.phtml`.

## Межа з DataTable

`DataTable` лишається read-only presentation primitive.

`OperationalGrid` використовується, коли рядок має first-class mutation actions:

- POST form;
- CSRF hidden fields;
- disabled state;
- navigation action;
- status/risk context;
- structured details payload.

Renderer не приймає arbitrary HTML callbacks. Row actions описуються даними.

### Редактор рядка

Для bounded quick-update сценаріїв рядок може містити `_form`:

- `action`;
- `hidden`;
- `fields` лише типу `select`;
- `submit`.

Це навмисно вузький контракт. Textarea, arbitrary input widgets, nested templates та HTML callbacks не підтримуються.

## Впровадження у production

`COS Control Center → Proposed Actions` переведено з raw `tn-listing-table` на OperationalGrid.

Збережено:

- Execute через POST `cos/action/{id}/execute`;
- CSRF token;
- Approve deep-link;
- status/risk presentation;
- JSON parameters;
- empty state.

Backend command path не змінювався.

### Швидке оновлення Client Case

`client_case/index.phtml` переведено з raw operational table на OperationalGrid row editor.

Збережено без зміни backend contract:

- POST `client-case/quickUpdate/{id}`;
- CSRF token;
- `return_url=client-case`;
- stage;
- status;
- priority;
- assigned manager;
- deep-link «Відкрити».

## Сумісність із замороженою платформою

Ця зміна не змінює frozen Web Platform v1 contracts з ADR-0011.

OperationalGrid є additive presentation capability для PHTML compatibility surfaces і не створює новий frontend runtime.
