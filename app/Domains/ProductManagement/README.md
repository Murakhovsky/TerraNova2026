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

## Tenant location discovery

`product_management.location.search` is a bounded, tenant-scoped read QueryBus capability.
It filters by warehouse code/name, returns only ID/code/name/status (no stock write),
and supports explicit pagination. Native availability is obtained separately through
`stock.balance.get` and never estimated from external Shopify inventory observations.

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

## E7 native lifecycle Workspace

Product cards expose native SKU/UOM/barcode updates and soft archive for Product and Variant, using the existing canonical Gateway and human manager/tenant/CSRF/idempotency guards. Each form supplies the observed revision; archive additionally requires explicit confirmation. Variant IDs are checked against the tenant Product before dispatch. An independent Product read must agree with the exact operation receipt and expected revision before a success redirect. A receipt/readback mismatch is an uncertain outcome, not authorization to repeat with a fresh key.

Stock receipts/issues now lock the native Product/Variant lifecycle rows before checking eligibility; archive reads nonzero stock/reservations with a current locking read. Existing transfer already locks these rows. Variant archive also checks the parent state. No destructive deletion, new executor, Shopify or Sales behavior. Variant options remain preserved by the basic Workspace edit; advanced options editor and browser acceptance remain pending. Reservation branch must retain the same locks when integrated.

## E7 multi-currency base pricing and optimistic revision fence

Native Workspace supports a selected three-letter currency code and bounded pages of 30 SKU prices. Currency is not inferred or converted. The existing price.base.set input accepts optional expected_revision (0 for no current price), which the Workspace always supplies. Existing imports that omit this optional field retain their original receipt hash and call signature. Price mutations serialize with Product/Variant lifecycle locks, reject archived entities, and atomically write the immutable history and Event/Outbox. Success requires the independently read price, currency, Variant and exact next revision to match the receipt. An uncertain readback returns 503. Price lists, tier/quantity rules, channel pricing and price-history browsing are separate unfinished scope.

## E7 price history query

The tenant-scoped price.history.search capability reads immutable native base-price history per Variant and currency, with bounded pagination and a deterministic created_at / event ID sort. Each item includes the original and new amount, recorded actor reference, timestamp and history event ID. No price mutation, currency conversion or reconstruction of historical list prices is performed. Archived SKUs remain readable by managers. The Product card exposes this through the existing canonical query Gateway; another tenant's Variant returns no history. Current price revisions and history events are different concepts, and the existing history table does not store a revision number.

## E7 explicit price lists (P1 native core)

A tenant manager may create a retail, wholesale or partner list identified by a unique tenant-local code and fixed currency. The native price.list.entry.set command requires explicit list and Variant identity and expected revision (0 for an unpriced SKU). The write reuses the active Product/Variant and Shopify ownership fence, stores an immutable revision history, receipt, and native event/outbox in the same transaction. The read price.list.entry.get returns no implicit base-price fallback, FX conversion or customer eligibility. Foreign tenant identities do not reveal list currency or price. Existing list prices remain readable after archive but cannot be changed. The list search is paginated and supports exact code discovery. List lifecycle, entry removal, quantity tiers, customer/channel selection and dedicated history query are later scope. Migration advances module schema to 0.9.0.

## E7 price list lifecycle and history (P2)

Lists have active/archived status and an optimistic metadata revision, independent of SKU entry revisions. Existing lists become active at revision 1 via an additive migration. Update changes only the display name; code, currency and kind are immutable. Archive is terminal and blocks new entry writes; existing prices and immutable history remain readable for audit, not a quote eligibility decision. The new price.list.history.search query is tenant/list/SKU scoped and ordered by entry revision. All writes use canonical receipt/Event/Outbox and the same list row lock. Quantity tiers and explicit quote resolution remain separate scope. Schema version 0.10.0.

## E5 P1 — ручне резервування, без зовнішнього Order writer

`stock.reserve` та `stock.release` публікуються як tenant-manager
capabilities із типовими Kernel permissions/idempotency. Резерв не змінює
`on_hand` і не створює фізичний stock movement; `available` виводиться
як `on_hand-reserved`. Реліз скидає тільки `reserved`.

Операції атомарні в одному PDO transaction, ідентичність origin
`(tenant,source_ref,variant,location)` унікальна, повторення того самого
idempotency key повертає початковий receipt. У першому вертикальному
зрізі дозволено тільки `manual:` source reference. SalesOrder/OrderLine
не існує як підтверджений зовнішній контракт, і автоматичне резервування
від Sales/OMS, automatic expiry та partial release **не дозволені**.
Рішення описане в ADR-0017.

## E5 P2 — concurrent reservation + read-only stock reconciliation

Two independent MySQL/PHP workers simultaneously compete for the last
available Stock. The CI gate
`tests/integration/product_management_stock_concurrency_mysql.php`
requires that only one overcommitting writer wins, concurrent identical
idempotency keys yield one Reservation receipt, and concurrent distinct
release keys release a Reservation only once. All paths must preserve
same-transaction Event/Outbox and the immutable movement ledger.

