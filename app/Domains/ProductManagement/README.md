# Product Management Domain

**Status:** E0 foundation / not production-enabled. The module is disabled by default.

Product Management is one bounded context for **Catalog**, **Inventory**, **Movements**,
**Pricing**, and **channel observations**. Shopify is an external read-only integration,
not a Domain and not the product/stock authority until an explicit ownership cutover.

## Ownership

- Owns tenant-local Product, Variant/SKU, categories, locations, stock ledger,
  balance projection, base price, import staging and remote identity mappings.
- Does not own Sales deals/CRM, Procurement purchases, Finance payment ledger,
  Property inventory, fulfillment logistics or external checkout.
- A Shopify stock snapshot is an external observation, never a local movement.

## Runtime

- module ID: `product_management`; PSR-4: `Domains\ProductManagement\`.
- Registers through `ModuleDiscovery`; activation is per tenant.
- Manifest is not execution authorization. Only live, tested bindings are exposed.
- Kernel/Platform owns Policy, Approval, Action, Queue, Outbox and Workflow.
- E0–E4 use a read-only Shopify adapter. **No Shopify mutation endpoints.**

## Rollout

E0 bootstrap → E1 catalog → E2 stock ledger → E3 pricing/import →
E4 Shopify read-only pull with staging and reconciliation.

See `docs/11-decisions/ADR-0016-product-management-domain.md`.

## Shopify webhook ingress

`POST /api/v1/integrations/shopify/webhook` accepts only a bounded raw body
with a valid Shopify HMAC-SHA256, enabled Product Management tenant, and a
uniquely resolvable active shop connection. The inbox deduplicates webhook IDs
and persists a digest, **not** the incoming PII-bearing payload. Webhooks are
not authorization to write stock or directly start Platform Workflow.

`SHOPIFY_CLIENT_SECRET` must exist in trusted server configuration.
Ambiguous shops and inactive tenants fail closed. No Shopify writeback in E4.

## Shopify E4 canonical write safety

A catalog pull does **not** create or update canonical Product, Variant, Price
or Stock. It records remote product/stock observations, verifies preapproved
GID mappings when present and quarantines missing or inconsistent mappings.
Approved canonical import is a separate follow-up action through the existing
Kernel Action/Approval and Platform Workflow path, not a hidden effect of a
GraphQL read. The pull service is not exposed as a public command.

## Human-only operational capabilities

The canonical Capability Gateway exposes `variant.create`, `location.create`,
`stock.receive`, `stock.issue` and `price.base.set` in addition to
Product creation and queries. These require an active tenant module, a manager
with `cos.tenant.manage`, CSRF-protected invocation and a bounded
idempotency key. Automated invokers still require approved Platform Workflow.
Destructive adjustments and Shopify writes are intentionally **not** enabled.

## Product lifecycle and independent ledger read

`product.update` and `product.archive` require the current
`expected_revision` and return a committed new revision or a conflict.
Archiving never deletes the Product or movements. Archiving a Product with
active reservations is rejected. `stock.movement.search` returns immutable
tenant-scoped movement facts, not a computed/forged stock history.

## Bounded CSV staging over Capability Gateway

`product_management.import.preview` (human manager only, max 12,000
characters through the existing 16 KiB Gateway) stages CSV without
creating any Products. It atomically binds `X-Idempotency-Key` to the CSV
digest and batch identity; the same key cannot stage different data.
`product_management.import.batch.get` reads native per-row results.
The pinned `digest` and `rows_digest` support a separate, independently
approved `product_management.import.apply` Kernel Action. Preview never
commits Products directly.

## Shopify pull lease and recovery

One active pull lease exists per tenant connection. Starting another pull during
an active lease fails closed. Each page atomically checkpoints its cursor and
extends the bounded lease. A previous run's stale callback cannot overwrite
the connection cursor or release a replacement run. Failed requests retain
their last completed page cursor for deliberate retry. This is a local sync
reservation, **not** a new workflow executor or autonomous scheduler.

## E1 categories

`category.create` creates tenant-scoped slugs and optional child nodes
only when a valid tenant-local parent exists. `category.assign` links a
native Product to a tenant-local Category once, through a durable operation
receipt and Domain Event. `category.search` reads paged hierarchy nodes.
Product reads expose category membership. Destructive merges and category
deletion remain deliberately unsupported.

## Manual Shopify canary via existing Symfony Console

Only a supervised shell operator with exported
`COS_SHOPIFY_OPERATOR_ENABLED=1` may use `cos:product:shopify`.
The `product_management` tenant module must first be installed/current
and explicitly activated through existing COS module administration.

Example workflow (replace references, do not paste secrets):

```sh
php symfony/bin/console cos:product:shopify provision --organization=tenant-id --shop=your-store.myshopify.com --confirm=PROVISION_READ_ONLY
php symfony/bin/console cos:product:shopify status --organization=tenant-id --connection=existing-32-character-id
php symfony/bin/console cos:product:shopify pull --organization=tenant-id --connection=existing-32-character-id --pages=1 --confirm=PULL_READ_ONLY
```

`SHOPIFY_SHOP_DOMAIN`, `SHOPIFY_CLIENT_ID` and
`SHOPIFY_CLIENT_SECRET` must be exported server-side only. Provision
verifies the app's OAuth grant without persisting the token. Pull
records only external observations and conflicts in COS; it neither
publishes Products nor writes to Shopify. No periodic sync is started.

## Canonical Gateway acceptance

`tests/unit/product_management_capability_gateway.php` verifies that
live Command/Query bindings honor tenant activation, manager permissions,
unforgeable tenant/actor input schemas and human-only mutation provenance.
`tests/unit/product_management_capability_input_schemas.php` exercises
the fail-closed local JSON Schema validator. Both run under existing
`bin/verify fast`, without new execution authority.

## E1 Variant lifecycle

`variant.update` edits SKU, UOM, barcode and a small name/value options list with
`expected_revision` optimistic concurrency fencing. SKU is immutable after
the first posted inventory movement. No change of inventory-tracking mode is
permitted through this capability. `variant.archive` is a soft archive,
allowed only with zero on-hand/reserved across all tenant locations. Events
and operation receipts are transactionally appended to Kernel Outbox.

## E4 immutable Shopify import preview (no canonical writes)

A supervised operator can stage a complete remote Product snapshot after
a successful bounded read-only pull. Every snapshot now tracks
`is_complete`: raw first-page observations are incomplete and only
a fully expanded and validated variant/location snapshot is eligible.
Legacy snapshots default to incomplete.

Shopify shop currency is read from `shop.currencyCode` on the authenticated
GraphQL read and must be fresher than 24 hours, as must the Product snapshot.
Only base shop currency is represented, not customer market pricing.

The operator can inspect a snapshot's SHA-256 and completeness without
exposing raw catalog data:

```sh
php symfony/bin/console cos:product:shopify inspect --organization=tenant-id \
  --connection=existing-32-character-id --product-gid=gid://shopify/Product/123
