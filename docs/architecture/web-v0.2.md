# WEB V0.2 — Workspace Foundation

Status: architecture increment after `WEB V0.1`.

## Goal

Turn the V0.1 interface skeleton into an enforceable foundation for feature development without changing business-domain behavior.

V0.2 does not redesign Sales, Clients or Properties. It defines how those screens must be built next.

## Boundaries

Frontend remains an Interface / Presentation layer.

```text
Domains/*
  ↓ application contracts/read models
Interfaces/Web + Interfaces/Api
  ↓
frontend/features/*
  ↓
Public / Portal / Workspace UI
```

No `Domains/Frontend` or equivalent technical pseudo-domain is allowed.

## Source structure

```text
frontend/
├── api/
├── assets/
├── components/       # interaction primitives shared by surfaces
├── core/             # shell/navigation/bootstrap behavior
├── entrypoints/      # Vite composition roots only
├── features/         # page/domain projections, loaded when needed
│   ├── cos/
│   └── diagnostics/
├── layouts/          # surface/page geometry
├── spatial/
└── styles/           # design tokens + generic visual primitives
```

Feature code must not be moved into `core` merely because several screens may eventually use it. Promote code only after a real second consumer exists.

## Shared server-rendered UI

Reusable PHTML primitives live under:

```text
app/Interfaces/Web/View/components/ui/
```

V0.2 provides:

- Page Header
- KPI Card
- State / Empty / Error surface
- Status Badge
- Tabs

These partials render data only. Business rules, authorization and persistence do not belong in UI components.

## Shared browser UI

`frontend/components/interactive.js` owns generic interaction hooks such as opening/closing dialogs and dismissible UI.

`frontend/core/workspace-shell.js` continues to own only Workspace shell behavior: navigation collapse, mobile drawer and command palette.

The two responsibilities are intentionally separate.

## Layout contract

`frontend/layouts/surfaces.css` defines reusable page geometry and density for Public, Portal and Workspace surfaces.

Canonical Workspace page classes:

```text
.tn-workspace-page
.tn-workspace-page--wide
.tn-workspace-page--compact
.tn-workspace-section
.tn-workspace-surface
```

New Workspace screens should use these classes instead of introducing page-specific outer margins and widths.

## Feature bundle contract

Feature-specific CSS/JS is loaded through a named Vite entrypoint and attached by the controller using `pageAssetEntries`.

Example:

```php
$this->view->pageAssetEntries = ['diagnostics-methodology-studio'];
```

The global layout resolves those entries through `ViteAssetManifest`.

V0.2 establishes two real examples:

- `cos-control-center`
- `diagnostics-methodology-studio`

This is the template for subsequent Sales, Client and Property feature bundles.

## Workspace shell opt-in

A controller whose view does not render `shared/manager_header` itself may opt into global Workspace chrome with:

```php
$this->view->workspaceSection = 'cos';
```

The global layout renders the canonical Workspace shell before the page content.

This migration mechanism prevents duplicate shell markup while old screens are converted incrementally.

## Diagnostics migration

Methodology Studio is moved from direct files under `public/assets` into:

```text
frontend/features/diagnostics/
```

Its entrypoint is built by Vite. Direct PHTML references to `/assets/js/*` and `/assets/css/*` are forbidden by architecture tests.

`public/assets/js` and `public/assets/css` are removed from tracked browser source.

## UI primitives available to WEB V0.3+

Generic styling now covers:

- Button variants
- Card / Panel
- KPI Card
- Status / Badge
- Input / Select / Textarea / Field
- Tabs
- State / Empty / Error / Loading
- Skeleton
- Alert
- Dialog
- Filter Bar
- Data Table wrapper
- Toolbar
- Stack / Grid / Split patterns
- Page Header

A feature may extend these primitives, but must not fork its own design system.

## CI rules

Frontend architecture checks must fail when:

- a Frontend DDD domain appears;
- canonical Workspace navigation changes without an explicit architecture decision;
- V0.2 shared components/layouts/features disappear;
- COS stops opting into Workspace shell;
- Diagnostics stops using its Vite entrypoint;
- a PHTML file directly references `/assets/js/` or `/assets/css/`;
- a declared Vite entrypoint is missing from the generated manifest.

## Definition of Done

WEB V0.2 is complete when:

1. shared UI partials exist and are presentation-only;
2. `frontend/components`, `frontend/features`, and `frontend/layouts` contain real source code;
3. COS Control Center uses canonical Workspace chrome;
4. Diagnostics is migrated from direct public assets to Vite;
5. feature-specific entrypoints are controller-selected;
6. direct legacy JS/CSS references are architecture-test violations;
7. CI builds every frontend entrypoint successfully;
8. no Sales/Client/Property business behavior is changed by this increment.

## Next increment

`WEB V0.3 — Sales Workspace` should be the first complete vertical UI built on this foundation:

```text
Sales
├── Overview
├── Today
├── Pipeline
├── Leads
├── Deals
└── Deal Workspace
```

It should consume the existing Sales read API and application contracts rather than introduce a parallel frontend data model.
