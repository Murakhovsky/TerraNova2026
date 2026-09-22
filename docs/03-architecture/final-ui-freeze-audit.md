---
title: "Фінальний UI freeze audit"
description: "Route/view audit, desktop/mobile visual QA, dead-code cleanup і post-freeze refinements для COS Web після закриття production adoption."
status: active
updated: 2026-09-22
kind: architecture
---

# Фінальний UI freeze audit

Цей audit закриває production UI після PHASE 9–15 і Wave 12 final closure. Його мета — не додати ще один дизайн-шар, а довести, що routed Web surface не містить 404, dead views, випадкових legacy primitives або неперевірених responsive regressions.

## Що перевіряється

### Маршрут → контролер → представлення

`tests/architecture/web_experience_final_route_audit.php`:

- парсить canonical `symfony/config/routes.yaml`;
- перевіряє існування кожного route controller і методу;
- звіряє `PublicPageCatalog` з фактичним constrained Symfony route;
- будує reachability graph для PHTML views від canonical PHP runtime через recursive partial/view references;
- забороняє unreachable PHTML;
- забороняє повернення retired `index/index` і `company_os/*`;
- забороняє retired `cos-site` bundle;
- рекурсивно забороняє product PHTML legacy primitives `tn-btn`, `tn-form-status`, `tn-empty-state`, старі local filter/toolbars і status-pill.

`tests/functional/web_route_smoke.php` запускається на живому Symfony runtime і проходить усі static HTML GET/HEAD routes. 404, 405, 5xx або відсутність відповіді є failure. Protected routes можуть відповідати canonical redirect/401/403; retired routes можуть повертати 410.

## Знайдені та виправлені route defects

Audit знайшов реальний public 404-кластер: `PublicPageCatalog` і sitemap публікували статичні сторінки, але Symfony не мав delivery route.

Відновлено canonical route через `PublicContentPageController::page` для:

- `/terra-nova`;
- `/agency`;
- `/services`;
- `/partners`;
- `/team`;
- `/cases`;
- `/vacancies`;
- `/contacts`;
- `/it`;
- `/art`;
- `/cos`.

`/contacts` підтримує POST через canonical Sales `receivePublicLead`, а не через повернення legacy controller.

Старий public navigation target `/cos/en` видалено. Замість воскресіння retired multilingual `company_os` створено один canonical public COS page на `/cos`.

## Мертві представлення та assets

Після перевірки runtime references видалено:

- `app/Interfaces/Web/View/index/index.phtml`;
- `app/Interfaces/Web/View/company_os/index.phtml`;
- `app/Interfaces/Web/View/company_os/domain.phtml`;
- `app/Interfaces/Web/View/company_os/not_found.phtml`;
- `frontend/entrypoints/cos-site.js`;
- `frontend/styles/cos-site.css`.

Одночасно прибрано їх Vite/layout/test contracts.

`property/pdf.phtml` не видаляється: Web route використовує presentation print flow, але `PropertyPresentationService` використовує цей PHTML як service-level print renderer.

## Очищення legacy primitives

Живі routed PHTML більше не повинні використовувати:

- `tn-btn*`;
- `tn-form-status`;
- `tn-empty-state`;
- `tn-filter-bar`;
- `tn-crm-filters`;
- `tn-manage-filters`;
- `tn-ui-toolbar`;
- `tn-status-pill`.

Buttons переведено на `tn-ui-button`, status messages — на `tn-ui-alert`, empty/error surfaces — на canonical `State` там, де це семантично доречно.

Spatial upload зберігає `data-spatial-upload-status`, але visibility тепер керується native `hidden`, а не presentation-класом `is-visible`.

Obsolete selectors прибрано або переведено на canonical selectors у production/public/client CSS.

## Таблиці та форми

PHASE 13 вже забороняє product-level raw tables, окрім canonical table renderers. Editable operational surfaces використовують `OperationalGrid` або явно зафіксований domain-specific mutation pattern.

Final audit не перетворює form-heavy workflow на read-only DataTable. Перевіряється інше:

- local filter forms не повертаються;
- canonical form ownership не губиться;
- routed forms залишаються видимими, підписаними та не виходять за viewport;
- CSRF/mutation contracts захищаються domain/production gates.

## Матриця Visual QA

`tests/browser/web_platform_quality.mjs` проходить representative public journey:

- Home;
- Login;
- Register;
- Blog;
- Property Catalog;
- Property Map;
- Favourites;
- Property Submit;
- Services;
- Contacts.

Для кожної surface використовуються чотири profiles:

- desktop 1440×1000 / light preference;
- desktop 1440×1000 / dark preference;
- mobile 390×844 / light preference;
- mobile 390×844 / dark preference.

Перевіряються:

- spacing/geometry через sane `main` bounds;
- typography: базовий font-size та line-height;
- horizontal overflow і конкретні offenders;
- sticky/fixed elements;
- form controls, viewport clipping та мінімальна висота target;
- keyboard focus;
- labels, image alt, accessible names, duplicate IDs;
- browser/network errors;
- screenshot non-blank sanity.

Screenshots зберігаються у CI artifact `wave-12-23-web-quality`.

## Межі темної та світлої тем

Canonical Symfony Experience Platform має explicit theme tokens і dark/light contracts.

Retained PHTML compatibility/public surfaces на момент freeze мають детерміновану light palette. Final audit запускає їх також із browser `prefers-color-scheme: dark`, щоб ловити runtime, geometry, overflow і form regressions, але не додає неперевірену другу dark palette у frozen compatibility layer.

Тобто dark preference перевіряється як robustness state, а не як обіцянка повного dark skin для кожної retained PHTML surface.

## Спеціалізовані surfaces

Canonicalization не означає, що всі екрани повинні мати однаковий shell.

Свідомо specialized залишаються:

- rich Property detail/presentation hero з media/schema/business facts;
- public Spatial scene/viewer;
- Home marketing composition;
- operational editable grids;
- service-level Property PDF renderer.

Вони проходять спільні architecture/runtime/visual gates, але не підміняються generic component лише заради однакового DOM.

## Застарілі тести й документація

Під час audit:

- `web_v014_standard_stack.php` перестав охороняти retired `company_os/index.phtml`;
- `property_public_read_cutover.php` переведено зі старого `index/index.phtml` на canonical `HomePageController + home/canonical`;
- `web-sitemap.md` переведено з dead `/cos/en` на live `/cos`;
- sitemap architecture gate тепер вимагає, щоб `PublicPageCatalog` мав фактичний Symfony route.

## Правило freeze

Після merge цього audit будь-який новий routed UI повинен одночасно:

1. мати canonical route/controller ownership;
2. мати reachable renderer;
3. проходити static route smoke;
4. не повертати legacy presentation primitives;
5. проходити desktop/mobile browser QA, якщо входить у representative production journey;
6. не вводити новий design primitive, якщо задача вирішується наявним canonical contract.

## Критерії завершення

- route/controller graph gate зелений;
- unreachable PHTML = 0;
- static routed-page smoke не має 404/405/5xx;
- residual legacy primitives у product PHTML = 0;
- Vite/build не містить retired `cos-site`;
- representative public journey проходить desktop/mobile × light/dark-preference QA;
- PHASE 13–15, Wave 12 closure, accessibility та performance gates залишаються green;
- obsolete merged/superseded automation branches очищені після merge final audit PR.