```

Then stage a pinned candidate:

```sh
php symfony/bin/console cos:product:shopify stage --organization=tenant-id \
  --connection=existing-32-character-id --product-gid=gid://shopify/Product/123 \
  --expected-hash=64-character-source-sha256 --confirm=STAGE_NO_WRITES
```

Stage verifies each Variant, unique SKU, inventory pagination, currency
and base price, rejects existing mappings and native SKU collisions, and
records immutable normalized JSON/source digest in
`pm_shopify_import_previews` for human review. **No Product/Variant/Price,
stock ledger or mapping mutation occurs.** The separate approved canonical
Action materialization remains a required next release gate.

## E4 independently approved Shopify canonical import (tenant-disabled pilot)

After a supervised read-only pull, `inspect` and `stage` freeze a Shopify
Product candidate and its **shop-base** currency, every Variant SKU/Barcode,
base price, source GID, and exact normalized digest. **Staging writes no
canonical Product, Price or Stock.**

An authenticated tenant manager proposes a single Goal + immutable Plan using
`ShopifyImportPlanProposalService::propose(actor, preview_id, source_hash,
normalized_digest)`. The Plan has one
`product_management.shopify.import.apply` capability step with a pinned
`shopify_import_preview` target. Independent Plan Approval, canonical
Platform Workflow, and independently approved high-risk Action are required.
Neither the operator console nor a webhook can invoke the writer directly.

The Action Worker dispatches only `ShopifyImportApplyHandler` and checks tenant
activation before applying. `ShopifyImportApplyService` fences concurrent
writers by tenant, connection and external Product GID. It reuses native
Product/Variant/Price services with deterministic operation receipt keys,
commits external GID mappings only after native evidence, and keeps per-Variant
receipt rows for independent verification and crash recovery. Successful
materialization creates ONE Product with all approved Variants and base prices.
It **does not** write back to Shopify or create any stock movements.

A source snapshot change or ambiguous partial write blocks the preview for
**operator reconciliation**; no automatic blind retry. The Run outcome
`product_management.shopify_variants_verified` requires an attested Action
receipt *and* rereads each native Product, Variant, Price, mapping and immutable
Variant receipt. A synthetic completed Workflow flag alone is insufficient.

Release gates still include full human UI approval acceptance, live read-only
Shopify scope verification, production operator sign-off, and crash/recovery soak.

## Shopify-authoritative local write fence

After an approved import attaches Product/Variant mappings to a ready
`shopify_authoritative` connection, native Product/Variant updates and
archives, new Variants on that Product, base price changes, and local stock
receipts/issues/transfers are rejected by the Domain's own writers.
This includes direct UI/Capability commands and background use of the same
services. The approved importer initializes all Variants and prices **before**
attaching the Product mapping; it never uses a privileged bypass.

An eventual ownership cutover is a separate reviewed business workflow.
Changing source ownership through SQL or assuming `observation_only` is a
production-ready switch is not an authorized cutover procedure. Shopify
stock observations remain separate from the native ledger throughout E4.

## E4 unknown-outcome recovery audit

A pre-existing `applying` state means that a previous worker can have
committed native Product, Variant, Price, Events, mappings or receipts before
losing its Action response. A second invocation of the same approved Action
**does not resume any native writes**. Under the per-product MySQL named lock,
it moves the uncertain attempt to `blocked`. No automatic retry, reset,
mapping reassignment or deletion is authorized.

For a supervised tenant-enabled server shell operator, inspect the immutable
preview and existing native receipts **without writing anything**:

```sh
COS_SHOPIFY_OPERATOR_ENABLED=1 php symfony/bin/console cos:product:shopify audit \
  --organization=tenant-id --connection=existing-32-character-id \
  --preview-id=existing-32-character-preview-id
