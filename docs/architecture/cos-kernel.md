# COS Kernel: canonical architecture

COS is implemented as a modular monolith with hexagonal boundaries. MVC remains a delivery pattern for HTTP interfaces; it is not the architecture of the business core.

```text
Business transaction -> Event + Outbox -> durable consumer -> Context
    -> Rule / Agent -> Action -> Policy -> Approval / Queue
    -> Execution -> Result Event -> Audit + Metric
```

## Architectural levels

```text
Interfaces (Web / API / CLI / Telegram / Webhook)
    -> Domain Application / Kernel use cases

Domains (Sales / Finance / Inventory / ...)
    -> Kernel contracts and domain-owned outbound ports

Infrastructure (MySQL / CRM / LLM / messaging / queue)
    -> implements Domain and Kernel contracts

Bootstrap (composition root)
    -> is the only level allowed to assemble every concrete dependency
```

There is no class inheritance relationship between a Domain and the Kernel. A Domain implements Kernel contracts and contributes a `DomainModuleInterface` descriptor through composition.

## Dependency rules

```text
Kernel         -> PHP only
Domain         -> Kernel + the same Domain
Infrastructure -> Kernel and Domain contracts
Interfaces     -> Application/Kernel services exposed for delivery
Bootstrap      -> all levels, because it assembles the application
```

Forbidden dependencies:

```text
Kernel -X-> Domains, Infrastructure, Interfaces, Phalcon, PDO
Domain -X-> Infrastructure, Interfaces, Phalcon, PDO, legacy Common services
Infrastructure -X-> MVC controllers and views
```

`tests/architecture/layer_dependencies.php` enforces Kernel, Domain, Interface, and persistence boundaries automatically.

## Source layout

```text
app/
|-- Kernel/
|   |-- Event/
|   |-- Rule/
|   |-- Agent/
|   |-- Action/
|   |-- Policy/
|   |-- Approval/
|   |-- Queue/
|   |-- Audit/
|   |-- Transaction/
|   |-- Configuration/
|   |-- Tenant/
|   |-- Operations/
|   |-- Observability/
|   `-- Module/
|
|-- Domains/
|   `-- Sales/
|       |-- Model/                 business values and invariants
|       |-- Application/
|       |   |-- Contract/          outbound ports
|       |   |-- DTO/               port commands and results
|       |   `-- UseCase/           transactional application orchestration
|       |-- Automation/
|       |   |-- Event/
|       |   |-- Rule/
|       |   |-- Agent/
|       |   |-- Action/
|       |   |-- Job/
|       |   `-- Policy/
|       `-- Bootstrap/
|           `-- SalesDomainModule.php
|
|-- Infrastructure/
|   |-- Database/                  generic Kernel persistence
|   |-- Persistence/MySql/Sales/   Sales port implementations
|   |-- Integration/Crm/           routed CRM integrations
|   |-- ReadModel/                 query-only projections for delivery
|   |-- Operations/                metrics
|   |-- Observability/             structured logging
|   `-- Llm/
|
|-- Interfaces/
|   |-- Web/                       MVC controllers, CSRF, tenant context
|   `-- Api/                       health, approvals, CRM webhook
|-- Bootstrap/
|   |-- InfrastructureServices.php
|   |-- SalesServices.php
|   `-- KernelServices.php
|
`-- config/services_kernel.php     compatibility entrypoint to Bootstrap
```

Folders are created when a responsibility exists. A Domain must not contain ceremonial empty `Service`, `Repository`, or `Factory` folders.

## Kernel responsibilities

The Kernel owns mechanisms and lifecycles, never business vocabulary:

- `Event`: immutable event envelope and publication.
- `Rule`: deterministic condition evaluation.
- `Agent`: structured LLM decision runtime; it produces proposals only.
- `Action`: controlled mutation lifecycle and execution.
- `Policy`: `AUTO`, `APPROVAL_REQUIRED`, or `DENIED` gate.
- `Approval`: human decision lifecycle.
- `Queue`: durable asynchronous jobs, retries, leases, and dead letters.
- `Audit`: explanation and result trail.
- `Transaction`: an interface used by Kernel services; PDO is an Infrastructure detail.
- `Module`: the standard extension boundary for Domains.
- `Configuration`: validates Domain manifests before provisioning rules and policies.
- `Tenant`: exposes the active organization without coupling the core to sessions.
- `Operations`: owns worker lifecycle plus health and metric contracts.

