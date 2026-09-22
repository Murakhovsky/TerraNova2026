# WEB V0.9 — Portal / Cabinet: актуальний native contract

WEB V0.9 історично ввів окрему Portal surface. Після native Symfony cutover і PHASE 14 shell closure production contract спрощено до фактичного runtime, без збереження мертвого UI shell заради історичної версії.

## Runtime ownership

Canonical routes:

- `/cabinet` → `CabinetPageController::index`;
- `/cabinet/submission/{id}` → `CabinetPageController::retiredSubmission`.

`CabinetPageController`:

- читає native COS/Symfony tenant context;
- redirect-ить unauthenticated user на `/auth/login`;
- redirect-ить manager user на `/sales`;
- для Portal user рендерить `cabinet/canonical.phtml`;
- старий submission editor повертає HTTP 410 через `cabinet/retired-submission.phtml`.

Portal лишається Interface/Presentation surface і не створює `Domains/Portal`.

## Canonical Cabinet

`cabinet/canonical.phtml` використовує:

- canonical PageHeader;
- canonical Panel;
- server-owned `currentUser`, `portalRole`, `organizationId`;
- logout через native `/auth/logout`;
- read-only `tn-portal-profile` для identity context.

Окремий Portal navigation/header більше не існує. Після PHASE 14 були виведені:

- `shared/portal_header.phtml`;
- `frontend/features/portal/cabinet.js`;
- historical `cabinet/index.phtml`;
- historical `cabinet/submission.phtml`.

Це не втрата функціональності: canonical Cabinet більше не рендерив dedicated Portal header, тому browser module був no-op.

## Asset ownership

`frontend/entrypoints/portal-cabinet.js` залишається canonical Portal entrypoint і завантажує:

- shared design system;
- Portal layout baseline;
- мінімальний `frontend/features/portal/cabinet.css`;
- shared `initProductionUX()`.

Feature CSS тепер володіє лише живим Cabinet presentation contract:

- `.tn-portal-page`;
- `.tn-portal-profile`;
- mobile profile layout.

Header/menu/hero/card/list selectors старого Portal shell видалені.

Окремого Portal JavaScript немає. Progressive busy/submission behavior належить shared `frontend/core/production.js`.

## Security and business ownership

Browser layer не визначає:

- role/capabilities;
- tenant/organization;
- permissions;
- redirect policy;
- submission lifecycle.

Ці рішення лишаються server-side.

## Regression gate

`tests/architecture/web_v09_portal_refinement.php` перевіряє:

- native `CabinetPageController`;
- canonical Cabinet і HTTP 410 retired submission boundary;
- Symfony routes;
- відсутність historical Cabinet renderers;
- відсутність retired `portal_header.phtml` і Portal browser module;
- мінімальний Cabinet CSS contract;
- Vite `portal-cabinet` entrypoint;
- shared production guard;
- відсутність `Domains/Portal`.

## Definition of Done

WEB V0.9 у поточному runtime вважається закритим, коли:

1. `/cabinet` належить native Symfony/COS identity lifecycle;
2. manager не отримує окремий дубль Workspace всередині Portal;
3. Cabinet використовує canonical UI primitives;
4. retired submission route лишається явним HTTP 410 compatibility boundary;
5. dedicated legacy Portal header/menu artifacts не повертаються;
6. Portal entrypoint містить тільки реально потрібні assets;
7. permission/business truth лишається server-side;
8. architecture gates захищають цей boundary.
