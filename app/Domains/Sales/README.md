# Sales Domain

`Sales` is the reference COS bounded context. It owns the lifecycle from an inbound lead to a managed client case/deal, its activities, property matches, follow-ups, Sales decisions and external CRM synchronization.

## Ownership

Sales owns:

- people in a Sales relationship;
- inbound leads and their qualification state;
- client cases/deals, pipeline stage, status and priority;
- Sales activities, next contact and property matches;
- Sales business events, deterministic rules, Agent definition, actions and policies;
- translation between canonical Sales operations and an external CRM.

Sales does not own Property listings, authentication/memberships, Telegram delivery, files/media, generic queues, LLM transport or operational telemetry. Those capabilities are accessed through ports or Kernel services.

### Legacy compatibility boundary

`Infrastructure/Persistence/Phalcon/Telegram` contains old ActiveRecord models for the `request_*` tables. They are temporarily kept so the existing Telegram interface can read historical Sales records. This directory is a quarantined compatibility adapter: new business rules, use cases, rendering or Telegram behavior must not be added there. New delivery code belongs in `Interfaces/Telegram`; new Sales persistence implements a contract from `Application/Contract`.

## Standard layout

```text
Sales/
|-- Model/                  typed vocabulary and business invariants
|-- Application/
|   |-- Contract/           repositories, gateways and external ports
|   |-- DTO/                immutable commands/results crossing a port
|   |-- Support/            shared application normalization only
|   `-- UseCase/            transactional business orchestration
|-- Automation/
|   |-- Event/              Sales-owned facts
|   |-- Rule/               deterministic reactions
|   |-- Agent/              Agent definition, never direct mutation
|   |-- Action/             handlers delegating to application ports
|   |-- Job/                durable asynchronous entrypoints
|   `-- Policy/             AUTO / APPROVAL_REQUIRED / DENIED
|-- Infrastructure/
|   |-- Persistence/        implementations of Sales-owned ports
|   `-- ReadModel/          tenant-scoped query projections
`-- Bootstrap/
    `-- SalesDomainModule.php
```

The common composition root is `app/Bootstrap/SalesServices.php`. Sales services must not be registered inside a Web module: Web, API, CLI, Telegram and workers must resolve the same use cases.

## Canonical vocabulary

New code must use the backed enums under `Model` instead of introducing free-form status arrays:

- `PipelineStage`;
- `ClientCaseStatus`;
- `ClientCaseType`;
- `SalesPriority`;
- `LeadStatus`;
- `PropertyMatchStatus`;
- `SalesActivityType`;
- `SalesCurrency`.

Database values and external CRM values are translated at adapter boundaries. An external provider must never define an internal event or pipeline vocabulary.

## Write guarantees

Every state-changing use case follows this order inside one transaction:

```text
validate tenant and input
-> mutate through a Sales port
-> publish a Sales DomainEvent
-> commit business state + Event + Outbox atomically
```

Controllers, Telegram commands and workers call use cases. They do not reproduce Sales transitions or execute Sales SQL. Automation Action handlers also contain no SQL; they invoke a port and let Kernel record the result.

## Adding a Sales capability

1. Add or reuse a typed value in `Model`.
2. Add an immutable DTO when the operation crosses an adapter or has more than a few scalar arguments.
3. Define the smallest outbound contract in `Application/Contract`.
4. Implement one transactional use case in `Application/UseCase`.
5. Add the MySQL/CRM adapter under `Infrastructure`.
6. Register it in `app/Bootstrap/SalesServices.php`.
7. If automation is required, add the Event, Rule/Agent, Action handler and Policy, then declare ownership in `SalesDomainModule`.
8. Add a smoke test plus a real-MySQL test when persistence, locking or tenant isolation changes.

## Definition of done

A Sales change is ready only when tenant scope is explicit, writes and Events are atomic, external calls are idempotent, every Action passes Policy, no sensitive Agent context is persisted unredacted, and architecture/smoke/integration tests pass.
