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
The signed `digest` is returned for the later approved commit workflow.
Batch apply remains disabled until Kernel Action + independent Approval exists.

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
