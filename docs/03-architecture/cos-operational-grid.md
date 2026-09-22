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

## Перша production adoption

`COS Control Center → Proposed Actions` переведено з raw `tn-listing-table` на OperationalGrid.

Збережено:

- Execute через POST `cos/action/{id}/execute`;
- CSRF token;
- Approve deep-link;
- status/risk presentation;
- JSON parameters;
- empty state.

Backend command path не змінювався.

## Freeze compatibility

Ця зміна не змінює frozen Web Platform v1 contracts з ADR-0011.

OperationalGrid є additive presentation capability для PHTML compatibility surfaces і не створює новий frontend runtime.
