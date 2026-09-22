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

- Wave 2: Content Administration;
- Wave 3: Spatial Administration;
- Wave 4: administration closure та route/view cleanup, якщо аудит покаже compatibility debt.

## Критерії завершення

- Users Administration використовує canonical PageHeader, State, KPI, FilterBar, Panel і DataTable;
- role capability semantics не змінені;
- create/update user mutations і CSRF contract збережені;
- editable accounts grid явно зафіксований як interaction boundary;
- PHASE 11 architecture gate запускається у CI.