```

The resulting bounded JSON includes snapshot hash status, Product creation
receipt, current native Product/Variant/Price verification, mapping status and
per-step receipts. `automatic_replay_authorized` is always `false`.
`manual_reconciliation_required` must be resolved by an independently
reviewed recovery procedure outside this E4 pilot; the audit does not claim
that operator acknowledgement itself makes a partially committed action safe.

## Single approved Shopify Product identity claim

Different immutable snapshots of the same external Product GID may be
staged for comparison, but **only one preview** per tenant, Shopify connection
and Product GID can have a bound canonical Plan. A generated MySQL
`planned_product_gid` column and unique index enforce this even under
concurrent Plan proposals. A blocked/unknown-outcome attempt retains its
claim so a newer price/SKU snapshot cannot silently create a second native
Product. Planner preflight reports this as a manual reconciliation conflict.
The migration intentionally fails on conflicting historical planned rows:
those must be reviewed, not silently deduplicated.

## Shopify nested pagination (read-only)

Every Product's variant connection and every InventoryItem's location
connection is fully resolved before COS records stock observations.
Additional GraphQL requests use `product(id)` or `inventoryItem(id)`
with bounded `after` cursors. Maximum: 128 variant pages, 40 pages
per InventoryItem and 256 extra nested GraphQL queries per Product.
Repeated/missing cursors, identity changes, duplicate variant/location
GIDs and budget overruns cause quarantined conflicts; original snapshots
are retained and **no partial nested stock is accepted**. This remains
read-only and does not apply Shopify quantities to canonical COS balances.

## Shopify throttling

Admin GraphQL can return HTTP 200 with `errors[].extensions.code=THROTTLED`.
The read transport handles both this condition and transient 429/503 with
at most three bounded retry attempts. Other GraphQL errors fail closed.
No response containing unresolved errors is promoted to a Product snapshot.
Large stores still require a later cost-aware bulk import strategy.

## Archived Product stock fence

A Product cannot be archived while any tenant variant has nonzero
`on_hand` or `reserved`, not merely active reservations. Postings on
variants whose parent Product has been archived are rejected at the
transactional store. Variant Domain Events use the real variant aggregate ID.

## Atomic warehouse-to-warehouse transfer

`stock.transfer` is a single tenant-scoped Capability Gateway Command,
not separate independently retriable issue/receipt requests. It locks
the two active locations and balances in deterministic order, posts
two equal and opposite entries in the native immutable stock ledger,
and writes one `product_management.stock.transferred` event via Kernel Outbox
within the same MySQL transaction. It rejects cross-tenant, same-location,
overdrawn and reused-key-with-different-payload requests. Shopify writeback
remains unavailable.

## E1 multilingual product content

`translation.upsert` writes a Product translation (locale, name, optional
description) through the canonical Capability Gateway and a transactional
Domain Event/Outbox. Locales are bounded language tags such as `uk`, `en`,
`en-US`. Reads through `product.get` return all tenant-local translations.
An archived Product cannot receive translations. Shopify remains an external
read-only observation source, not the authoritative editor for COS translations.

## E3 immutable CSV staging evidence

New CSV previews store an additional `rows_digest` over each original row's
SKU, normalized payload, validation status and error. Both batch-level and
per-row hashes are retained separately from mutable application state. Legacy
staged batches without these hashes are **not** eligible for approved commit.
The original CSV bytes remain represented by `digest`; apply must verify
both pinned digests and independent canonical Action approval before writing.

## E3 approved CSV apply (canonical Action only)

`product_management.import.apply` is **not a direct Command Bus/HTTP mutation**.
The existing Goal/Plan/Platform Workflow must independently approve and
create a high-risk `APPROVAL_REQUIRED` Kernel Action with a pinned
`target_type=product_import_batch`, `target_id`, `batch_digest`,
`rows_digest` and canonical `wf:<SHA-256>` idempotency key.
The configured Product Management module exposes a required human approval
policy and one domain-owned handler to the **existing** Action Worker.
`import.preview` cannot apply any row. A partial failure blocks the batch
for operator reconciliation; no automatic replay or Shopify writeback.

### Tenant activation fence on approved imports

The Action handler checks the existing `ActiveModuleResolver` before
touching staged CSV or Product rows. A valid approved Action still fails
closed when the target tenant's Product Management module is disabled.

### Parallel Action worker fencing

The approved CSV writer holds one tenant/batch-specific MySQL advisory lock
across row execution. A second worker on a different DB connection fails
before any Product write. A process crash releases its named lock at DB
connection teardown, while durable row receipts and Action claims determine
recovery eligibility; expired local locks never authorize an unapproved Action.

## E1 Product Media: verified file references

Product media links reuse Platform FileStorage and the tenant-owned COS
Documents `cos_document_files` registry. A media `file_id` is accepted
only for the same organization, with a verified original SHA-256 checksum,
matching byte size and detected image MIME, and a tenant-hashed storage path.
An authorized user cannot pass arbitrary external URLs, filesystem paths,
or another organization's file ID.

Product/Variant relations, cover/gallery role, sort order and soft-detach
are native Product Management records. Detach never deletes shared Platform
file bytes. Product reads include safe media metadata and never return
internal storage keys. Legacy `tn_media_assets` are not treated as
tenant-owned evidence and cannot be linked directly.

### Crash between native Product creation and CSV row receipt

An approved Action can safely reuse the same row idempotency key after a
worker crash. If Product.create already committed to the native Product
registry and Kernel Outbox, re-execution reads its original receipt, checks
native Product identity/SKU and completes the still-unwritten CSV row
receipt. No second Product or Domain Event is produced. Rows with conflicting
native evidence remain blocked for manual reconciliation.

## E3 canonical Goal / Plan proposal

`ProductImportPlanProposalService::propose` is a human-only, tenant-manager
application entry point that validates each immutable staged CSV row before
creating a real COS Goal and pinned Federation Plan through the existing
`FederationGoalStore`. It binds a batch to only one Goal/Plan and requires
all rows ready, rejecting invalid and duplicate rows rather than silently
skipping them. It neither approves a Plan nor starts an Action or Product
mutation. Independent Plan and Action Approvals remain mandatory.

## E3 independently attested Goal outcome

`ProductImportRunOutcomeProvider` is registered with the existing
`FederationTrustedOutcomeEvidenceResolver` and only accepts a completed,
attested Workflow Action receipt tied to the same tenant and approved Plan.
It recomputes every staged row hash, verifies exact CSV rowset digest,
native Product name/type, Variant SKU, Product ID and row receipt key.
A completed Workflow alone can never satisfy the Goal. Partial batches
are unknown, and fingerprint drift fails closed. The outcome is one
Run-scoped metric, `product_management.csv_products_verified`.

## E3 full canonical acceptance

`tests/integration/product_management_approved_goal_e2e_mysql.php` runs
the actual Product-backed Goal/Plan, stored independent Plan Approval
evidence, canonical Platform Workflow/Action claim, independent Action
Approval, Product writer, Action receipt attestation and Run-scoped Goal
outcome against MySQL 8.4. It rejects premature execution, duplicate
Action dispatch and forged CSV row receipts. No second executor exists.
This technical E2E does not substitute for operator sign-off or UI acceptance.

## E4 per-store COS Credential Vault binding

The supervised Shopify operator can provision a connection with
`--secret-ref=env://COS_SHOPIFY_STORE_A` rather than the legacy
`shopify_env_primary` pilot configuration. Set `COS_SHOPIFY_STORE_A` on the
server to a **JSON object**, for example
`{"shop_domain":"store-a.myshopify.com","client_id":"...","client_secret":"..."}`.
The existing `CredentialVaultInterface` resolves the JSON in the trusted
connection/tenant context; only its **reference** is stored in COS. The
resolved `shop_domain` must exactly match the provisioned shop. No secret is
sent to the Workspace, log, event or import snapshot.

OAuth read tokens and webhook HMAC both select credentials from the
**uniquely matched connection**. A Shopify webhook with an unknown or
ambiguous shop fails closed. Store A's HMAC is never checked against Store
B's secret. The default legacy single-store resolver remains temporarily
available for the existing operator pilot and should not be used when
onboarding multiple stores. This changes neither Shopify source-of-truth
ownership nor the E4 prohibition on remote writes.
