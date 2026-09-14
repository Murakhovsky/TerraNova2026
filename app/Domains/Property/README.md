# Property Domain

Property is the COS canonical registry of **physical real-estate assets**.

Its authority is the identity and intrinsic state of real property: what physically exists, where it is, what it is, how it is structured, its lifecycle state, where facts came from, and explicit relations to the asset.

Property is not Sales, CRM, Inventory or Listing.

## Ubiquitous language

- `PropertyAsset` — a physical real-estate asset or structural node with stable canonical identity.
- `PropertyIdentity` — tenant-local canonical identity `(organization_id, asset_id)`.
- `PropertyAssetKind` — structural role such as development, building, floor or unit.
- `PropertyType` — concrete physical/category specialization such as apartment or commercial unit.
- `PropertyLocation` / `LocationNode` — canonical spatial identity and normalized geography.
- `PropertyLifecycle` — physical lifecycle independent of sales or publication state.
- `PropertyRelation` — umbrella domain language for explicit relations owned by Property.
- `PropertyAssetRelation` — explicit asset-to-asset structural or spatial relation.
- `PropertyPartyRelation` — Property-owned fact that an opaque Party identity has a role toward an asset.
- `PropertyMedia` — media attached to or documenting the physical asset.
- `PropertySource` — an identified origin of Property data.
- `ExternalReference` — a source-system identity mapped to one canonical asset.
- `DataProvenance` — source, observed value, confidence and verification state for a concrete canonical field.
- `PropertySubmission` — intake material from which a canonical asset may be created or updated.

New code should prefer this language over vague `object`, `realty`, anonymous arrays or untyped status strings.

## Fundamental boundary

### PROPERTY — what physically exists

Property owns facts that remain meaningful even if nobody is currently selling, renting, advertising or discussing the asset: identity, type, structure, location, intrinsic characteristics, physical lifecycle, source/provenance and relations.

### INVENTORY — what can currently be commercialized

Inventory is a commercial projection over Property. Availability, asking price, mandate, rent/sale terms, responsible commercial team and reservation state are not intrinsic Property facts.

### LISTING — how it is represented to a market/channel

Listing owns headline, marketing copy, publication media ordering, channel state, SEO/public slug and publication schedule. It references Property/Inventory; it does not redefine the physical asset.

## Ownership map

Property owns:

- canonical asset identity;
- physical characteristics and structure;
- canonical location and geospatial facts;
- asset lifecycle;
- asset-to-asset relations;
- Party-to-asset relation facts without copying Party data;
- asset media/documentation relations;
- source identities and field-level provenance;
- submission/intake lifecycle before canonicalization.

Property explicitly does **not** own:

- persons, organizations, phones, emails, conversations or relationship history — CRM;
- opportunities, deals, pipeline stages or sales performance — Sales;
- commercial availability, mandate, price and sale/rent terms — Inventory;
- public/channel copy, publication status, SEO or advertising representation — Listing;
- campaign performance or marketing attribution — Marketing/Analytics projections.

## Compatibility rule

Legacy catalog, presentation, moderation and `Realty/Object` structures remain compatibility surfaces while canonical Property evolves.

1. `PROPERTY != INVENTORY != LISTING`.
2. New Property contracts describe canonical asset behavior rather than page/UI behavior.
3. Legacy catalog/public presentation code may read Property but must not define its canonical model.
4. Commercial data must move toward explicit domain projections/contracts rather than additional columns on the canonical asset.
5. Legacy `tn_properties` remains compatible while `tn_property_assets` becomes the canonical registry.

## Property V0.2 — Domain Foundation

V0.2 establishes Property as an independent COS bounded context and fixes the rules that all later Property versions build on.

Implemented:

- canonical definition of Property as the COS source of truth for physical real-estate assets;
- explicit `PROPERTY / INVENTORY / LISTING` boundary;
- canonical domain language and ownership rules;
- compatibility rule for legacy `Realty/Object`, catalog and presentation code;
- Kernel runtime module through `propertyDomainModule`;
- Property capabilities:
  - `property.registry`;
  - `property.read`;
  - `property.write`;
  - `property.intake`;
  - `property.media`;
  - `property.catalog`;
- canonical runtime API under `/api/v1/property-registry`, separate from legacy `/api/v1/properties`;
- module configuration provisioning and readiness diagnostics;
- dedicated Property CI workflow;
- tenant boundary with `organization_id` as a persistence invariant;
- tenant-scoped repositories, mutable rows and composite foreign keys;
- cross-tenant IDs treated as inaccessible/absent;
- shared taxonomy and geography reference data kept global;
- immutable core domain model:
  - `PropertyAsset`;
  - `PropertyType`;
  - `PropertyLocation`;
  - `PropertyLifecycle`;
