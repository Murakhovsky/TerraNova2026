# WEB V0.13 — Explicit Routing & 404 Closure

WEB V0.13 resolves the router debt deliberately left open by WEB V0.12. It changes application routing semantics, not the `Public | Portal | Workspace` model and not business-domain ownership.

## Goal

The Web application must no longer expose controllers/actions merely because their class and method names happen to match a URL.

The shared router is therefore constructed as `Router(false)`, which disables Phalcon's built-in default routes. Every supported journey must be reachable through an application-owned route declaration or a module route contributor.

## Route ownership

Routing stays layered instead of being collapsed into one giant route table:

- `FrontendRoutes` keeps established public/application routes;
- `CoreWebRoutes` owns application-level root, authentication, Portal and core Administration routes;
- Spatial Web journeys have since moved from the V0.13 compatibility route table to canonical Symfony routes;
- Architecture Explorer and Diagnostic SSR have also moved to Symfony;
- the remaining Phalcon router owns only the shrinking Public/Portal/Property/Core compatibility surface;
- `Bootstrap\SpatialModule` remains temporarily only because legacy Property presentation still resolves Spatial scene data.

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

/admin
/admin/users
/admin/analytics
/admin/createUser
/admin/updateUser/{id}
```

Homepage, login/register and submission routes retain the HTTP methods required by their existing forms. Telegram binding routes were later retired together with the disabled inbound bot runtime; user administration mutations remain explicit POST routes.

## Spatial compatibility closure

V0.13 originally made Spatial journeys explicit before disabling Phalcon defaults. They are now canonical Symfony routes:

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

Spatial Domain ownership is unchanged. Only HTTP/SSR delivery moved from Phalcon to Symfony.

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
- unauthenticated `/cabinet` and `/admin` remain legacy explicit routes, while `/spatial/manage` is now Symfony-owned and redirects to login;
- implicit aliases `/cabinet/index` and `/admin/index` return `404`;
- retired `/cabinet/telegramConnect` resolves to `404`, while GET cannot execute the Symfony POST-only `/spatial/save` route;
- an arbitrary Web path returns the canonical rendered `404`;
- an arbitrary `/api/*` path returns the canonical JSON `404` with `error=not_found`.

This separates concerns deliberately: architecture CI checks both the shrinking Phalcon core declarations and Symfony-owned migrated routes, while deployment smoke proves real proxy/router behavior on the production-like runtime.

## Regression gate

`tests/architecture/web_v013_explicit_routing.php` verifies:

1. production router construction uses `Router(false)`;
2. core compatibility routes remain explicit and Spatial routes are canonical in Symfony;
3. mutation routes retain POST restrictions across the cutover;
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
