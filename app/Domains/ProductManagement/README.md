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
