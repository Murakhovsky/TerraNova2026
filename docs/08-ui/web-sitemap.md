---
title: Web Sitemap
description: Canonical product map for Public, Portal and Workspace surfaces, route ownership and SEO visibility.
status: active
updated: 2026-09-15
kind: ui
---

# Web Sitemap

COS Web is not one flat website. It has three canonical human-facing surfaces:

```text
Public    = external world
Portal    = user + interaction with the company
Workspace = company + operational work
```

The sitemap therefore follows surface ownership first, not controller names.

## 1. Canonical product map

```text
/
├── PUBLIC
│   ├── Real Estate
│   │   ├── /property/catalog
│   │   ├── /property/show/{slug}
│   │   ├── /property/type/{type}
│   │   ├── /property/city/{location}
│   │   ├── /nerukhomist/{location}/{type}
│   │   ├── /property/favour
│   │   └── /property/submit
│   │       ├── alias: /submit-property
│   │       └── alias: /property/create
│   │
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
│   │
│   ├── Content
│   │   ├── /blog
│   │   ├── /blog/{slug}
│   │   └── /guide/{slug}
│   │
│   └── COS public site
│       ├── /cos
│       ├── /cos/{lang}
│       └── /cos/{lang}/domains/{slug}
│
├── PORTAL
│   ├── /cabinet
│   │   ├── Overview
│   │   ├── #properties       My Properties
│   │   ├── #submissions      Submitted properties
│   │   ├── #requests         Requests
│   │   └── #profile          Account profile
│   │
│   ├── /cabinet/submission/{id}
│   ├── /property/catalog     shared Public capability
│   ├── /property/favour      shared Public capability
│   └── /property/submit      shared Public capability, role-aware link
│
└── WORKSPACE
    ├── /admin                         Overview
    │
    ├── Sales
    │   ├── /sales/dashboard           Overview
    │   ├── /sales/today               Today
    │   ├── /sales/pipeline            Pipeline
    │   ├── /sales/leads               Leads
    │   ├── /sales/deals               Deals
    │   ├── /sales/deals/{id}          Deal workspace
    │   ├── /sales/director            Director view
    │   └── /sales/admin               Sales Admin, admin only
    │
    ├── Clients
    │   ├── /client-case/inbox         Inbox
    │   └── /client-case               Cases
    │
    ├── Property
    │   ├── /property/manage           Inventory
    │   ├── /property/listing          Commercial listing
    │   ├── /property/map              Locations
    │   ├── /property/submissions      Moderation
    │   ├── /spatial/manage            3D / Spatial
    │   └── /property/catalog          Public catalog projection
    │
    ├── COS Control Center
    │   ├── /cos/control-center        Overview
    │   ├── /cos/architecture          Architecture
    │   ├── /cos/control-center#actions
    │   ├── /cos/control-center#approvals
    │   ├── /cos/control-center#agents
    │   ├── /cos/control-center#rules
    │   ├── /cos/control-center#events
    │   ├── /cos/control-center#audit
    │   └── /admin/diagnostics/methodology-studio
    │
    ├── Analytics
    │   └── /admin/analytics
    │
    └── Administration
        ├── /admin/users               admin only
        └── /admin/content
```

## 2. Public surface

Public is the only surface intended for search indexing and anonymous discovery.

### Primary public navigation

The canonical top-level navigation is:

1. Real Estate — `/property/catalog`;
2. Services — `/services`;
3. Partners — `/partners`;
4. Terra Nova — `/terra-nova`;
5. COS — `/cos/en`.

Additional public pages are discoverable through content links, footer navigation, search, campaigns and SEO landings rather than all being forced into the primary header.

### Real Estate subtree

`Property` owns the real-estate public projection.

```text
/property/catalog
├── filters/search
├── /property/show/{slug}
├── /property/type/{type}
├── /property/city/{location}
└── /nerukhomist/{location}/{type}
```

`Property` remains the asset source of truth. Public pages are projections of Property and commercial availability, not a second property model.

`/property/favour` is currently a Public capability backed by server session state. It is not a Portal-owned page merely because logged-in users can use it.

`/property/submit` is also currently a Public capability. Portal may link to it conditionally, but Portal does not own the route.

### Terra Nova public pages

Static public pages currently supplied by `PublicPageService`:

- `/terra-nova`;
- `/agency`;
- `/it`;
- `/art`;
- `/services`;
- `/team`;
- `/partners`;
- `/cases`;
- `/vacancies`;
- `/contacts`.

### Content subtree

```text
/blog
├── /blog/{slug}
└── /guide/{slug}
```

Blog posts are editorial content. Guide pages are SEO/content landing pages and must not own business state.

### COS public site

COS marketing/product presentation is a Public surface even though COS Control Center is Workspace.

```text
/cos
└── /cos/{lang}
    └── /cos/{lang}/domains/{slug}
```

Supported languages currently are:

- `en`;
- `de`;
- `fr`;
- `pl`;
- `uk`.

The public COS domain catalog currently exposes 21 domain pages per language.

## 3. Portal surface

Portal is authenticated user context. It must answer:

