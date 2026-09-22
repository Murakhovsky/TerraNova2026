---
title: "Впровадження Administration у production UI"
description: "Канонічне впровадження administration surfaces у COS без зміни identity, permission та mutation semantics."
status: active
updated: 2026-09-22
kind: architecture
---

# Впровадження Administration у production UI

PHASE 11 переносить production Administration surfaces на canonical COS presentation contracts. Мета хвилі не змінювати Identity або permission model, а прибрати локальні presentation primitives та зафіксувати спільні UI contracts.

## Хвиля 1

### Користувачі та ролі (`Users Administration`)

`admin/users.phtml`

- legacy `tn-listing-hero` замінено на canonical PageHeader;
- page/action states використовують canonical State;
- user summary переведено на canonical KPI cards із збереженням filter links;
- локальний GET filter form замінено на shared FilterBar;
- role capability matrix переведено на canonical Panel + DataTable + semantic Status;
- create-user form розміщено у canonical panel shell;
- editable accounts table збережено як domain-specific editable grid;
- `admin/createUser`, `admin/updateUser/{id}`, `csrf_token` та всі mutation field names не змінені.

Controller contract лишається у `CoreWorkspacePageController`: read surface вимагає admin context, create/update виконують окрему CSRF validation перед викликом Identity Administration service.

## Хвиля 2

### Контент і SEO (`Content Administration`)

`content/manage.phtml`

- legacy hero замінено на canonical PageHeader;
- unavailable state використовує canonical State;
- content summary переведено на KPI cards;
- локальний filter form замінено на shared FilterBar;
- materials listing переведено на canonical Panel + DataTable;
- content status та SEO score відображаються semantic status cells;
- n8n delivery history лишається domain-specific list у canonical panel shell;
- edit/create links та query semantics не змінені.

### Редактор контенту (`Content Editor`)

`content/edit.phtml`

- legacy hero замінено на canonical PageHeader;
- save result використовує canonical State;
- content, media, SEO/Open Graph та revisions sections переведено на canonical panel shell;
- public preview action зберігає `target=_blank` та `rel=noopener`;
- save route `admin/content/save/{id}`, CSRF і всі content/SEO fields не змінені.

Controller contract лишається у `ContentAdminPageController`: manage/edit потребують manager context, а save окремо перевіряє CSRF перед викликом Content service.

## Хвиля 3

### Просторове адміністрування (`Spatial Administration`)

`spatial/manage.phtml`

- legacy hero замінено на canonical PageHeader;
- status feedback використовує canonical State;
- scene summary переведено на KPI cards;
- локальний filter form замінено на shared FilterBar;
- scene inventory переведено на canonical Panel + DataTable;
- status cells мають semantic tones;
- create/edit links, status filters та queue anchor не змінені.

### Редактор 3D-сцени (`Spatial Editor`)

`spatial/edit.phtml`

- legacy hero замінено на canonical PageHeader;
- action result використовує canonical State;
- configuration, upload, external asset, assets, capture, hotspot, versions і processing sections переведено на canonical panel shell;
- multipart upload та `data-spatial-*` hooks не змінені;
- `spatial/save`, `upload`, `external`, `capture`, `hotspot`, `publish` mutation routes не змінені;
- scene/viewer/provider/camera/settings field names не змінені.

`spatial/scene.phtml` свідомо не канонізується як administration surface. Це public specialized viewer, який використовує `shared/spatial_viewer` і має окремий rendering contract.

Controller contract лишається у `SpatialPageController`: manage/edit/mutations потребують manager context, а public scene читається окремо без workspace shell.

## Хвиля 3

### Керування Spatial (`Spatial Administration`)

`spatial/manage.phtml`

- legacy hero замінено на canonical PageHeader;
- status_message використовує canonical State;
- scene summary переведено на canonical KPI cards;
- локальний GET filter form замінено на shared FilterBar;
- scene inventory переведено на canonical Panel + DataTable;
- scene status подається semantic status cells;
- edit links, queue anchor та manager-only semantics не змінені.

### Редактор Spatial (`Spatial Editor`)

`spatial/edit.phtml`

