# V1 Domain Skeletons

Finance, Procurement, HR та Construction залишаються installable COS Domains до повної runtime-реалізації. Їхні `module.php` manifests discoverable через Kernel Module infrastructure, вимкнені за замовчуванням і навмисно не декларують runtime services, routes, jobs, capabilities або database migrations.

## Finance
Канонічний словник: `Account`, `Transaction`, `Invoice`, `Payment`, `Budget`, `Expense`, `Revenue`. Грошові значення використовують `Kernel\\Shared\\Domain\\Money`.

## Procurement
Канонічний словник: `Supplier`, `PurchaseRequest`, `Quote`, `Order`, `Delivery`.

## HR
Канонічний словник: `Employee`, `Position`, `Candidate`, `Recruitment`, `Onboarding`, `Performance`.

## Construction
Канонічний словник: `Project`, `Site`, `ConstructionObject`, `Estimate`, `Contractor`, `Work`, `Material`, `Milestone`, `Inspection`. `ConstructionObject` відповідає бізнес-поняттю Object.

## Домени, що вийшли зі skeleton-стану

- **Real Estate `1.0.0`**: V1-stable brokerage orchestration runtime поверх Sales + Property; persistence schema `0.2.0`.
- **Service `1.0.0`**: V1-stable Request/Ticket/SLA/Assignment/Escalation/Resolution runtime; persistence schema `0.2.0`.

Ці skeletons фіксують мову та module boundaries. Вони не вважаються завершеними business capabilities, доки окремо не реалізовані Application use cases, persistence adapters, permissions, workflows і public interfaces.
