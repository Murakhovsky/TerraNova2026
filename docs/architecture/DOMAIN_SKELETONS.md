# V1 Domain Skeletons

Service, Finance, Procurement, HR, Construction та Real Estate оголошені як installable COS Domains до повної runtime-реалізації. Їхні `module.php` manifests discoverable через Kernel Module infrastructure, вимкнені за замовчуванням і навмисно не декларують runtime services, routes, jobs, capabilities або database migrations.

## Service
Канонічний словник: `ServiceCase`, `Request`, `Ticket`, `SLA`, `Assignment`, `Resolution`.

## Finance
Канонічний словник: `Account`, `Transaction`, `Invoice`, `Payment`, `Budget`, `Expense`, `Revenue`. Грошові значення використовують `Kernel\\Shared\\Domain\\Money`.

## Procurement
Канонічний словник: `Supplier`, `PurchaseRequest`, `Quote`, `Order`, `Delivery`.

## HR
Канонічний словник: `Employee`, `Position`, `Candidate`, `Recruitment`, `Onboarding`, `Performance`.

## Construction
Канонічний словник: `Project`, `Site`, `ConstructionObject`, `Estimate`, `Contractor`, `Work`, `Material`, `Milestone`, `Inspection`. `ConstructionObject` відповідає бізнес-поняттю Object.

## Real Estate
Real Estate є брокерським orchestration Domain поверх Property. Він не дублює canonical Property registry, inventory, catalog, listing/publication або presentation lifecycle.

Ці skeletons фіксують мову та module boundaries. Вони не вважаються завершеними business capabilities, доки окремо не реалізовані Application use cases, persistence adapters, permissions, workflows і public interfaces.
