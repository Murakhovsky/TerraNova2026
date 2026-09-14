# WEB V0.9 — Portal Refinement

## Goal

WEB V0.9 refines the authenticated Portal surface after the Company Workspace migrations. It keeps the existing Cabinet data and business services, but gives Portal pages an explicit frontend bundle, private metadata, responsive behaviour and a regression contract.

This is an Interface/Presentation release. It does not create a `Portal` DDD domain and does not move Property, Sales or authentication rules into browser code.

## Refined surfaces

- `/cabinet`
- `/cabinet/submission/{id}`

Existing routes, server-side ownership checks, Telegram actions and property-submission rules remain unchanged.

## Surface boundary

`shared/manager_header.phtml` already resolves the authenticated role:

- manager/admin users receive Company Workspace navigation;
- non-team users receive Portal navigation with `data-interface-surface="portal"`.

WEB V0.9 preserves that split. `CabinetController` declares `interfaceSurface = workspace|portal` for presentation context but does not set `workspaceSection = portal`.

The dedicated `portal-cabinet` browser module activates only when the rendered header identifies the Portal surface. This prevents Portal styling from leaking into a manager visiting `/cabinet`.

## Portal navigation ownership

The core Portal owns only the Overview destination. Property contributes the role-aware Portal capabilities:

- Real Estate catalog;
- Favourites;
- My Properties / Listing for permitted roles;
- Submit Property for permitted roles.

Module-aware navigation remains authoritative. WEB V0.9 does not duplicate those role rules inside a new navigation layer.

## Frontend bundle

WEB V0.9 adds:

- `frontend/entrypoints/portal-cabinet.js`;
- `frontend/features/portal/cabinet.css`;
- `frontend/features/portal/cabinet.js`.

The bundle provides Portal-scoped responsive layout, table overflow handling and progressive form submit state through `aria-busy`. Existing PHTML remains server-rendered and remains the source of page structure.

## Data and security behaviour

The release preserves:

- `AuthService::cabinetData()` as the existing Cabinet read source;
- existing ownership checks for submitted properties;
- existing manager-only operational additions;
- current Telegram connect/disconnect workflow;
- existing 404/503 behaviour.

Cabinet pages are explicitly `noindex,nofollow`.

WEB V0.9 does not claim new CSRF guarantees or alter mutation authorization.

## Explicitly outside WEB V0.9

- a new Portal business domain;
- `/app/*` route migration;
- account/profile editing features that do not yet have an application contract;
- duplicated Property role rules;
- moving manager operational dashboards into the client Portal;
- Public site redesign.

## Definition of Done

WEB V0.9 is closed when:

1. Cabinet overview and submission editor load the dedicated Portal bundle;
2. the browser bundle activates only on a rendered Portal surface;
3. manager/admin Cabinet access retains Workspace navigation;
4. Portal navigation remains module-aware and role-gated;
5. responsive and progressive submit states are present;
6. Vite asset validation includes `portal-cabinet`;
7. a dedicated WEB V0.9 architecture gate passes independently of unrelated runtime failures.
