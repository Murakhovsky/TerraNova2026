---
title: "Впровадження Property у production UI"
description: "Канонічне впровадження Property production surfaces, фільтрів, порівняння та межі editable grid у COS."
status: active
updated: 2026-09-21
kind: architecture
---

# Впровадження Property у production UI

PHASE 10 переносить production Property surfaces на canonical COS presentation contracts без зміни Property business logic, permissions, routing або mutation semantics.

Мета не в тому, щоб замінити кожен HTML-фрагмент одним універсальним компонентом. Простий read-only table має переходити на DataTable. Editable inventory grid з inline forms залишається окремим interaction pattern до появи відповідного canonical DataGrid contract.

## Хвиля 1

### Реєстр менеджера (`Manager Registry`)

`property/manage.phtml`

- локальний `tn-manage-filters` замінено на shared FilterBar;
- збережено query contract для status, deal type, type, location, property group, source, agent, visibility, priority, operational stage, quality та sort;
- inline status form і manager CTA залишені без зміни поведінки;
- editable manager table навмисно ще не перетворюється на простий DataTable.

### Sales Inventory

`property/listing.phtml`

- локальний `tn-crm-filters` замінено на shared FilterBar;
- збережено inventory query contract, permissions та `return_url`;
- inline property editing forms, reservation/fixation controls та save semantics не змінені;
- таблиця залишається domain-specific editable grid до окремої canonical DataGrid хвилі.

### Порівняння (`Compare`)

`property/compare.phtml`

- legacy comparison table замінено на canonical DataTable;
- error/empty states використовують canonical State;
- mobile behavior переходить на responsive cards;
- вибір об'єкта зберігає `data-save-property` та `data-toggle-text`;
- DataTable отримав generic action-cell contract через canonical ActionBar.

## Хвиля 2

### Робочий простір групи (`Group Workspace`)

`property/group.phtml`

- legacy page hero замінено на canonical PageHeader;
- missing-group state використовує canonical State;
- список об’єктів локації/ЖК/проєкту переведено на canonical Panel + DataTable;
- status, price, media та presentation link подані через semantic table cells;
- edit/view actions використовують canonical ActionBar через DataTable action-cell contract;
- edit, PDF/share і client-presentation форми залишені функціонально без змін.

## Хвиля 3

### Створення об’єкта (`Property Add`)

`property/add.phtml`

- unavailable state переведено на canonical State;
- page hero замінено на canonical PageHeader;
- legacy `tn-admin-card` / `tn-admin-card__head` shell замінено на canonical panel classes;
- create form, multipart upload, field names та POST route не змінені.

### Редактор об’єкта (`Property Edit`)

`property/edit.phtml`

- missing-object state використовує canonical State;
- editor hero замінено на canonical PageHeader + ActionBar;
- ActionBar отримав generic attributes contract для link actions, тому `target`, `rel` та інші link attributes не губляться;
- 19 legacy admin-card shells переведено на canonical panel shell;
- quick actions, presentation/share, copy actions, PDF links, anchors та всі editor forms збережені.

## Межа editable grid

`property/manage.phtml` і `property/listing.phtml` містять не звичайні таблиці, а робочі editable grids: inline status mutations, form ownership, reservation, client fixation, commission, owner та next-action controls.

Їх не можна безпечно підміняти read-only DataTable лише заради однакового HTML. Наступна хвиля має або:

1. перенести їх на canonical DataGrid з підтримкою editable cells/actions/forms; або
2. спочатку винести mutation UX у row/detail actions і після цього перейти на DataTable.

До цього моменту PHASE 10 gate захищає canonical filters і не допускає повернення старих локальних filter forms.

## Критерії завершення

- Compare використовує canonical DataTable + State;
- DataTable action cells використовують canonical ActionBar;
- Manager Registry використовує shared FilterBar;
- Sales Inventory використовує shared FilterBar;
- query parameters і manager mutation contracts збережені;
- Group Workspace використовує canonical PageHeader + State + Panel + DataTable;
- Property Add/Edit використовують canonical PageHeader + State + Panel shell без зміни mutation contracts;
- editable grids явно зафіксовані як наступний migration boundary;
- architecture gate виконується у CI.
