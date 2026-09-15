# WEB V0.13 — Explicit Routing & 404 Closure

WEB V0.13 resolves the router debt deliberately left open by WEB V0.12. It changes application routing semantics, not the `Public | Portal | Workspace` model and not business-domain ownership.

## Goal

The Web application must no longer expose controllers/actions merely because their class and method names happen to match a URL.

The shared router is therefore constructed as `Router(false)`, which disables Phalcon's built-in default routes. Every supported journey must be reachable through an application-owned route declaration or a module route contributor.

## Route ownership

Routing stays layered instead of being collapsed into one giant route table:

- `FrontendRoutes` keeps established public/application routes;
- `CoreWebRoutes` owns application-level root, authentication, Portal and core Administration routes;
- `SpatialWebRoutes` explicitly preserves the Spatial Web journeys that previously depended on default controller/action routing;
- platform and visualization routes keep their existing route owners;
- Sales, Property and Diagnostic routes remain owned by their module route contributors;
- `Bootstrap\SpatialModule` continues to own Spatial API routes.

WEB V0.13 does not move business routing into a new Domain and does not make `CoreWebRoutes` an owner of domain capabilities.

## Core explicit routes

The cutover explicitly preserves the currently supported non-domain entry points:

```text
/
/auth/login
/auth/register
/auth/logout

/cabinet
/cabinet/submission/{id}
/cabinet/telegramConnect
/cabinet/telegramDisconnect

/admin
/admin/users
/admin/analytics
/admin/createUser
/admin/updateUser/{id}
```

Homepage, login/register and submission routes retain the HTTP methods required by their existing forms. Mutation-only actions such as Telegram binding and user administration are declared as POST routes.

## Spatial compatibility closure

The audit found that the Spatial Web workspace still relied on Phalcon default routes. V0.13 makes those journeys explicit before disabling defaults:

```text
GET  /spatial/manage
GET  /spatial/edit
GET  /spatial/edit/{id}
POST /spatial/save/{id}
POST /spatial/upload/{id}
POST /spatial/external/{id}
POST /spatial/capture/{id}
POST /spatial/hotspot/{id}
POST /spatial/publish/{id}
GET  /spatial/scene/{slug}
```

This is a compatibility migration only. Spatial service/domain ownership is unchanged.

## Canonical not-found behavior

`CoreWebRoutes` installs one application-wide 404 target after Web module route contributors have registered their routes.

Unknown Web URLs resolve to `ErrorController::notFoundAction()` and reuse the WEB V0.12 safe failure contract:

- Public-looking URLs render a Public 404;
- `/cabinet*` renders a Portal 404;
- Workspace prefixes render a Workspace 404;
- unknown `/api*` and `/webhooks*` paths return a small JSON `404` contract;
- all rendered failures remain `noindex,nofollow` and do not expose exception details.

Paths such as `/cabinet/index`, `/admin/index` or arbitrary `/controller/action` URLs are no longer implicit aliases. They resolve through the application-wide 404 unless explicitly declared.

## URL normalization

The shared router enables `removeExtraSlashes(true)` so trailing/duplicate slash noise does not require a second route tree. URL normalization must not restore default controller/action discovery.

## Live routing smoke

`tests/smoke/web_v013_live_routes.sh` runs on the deployed AWS dev application after the external HTTPS health check. It validates the routing semantics against the real Phalcon runtime rather than emulating the extension inside architecture CI.

The smoke verifies:

- `/` and `/auth/login` are reachable;
- unauthenticated `/cabinet`, `/admin` and `/spatial/manage` explicitly resolve and redirect to login;
- implicit aliases `/cabinet/index` and `/admin/index` return `404`;
- GET requests cannot reach POST-only `/cabinet/telegramConnect` or `/spatial/save`;
- an arbitrary Web path returns the canonical rendered `404`;
- an arbitrary `/api/*` path returns the canonical JSON `404` with `error=not_found`.

This separates concerns deliberately: architecture CI checks declaration contracts without requiring the Phalcon extension, while deployment smoke proves real router behavior on the production-like runtime.

## Regression gate

`tests/architecture/web_v013_explicit_routing.php` verifies:

1. production router construction uses `Router(false)`;
2. core and Spatial compatibility routes are declared explicitly;
3. mutation routes retain POST restrictions;
4. global `/:controller/:action` default patterns do not exist;
5. canonical entry points resolve to their intended controllers/actions;
6. default-style aliases and unknown URLs resolve to the canonical error controller;
7. route registration happens after module route contributors where required for the global fallback;
8. the 404 controller preserves Public, Portal, Workspace and API failure semantics;
9. the AWS dev pipeline retains the live routing smoke.

The gate is also part of the global `COS Runtime Checks`, so AWS dev deployment cannot proceed when explicit routing regresses.

## Definition of Done

WEB V0.13 is closed when:

- default Phalcon routes are disabled in runtime configuration;
- all audited routes that depended on defaults have explicit owners;
- the dedicated WEB V0.13 gate succeeds;
- the existing frontend route gate succeeds;
- global COS Runtime Checks succeed;
- AWS dev deployment and external HTTPS health check succeed on the same `main` commit;
- the post-deploy live routing smoke succeeds on that same commit.