- physical lifecycle separated from Sales, Inventory and publication statuses;
- typed commands for:
  - asset registration;
  - reclassification;
  - relocation;
  - lifecycle change;
- Property-owned domain events integrated with Kernel event ownership;
- Property rule context provider;
- tenant-safe command context.

**Result:** Property becomes a real bounded context with its own runtime, tenant isolation, canonical model, command contracts and events instead of remaining a collection of real-estate screens and tables.

## Property V0.3 — Asset Registry & Structure

V0.3 makes Property describe the physical structure of the real world without forcing every asset into one universal hierarchy or one giant nullable table.

Implemented:

- canonical asset registry in `tn_property_assets`;
- `PropertyAssetKind` for structural roles such as:
  - development;
  - building;
  - section / entrance;
  - floor;
  - unit;
  - land plot;
  - house;
- `PropertyType` retained as the concrete specialization of an asset;
- explicit separation `kind != type`, for example `kind=unit`, `type=apartment`;
- graph-based `PropertyAssetRelation` model instead of a mandatory parent chain;
- asset relation types:
  - `contains`;
  - `part_of`;
  - `located_in`;
  - `built_on`;
  - `serves`;
  - `adjacent_to`;
- support for structures such as:
  - `Development -> Building -> Entrance -> Floor -> Unit`;
  - `LandPlot -> House`;
  - other valid graph combinations without fake hierarchy levels;
- typed physical specifications:
  - `ResidentialSpec`;
  - `LandSpec`;
  - `CommercialSpec`;
  - `BuildingSpec`;
- normalized location registry through:
  - `LocationNode`;
  - `PropertyAddress`;
  - `GeoPoint`;
  - `GeoBoundary`;
- normalized geography hierarchy for country, region, district, city, settlement, city district and street;
- global normalized geography with tenant-owned Property assets;
- canonical persistence tables:
  - `tn_property_assets`;
  - `tn_property_asset_relations`;
  - `tn_property_residential_specs`;
  - `tn_property_land_specs`;
  - `tn_property_commercial_specs`;
  - `tn_property_building_specs`;
  - `tn_location_nodes`;
  - `tn_addresses`;
  - `tn_geo_points`;
  - `tn_geo_boundaries`.

**Result:** Property becomes a graph-based registry capable of naturally describing an apartment, cottage, land plot, residential development, building, parking unit or commercial unit with the same domain model.

## Property V0.4 — Identity, Relations & Provenance

V0.4 turns the asset registry into a system that knows not only what the canonical asset is, but also where facts came from, how trustworthy they are and whether incoming data describes an existing asset.

Implemented:

- canonical `PropertyIdentity` based on tenant-local `(organization_id, asset_id)`;
- no second competing canonical Property ID;
- `PropertySource` as a first-class description of data origin;
- `ExternalReference` mapping external source identities to one canonical asset;
- supported source scenarios including:
  - developer API;
  - manual input;
  - owner submission;
  - partner;
  - CRM import;
  - MLS;
  - parser;
  - external API;
- tenant-level uniqueness of `(source_system, external_id)` so one external identity maps to one canonical asset;
- field-level `DataProvenance` containing:
  - `field_path`;
  - observed value;
  - source;
  - observed/imported timestamps;
  - confidence;
  - verification status;
- preservation of conflicting observations instead of silent overwrite;
- `PropertyPartyRelation` for Property-owned facts between a Party and an asset;
- supported Party relation roles:
  - `OWNER`;
  - `CO_OWNER`;
  - `DEVELOPER`;
  - `MANAGER`;
  - `REPRESENTATIVE`;
  - `TENANT`;
  - `OPERATOR`;
  - `CONTRACTOR`;
- opaque CRM Party references instead of copying Person/Organization/contact data into Property;
- temporal Party relations through `valid_from` / `valid_to`;
- relation source and confidence;
- identity signals for duplicate detection;
- deterministic `PropertyIdentityResolver`;
- canonical intake pipeline:
  `Submission -> validation -> identity signals -> duplicate scoring -> moderation -> CREATE / MERGE / REVIEW / REJECT -> canonical PropertyAsset`;
- matching signals including:
  - external ID;
  - cadastral number;
  - normalized address;
  - development/building/unit position;
  - area;
  - structural identity;
- exact external/cadastral identity may resolve to `MERGE`;
- strong but non-authoritative matches may resolve to `REVIEW`;
- fuzzy matching does not automatically `REJECT` a submission;
- canonical persistence tables:
  - `tn_property_sources`;
  - `tn_property_external_references`;
  - `tn_property_provenance`;
  - `tn_property_party_relations`;
  - `tn_property_identity_resolutions`.

**Result:** Property becomes a canonical registry that understands asset identity, relationships, duplicate resolution and the provenance and confidence of individual facts rather than merely storing the latest value it received.
