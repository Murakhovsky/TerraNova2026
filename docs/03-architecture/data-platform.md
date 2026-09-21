---
title: Платформа даних Web
description: Канонічний DataGrid, server-side query state, saved views, export, bulk actions і mobile list rendering для COS Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа даних Web

Wave 12.9 вводить єдину Data Platform для list/grid surfaces.

Базова архітектура:

```text
URL state
↓
Web adapter
↓
Application Query / Read Model
↓
DataGridPage
↓
CosDataGrid
↓
Twig HTML
```

DataGrid не володіє business data і не створює окремий client-side data layer.

## Канонічний набір

Platform primitives:

- `DataGridColumn`;
- `DataGridFilter`;
- `DataGridQuery`;
- `DataGridPage`;
- `DataGridSavedView`;
- `DataGridState`;
- `DataGridUrlBuilder`;
- `DataGridCsvExporter`;
- `CosDataGrid`.

Browser behavior:

- `data-grid` controller для visible-row selection;
- row action event;
- bulk action event;
- selection count;
- select-all synchronization desktop/mobile.

## Серверний стан

Канонічний query state живе в URL:

```text
q
page
per_page
sort
dir
filter[...]
columns[]
view
```

Це дає:

- deep links;
- browser back/forward;
- shareable views;
- deterministic SSR;
- server-side pagination;
- server-side search;
- server-side filtering;
- server-side sorting.

Browser controller не зберігає query state у `localStorage` або `sessionStorage`.

## Модель читання

Domain/Product surface реалізує query через власний Application Query або read model.

```text
DataGridQuery
↓
Domain Web adapter mapping
↓
Application Query
↓
Read Model
↓
DataGridPage
```

Заборонено будувати generic SQL усередині `CosDataGrid`.

DataGrid не знає про Doctrine, таблиці БД або Repository.

## Колонки

`DataGridColumn` описує presentation contract:

- stable key;
- label;
- sortable;
- filterable;
- default visibility;
- mobile priority;
- alignment.

Visible column state може бути частиною URL або saved view.

DataGrid не приймає executable JavaScript formatter з Domain.

## Збережені представлення

`DataGridSavedView` є server-owned описом query state.

Saved view може зберігатися product adapter у БД, але browser component отримує вже дозволений набір view.

Канонічна форма:

```text
SavedView
  id
  label
  DataGridQuery
```

Permissions на saved/shared views перевіряються server-side.

## Масові дії

Bulk selection є короткоживучим browser state.

Controller диспатчить:

```text
cos:datagrid-selection-change
cos:datagrid-row-action
cos:datagrid-bulk-action
```

Action IDs приходять із `UIAction`.

Placement:

```text
datagrid.row
datagrid.bulk
```

Stimulus не виконує Application Command самостійно.

Presentation/Application adapter отримує event, повторно резолвить permission/action contract і лише потім виконує Command.

## Експорт

Export є server operation.

`DataGridCsvExporter` є базовим CSV presentation exporter для вже дозволених rows/columns.

Для великих export:

```text
Export request
↓
Application Command
↓
Async Operation
↓
generated file
↓
Activity Center / notification
```

DataGrid ніколи не вивантажує весь dataset у browser лише для створення CSV.

## Стани

Canonical states:

- ready;
- loading;
- empty;
- error.

Empty state відрізняється від error.

Loading не маскує stale/error state.

## Мобільний режим

На вузькому viewport DataGrid переходить із table rendering у entity-card rendering.

Той самий server payload і той самий `UIAction` contract використовуються для desktop та mobile.

Mobile priorities визначають порядок полів у card.

Немає окремого mobile API.

## Адаптер Tabulator

Tabulator дозволений лише для advanced grid interaction, де native/Twig rendering недостатній.

Default:

```text
Twig + server Query
```

Advanced:

```text
Twig server payload
↓
BrowserAdapter::Tabulator
↓
local advanced rendering
```

Canonical Tabulator adapter:

- отримує preloaded rows;
- отримує declarative columns;
- має whitelist безпечних options;
- не приймає `ajaxURL`;
- не виконує `fetch()`;
- не стає власником server pagination/search/filter.

Таким чином Tabulator лишається library adapter, а не архітектурою застосунку.

## Live-компоненти

Для grids із частими server interactions продуктова surface може обгорнути `CosDataGrid` у Symfony UX Live Component.

Live Component володіє тільки Web query state і викликає Application Query.

```text
LiveProp query state
↓
Application Query
↓
DataGridPage
↓
CosDataGrid
```

Default server semantics і URL contract лишаються однаковими.

## Межі

Data Platform не може:

- викликати internal REST;
- виконувати generic Domain mutation;
- читати Repository з Twig;
- володіти SQL;
- зберігати authoritative saved views у browser storage;
- передавати authorization у JavaScript;
- обходити `UIActionResolver`;
- завантажувати весь dataset у browser для pagination/export.

## Reference

`/dev/ui` показує canonical DataGrid із:

- search;
- status filter;
- sort;
- column visibility;
- saved views;
- selection;
- row/bulk actions;
- pagination;
- CSV export;
- mobile cards.

Reference dataset існує лише у Dev catalog.

## Перевірка

CI перевіряє:

- presentation/domain boundaries;
- URL state;
- row/bulk UIAction events;
- token-only CSS;
- mobile rendering;
- hardened Tabulator adapter;
- real server search/filter/export через `cos:web:data:smoke`.
