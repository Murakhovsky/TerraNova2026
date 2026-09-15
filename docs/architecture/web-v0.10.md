# WEB V0.10 — Public Surface Refinement

## Goal

WEB V0.10 makes **Public** a first-class frontend surface instead of a collection of public-looking pages with mixed ownership.

The frontend contract remains:

- **Public** = external world;
- **Portal** = authenticated user + interaction with the company;
- **Workspace** = company + operational work.

This release changes presentation/delivery boundaries only. It does not create `Public` or `Frontend` DDD domains and does not duplicate Property or Sales business rules in browser code.

## Canonical Public journey

```text
Home
  → Catalog / Map
  → Property
  → Presentation
  → CTA / Request
  → Sales / Client Case runtime
```

The public side owns discovery and conversion. CRM case operations remain in Company Workspace.

## Public navigation

The canonical main navigation is:

1. Нерухомість
2. Послуги
3. Партнерам
4. Terra Nova
5. COS

Home remains available through the Terra Nova logo. Internal `Listing / MLS` is never exposed in Public navigation.

## Public Property delivery

`PublicPropertyController` becomes the delivery boundary for canonical public Property routes:

- `/property`;
- `/property/catalog`;
- `/property/map`;
- `/property/show/{slug}`;
- `/property/presentation/{slug}`;
- `/property/submit`;
- `/property/create`;
- `/submit-property`.

It composes existing Property application/read contracts and never resolves manager client cases, Workspace roles or operational Sales actions.

The legacy mixed `PropertyController` remains available for Workspace/legacy actions until the extraction phase in WEB V0.11.

## Homepage

The homepage now renders `index/public.phtml` through the shared Public header/footer.

It uses only current backend projections:

- featured properties;
- property types;
- locations;
- inbound request flow.

Static project inventories, fabricated location counts and the public link to internal Listing/MLS are removed from the canonical homepage.

## Catalog and property page

Existing catalog, property and presentation views remain reusable because they already expose the mature public representation:

- filters and pagination;
- public property cards;
- gallery and characteristics;
- location and spatial presentation;
- request CTA;
- related/grouped objects;
- schema.org metadata.

Canonical public routes now render them through `PublicPropertyController`, so manager-only CRM projections are not supplied to the Public surface.

## Map

The old map placed markers from array index arithmetic. WEB V0.10 replaces that with a projection based on the actual `latitude` and `longitude` fields in the Property catalog read model.

Objects without coordinates remain available in Catalog and are explicitly reported as not plotted.

This is a lightweight geographic projection, not a replacement for a future dedicated mapping provider.

## Public browser bundle

`frontend/entrypoints/public-surface.js` owns presentation-only behaviour:

- closing mobile Public navigation after selection;
- progressive form submit state;
- lazy/async image hints for property cards.

It does not own permissions, roles, property lifecycle, Sales state or business validation.

## Responsive and accessibility baseline

Public surface refinement adds/keeps:

- keyboard-visible focus;
- minimum 44px interactive controls where the Public bundle owns sizing;
- responsive location/search layouts;
- one-column mobile behaviour;
- image aspect-ratio/object-fit;
- reduced-motion support;
- semantic navigation and status regions already present in server views.

## SEO contract

SEO remains a presentation contract:

- canonical URLs and metadata are set by Web controllers;
- property/catalog views expose schema.org JSON-LD where already supported;
- no SEO Domain is introduced.

## Regression gate

`tests/architecture/web_v010_public_surface.php` protects against:

- creating `Domains/Public` or `Domains/Frontend`;
- restoring internal Listing/MLS to the canonical homepage;
- Public Property routes returning to the mixed controller;
- manager/CRM dependencies entering `PublicPropertyController`;
- fake map coordinates;
- unmanaged Public browser assets;
- business logic entering Public JavaScript.

## Definition of Done

WEB V0.10 is closed when:

1. homepage, content and canonical Property routes explicitly own the Public surface;
2. public navigation is compact and does not expose Workspace capabilities;
3. canonical Property public routes use `PublicPropertyController`;
4. catalog/show/presentation receive only Public delivery data;
5. homepage uses real backend projections instead of static inventory claims;
6. map positions derive from real coordinates;
7. Public browser code remains presentation-only;
8. Vite owns the Public feature bundle;
9. dedicated architecture regression gate passes;
10. full COS runtime CI and dev deployment remain green.
