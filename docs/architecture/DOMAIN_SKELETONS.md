# V1 Domain Skeletons

Service, Finance and Procurement are declared as COS Domains before their runtime implementation. Their `module.php` manifests are discoverable by Kernel Module infrastructure but disabled by default and intentionally declare no runtime service, routes, jobs, capabilities or database migrations.

## Service
Canonical vocabulary: `ServiceCase` (business name: Case), `Request`, `Ticket`, `SLA`, `Assignment`, `Resolution`. PHP class `ServiceCase` avoids the reserved `case` keyword.

## Finance
Canonical vocabulary: `Account`, `Transaction`, `Invoice`, `Payment`, `Budget`, `Expense`, `Revenue`. Monetary fields use `Kernel\\Shared\\Domain\\Money`; persistence, ledger rules, accounting policy, taxation and payment-provider integration are outside this skeleton.

## Procurement
Canonical vocabulary: `Supplier`, `PurchaseRequest`, `Quote`, `Order`, `Delivery`.

These skeletons establish language and module boundaries only. They must not be treated as completed business capabilities until Application use cases, persistence adapters, permissions, workflows and public interfaces are implemented deliberately.
