---
title: "Впровадження Property у production UI"
description: "Канонічне впровадження Property production surfaces, фільтрів, порівняння та межі editable grid у COS."
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
- editable grids явно зафіксовані як наступний migration boundary;
- architecture gate виконується у CI.
