# Frontend Legacy Audit — WEB V0.11

Status vocabulary:

- **USED** — canonical runtime source still intentionally used;
- **MIGRATED** — behavior/style moved under correct surface/feature ownership;
- **DUPLICATE** — older source overlaps canonical primitives and must not be used by new code;
- **DEAD** — no canonical runtime reference remains.

## CSS inventory

| Source / selector family | Status | V0.11 decision |
| --- | --- | --- |
| `frontend/styles/tokens.css` | USED | canonical token source; compatibility aliases added for migration |
| `frontend/styles/foundation.css` | USED | shared accessibility/base utilities |
| `frontend/styles/components.css` | USED | shared reusable UI primitives |
| `frontend/styles/patterns.css` | USED | shared composition patterns |
| `frontend/styles/design-system.css` | USED | canonical shared load-order entry |
| `frontend/styles/layouts/public.css` | MIGRATED | Public visual contract now surface-owned rather than globally injected |
| `frontend/styles/layouts/portal.css` | USED | Portal surface baseline |
| `frontend/styles/layouts/workspace.css` | USED | Workspace surface baseline and compatibility composition |
| `frontend/styles/terranova-club.css` | DEAD | retained only as quarantined historical source/reference; canonical runtime must not import it |
| `frontend/styles/terranova-home.css` | DEAD | WEB V0.10 homepage replaced this bundle |
| `frontend/styles/interface.css` | DUPLICATE | old aggregate; Workspace entrypoint composes canonical files directly |
| `frontend/styles/workspace.css` | MIGRATED | consumed only through canonical Workspace surface composition |
| `frontend/styles/workspace-mobile.css` | MIGRATED | consumed only through canonical Workspace surface composition; historical compatibility comment is source debt, not runtime ownership |
| `frontend/layouts/surfaces.css` | USED | shared density/layout contract imported by design system |
| feature CSS under `frontend/features/*` | USED | must load after surface ownership; Cabinet feature CSS is reduced to live profile/layout selectors |

## Historical selector families

| Selector family | Status | Ownership |
| --- | --- | --- |
| `.tn-header`, `.tn-nav`, `.tn-footer`, `.tn-logo` | MIGRATED | Public surface |
| `.tn-page`, `.tn-page-hero`, `.tn-kicker` on public pages | MIGRATED | Public surface / Public feature |
| `.tn-property-*`, `.tn-card-*`, catalog/presentation selectors | MIGRATED | Public surface plus Property feature bundles |
| `.tn-ui-*` primitives | USED | shared design system |
| `.tn-workspace-*`, `.tn-command-palette*` | USED | Workspace surface |
| `.tn-portal-profile`, `.tn-portal-page` | USED | minimal native Cabinet presentation |
| historical `.tn-portal-header*`, `.tn-portal-hero*`, `.tn-portal-card*`, `.tn-portal-state*` | DEAD | retired after native Cabinet/PHASE 14 shell closure |
| old homepage-only `.tn-home-*` visual blocks not rendered by WEB V0.10 | DEAD | no canonical runtime owner |

## JavaScript inventory

| Source / behavior | Status | V0.11 decision |
| --- | --- | --- |
| `frontend/entrypoints/public-surface.js` | USED | canonical Public entrypoint |
| `frontend/features/public/surface.js` | USED | menu close, lazy image hints, submit busy state |
| `frontend/features/public/interactions.js` | MIGRATED | Public-only saved state, attribution and analytics |
| `frontend/entrypoints/terranova-interface.js` | USED | canonical Workspace entrypoint |
| `frontend/core/workspace-shell.js` | USED | Workspace presentation shell |
| `frontend/entrypoints/portal-cabinet.js` | USED | canonical Portal entrypoint |
| `frontend/features/portal/cabinet.js` | DEAD | removed after dedicated Portal header/menu retirement; shared production UX remains authoritative |
| `frontend/entrypoints/terranova-club.js` | DEAD | removed from Vite runtime; historical source may remain quarantined |
| `frontend/entrypoints/terranova-home.js` | DEAD | removed from Vite runtime after WEB V0.10 homepage replacement |
| historical fake `data-inbound-request-form` success handler | DEAD | removed; browser must not report CRM success without backend success |

## Browser persistence debt

`frontend/features/public/interactions.js` still uses:

- `localStorage` for historical favourite/saved-property UI state;
- `sessionStorage` for marketing campaign attribution.

These are **MIGRATED but deprecated**. They are now confined to Public and no longer execute in Portal or Workspace.

WEB V0.12 owns the next decision:

- replace favourites persistence with an application/backend contract or explicitly downgrade the feature;
- move campaign attribution into a controlled request/session contract if persistence remains necessary;
- prohibit browser persistence from becoming business truth.

## Runtime rules after V0.11

Canonical runtime must not:

- globally load `terranova-club` + `terranova-interface` together;
- expose `terranova-club` or `terranova-home` as Vite inputs;
- import `terranova-club.css` from an entrypoint;
- let Public persistence/analytics behavior execute in Portal or Workspace;
- restore a dedicated Portal header/menu browser module without a live rendered contract;
- bypass the Vite manifest with hand-written browser asset URLs.

Canonical runtime must:

- load one surface bundle;
- load shared design-system primitives before the surface;
- append feature bundles after the surface;
- keep server/application services authoritative for permissions and business state.
