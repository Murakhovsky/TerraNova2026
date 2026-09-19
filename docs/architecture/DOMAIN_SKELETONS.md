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

- **Real Estate `0.2.0`**: brokerage orchestration runtime поверх Sales + Property.
- **Service `0.2.0`**: Request/Ticket/SLA/Assignment/Escalation/Resolution runtime.

Ці skeletons фіксують мову та module boundaries. Вони не вважаються завершеними business capabilities, доки окремо не реалізовані Application use cases, persistence adapters, permissions, workflows і public interfaces.
