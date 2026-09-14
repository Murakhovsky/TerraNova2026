# Property Domain

## Property V0.2.0 — Canonical Domain Definition

Property is the COS canonical registry of **physical real-estate assets**.

Its authority is the identity and intrinsic state of real property: what physically exists, where it is, what it is, how it is structured, its lifecycle state, media that documents the asset, and explicit relations to the asset.

Property is not the Sales domain, not CRM, and not the public advertising catalog.

## Ubiquitous language

The canonical Property vocabulary is:

- `PropertyAsset` — a physical real-estate asset with stable identity.
- `PropertyType` — the physical/category classification of an asset.
- `PropertyLocation` — address, spatial identity and geospatial facts of an asset.
- `PropertyLifecycle` — the asset lifecycle independent of a sales pipeline.
- `PropertyRelation` — explicit relationships between an asset and other domain identities.
- `PropertyMedia` — media attached to or documenting the physical asset.
- `PropertySubmission` — intake material from which a canonical asset may be created or updated.

These names are domain language. New code should prefer them over vague terms such as `object`, `realty`, arbitrary status strings, or untyped property arrays when crossing domain boundaries.

## The fundamental boundary

### PROPERTY

**What physically exists.**

Property owns facts that remain meaningful even if nobody is currently selling, renting, advertising or discussing the asset.

Examples: asset identity, type, address, coordinates, areas, rooms, construction facts, physical state, lifecycle, parent/child structure and asset media.

### INVENTORY

**What an organization can currently sell, rent or otherwise commercialize.**

Inventory is a commercial projection over Property. It may reference a `PropertyAsset`, but availability, commercial terms, mandate, responsible team, asking price, rent terms and sale/rent readiness are not intrinsic Property facts.

Inventory does not redefine the physical asset.

### LISTING

**How an inventory item or asset is represented to a market/channel.**

Listing owns publication-specific representation: headline, marketing description, publication media ordering, channel state, SEO/public slug, publication schedule and channel-specific presentation.

Listing does not own the physical asset and must not become a second property database.

## Ownership map

Property owns:

- canonical asset identity;
- physical characteristics and structure;
- canonical location and geospatial facts;
- asset lifecycle;
- asset-to-asset and explicit domain relations;
- asset media/documentation relations;
- intake/submission lifecycle before canonicalization.

Property explicitly does **not** own:

- leads, persons, clients, conversations or relationship history — CRM;
- opportunities, deals, pipeline stages, follow-ups or sales performance — Sales;
- commercial availability, mandate and sale/rent terms — Inventory;
- public/channel copy, publication status, SEO or advertising representation — Listing;
- campaign performance or marketing attribution — Marketing/Analytics projections.

## Compatibility rule for legacy code

The current codebase still contains catalog, presentation, moderation and legacy `Realty/Object` structures under or around Property. They are compatibility surfaces, not permission to expand Property into a catch-all real-estate module.

While V0.2–V0.4 migrates the implementation:

1. no new business rule may infer that `PROPERTY == INVENTORY == LISTING`;
2. new Property contracts must describe canonical asset behavior rather than page/UI behavior;
3. legacy catalog/public presentation code may read Property, but it must not define Property's canonical model;
4. cross-domain commercial data should move toward explicit projections/contracts instead of additional columns on the canonical asset.

## Property V0.2.1 — Module runtime

Property participates in the Kernel module runtime through `propertyDomainModule`.

Canonical runtime capabilities are:

- `property.registry`
- `property.read`
- `property.write`
- `property.intake`
- `property.media`
- `property.catalog`

The module contributes its own canonical runtime API surface under `/api/v1/property-registry`. This is intentionally separate from the legacy `/api/v1/properties` public catalog API: one describes the COS asset registry runtime, the other remains a compatibility/presentation surface until later migration.

Property also owns a tenant-scoped configuration namespace. V0.2.1 provisions it with no default runtime rules; rules are introduced only after Property commands and events have canonical contracts.

Runtime health is reported through the Kernel `ModuleReadinessDiagnostic`, so Property installation state, deployed/schema versions, migrations, dependencies and enabled state are evaluated by the same control-plane mechanism as other COS modules.

## Property V0.2.2 — Tenant boundary

`organization_id` is now a Property persistence invariant rather than an optional filter convention.

Tenant-owned mutable Property data includes:

- `tn_properties`;
- `tn_property_groups`;
- `tn_property_submissions`;
- `tn_property_images`;
- `tn_property_features`;
- `tn_property_activities`.

`tn_property_types` and `tn_locations` remain shared reference data. They describe real-world taxonomy and geography rather than organization ownership.

The tenant boundary is enforced at three levels:

1. persistence adapters receive the active `organizationContext` and scope reads/writes by it;
2. mutable child rows carry `organization_id` explicitly;
3. composite foreign keys such as `(organization_id, property_id)` prevent media, features, activities or submissions from being related to an asset owned by another organization.

The management, submission and moderation persistence paths all require a non-empty organization scope. Cross-tenant IDs are treated as absent rather than as accessible records.

## Direction after V0.2.2

V0.2.3 introduces the canonical core domain model: `PropertyAsset`, `PropertyType`, `PropertyLocation` and `PropertyLifecycle`. Later V0.2 slices add commands, events and relationships on top of those types instead of continuing to pass anonymous arrays through every boundary.
