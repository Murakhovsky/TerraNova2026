---
title: Карта Web-поверхонь
description: Канонічна продуктова карта Public, Portal і Workspace, власність маршрутів та правила індексації.
status: active
updated: 2026-09-21
kind: ui
---

# Карта Web-поверхонь

COS Web має три людські поверхні:

```text
Public    = зовнішній світ
Portal    = користувач і його взаємодія з компанією
Workspace = компанія й операційна робота
```

Карта будується за **призначенням поверхні**, а не за назвами controllers.

> Це продуктова карта, а не повний реєстр технічних routes. Після Symfony-only cutover джерелом істини для HTTP-маршрутів є `symfony/config/routes.yaml`, а navigation contributors проходять architecture-gate: кожен їхній page URL повинен мати Symfony `GET`/`HEAD` route.

## 1. Канонічна карта

```text
/
├── PUBLIC
│   ├── Нерухомість
│   │   ├── /property/catalog
│   │   ├── /property/show/{slug}
│   │   ├── /property/type/{type}
│   │   ├── /property/city/{location}
│   │   ├── /nerukhomist/{location}/{type}
│   │   ├── /property/favour
│   │   └── /property/submit
│   │       ├── /submit-property
│   │       └── /property/create
│   ├── Terra Nova
│   │   ├── /terra-nova
│   │   ├── /agency
│   │   ├── /services
│   │   ├── /partners
│   │   ├── /team
│   │   ├── /cases
│   │   ├── /vacancies
│   │   ├── /contacts
│   │   ├── /it
│   │   └── /art
│   ├── Контент
│   │   ├── /blog
│   │   ├── /blog/{slug}
│   │   └── /guide/{slug}
│   └── COS
│       ├── /cos
│       ├── /cos/{lang}
│       └── /cos/{lang}/domains/{slug}
│
├── PORTAL
│   ├── /cabinet
│   │   ├── #properties
│   │   ├── #requests
│   │   └── #profile
│   ├── /cabinet/submission/{id}
│   ├── /property/catalog
│   ├── /property/favour
│   └── /property/submit      залежить від ролі
│
└── WORKSPACE
    ├── /admin
    ├── Growth
    │   ├── /growth
    │   ├── /growth/candidates
    │   ├── /growth/candidates/{id}
    │   ├── /growth/accounts
    │   ├── /growth/accounts/{id}
    │   ├── /growth/signals
    │   ├── /growth/collectors
    │   └── /growth/learning
    ├── Sales
    │   ├── /sales/dashboard
    │   ├── /sales/today
    │   ├── /sales/pipeline
    │   ├── /sales/leads
    │   ├── /sales/deals
    │   ├── /sales/deals/{id}
    │   ├── /sales/director
    │   └── /sales/admin      лише admin
    ├── Клієнти
    │   ├── /client-case/inbox
    │   └── /client-case
    ├── Property
    │   ├── /property/manage
    │   ├── /property/listing
    │   ├── /property/map
    │   ├── /property/submissions
    │   ├── /spatial/manage
    │   └── /property/catalog
    ├── COS
    │   ├── /cos/control-center
    │   ├── /cos/architecture
    │   ├── #actions
    │   ├── #approvals
    │   ├── #agents
    │   ├── #rules
    │   ├── #events
    │   ├── #audit
    │   └── /admin/diagnostics/methodology-studio
    ├── /admin/analytics
    ├── /admin/content
    └── /admin/users          лише admin
```

## 2. Public

Public є єдиною поверхнею, призначеною для анонімного відкриття та пошукової індексації.

### Основна навігація

Public navigation визначає:

1. Нерухомість → `/property/catalog`;
2. Послуги → `/services`;
3. Партнерам → `/partners`;
4. Terra Nova → `/terra-nova`;
5. COS → `/cos/en`.

### Terra Nova

`PublicPageCatalog` визначає десять статичних сторінок:

```text
/terra-nova  /agency     /it       /art
/services    /team       /partners /cases
/vacancies   /contacts
```

### Публічний COS

Публічна COS surface підтримує п’ять мов:

```text
en  de  fr  pl  uk
```

Поточний каталог містить **21** сторінку напрямів COS для кожної мови.

```text
/cos
/cos/{lang}
/cos/{lang}/domains/{slug}
```

Публічний `/cos/{lang}` і операційний `/cos/control-center` є різними поверхнями.

### Нерухомість

Property залишається джерелом правди про активи. Public pages є проєкцією канонічних Property та комерційних даних, а не другою моделлю нерухомості.

`/property/favour` зараз використовує server-session state. `/property/submit` є Public capability, хоча Portal показує посилання на неї лише дозволеним ролям.

## 3. Portal

Portal є authenticated user context.

```text
Огляд         → /cabinet
Нерухомість   → /property/catalog
Вибрані       → /property/favour
Мої об’єкти   → /cabinet#properties
Подати об’єкт → /property/submit, якщо роль дозволяє
Звернення     → /cabinet#requests
Профіль       → /cabinet#profile
```

Детальна сторінка поданого об’єкта:

```text
/cabinet/submission/{id}
```

`Мої об’єкти` не є `/property/listing`: останній маршрут належить Workspace.

## 4. Workspace

Workspace є операційним контекстом компанії. Навігація складається з core та внесків активних modules.

