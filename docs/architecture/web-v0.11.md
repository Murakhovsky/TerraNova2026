# WEB V0.11 — Design System & Legacy CSS Extraction

## Goal

WEB V0.11 removes accidental cross-surface frontend ownership.

Canonical runtime becomes:

```text
Design System
  tokens
  foundation
  components
  patterns
      ↓
Surface
  Public | Portal | Workspace
      ↓
Feature
```

The release does not create a Frontend, Public or Portal DDD domain. It only reorganizes Interface/Presentation ownership.

## Canonical stylesheet contract

Shared design-system source:

```text
frontend/styles/
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

`design-system.css` imports in this order:

```text
tokens → foundation → components → patterns
```

A surface entrypoint then loads exactly one surface stylesheet before feature-specific CSS.

## Surface runtime ownership

### Public

Entrypoint: `public-surface`

Owns:

- shared design-system primitives;
- Public surface stylesheet;
- Public accessibility/responsive rules;
- Public-only interaction behavior;
- Public feature CSS.

Historical `terranova-club` behavior is no longer globally injected into Portal or Workspace.

### Portal

Entrypoint: `portal-cabinet`

Owns:

- shared design-system primitives;
- Portal surface stylesheet;
- Cabinet feature CSS/JS.

Portal does not inherit Workspace shell assets.

### Workspace

Entrypoint: `terranova-interface`

Owns:

- shared design-system primitives;
- Workspace surface stylesheet;
- reusable interface components;
- Workspace shell behavior.

Workspace does not load Public catalog/favourites/marketing interactions.

## Root layout

`app/Interfaces/Web/View/index.phtml` resolves exactly one base surface entry:

```text
workspace → terranova-interface
portal    → portal-cabinet
public    → public-surface
```

Explicit controller surface ownership wins. `workspaceSection` always implies Workspace. Historical internal routes are conservatively inferred as Workspace for compatibility; `/cabinet*` is inferred as Portal; the fallback is Public.

The body exposes:

```html
data-interface-surface="public|portal|workspace"
```

Feature bundles are appended and de-duplicated after the surface bundle.

## Legacy CSS extraction

`terranova-club.css` is no longer a global runtime dependency. Its Public visual contract is moved under Public surface ownership. The historical source file has been removed; Git history is the archive.

The old homepage bundle `terranova-home` is retired from Vite because WEB V0.10 replaced that homepage with the canonical Public surface.

`interface.css` was a compatibility aggregate during WEB V0.11 and is now retired post-freeze. `terranova-interface.js` composes `design-system.css` and `layouts/workspace.css` directly, while `layouts/workspace.css` owns the live responsive workspace composition.

## Legacy JavaScript extraction

Historical Public interaction code from `terranova-club.js` is moved to:

```text
frontend/features/public/interactions.js
```

The extracted module owns only Public presentation behavior:

- saved/favourite UI state;
- Public search-tab/category state;
- request-intent projection;
- campaign attribution fields;
- click analytics.

A historical fake form-success handler that called `preventDefault()` without submitting to the backend is removed.

WEB V0.12 subsequently closed the historical `localStorage` / `sessionStorage` debt: favourites moved to the server-session `/api/v1/public/properties/favourites` contract, campaign attribution is derived from the current URL, and canonical frontend JavaScript is guarded against browser persistence.

## Vite ownership

Retired runtime entrypoints:

- `terranova-club`;
- `terranova-home`.

Canonical Vite entrypoints remain feature/surface oriented. `frontend_assets.php` verifies the full runtime entry set and prevents retired global bundles from returning.

## Regression gate

`tests/architecture/web_v011_design_system.php` protects:

- design-system load order;
- one base bundle per surface;
- `data-interface-surface` ownership;
- removal of global `terranova-club` / `terranova-home` runtime references;
- Public/Portal/Workspace entrypoint composition;
- Public JS extraction;
- legacy audit completeness;
- no Frontend/Public/Portal DDD domains.

## Definition of Done

WEB V0.11 is closed when:

1. Public, Portal and Workspace each load one canonical surface bundle;
2. shared CSS is ordered as tokens → foundation → components → patterns;
3. feature CSS loads after its surface layer;
4. root layout no longer globally injects Public + Workspace bundles together;
5. `terranova-club` and `terranova-home` are absent from Vite runtime input;
6. historical Public JS is feature-owned rather than global;
7. fake browser-side form success behavior is removed;
8. legacy CSS/JS is classified as USED / MIGRATED / DUPLICATE / DEAD;
9. Vite remains the single asset pipeline;
10. architecture gates prevent global legacy dependencies from returning.
