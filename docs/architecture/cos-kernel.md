# COS Kernel v0.1

COS Kernel implements the reusable business loop inside the existing TerraNova/AIDA application:

```text
Event -> Context -> Rule/Decision -> Action -> Policy -> Execution -> Result -> Audit
```

The Kernel does not contain Sales entities such as `Deal`, `Lead`, or `ClientCase`. Domain-specific event factories and action handlers live under `app/Domains`.

## Source layout

```text
app/
|-- Kernel/
|   |-- Event/             event envelope, outbox contract and dispatcher
|   |-- Rule/              deterministic condition evaluation
|   |-- Agent/             agent contract and structured result
|   |-- Action/            action aggregate, lifecycle and executor
|   |-- Policy/            AUTO / APPROVAL_REQUIRED / DENIED decision
|   |-- Approval/          approval state
|   `-- Audit/             structured audit contract
|-- Domains/
|   `-- Sales/
|       |-- Event/         Sales event factories
|       `-- Action/        Sales action handlers
|-- Infrastructure/
|   |-- Database/
|   |   |-- Event/         MySQL transactional outbox
|   |   `-- Transaction/   PDO transaction boundary
|   `-- Crm/
|       |-- CrmRegistry.php
|       |-- RoutedCrmGateway.php
|       `-- Aida/           native AIDA CRM adapter
|-- common/                existing shared and legacy code
|-- modules/               existing Phalcon delivery modules
|-- config/
|   `-- services_kernel.php
`-- migrations/
```

## Dependency rules

```text
Phalcon modules -> Sales domain -> Kernel
Infrastructure  -> domain/kernel contracts
Kernel          -X-> Sales, Phalcon, OpenAI, Telegram, PDO
Agent           -X-> ActionExecutor or external integrations
```

Infrastructure classes may depend on PDO, Phalcon, RabbitMQ, OpenAI, and Telegram. Kernel classes must remain framework-independent.

## Database migrations

Apply migrations in filename order after the existing TerraNova migrations:

1. `20260822_000008_cos_events.sql`
   - `cos_events`
   - `cos_event_outbox`
   - `cos_event_consumptions`
2. `20260822_000009_cos_decisioning.sql`
   - `cos_rules`
   - `cos_rule_evaluations`
   - `cos_decisions`
3. `20260822_000010_cos_execution.sql`
   - `cos_actions`
   - `cos_action_attempts`
   - `cos_policies`
   - `cos_policy_evaluations`
   - `cos_approvals`
4. `20260822_000011_cos_audit_agents.sql`
   - `cos_agent_runs`
   - `cos_audit_log`

All COS records carry `organization_id`. Until a canonical organization table is introduced, this value intentionally has no foreign key to the existing legacy company models.

## Transactional event publishing

Business state and its event must be persisted in the same PDO transaction:

```php
$transactionManager->transactional(function () use ($deal, $event): void {
    $dealRepository->save($deal);
    $eventOutbox->append($event);
});
```

`MysqlEventOutbox` rejects writes outside a transaction. It writes the immutable event and dispatch record together. A worker will later claim `cos_event_outbox` rows and dispatch them.

Consumers use `cos_event_consumptions` with the unique `(event_id, consumer_name)` key so redelivery cannot repeat a logical result.

## Action lifecycle

```text
PROPOSED -> PENDING_APPROVAL -> QUEUED -> RUNNING -> COMPLETED
    |               |                       `-----> FAILED -> QUEUED
    `---------------`-----> REJECTED
```

Only the `Action` aggregate changes its status. Agents return proposals; they cannot execute actions.

An action idempotency key is unique inside an organization. A recommended key for a rule-created action is:

```text
event_id + rule_id + action_type + target_id
```

## Default policy behavior

If no policy matches an action, `PolicyEngine` returns `DENIED`. Automatic execution must always be enabled by an explicit active policy.

## First vertical slice

The first production flow should be implemented in this order:

```text
ClientCase stage changes
-> sales.deal.stage_changed appended to outbox
-> Sales rule context is built
-> Rule Engine matches an active rule
-> sales.create_followup_task Action is persisted
-> Policy Engine returns AUTO
-> CreateFollowupTaskHandler creates tn_client_case_activities row
-> Action result and audit entry are persisted
```

AI is deliberately excluded from this first slice. `AgentInterface` and `AgentResult` define the future boundary without granting an agent mutation capabilities.

## Deterministic Sales processes

The first process catalog is implemented by `Domains\Sales\Rule\SalesDeterministicProcessCatalog` and seeded by migration `20260822_000012_sales_deterministic_processes.sql`:

1. `sales.deal.created` creates a qualification task for an active Deal in the `new` stage.
2. `sales.deal.stage_changed` creates a follow-up task when an active working-stage Deal has no `next_contact_at`.
3. `sales.followup.overdue` creates an urgent escalation task for an unfinished overdue activity.

All three actions use an explicit `AUTO` policy and only create internal CRM tasks. They do not send messages or mutate deal stages.

## CRM boundary

Sales action handlers do not write CRM tables and do not select a provider. They send commands through `CrmGatewayInterface`:

```text
CreateFollowupTaskHandler
-> RoutedCrmGateway
-> organization CRM resolver
-> CrmRegistry
-> Aida / HubSpot / Pipedrive adapter
```

The active CRM provider is configured per organization in `cos_integrations`. Entity mappings and outbound idempotency references are stored in `cos_external_references`; synchronization progress belongs to `cos_sync_state`.

`AidaCrmAdapter` is the first native adapter and is the only class allowed to translate `sales.create_*_task` into an insert in `tn_client_case_activities`. A future external CRM adapter implements the same `CrmPort` without changing the Kernel or Sales rules.

## Next implementation steps

1. Add repositories for rules, actions, policies, and audit records.
2. Add the outbox worker with row claiming, retry, and dead-letter behavior.
3. Integrate event append into the existing ClientCase stage-change service.
4. Add integration tests for transaction rollback and duplicate delivery.
5. Add the Sales Intelligence agent only after the deterministic loop works end to end.