```text
Core       → Огляд, COS, Аналітика, Адміністрування
Growth     → Growth Opportunity Intelligence
Sales      → Sales, Клієнти
Property   → Нерухомість
Diagnostic → COS / Diagnostics
```

### Growth

`GrowthWebProvider` визначає provider-backed Workspace surface:

```text
/growth
/growth/candidates
/growth/candidates/{id}
/growth/accounts
/growth/accounts/{id}
/growth/signals
/growth/collectors
/growth/learning
```

Overview і list pages використовують read-only Growth Workspace projection. Candidate/Account detail pages складають existing application briefs; mutation actions проходять через `/api/v1/growth/*`, а не дублюють Domain lifecycle у Web layer. `/growth/learning` також показує governed Optimization evidence/recommendation і виконує Generate / Accept / Dismiss / Materialize через canonical API; policy activation у Workspace навмисно відсутня.

### Sales

`SalesNavigationContributor` визначає:

```text
/sales/dashboard
/sales/today
/sales/pipeline
/sales/leads
/sales/deals
/sales/director
/sales/admin       лише admin
```

`/sales/deals/{id}` є окремим робочим простором угоди.

### Клієнти

```text
/client-case/inbox
/client-case
```

Ця секція зараз надходить із Sales navigation contribution.

### Property

`PropertyNavigationContributor` визначає:

```text
/property/manage
/property/listing
/property/map
/property/submissions
/spatial/manage
/property/catalog
```

`/property/catalog` тут є переходом із Workspace у Public projection, а не окремою Workspace-копією каталогу.

### COS і Diagnostic

```text
/cos/control-center
/cos/architecture
/cos/control-center#actions
/cos/control-center#approvals
/cos/control-center#agents
/cos/control-center#rules
/cos/control-center#events
/cos/control-center#audit
```

`DiagnosticNavigationContributor` не створює top-level section, а додає:

```text
/admin/diagnostics/methodology-studio
```

до секції COS.

### Базове адміністрування

Symfony routing явно реєструє:

```text
/admin
/admin/users
/admin/analytics
/admin/content
```

Після routing recovery ці URL належать лише Symfony runtime; Phalcon `CoreWebRoutes` більше не існує.

## 5. Автентифікація

Authentication є межею доступу, а не продуктовою поверхнею:

```text
/auth/login
/auth/register
/auth/logout
```

Ці routes явно зареєстровані в `CoreWebRoutes`.

## 6. Технічні routes поза продуктовою картою

Не повинні з’являтися як продуктова навігація:

```text
/api/**
/webhooks/**
/analytics/track
health endpoints
integration callbacks
POST routes approve/reject/execute
internal AJAX/data endpoints
```

Вони належать до API та integration reference.

## 7. XML-карта сайту

`/sitemap.xml` не є цією продуктовою картою.

`App\Web\Seo\SitemapController` у Symfony зараз включає:

- `/`;
- `/property/catalog`;
- `/property/submit`;
- `/blog`;
- усі сторінки `PublicPageCatalog`;
- Property type та location pages;
- location + type SEO landing pairs;
- опубліковані Property details;
- blog та guide items.

### Відома прогалина

Публічні COS pages:

```text
/cos/{lang}
/cos/{lang}/domains/{slug}
```

ще не генеруються Symfony `SitemapController`. Це SEO-прогалина, а не проблема Workspace routing.

## 8. Індексація

Канонічне правило:

```text
Public    → index,follow, якщо сторінка призначена для discovery
Portal    → noindex,nofollow
Workspace → noindex,nofollow
API       → не є HTML-ціллю індексації
```

Базовий Web layout класифікує `/admin`, `/auth`, `/cabinet`, `/client-case`, `/sales`, `/growth`, `/cos/control-center` та операційні Property paths як private для meta robots.

`robots.txt` додатково закриває `/admin`, `/auth`, `/cabinet`, `/client-case` та основні операційні Property paths. Це лише crawler hint, не authorization mechanism.

## 9. Правило власності

Route належить поверхні за призначенням, а не лише за URL prefix:

```text
/cos/en             → Public
/cos/control-center → Workspace
/property/catalog   → Public
/property/submit    → Public capability, доступна з Portal
/property/listing   → Workspace
/cabinet            → Portal для будь-якої ролі
```

## 10. Джерела перевірки

Стан на `2026-09-23` звірено з:

```text
symfony/config/routes.yaml
symfony/src/Web/Navigation/NavigationBuilder.php
symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php
symfony/src/Web/Experience/Extension/Provider/*WebProvider.php
app/Domains/Content/Application/Service/PublicPageCatalog.php
symfony/src/Web/Operations/ControlCenterPageController.php
symfony/src/Web/Seo/RobotsController.php
symfony/src/Web/Seo/SitemapController.php
docs/12-reference/module-routes.md
```

## 11. Правило розвитку

Нові modules мають розширювати карту через власні navigation contributors і routes застосунку, а не шляхом складання всього в `FrontendNavigation`, CRM, Property або `/admin`.

```text
Surface
  → Module
      → Capability
          → Page / Workflow
              → Application Use Case
                  → Domain
```

Це зберігає вертикаль COS:

```text
Business → Workflow → Domain → Capability → Runtime → Service → Code
```
> Routing recovery rule: retained server-rendered surfaces must not expose links or forms to undeclared Symfony routes.
> Route recovery is rebased on the current Experience Platform interaction layer; recovered PHTML surfaces remain server-driven and Symfony-owned.
