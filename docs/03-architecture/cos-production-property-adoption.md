---
title: "Впровадження Property у production UI"
description: "Канонічне впровадження Property production surfaces, фільтрів, порівняння та межі editable grid у COS."
status: active
updated: 2026-09-22
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

## Хвиля 4

### Каталог (`Catalog`)

`property/catalog.phtml`

- legacy catalog hero замінено на canonical PageHeader;
- quick links перенесено у canonical ActionBar через PageHeader actions;
- live result count зберігає `data-catalog-count` через generic `metaValueAttributes`;
- основні catalog forms, filters, result grid, pagination і AJAX contracts не змінені.

### Карта (`Map`)

`property/map.phtml`

- legacy hero замінено на canonical PageHeader;
- unavailable/empty states переведено на canonical State;
- geo pins, coordinate projection та catalog navigation не змінені.

### Вибране (`Favourites`)

`property/favour.phtml`

- legacy hero замінено на canonical PageHeader;
- unavailable/empty states переведено на canonical State;
- live favourite count зберігає `data-favourite-count`;
- `data-favourite-list`, `data-favourite-item` та `data-save-property` contracts не змінені.

PageHeader отримав generic `metaValueAttributes`, щоб live DOM counters можна було переносити на canonical shell без втрати JavaScript behavior.

## Хвиля 5

### Публічна картка (`Property Show`)

`property/show.phtml`

- missing/unavailable state переведено на canonical State;
- rich Product hero зберігається спеціалізованим, бо містить schema.org Product/Offer, price, summary facts і media;
- hero actions переведено на canonical ActionBar;
- analytics, request intent, phone/Telegram, favourite та gallery data contracts збережені.

### Презентація (`Presentation`)

`property/presentation.phtml`

- missing presentation state використовує canonical State;
- rich property-presentation hero залишається спеціалізованим, але action cluster переходить на canonical ActionBar;
- request intent, analytics, PDF, full-card і copy-link contracts збережені;
- simple group-presentation hero переведено на canonical PageHeader;
- empty group presentation state переведено на canonical State.

Ця хвиля фіксує важливий принцип: canonical UI не означає примусово замінювати rich domain-specific hero на generic PageHeader, якщо specialized surface несе schema, media або складну business information architecture.

## Хвиля 6

### Деталі заявки (`Submission Detail`)

`property/submission.phtml`

- missing/unavailable state переведено на canonical State;
- legacy submission hero замінено на canonical PageHeader;
- main/detail sidebar `tn-admin-card` shells переведено на canonical panel shell;
- media review, contact information та published-property deep link збережені;
- moderation POST contract не змінено: review, needs_changes, approve, publish, reject і spam лишаються окремими `moderation_action`.

## Хвиля 7

### SEO-добірка (`SEO Landing`)

`property/seo.phtml`

- legacy SEO hero замінено на canonical PageHeader;
- sale/rent/investment navigation переведено у canonical actions;
- unavailable/empty catalog states переведено на canonical State;
- schema.org Product/Offer markup, pagination та favourite behavior збережені.

### Публічна подача (`Property Submit`)

`property/submit.phtml`

- legacy submit hero замінено на canonical PageHeader;
- unavailable state використовує canonical State;
- multipart POST form лишається спеціалізованою submission form;
- honeypot, owner/contact fields, property fields, media inputs і moderation submission contract не змінені.

## Хвиля 8

### Закриття route/view debt

PHASE 10 завершено не тільки візуально, а й на рівні фактичного Symfony route/view graph.

Canonical runtime тепер явно використовує:

- `property/catalog.phtml`;
- `property/map.phtml`;
- `property/favour.phtml`;
- `property/show.phtml`;
- `property/presentation.phtml`;
- `property/seo.phtml`;
- `property/submit.phtml`;
- `property/workspace_canonical.phtml`;
- `property/submissions.phtml`;
- `property/submission_canonical.phtml`.

### Вибране (Favourites) route closure

`/property/favour` повернуто в canonical Symfony routing через `PropertyPageController::favour`.

Одночасно canonical State отримав generic `attributes` contract, а empty state Favourites знову експонує `data-favourite-empty`. Це відновлює browser contract у `frontend/features/public/interactions.js`, який приховує empty state після завантаження збережених об’єктів.

### Retired compatibility views

Як непідключені до canonical Symfony runtime видалено:

- `property/manage.phtml`;
- `property/listing.phtml`;
- `property/add.phtml`;
- `property/edit.phtml`;
- `property/group.phtml`;
- `property/submission.phtml`;
- `property/create.phtml`;
- `property/compare.phtml`;
- `property/pdf.phtml`.

`/property/manage` і `/property/listing` рендерять `property/workspace_canonical.phtml`.
`/property/submission/{id}` рендерить `property/submission_canonical.phtml`.
`/property/create` є alias до `PropertyPageController::submit`.
`/property/pdf/{slug}` є redirect до presentation print flow і не має окремого PHTML renderer.

Історичний `WEB V0.7 Property Workspace` gate збережено як ім’я CI-контракту, але переведено з видаленого Phalcon-era `PropertyController` на актуальний Symfony Property runtime.

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
- Catalog, Map і Favourites використовують canonical PageHeader/State із збереженням live JS counters;
- Property Show/Presentation використовують canonical State + ActionBar, а simple group presentation — PageHeader;
- Submission Detail використовує canonical State + PageHeader + Panel без зміни moderation actions;
- SEO Landing і Property Submit використовують canonical PageHeader/State без зміни structured catalog або submission contracts;
- canonical Symfony route/view graph не посилається на retired compatibility views;
- /property/favour має живий Symfony route та збережений browser empty-state contract;
- /property/create залишається alias до canonical public submit flow;
- /property/pdf/{slug} використовує presentation print flow без окремого PHTML;
- WEB V0.7 gate переведений на canonical Symfony Property runtime;
- architecture gate виконується у CI.
