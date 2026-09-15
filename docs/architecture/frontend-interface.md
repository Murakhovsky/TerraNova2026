# Terra Nova Frontend / Interface Architecture

Status: **WEB V0.11**

## 1. Boundary

Frontend is an **Interface / Presentation** layer over application and domain capabilities. It is not a DDD domain.

```text
Business Domain
  → Application / Read Model
  → Web / API Interface
  → Frontend feature projection
  → Human workflow
```

Forbidden domain directories:

- `app/Domains/Frontend`;
- `app/Domains/Public`;
- `app/Domains/Portal`.

Server-side web code lives under `app/Interfaces/Web`. Browser source lives under `frontend`.

## 2. Canonical surfaces

Terra Nova has exactly three frontend surfaces.

### Public

External world: homepage, real-estate catalog/map/property/presentation, services, partners, company content and public COS product pages.

Primary navigation:

`Нерухомість | Послуги | Партнерам | Terra Nova | COS`

### Portal

Authenticated user interaction with Terra Nova: overview, own real-estate context, requests/cases, submission and account projection.

Portal is user-centric and never renders the Company Workspace sidebar.

### Company Workspace

Operational company work: Sales, Clients, Property operations, COS, Diagnostics, Analytics and Administration.

Workspace is company + work. It is role/capability aware but browser code never becomes the source of permission truth.

## 3. Runtime surface ownership

The root layout resolves one base entrypoint:

```text
Public    → public-surface
Portal    → portal-cabinet
Workspace → terranova-interface
```

Feature bundles are appended after the base surface entry and de-duplicated.

The rendered body exposes:

```html
<body data-interface-surface="public|portal|workspace">
```

Controllers should declare `interfaceSurface` where surface ownership is explicit. `workspaceSection` implies Workspace. Compatibility inference exists only for historical routes and must not become a substitute for explicit ownership in new controllers.

## 4. Browser source architecture

```text
frontend/
├── api/
├── components/
├── core/
├── entrypoints/
├── features/
│   ├── analytics/
│   ├── clients/
│   ├── cos/
│   ├── diagnostics/
│   ├── home/
│   ├── portal/
│   ├── property/
│   ├── public/
│   └── sales/
├── layouts/
├── spatial/
└── styles/
    ├── tokens.css
    ├── foundation.css
    ├── components.css
    ├── patterns.css
    ├── design-system.css
    └── layouts/
        ├── public.css
        ├── portal.css
        └── workspace.css
```

A browser feature is a projection of an existing capability, not a new business truth.

## 5. Design-system load order

Canonical order:

```text
tokens
→ foundation
→ components
→ patterns
→ surface
→ feature
```

`frontend/styles/design-system.css` owns the shared part of this order.

Surface entrypoints then load exactly one surface stylesheet before feature CSS.

### Shared primitives

Current canonical primitives include:

- button / actions;
- card / panel / KPI;
- status / badge;
- input / select / textarea / field;
- tabs;
- empty/error/loading state;
- skeleton;
- alert;
- dialog;
- table wrapper/table;
- filter bar;
- page header/meta;
- stack/grid/split/toolbar patterns.

Features should extend these rather than creating another private design system.

## 6. Surface density

- Public: spacious, visual, editorial.
- Portal: personal, task-oriented, moderate density.
- Workspace: compact, information-dense, operational.

Responsive baseline:

- mobile: `<= 650px`;
- tablet: `651–1050px`;
- desktop: `> 1050px`;
- wide optimization: `>= 1440px`.

## 7. Asset policy

Browser source is built only through Vite.

Canonical flow:

```text
frontend source
  → Vite entrypoint
  → public/build/.vite/manifest.json
  → ViteAssetManifest
  → server layout
```

Forbidden:

- direct `/assets/js/*` or `/assets/css/*` references from PHTML;
- browser source under `public/js`, `public/css`, `public/assets/js`, `public/assets/css`;
- page-local manually versioned bundles;
- retired global `terranova-club` / `terranova-home` Vite entrypoints.

## 8. Legacy policy after WEB V0.11

Historical source may remain temporarily as quarantined reference, but canonical runtime may not depend on it accidentally.

`terranova-club` and `terranova-home` are retired as Vite inputs.

Public behavior that still matters is feature-owned under `frontend/features/public`.

Legacy selector and JS status is tracked in `docs/architecture/frontend-legacy-audit.md` using:

- USED;
- MIGRATED;
- DUPLICATE;
- DEAD.

## 9. Browser responsibility

Browser code may own:

- rendering/interactions;
- view filters and ephemeral UI state;
- responsive navigation;
- loading/empty/error presentation;
- progressive submit state;
- accessibility behavior;
- non-authoritative presentation preferences.

Browser code must not own:

- permissions;
- deal/property lifecycle rules;
- publication rules;
- diagnostic scoring;
- COS action policy;
- server validation/business invariants.

Public `localStorage` / `sessionStorage` compatibility behavior is documented debt and is scheduled for WEB V0.12 review. It must never become backend/business truth.

## 10. UI states

Data-driven screens should explicitly account for the states supported by their server/application contract:

- Loading / Loaded;
- Empty / Filtered empty;
- Partial data;
- Error / API unavailable;
- Permission denied;
- Saving / Saved;
- Validation error.

Raw backend exceptions are not user interface copy.

## 11. Accessibility baseline

Target: WCAG 2.2 AA.

Minimum rules:

- keyboard-operable controls;
- visible focus;
- semantic landmarks;
- `aria-current` for active navigation;
- status not communicated by color alone;
- practical ~44px touch targets;
- reduced-motion support;
- responsive forms and tables.

## 12. Current migration state

Completed sequence:

1. Workspace foundation and shell;
2. Sales;
3. Client Case Workspace;
4. Property Workspace;
5. COS Control Center;
6. Diagnostics;
7. Analytics;
8. Portal refinement;
9. Public refinement;
10. Design System & legacy extraction.

Next: **WEB V0.12 — Frontend Production Closure**.
