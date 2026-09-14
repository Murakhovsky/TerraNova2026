# Property Domain

Property is the COS **Canonical registry** of physical real-estate assets and the owner of Property-specific commercial projections.

Its core rule is simple:

```text
PROPERTY != INVENTORY != LISTING != PUBLICATION
```

## Canonical boundaries

### PROPERTY

Answers **what physically exists**. `PropertyAsset` owns stable physical identity, `PropertyType`, `PropertyLocation`, `PropertyLifecycle`, structure, typed physical specs, `PropertyRelation` / `PropertyAssetRelation`, `PropertyMedia`, source/provenance and canonical history. It never owns a buyer, Sales pipeline stage or asking-price lifecycle.

### INVENTORY

Answers **what this organization can commercialize now**. `InventoryItem` owns transaction type, asking price, availability, reservation/commercial status and commercial terms for one PropertyAsset.

### LISTING

Answers **how one InventoryItem is presented to the market**. A Listing owns marketing title/description, presentation price, SEO/public features and media references. `Publication` then distributes a Listing to one concrete channel.

`PropertySubmission` remains an intake concept before canonicalization. `PropertySource`, `ExternalReference` and `DataProvenance` explain where observed facts came from. `PropertyNetworkRecord` is synchronization evidence, not canonical Property truth. `PropertyIntelligenceSnapshot` is derived inference linked to facts/evidence and is never allowed to rewrite them.

## Domain ownership

Property owns canonical asset identity, structure, location, physical specs, lifecycle, asset relations, source/provenance facts, intake, Inventory, Listing/Publication, Property history, Property analytics read models, derived Property intelligence and External Property Network synchronization state.

Property does **not** own people or conversations (CRM), deals/pipelines/demand lifecycle (Sales), payments (Finance), legal documents (Documents), or provider secrets. Cross-domain consumers use explicit ports/events rather than reading Property storage directly.

Legacy `tn_properties`, historical presentation code and Telegram `Realty/Object` models remain compatibility surfaces only. New canonical code must not use them as its source of truth.

## Version map

### Property V0.2 — Domain Foundation

Established Property as an independent tenant-scoped bounded context with `PropertyAsset`, `PropertyType`, `PropertyLocation`, `PropertyLifecycle`, commands/events, runtime capability registration and the PROPERTY / INVENTORY / LISTING boundary.

### Property V0.3 — Asset Registry & Structure

Introduced `tn_property_assets`, graph-based asset relations, structural kinds (`development`, `building`, `entrance`, `floor`, `unit`, `land_plot`, `house`), typed residential/commercial/land/building specifications and normalized location/address/geometry primitives.

### Property V0.4 — Identity, Relations & Provenance

Added `PropertySource`, `ExternalReference`, field-level `DataProvenance`, opaque Party relations and identity-resolution history. External source identity is `(organization_id, source_system, external_id)` and never becomes a second canonical Property ID.

### Property V0.5 — Inventory

Separated physical Property from organization-scoped commercial offers. Inventory owns transaction type, asking price, availability and commercial status (`AVAILABLE`, `RESERVED`, `ON_HOLD`, `UNDER_OFFER`, `SOLD`, `RENTED`, `OFF_MARKET`, `WITHDRAWN`) plus append-only price/status history and reservation facts.

A sold InventoryItem does not mean the physical Property became “sold”.

### Property V0.6 — Listing & Publication

Separated market content from commercial inventory:

```text
PropertyAsset -> InventoryItem -> Listing -> Publication -> Channel
```

Listings own title/description/presentation price/SEO/public features/media. Publications own channel-specific external ID/URL, sync state and publication lifecycle.

### Property V0.7 — History & Cross-Domain Contracts

Added append-only Property/Inventory/Publication history and the `PropertyReferencePort`. Sales consumes Property references/presentations through that port and must not query or modify Property tables directly.

Domain law:

> A Domain owns its state. Other Domains may request, reference and react, but never modify that state directly.

### Property V0.8 — Property Analytics

Added canonical supply analytics over Property-owned tables: asset totals, inventory states, price/m², inventory age/DOM, changes and stock segmentation.

Cross-domain supply/demand composition happens outside Property. Sales contributes explicit matched `ClientCase` demand through its own read-model port. The first demand signal is deliberately labelled `explicit_property_matches`; it is observed demand, not invented latent demand.

### Property V0.9 — Property Intelligence

Added evidence-linked derived intelligence: valuation range, deterministic comparables, liquidity/demand scores, market position, price anomaly, inventory risk, expected DOM and recommended asking price.

AI/heuristic output is stored in versioned `PropertyIntelligenceSnapshot` rows with facts, market signals, evidence hash, methodology version, provider/model, confidence and cost metadata. It never overwrites `PropertyAsset` or `InventoryItem` facts.

### Property V0.10 — External Property Network

V0.10 adds a transport-agnostic federation/synchronization boundary for MLS, developer APIs, partner feeds, portal feeds and file/custom integrations.

Architecture:

```text
External system
      |
PropertyNetworkConnector
      |
PropertyNetworkSyncService
      |
+-----+--------------------+
|                          |
IMPORT                     EXPORT
|                          |
durable network ledger     canonical export projection
|                          |
PropertyNetworkIntakePort  connector.push()
|
PropertySubmission
|
validation / identity resolution / moderation
|
canonical PropertyAsset
```

Rules:

1. Connectors are adapters. Property does not contain portal-specific SDK logic.
2. Credentials/tokens are not stored in Property. `configuration_reference` points to external configuration/secret ownership.
3. Every sync has a durable run and record ledger with payload hash and cursors.
4. Exact redelivery is idempotent. A changed payload becomes a new observation.
5. Incoming `UPSERT` goes through `PropertySubmission`; it never writes `tn_property_assets` directly.
6. Incoming `DELETE` becomes a `TOMBSTONE`. An external provider cannot delete canonical Property truth.
7. Network records reuse V0.4 `PropertySource` identity and retain source/external IDs for later canonical resolution/provenance.
8. Export reads canonical `Asset + Inventory + Listing` projections and never falls back to legacy `tn_properties`.
9. Publication and Network remain distinct: Publication is channel distribution of our Listing; Network is system-to-system record federation.

Canonical V0.10 tables:

- `tn_property_network_connectors`
- `tn_property_network_sync_runs`
- `tn_property_network_records`

Runtime services:

- `PropertyNetworkConnectorRegistry`
- `PropertyNetworkSyncService`
- `PropertyNetworkIntakePort`
- `PropertyNetworkExportPort`
- `PropertyNetworkSyncRepositoryInterface`

## Current architectural debt

The canonical direction is stable, but Property is not declared V1.0 yet.

- `MysqlPropertyManagementRepository` is still a large compatibility-heavy repository and should be decomposed later.
- Legacy `tn_properties`/Telegram models still exist for compatibility; new canonical layers are prohibited from depending on them.
- V0.4 identity/provenance has stronger schema/model maturity than operational automation around canonical merge/review workflows.
- V0.8 demand currently represents explicit Sales property matches, not full latent demand.
- V0.10 provides the federation boundary and durable synchronization lifecycle; concrete OLX/DIM.RIA/MLS/developer connectors remain integration adapters outside the domain core.

V1.0 should only be declared after canonical registry usage, Inventory/Listing separation, stable cross-domain contracts and External Network boundaries have survived real integration traffic without requiring core-model rewrites.
