---
title: ADR-0013 — DataGridFilter v1 отримує additive text-filter contract
description: Рішення розширити заморожений Web Platform v1 DataGridFilter підтримкою text-фільтрів без зміни existing select semantics.
status: accepted
updated: 2026-09-23
kind: decision
---

# ADR-0013 — DataGridFilter v1 отримує additive text-filter contract

## Контекст

Web Platform v1 заморозив DataGrid як канонічний server-first collection runtime.

Під час VR-007 production route `/sales/deals` довів реальний use case, якого початковий contract не покривав: поряд із select-фільтрами Collection потребує звичайного текстового domain filter, наприклад Source.

Робити окремий Sales filter UI або дублювати DataGrid toolbar означало б порушити Wave 13 governance.

## Рішення

`DataGridFilter` розширюється additive полями:

- `type` зі значеннями `select | text`;
- optional `placeholder`.

Default лишається `select`, тому існуючі callers не змінюють поведінку.

`select` як і раніше вимагає options. `text` не вимагає options і рендериться тим самим canonical DataGrid filter surface.

## Межа рішення

Це не відкриває довільний renderer API і не перетворює DataGridFilter на form builder.

Нові filter types після цього рішення потребують окремого production evidence та Web Platform change-control.

Sales semantics не потрапляють у generic contract: DataGrid знає лише тип control, label, value, options і placeholder.

## Наслідки

Позитивні:

- production Collections не створюють page-specific filter bars;
- існуючі select filters залишаються backward-compatible;
- DataGrid може покривати реальні text-filter use cases через server-owned query state.

Компроміс:

- frozen Web Platform contract змінено, тому зміна захищається цим ADR і відповідними architecture gates.
