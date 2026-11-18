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
