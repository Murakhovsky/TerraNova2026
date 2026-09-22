# WEB V0.6 — Clients Workspace

## Purpose

WEB V0.6 migrates the existing client CRM screens into the shared Company Workspace without changing Sales business rules or inventing a separate Clients Domain.

The migrated surface is:

```text
Clients
├── Inbox
├── Cases
└── Case Workspace
```

Canonical routes remain unchanged:

- `/client-case/inbox`;
- `/client-case`;
- `/client-case/show/{id}`.

This is a presentation-layer migration. Existing Sales-owned read models and command services remain the business source of truth.

## Runtime boundary

`ClientCaseController` now declares the Workspace contract for all read screens:

```text
workspaceSection = clients
workspaceActive  = inbox | cases
pageAssetEntries = clients-workspace
```

The global Web layout owns the shared Workspace shell. Client Case views still contain their historical `shared/manager_header` partial call for compatibility with older rendering paths, but the partial suppresses that call whenever a migrated controller has declared `workspaceSection`. The layout then renders the shell exactly once with `layoutOwned=true`.

Історичний duplicate-shell guard зберігається лише як layout safety contract. Після PHASE 12 самі Client Case views уже використовують canonical presentation components і більше не залежать від окремого compatibility bridge.

## Navigation ownership

Clients navigation remains owned by the Sales module through `SalesNavigationContributor`:

- Clients -> Inbox -> `/client-case/inbox`;
- Clients -> Cases -> `/client-case`.

WEB V0.6 does not create `Domains/Clients`. Client cases, inbound requests, pipeline stages, activities and related commands remain under the existing Sales business boundary.

Module-aware behavior from WEB V0.5 therefore continues to apply: disabling Sales removes the Clients section from Workspace navigation.

## Frontend bundle

WEB V0.6 adds a dedicated Vite entrypoint:

```text
frontend/entrypoints/clients-workspace.js
    -> frontend/features/clients/workspace.css
```

The bundle is loaded only by Client Case read screens.

The CSS adapts the existing CRM markup to the shared Workspace visual system:

- workspace density and spacing;
- cards and panels;
- Inbox request cards;
- filters and forms;
- KPI tiles;
- pipeline/funnel columns;
- case tables;
- Case Workspace detail grids;
- responsive mobile/tablet behavior.

`tn-client-workspace` тепер оголошується безпосередньо в трьох canonical Client Case views. Окремий browser scoping script видалено як зайвий. Pending/`aria-busy` submit behavior лишається у спільному `initProductionUX`, а бізнес-переходи залишаються server-side.

## Data and failure behavior

WEB V0.6 does not replace `ClientCaseReadModelInterface`, `ClientCaseCommandService` or `SalesInboundService`.

Existing read behavior remains:

- Inbox filters and statistics;
- case filters and statistics;
- pipeline stages;
- manager options;
- property/location context;
- inbound requests;
- activities;
- property matches;
- COS intelligence projection where available.

Existing partial-failure behavior also remains. A Client read failure returns the existing 503 page state; COS intelligence failure does not make the entire Case Workspace unavailable.

## Історичний compatibility debt

`Interfaces\Web\Service\ClientCaseService` remains a deprecated compatibility facade. Removing it requires a separate delivery refactor because the current controller exposes multiple mature mutation flows through that facade.

WEB V0.6 does not mix that refactor into a UI migration merely to make the directory tree look more enlightened.

The historical `shared/manager_header` call залишається у трьох PHTML views як inert duplicate-shell guard. Presentation bridge для Client Case вже закрито PHASE 12; подальше видалення самого guard має бути окремим layout cleanup, а не умовою canonical UI.

## Security boundary

Navigation visibility remains presentation only. Backend authentication/manager checks stay authoritative.

WEB V0.6 does not claim to introduce a new authorization or CSRF model. Existing mutation security is unchanged and should be hardened as its own explicit release rather than silently changing form semantics inside a presentation migration.

## Regression contract

`tests/architecture/web_v06_clients_workspace.php` verifies:

- Client Case read screens declare the Clients Workspace contract;
- the shared shell is layout-owned and cannot render twice;
- no Clients Domain is invented;
- Sales continues to own Clients navigation;
- the dedicated Vite bundle exists and is covered by frontend asset checks;
- responsive Clients CSS існує, а submit-state behavior централізований у shared production runtime;
- Client Case PHTML does not bypass Vite with direct asset references.

`.github/workflows/web-v06.yml` runs this contract independently of the larger COS Runtime Checks workflow. That matters while unrelated legacy gates can still fail before the general frontend stage is reached.

## Definition of Done

WEB V0.6 is complete when:

- Inbox, Cases and Case Workspace are rendered inside the shared Company Workspace shell;
- Clients is still a Sales module UI projection rather than a new business Domain;
- the shell renders once, even while legacy templates retain their historical header partial;
- the Client screens load a dedicated `clients-workspace` Vite entrypoint;
- existing CRM workflows remain available;
- responsive behavior covers desktop, tablet and mobile;
- form submission exposes an accessible pending state;
- a dedicated architecture regression test protects the boundary;
- a dedicated CI workflow validates the Web release without depending on unrelated Sales gates;
- existing routes are unchanged.