- legacy breadcrumb/hero shell замінено на canonical PageHeader;
- status_message використовує canonical State;
- configuration, upload, external asset, assets, capture, hotspot, versions і jobs sections переведено на canonical panel shell;
- specialized upload/dropzone/progress UI лишається domain-specific;
- save/upload/external/capture/hotspot/publish routes не змінені;
- multipart upload та `data-spatial-upload`, `data-spatial-dropzone`, `data-spatial-progress` contracts збережені.

### Публічна Spatial-сцена (`Spatial Scene`)

`spatial/scene.phtml` не канонізується як admin surface.

Public scene лишається specialized viewer surface з `shared/spatial_viewer`, `tn-spatial-public` та `tn-spatial-summary`. Це навмисно: 3D viewer є domain-specific presentation runtime, а не ще одна administration card.

Controller contract лишається у `SpatialPageController`: manage/edit та mutations потребують manager context; public scene читається окремо через `publicScene(slug)`.

## Хвиля 3

### Просторові сцени (`Spatial Administration`)

`spatial/manage.phtml`

- legacy hero замінено на canonical PageHeader;
- status messaging використовує canonical State;
- scene summary переведено на canonical KPI cards;
- локальний filter form замінено на shared FilterBar;
- scene inventory переведено на canonical Panel + DataTable;
- status cells використовують semantic tones;
- links на `spatial/edit/{id}` та query semantics не змінені.

### Редактор сцени (`Spatial Editor`)

`spatial/edit.phtml`

- legacy hero замінено на canonical PageHeader;
- action result використовує canonical State;
- configuration, files, external assets, asset inventory, capture, hotspots, versions та processing jobs переведені на canonical panel shell;
- drag-and-drop contracts `data-spatial-upload`, `data-spatial-dropzone`, `data-spatial-file`, `data-spatial-progress` збережені;
- multipart upload, external asset, capture, hotspot та publish routes не змінені;
- public preview action зберігає `target=_blank` та `rel=noopener`.

`spatial/scene.phtml` навмисно не канонізується як administration surface. Це public specialized 3D viewer, який зберігає `shared/spatial_viewer` та власну spatial presentation hierarchy.

Controller contract лишається у `SpatialPageController`: manage/edit та mutations потребують manager context, тоді як public scene є окремим read surface.

## Межа editable grid

Users table не є read-only data table. Кожен рядок одночасно є формою редагування `full_name`, `phone`, `role`, `status` та optional password reset.

Тому PHASE 11 Wave 1 не маскує її під canonical DataTable. Наступний крок для цього pattern має бути окремий canonical editable DataGrid/FormGrid contract або винесення mutation UX у row/detail actions.

До цього моменту gate захищає:

- canonical shell, filters і capability matrix;
- form ownership через `form="user-form-{id}"`;
- CSRF token;
- create/update routes;
- mutation field names.

## Наступні хвилі

- Wave 2: Content Administration — виконано;
- Wave 3: Spatial Administration — виконано;
- Wave 4: administration closure та route/view cleanup, якщо аудит покаже compatibility debt.

## Критерії завершення

- Users Administration використовує canonical PageHeader, State, KPI, FilterBar, Panel і DataTable;
- role capability semantics не змінені;
- create/update user mutations і CSRF contract збережені;
- editable accounts grid явно зафіксований як interaction boundary;
- Content Administration використовує canonical PageHeader, State, KPI, FilterBar, Panel і DataTable;
- Content editor зберігає save/CSRF/content/SEO mutation contracts;
- Spatial Administration використовує canonical PageHeader, State, KPI, FilterBar, Panel і DataTable;
- Spatial editor зберігає upload/external/capture/hotspot/publish та drag-and-drop contracts;
- public Spatial viewer лишається specialized public surface;
- Spatial Administration використовує canonical PageHeader, State, KPI, FilterBar, Panel і DataTable;
- Spatial editor зберігає upload/capture/hotspot/publish interaction contracts;
- Public Spatial Scene лишається specialized viewer surface;
- Spatial Administration використовує canonical PageHeader, State, KPI, FilterBar, Panel і DataTable;
- Spatial editor зберігає upload/external/capture/hotspot/publish та data-spatial-* contracts;
- public Spatial viewer лишається specialized surface;
- PHASE 11 architecture gate запускається у CI.
