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

Renderer не приймає arbitrary HTML callbacks. Row actions і editable fields описуються даними.

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



## Редаговані рядкові форми

Другий additive post-freeze крок розширює OperationalGrid для row-owned mutation forms.

Row може оголосити `_form`:

- стабільний form id;
- action;
- method;
- hidden fields, включно з CSRF.

Editable cells використовують `kind: field` або `kind: fields` і можуть рендерити:

- text/email/tel/password/number/date/datetime-local/url/search inputs;
- select;
- placeholder;
- min/max/step;
- minlength/maxlength;
- autocomplete;
- required;
- aria-label.

Submit action використовує `kind: submit` і посилається на row form через HTML `form` ownership. Тобто control може фізично бути в іншій cell, але mutation semantics лишається однією формою.

Arbitrary HTML так само не приймається.

## Друга production adoption

`Administration → Users` переведено з raw editable table на OperationalGrid.

Збережено:

- `admin/updateUser/{id}`;
- CSRF token;
- `full_name`;
- `phone`;
- `role`;
- `status`;
- optional password reset;
- row-level Save action.

Create-user form лишається окремою canonical panel form, бо не є row mutation у grid.


## Третя production adoption

`Client Case → операційний список` переведено з локальної quick-update таблиці на OperationalGrid.

Кожен рядок зберігає окремий row form:

- POST `client-case/quickUpdate/{id}`;
- CSRF token;
- `return_url=client-case`;
- `stage_id`;
- `status`;
- `priority`;
- `assigned_user_id`.

Stage presentation використовує semantic `kind: stage`, workflow controls використовують `kind: fields`, а actions містять row submit `ОК` і deep-link `Відкрити`.

Client Case funnel лишається domain-specific visualization і не змішується з OperationalGrid.

## Сумісність із замороженою платформою

Ця зміна не змінює frozen Web Platform v1 contracts з ADR-0011.

OperationalGrid є additive presentation capability для PHTML compatibility surfaces і не створює новий frontend runtime.