`MySqlStockReconciliationAudit` reads a **single consistent SQL statement**
over all tenant Stock balances, movement sums and active reservations. It
reports `DRIFT` when either `on_hand != SUM(movements)` or
`reserved != SUM(active reservations)`, including missing projection rows.
It reports `INCOMPLETE` when limited output excludes unscanned pairs;
neither status is accepted as healthy. No repair or implicit stock posting
is allowed.

Server operator only (read-only and fail-closed):

```sh
docker compose exec -T -e COS_PRODUCT_STOCK_AUDIT_ENABLED=1 php \
  php bin/console cos:product:stock:audit --organization=TENANT_ID --limit=100
```

The operator must be explicitly run by an authorized server administrator
on a deployed tenant with the Product Management module enabled. The command
does not expose a new AI/CRON or public API mutation path and does not
auto-reconcile any conflict. A full clean audit requires `status=OK` and
`complete=true`. High-cardinality tenants require an explicit larger
limit or a future paginated read audit; a truncated response is not evidence.

## E5/E7 integrated reservation Workspace

Tenant managers can browse paginated reservations by SKU and warehouse, create manual reservations and explicitly release active reservations. `stock.reservation.search` independently verifies identity/status and balance readback. No automatic expiry, partial release or Sales order writer is admitted by ADR-0017. All mutations use existing Gateway → CommandBus → Domain and uncertain post-write verification returns 503.

## E7 quantity tiers and explicit price resolution

A list entry can be removed using a revisioned null-amount tombstone; history and previous receipts are retained. Tiers are independent SKU/list/minimum-quantity prices, versioned and removable using a null amount. Quantity thresholds are exact DECIMAL(20,6) and inclusive. price.resolve uses the highest active eligible threshold, then the explicit list entry if present; without a list it uses the requested currency base price only. It returns no quote for archived lists or archived Product/Variant, and never infers customer eligibility, converts FX or falls back across lists. Tiers remain independent of removed list entries. Schema 0.12.0. Tier history is stored atomically; browsing tier history and approval-based stock adjustments remain distinct work.

Advanced pricing Workspace: managers can add, update, remove and restore quantity thresholds; explicitly remove list entries; preview the selected price for a quantity. Every mutation compares an exact independently queried entry/tier with its receipt. Price removal and tier removal require explicit confirmation. This does not provide an automated Sales quote or change Sales order ownership.

Quantity-tier history is now available through `price.list.tier.history.search` and the Product card, including deleted thresholds, actor, revision, timestamp and old/new amount. Scope is tenant/list/Variant/exact minimum quantity. Read-only; no migration.

## E5 approved physical count (single SKU/location)

The Workspace proposes a canonical Goal and immutable Plan containing count document identity, actual nonnegative DECIMAL quantity, observed stock revision and explicit source document. It cannot change stock. The sole mutation is stock.count.apply, an existing Kernel Action Worker handler requiring independent Plan and Action approval, active module, high risk and canonical Workflow idempotency. It locks Product/Variant, location and balance; stale revisions, foreign scope, Shopify-owned writes and quantities below reserved are denied. An immutable count row, correction ledger movement (only for a nonzero delta), balance revision, Domain Event/Outbox and receipt commit atomically. A zero discrepancy still records a count and advances revision without creating a fake movement. stock.count.get exposes independent tenant-scoped evidence; the Goal outcome provider attests the actual Workflow Action and independently checks count and ledger identity/values. Stock count requests never imply a Sales/OMS order. Multi-SKU batch inventory is not part of this single-position implementation. Full MySQL E2E exercises Goal, pinned Plan, approval evidence fixture, independent Action approval and Worker admission; browser acceptance remains separate.

## E5 atomic multi-SKU inventory sessions / E7 browser acceptance

A single warehouse session captures 1–50 distinct explicitly counted local SKU positions, sorted by Variant identity. The paginated candidates query returns exact quantity, reserved amount and revision (including uninitialized zero balances); Shopify-authoritative and archived/non-stock Variants are excluded. Empty input excludes a row, explicit zero counts an empty position. Each page can be a separate count document; there is no implicit whole-warehouse coverage assertion. The Plan is immutable and contains the entire rowset, one canonical approved Action, and a Goal expecting all rows independently verified. Session header, per-SKU native count/ledger, balance revisions, one Domain event/outbox and parent receipt are one transaction. Any stale row, bound violation or duplicate origin aborts every row; no partial application. Derived child keys identify native lines only, not separate Actions. Native readback and run-linked evidence attest header and every exact approved row/ledger. No Sales/OMS ownership or external channel writes. Schema 0.14.0, 50 capabilities. Browser CI exercises real authenticated catalog creation, warehouse receipt, reserve/release, price tiers and history, session proposal and unchanged balances before approval, plus desktop/mobile screenshots. Full worker admission and outcome tested in separate native MySQL Goal E2E. Browser acceptance status is determined by CI, not this documentation.