Kernel execution emits `cos.action.completed` or `cos.action.failed`. A Domain emits its own business event when its business state changes; the Kernel does not invent a Sales, Finance, or Inventory event.

## Standard Domain module

Every Domain implements `Kernel\Module\DomainModuleInterface` and declares:

- owned event types;
- owned action types and their handlers;
- agent definitions and context builders;
- rule context provider;
- rule and policy catalogs.

`Kernel\Module\DomainModuleRegistry` validates unique ownership and routes execution without hard-coded `if sales`, `if finance`, or `if inventory` conditions in the Kernel.

Adding a Domain follows the same path:

```text
1. Create Domains/<Name>/{Model,Application,Automation,Bootstrap}
2. Define outbound ports in Application/Contract
3. Implement events, rules, agents, actions, and policies in Automation
4. Implement <Name>DomainModule
5. Implement physical adapters in Infrastructure
6. Register the module in Bootstrap services
```

## Sales example

Sales supplies meaning to the generic loop:

```text
sales.call.completed
-> Sales rule context
-> agent.run.sales_intelligence
-> Sales agent context
-> sales.send_followup proposal
-> generic policy and queue
-> Sales MessageGatewayInterface
-> MySQL or external CRM adapter
-> cos.action.completed
-> audit
```

Sales action handlers never execute SQL. They depend on ports such as:

- `DealRepositoryInterface`;
- `MessageGatewayInterface`;
- `FollowupRepositoryInterface`;
- `CrmGatewayInterface`.

MySQL and CRM implementations live under `Infrastructure` and can be replaced per organization without modifying Sales rules or Kernel code.

## MVC and Phalcon modules

MVC remains valid inside a Web interface:

```text
HTTP -> Controller -> Application/Kernel service -> ViewModel -> View
```

Controllers must not contain policies, SQL, domain transitions, or external integration selection. All endpoints live under `Interfaces/Web`, `Interfaces/Api`, `Interfaces/Telegram`, `Interfaces/Cli`, and similar entrypoint-oriented namespaces; `app/modules` has been removed.

Business areas must not be modeled as Phalcon modules. `Sales`, `Finance`, and `Inventory` are Domains because the same logic can be called from Web, API, CLI, Telegram, a queue worker, or an external CRM webhook.

## Composition root

The old monolithic service file is split by role:

- `Bootstrap/InfrastructureServices.php` creates concrete adapters;
- `Bootstrap/SalesServices.php` assembles the Sales Domain module;
- `Bootstrap/KernelServices.php` assembles Kernel runtimes from registered modules.

`config/services_kernel.php` only includes these files so existing Phalcon bootstraps remain compatible.

## Persistence and execution guarantees

- MySQL remains the source of truth; COS is not Event Sourcing.
- Business state, immutable Event, and Outbox row are stored in one transaction.
- Consumers record their own durable state; delivery is at-least-once and side effects are idempotent.
- Replay resets the selected Outbox rows and their consumer checkpoints together.
- Every business query and mutation is scoped by `organization_id`.
- Agents cannot access Action executors or infrastructure adapters.
- Agent input is redacted before storage and transmission and is removed by a retention job.
- Every Action passes Policy before execution.
- Actions and integration calls use organization-scoped idempotency keys.
- LLM work and mutations execute through durable jobs.
- CRM webhooks use HMAC verification, a durable inbox, retry/dead-letter handling, and explicit external-reference mapping.
- Default Policy behavior is deny when no explicit policy matches.

## Verification

The executable checks cover:

- architectural dependency direction;
- Event transaction, durable retry, consumer idempotency, and replay;
- deterministic Sales rules;
- outbound CRM routing plus inbound webhook HMAC/idempotency;
- Agent redaction and structured-output safety;
- configuration ownership and provisioning;
- production MySQL schema and tenant isolation;
- Policy and Approval behavior;
- queue retry/dead-letter behavior;
- the complete CallCompleted flow.
