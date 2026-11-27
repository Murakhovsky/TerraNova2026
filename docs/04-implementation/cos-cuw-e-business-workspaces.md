---
title: CUW-E Business Workspaces expansion
status: implementing
kind: implementation
---

# CUW-E / UX-03 expansion (#523)

Baseline pinned by parent #518: `10fc649df6aafdca432ec329ebd737f6a4abd1ec`.
This is source implementation, **not** D3/D4/D5 acceptance or production release.

## Reuse / adapt / new

- Reuse canonical Shell, ModuleExtensionRegistry and active module snapshot, WebExtensionCatalog, WorkspaceCompositionResolver, EntityRef, EntityLinkResolver, UIActionResolver, Gateway and existing native Domain forms.
- Adapt the Business Workspace feature controller: isolate record search to the owning active provider, preserve legacy native pages, independently re-read selected typed entities, show explicit error/empty/unknown, bounded pagination and tables.
- Add optional feature-local BusinessRecordProvider projection on existing registry providers; no new registry, Domain state, command bus, or authorization source. Finance provider is autodiscovered through existing WebExtensionProviderInterface tag. No shared services.yaml / routes.yaml / canonical Shell edit. Sales order POST uses a feature-owned route resource and existing Symfony import.
- Sales native confirmation is one narrowly typed Command via UIActionResolver + existing Gateway + Domain receipt/idempotency. No payment/provider write, stock allocation, shipment, worker activation, or automatic release.

## Scope and gaps

| Domain | Shared record/detail coverage | Existing native surfaces | Explicit limitations |
|---|---|---|---|
| Sales | Lead/deal existing query/summary; order exact native read, lines, payment evidence and controlled confirmation | Sales workspace, lead follow-up, deal and client-case pages | Order aggregate enumeration has no canonical list Query; no automatic external fulfillment; confirmation needs test witness |
| Product Management | Catalog/SKU search, native pagination, exact Product and variants | Product edit, pricing/attributes, warehouse, imports, analytics, Shopify proposal | Deep stock/import/Shopify functions remain native domain pages; no direct external Shopify mutation |
| Marketing | Campaign search and exact detail | Campaign draft, Meta accounts, performance/evidence, action queue | Search max 50, no offset in current contract; paid/Meta mutations remain governed |
| Finance | Exact budget envelope, reservations, order payment operation and documentary evidence | Existing approved Finance capability/actions | No envelope enumeration or unrestricted payment list; no payment/reconcile financial mutation without approval; missing evidence is unknown, pending is not confirmed money |
| Property | Asset search with server pagination, exact Asset, inventory/listing facts | Inventory/listing/moderation/map, canonical create/bulk | No duplicate property state; bulk/external execution remains governed |

CapitalMarkets, Diagnostic, Growth: existing registered navigation/search/workspace provider preserved, no rewrite. Non-entity native workspace links are explicit rather than empty fake record lists.

## Verification and handoff

- Static census: [cos-cuw-e-experience-coverage.csv](./cos-cuw-e-experience-coverage.csv) from the pinned generated manifest reference, explicit source binding matches only; six distinct runtime/presentation states remain UNVERIFIED. Re-run `php bin/ci/cuw-business-coverage.php` with root Composer dependencies for authoritative typed/declarative census on the final integrated tree. The script reads the canonical catalog, not an alternative registry. No runtime readiness is inferred.
- Fast CI automatically discovers `tests/unit/cuw_business_workspace_records.php` and `tests/architecture/cuw_business_workspaces_contract.php`. These are fixture/contract tests, not native DB evidence.
- Required acceptance: exact SHA DI and Twig/routes compilation; authentic two-tenant and role negatives; native Product/SKU, Marketing campaigns, Finance budget/payment operation, Sales order and Property reads; Sales confirmed-order receipt and same-key replay, stale revision/CSRF denial; existing Property creation and native Product/Marketing commands, non-mutating mobile viewport and keyboard, UK/EN review, screenshots. All tests involving a native mutation must run only in isolated test tenants and not on production.
- Existing AC-03/04/05/11/12/15/18 are targeted, not certified. Runtime/Frontend/Documentation CI and integrated-tree acceptance are required. For translations shared by other streams, integrator should move the new literal English labels into the common UK/EN catalog. Existing translated `uw.*` keys are preserved.
- Rollback: revert this feature branch; no schema migration, external write activation, or changes to old URLs.