## Product Attributes & Variants (schema 0.15.0)

Tenant-owned immutable attribute definitions support text, exact signed decimal (six fractional digits, optional unit), boolean and closed enum choices. Category bindings are additive with required/axis flags; effective Product set is the union of directly assigned categories. Missing required values are diagnostics, not a global publication gate. Product attribute patches are atomic, optimistic-revision checked, idempotent and preserve explicit false/zero; null removes optional values. Exact normalized filtering is tenant-scoped. Matrix preview expands up to four enum axes and 50 combinations in canonical order, yields deterministic SKU prefix + hash and checks all existing combinations including archived and SKU conflicts. Creation is all-or-nothing, revalidates Product revision and ownership and preserves original SKU/stock/prices/media. Managed combinations are immutable; no implicit migration of legacy options or default SKU. Readback via canonical query and existing Product projection. Workspace supports dictionary creation, category binding, filtering, JSON patch and preview/confirm matrix. No Shopify or Sales writes. Atomic multi-product patch is supported for up to 20 explicitly selected Products and 10 values, with a separate optimistic revision per Product; all rows share the native receipt transaction. Rich type-specific forms, definition/binding lifecycle and migration of legacy free-form options remain separate improvements; Runtime and browser acceptance are not yet verified.

## Product Analytics & Dashboard

Read-only query contracts: analytics.summary.get, analytics.stock.search, analytics.movement.search, analytics.quality.search and analytics.reconciliation.get. Native assortment counts, SKU availability and reservations, currency-specific base-price stock estimate (not cost/accounting valuation; unpriced stock explicitly marks incomplete), warehouse-position threshold drilldowns, ledger opening/closing/receipts/issues/adjustments/transfers, endpoint-average physical turnover (not COGS or sales), required-attribute/media/category/price quality diagnostics and bounded read-only reconciliation. No quantities summed across incompatible units. Explicit location includes uninitialized tracked positions; all-location drilldown only recorded active positions. UTC half-open movement interval limited to 366 days. Reports independently read-consistent, not a global transaction; threshold risk is operator-defined, not replenishment forecasting. Workspace /product-management/dashboard, desktop/mobile CI journey; exact-result MySQL fixture includes tenant isolation, transfers excluded from nontransfer issues, missing price, audit drift and completeness. Schema remains 0.15.0 (no analytics migration). 65 contracts. Revenue, margin, actual demand forecasting and accounting valuation require authoritative Sales/Finance/Procurement data and are not fabricated. No production acceptance claimed.

## AI Management / governed Product business operations

Eight explicitly allowlisted action-bound contracts under product_management.governed.* support product.create, variant.matrix.create, attribute.product.bulk_set, price.base.set (revision mandatory), stock.receive, stock.issue, stock.reserve and stock.release. Existing CSV apply and stock count Actions are unchanged. Direct command: writes remain human-only. Worker admits only a RUNNING canonical wf-key Action with independent approval, tenant module, pinned contract and strict schema. Existing native service transactions and idempotency receipts are reused. The current canonical Workflow classifies independently approved Actions as external, even though Product effects remain local. No new executor, synthetic actor or unrestricted dispatch.

Proposal-only endpoint POST /api/v1/product-management/governed/plans/propose accepts authenticated tenant manager session + CSRF; payload {key,description,operations:[{id,capability_id,parameters}]}, 1..20 ordered operations, 64 KB overall, 16 KB per capability. Server assigns target IDs, derives immutable Goal/Plan IDs, resolves live capabilities and sets criterion product_management.governed_operations_verified equals number of operations. Same tenant/key/payload resolves same proposed plan; conflicts rejected. No side effect or approval at proposal. Existing /workspace/goals is the approval/Run/evaluation interface. A model can produce structured proposal input; signed AI/CRON machine transport for Product (existing Property-specific automation intent path) and live LLM planning acceptance remain unimplemented. No service identity or approval bypass is claimed.

Business scenario: prepare existing SKU at warehouse, receive 10, price USD 125.5, reserve 3, issue 2, create a separate assortment Product, generate two variants for existing Product, patch a category attribute, release an existing reservation. The fixture expects 8 approved Actions and independent native effects, ending 8 physical / 3 reserved / 5 available, price 125.5000, new SKU and attributes, released reservation. Multi-step workflow is sequential, not globally atomic; each Action is atomic and a failed predecessor blocks dependents. IDs for later operations must be known before Plan approval; no dynamic substitution of previous Action outputs. Product Goal provider checks canonical approval/first-attempt evidence and native receipt scoped to time and Run, Product and Variant IDs, exact price/revision, ledger, reservation, matrix and normalized attribute values. Current mutable price/attributes are checked at evaluation and drift fails closed. Tampering must not be counted as satisfied. No production test or live agent claimed. 73 Capability Contracts, unchanged schema 0.15.0. MySQL scenario uses a Plan approval evidence fixture and real Action approvals/worker. Final CI and browser acceptance pending.
