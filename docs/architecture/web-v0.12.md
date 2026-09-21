# WEB V0.12 — Frontend Production Closure

WEB V0.12 closes FRONTEND EPIC II. It does not introduce a fourth frontend surface or new business capabilities. It hardens the existing `Public | Portal | Workspace` contract for production operation.

## Production contract

All three surfaces keep one runtime asset owner and the canonical CSS order:

`tokens → foundation → components → patterns → production → surface → feature`

The shared production layer owns cross-surface accessibility, responsive and failure-state rules. Feature code remains responsible only for feature presentation.

## Failure UX

Server-rendered Web UI has one safe failure contract for the production states `403`, `404`, `422`, `500` and `503`.

The UI receives a human-readable recovery state and a request ID. Full exception class/message and request URI remain in server logs. Raw exception messages must never be assigned to rendered `pageStatus` values.

The bootstrap-level `500` fallback is deliberately self-contained because it must still work when Vite, the view layer or application composition fails. It returns a minimal accessible page, a request ID, `noindex` semantics and `Cache-Control: no-store` without exposing the exception.

## Browser state

Browser persistence is not application truth.

- favourites use server session state via `/api/v1/public/properties/favourites`;
- Workspace sidebar collapse is ephemeral presentation state;
- campaign parameters are derived from the current URL and submitted with the current request;
- canonical frontend JavaScript may not use `localStorage` or `sessionStorage`.

This keeps business/account state behind server contracts instead of silently creating another database inside each browser.

## Submission safety

`frontend/core/production.js` provides the shared POST form guard:

- marks the form `aria-busy`;
- disables submit controls;
- rejects repeated submission while the first navigation is active;
- restores controls on browser `pageshow` navigation.

Feature bundles must not reimplement this behavior unless a form explicitly opts into repeat submissions.

## Responsive matrix

The production CSS contract explicitly covers:

- compact: `<= 650px`;
- medium: `651–1050px`;
- desktop: `1051–1439px`;
- wide: `>= 1440px`.

The matrix is a regression boundary, not a promise that every screen has a unique design at every width. Pages must remain operable without horizontal action loss, with mobile-safe actions and adequate readable widths.

## Accessibility baseline

The target baseline is WCAG 2.2 AA for production Web UI. V0.12 enforces the structural subset that can be guarded reliably in the current pipeline:

- visible keyboard focus remains in foundation CSS;
- interactive controls have a 44px minimum target height;
- failure states use semantic headings and recovery links;
- busy forms expose `aria-busy`;
- navigation toggles maintain `aria-expanded`;
- reduced-motion preferences suppress non-essential transition/animation motion.

Manual review remains required for content-level semantics, contrast in custom feature visualizations and complete keyboard flows.

## Performance budget

The WEB V0.12 architecture test reads the production Vite manifest after build and enforces a performance budget for the three canonical surface entrypoints. The intent is to stop ordinary Public/Portal/Workspace changes from silently absorbing large bundles.

Large specialist assets such as Spatial 3D and Spark remain explicit feature/vendor exceptions and must stay outside the canonical surface entrypoints.

## Legacy closure

The retired source entrypoints and styles are deleted:

- `frontend/entrypoints/terranova-club.js`;
- `frontend/entrypoints/terranova-home.js`;
- `frontend/styles/terranova-club.css`;
- `frontend/styles/terranova-home.css`.

Git history is the archive. Runtime source must not keep dead parallel design systems “just in case”.

## Router debt

Generic application-wide `Router::notFound()` is intentionally not enabled in WEB V0.12. The current Phalcon router is constructed with default routes, and Phalcon only guarantees `notFound()` behavior when default routes are disabled. Some legacy Web journeys still rely on those default routes.

Therefore V0.12 standardizes controller/resource `404` UX without changing routing semantics. Removing default routes and making every route explicit is tracked as separate router debt because it is an application-routing migration, not a frontend styling task.

## Regression gate

`.github/workflows/web-v012.yml` builds the frontend and runs `tests/architecture/web_v012_production_closure.php` together with the V0.11 and shared frontend asset gates.

The gate prevents:

1. frontend Domains from appearing;
2. retired entrypoints/styles from returning;
3. browser persistence from returning to canonical frontend JS;
4. raw exception messages from being rendered through Web `pageStatus`;
5. Public mobile navigation losing its open/close interaction;
6. surface entrypoints bypassing the shared production guard;
7. responsive/a11y production rules disappearing;
8. canonical Vite surface bundles exceeding their production budgets.

## Definition of Done

WEB V0.12 is closed when the dedicated gate, WEB V0.9, WEB V0.10, WEB V0.11, global runtime checks and AWS dev deployment all succeed on the same `main` commit.
