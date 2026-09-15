# WEB V0.9 — Portal / Cabinet Refinement

## Goal

WEB V0.9 turns `/cabinet*` into a real authenticated **Portal surface**.

The frontend contract is now explicit:

- **Workspace** = company + work;
- **Portal** = user + interaction with the company;
- **Public** = external world.

A user being a manager or administrator does not change the ownership of `/cabinet`. Staff can open the Portal as users, while operational work stays in Workspace routes.

This is an Interface/Presentation release. It does not create a `Portal` DDD domain and does not move Property, Identity or Sales business rules into browser code.

## Portal surface

The refined routes are:

- `/cabinet`;
- `/cabinet/submission/{id}`;
- existing Telegram connect/disconnect actions.

`CabinetController` always declares:

```php
interfaceSurface = portal
pageAssetEntries = ['portal-cabinet']
metaRobots = noindex,nofollow
```

The controller keeps backend truth for:

- authenticated user;
- effective organization role;
- role capabilities;
- ownership checks for submissions;
- cabinet data;
- Telegram binding.

Manager-only property operations are no longer assembled inside the Portal controller.

## Portal shell

`shared/portal_header.phtml` is the canonical Portal shell.

It owns:

- Terra Nova Portal identity;
- module-aware Portal navigation;
- authenticated user identity;
- mobile navigation;
- `aria-current` state;
- one surface marker: `data-interface-surface="portal"`.

Cabinet views no longer render `shared/manager_header.phtml`.

## Information architecture

Only capabilities backed by current application/runtime behaviour are shown.

### Overview

`/cabinet`

### Real Estate

Property module contributes:

- Catalog;
- Favourites;
- My Properties → `/cabinet#properties`;
- Submit Property when the server-side role allows it.

`My Properties` is intentionally not linked to `property/listing`: Listing remains a Company Workspace capability.

### Requests

`/cabinet#requests` renders the existing `cabinetData().inbound_requests` projection.

### Profile

`/cabinet#profile` currently exposes read-only account identity because no profile-edit application contract exists yet.

A decorative edit form was deliberately not invented.

### Documents / activity

Not shown because there is no current capability contract that supports it.

## Portal states

The server-rendered UI explicitly handles:

- loaded data;
- empty My Properties;
- empty submissions;
- empty requests;
- controller/service failure;
- Telegram success/error status;
- submission not found;
- submission service unavailable;
- saving state through `aria-busy`.

Validation remains native/server-side; browser JavaScript does not reproduce business validation rules.

## Responsive behaviour

Portal is mobile-first relative to Workspace.

At `<= 1050px`:

- Portal navigation moves behind a dedicated menu control;
- dense desktop identity chrome is removed;
- cards reduce columns.

At `<= 650px`:

- navigation becomes vertical;
- actions become one-column;
- page sections use compact spacing;
- form grids collapse to one column;
- primary controls target at least 44px interaction height.

Reduced-motion preferences are respected.

## Browser ownership

`frontend/entrypoints/portal-cabinet.js` loads:

- `frontend/features/portal/cabinet.css`;
- `frontend/features/portal/cabinet.js`.

Browser JavaScript owns only presentation behaviour:

- Portal mobile navigation;
- progressive submit state.

It does not decide permissions, property state, submission state or business transitions.

## Navigation ownership

`ModuleAwareNavigationService` remains authoritative.

Core Portal navigation owns generic Portal destinations:

- Overview;
- Requests;
- Profile.

Property contributes Property-specific destinations and submission role gating.

This keeps Portal as an interface surface rather than a new business domain.

## Regression gate

`tests/architecture/web_v09_portal_refinement.php` protects the release against:

- rendering Workspace shell from Cabinet;
- role-based switching of Portal/Workspace surface ownership;
- rebuilding manager operational data in Cabinet;
- duplicated role rules in PHTML;
- sending My Properties to Workspace Listing;
- missing Portal bundle/shell/mobile behaviour;
- accidental creation of `app/Domains/Portal`.

The dedicated workflow runs on `main`.

## Definition of Done

WEB V0.9 is closed when:

1. `/cabinet*` always owns the Portal surface;
2. Portal has one dedicated shell and centralized navigation;
3. Cabinet never renders the Workspace shell;
4. My Properties, submissions, requests, Telegram and profile projection use shared Portal presentation patterns;
5. role/capability truth remains server-side;
6. mobile navigation and one-column mobile forms are usable;
7. loading/saving, empty and failure states are represented where the current server contract supports them;
8. the Portal bundle is managed through Vite;
9. architecture/regression gates protect the boundary;
10. no `Domains/Portal` exists.