> What belongs to me, what have I sent to the company, and what is happening with my interaction?

Canonical root:

```text
/cabinet
```

Portal information architecture:

```text
Overview
Real Estate
├── Catalog              -> /property/catalog
├── Favourites           -> /property/favour
├── My Properties        -> /cabinet#properties
└── Submit Property      -> /property/submit when capability allows
Requests                 -> /cabinet#requests
Profile                  -> /cabinet#profile
```

Submitted property detail/edit route:

```text
/cabinet/submission/{id}
```

Important boundary:

- `My Properties` is not `/property/listing`;
- `/property/listing` is Workspace commercial inventory/listing;
- manager/admin opening `/cabinet` still receives Portal, not Workspace.

## 4. Workspace surface

Workspace is company operational context. It is module-composed and role-aware.

Top-level canonical modules:

```text
Overview
Sales
Clients
Property
COS
Analytics
Administration
```

### Sales

```text
/sales/dashboard
├── /sales/today
├── /sales/pipeline
├── /sales/leads
├── /sales/deals
│   └── /sales/deals/{id}
├── /sales/director
└── /sales/admin          admin only
```

Sales owns demand, opportunity and deal execution. It does not own Property assets or CRM identity.

### Clients

```text
/client-case/inbox
└── /client-case
```

This is the current Web projection for client cases/inbox. CRM remains the relationship/identity concern rather than a dumping ground for every operational screen.

### Property Workspace

```text
/property/manage
├── Inventory
├── /property/listing
├── /property/map
├── /property/submissions
├── /spatial/manage
└── /property/catalog
```

Interpretation:

- `manage` = operational asset/inventory work;
- `listing` = commercial listing workflow;
- `map` = location/spatial projection;
- `submissions` = moderation queue;
- `spatial/manage` = 3D/spatial operations;
- `catalog` = transition into the Public projection.

### COS Workspace

```text
/cos/control-center
├── Overview
├── /cos/architecture
├── #actions
├── #approvals
├── #agents
├── #rules
├── #events
├── #audit
└── /admin/diagnostics/methodology-studio
```

The public `/cos/{lang}` tree and Workspace `/cos/control-center` are different surfaces and must not share authorization semantics just because both start with `/cos`.

### Administration

```text
/admin
├── /admin/analytics
├── /admin/content
└── /admin/users          admin only
```

Administration contains platform/company administration, not Domain business workflows.

## 5. Authentication routes

Authentication is an access boundary, not a product surface:

```text
/auth/login
/auth/register
/auth/logout
```

Authenticated success redirects to `/cabinet`.

## 6. Routes intentionally excluded from the product sitemap

The following are runtime/delivery infrastructure and must not appear as product navigation:

- `/api/**`;
- `/webhooks/**`;
- `/analytics/track`;
- action endpoints such as approve/reject/execute POST routes;
- health endpoints;
- integration callbacks;
- internal AJAX/data endpoints.

They belong in API/integration reference, not in the human-facing sitemap.

## 7. SEO sitemap policy

`/sitemap.xml` is not the same artifact as this product sitemap.

The XML sitemap must contain only indexable Public pages. Portal and Workspace pages are never sitemap entries.

Current XML sitemap already includes:

- `/`;
- `/property/catalog`;
- `/property/submit`;
- `/blog`;
- all `PublicPageService` pages;
- Property type pages;
- Property location pages;
- location + type SEO landing pairs;
- published property details;
- blog and guide content items.

Current gap: COS Public pages (`/cos/{lang}` and `/cos/{lang}/domains/{slug}`) are valid Public content but are not yet generated by `SeoController::sitemapAction()`. This should be closed as a separate SEO/public-discovery change instead of coupling it to Workspace routing.

## 8. Indexing boundary

Canonical rule:

```text
Public    -> index,follow when page is intended for discovery
Portal    -> noindex,nofollow
Workspace -> noindex,nofollow
API       -> not an HTML indexing target
```

The base Web layout already classifies `/admin`, `/auth`, `/cabinet`, `/client-case`, `/sales`, `/cos/control-center` and operational Property paths as private for meta robots purposes.

`robots.txt` is a crawler hint, not an authorization mechanism. Authentication and capability checks remain mandatory regardless of indexing rules.

## 9. Ownership rules

A route belongs to the surface determined by user context and business purpose, not by its URL prefix alone.

Examples:

- `/cos/en` -> Public;
- `/cos/control-center` -> Workspace;
- `/property/catalog` -> Public, even when opened from Portal or Workspace;
- `/property/submit` -> Public capability linked from Portal;
- `/property/listing` -> Workspace;
- `/cabinet` -> Portal for every role.

## 10. Evolution rule

New modules should extend this map through their own navigation contributors and application routes.

Do not solve growth by putting everything into `FrontendNavigation`, CRM, Property or `/admin`.

The desired long-term shape is:

```text
Surface
  -> Module
      -> Capability
          -> Page / workflow
              -> application use case
                  -> Domain
```

This preserves the core COS vertical:

```text
Business -> Workflow -> Domain -> Capability -> Runtime -> Service -> Code
```
