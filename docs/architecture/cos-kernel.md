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
|   |-- Queue/             durable jobs, handlers and worker
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
5. `20260822_000012_sales_deterministic_processes.sql` through `20260822_000014_action_policies.sql`
   - initial Sales rules, integrations and action policies
6. `20260822_000015_cos_jobs.sql`
   - `cos_jobs` with retries, leases and dead-letter status
7. `20260822_000016_call_completed_flow.sql`
   - the `sales.call.completed` Sales Intelligence rule

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

## Asynchronous execution

Agent inference and Action execution run only through durable jobs:

```text
AGENT_RUN -> structured decision -> policy -> ACTION_EXECUTION
```

`cos_jobs` uses an atomic `FOR UPDATE SKIP LOCKED` claim, a worker lease, exponential retry, and `DEAD` after `max_attempts`. The unique organization/idempotency key makes redelivery safe. Run bounded worker batches through `./run queue run`; a process supervisor should invoke it continuously in production.

## CallCompleted vertical slice

```text
completed call activity + sales.call.completed (one transaction)
-> Rule Engine
-> AGENT_RUN job
-> SalesIntelligenceAgent structured decision
-> sales.send_followup Action
-> AUTO policy
-> ACTION_EXECUTION job
-> SendMessageHandler
-> sales.followup.sent
-> audit records sharing one correlation_id
```

The Agent only returns `ActionProposal` values. It has no repository or executor capable of mutating business state.

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

## Verification

`tests/integration/call_completed_flow.php` exercises the complete Event-to-result cycle, including the persisted Decision, with an in-memory database boundary and a fake structured LLM. `tests/smoke/queue_retry.php` verifies that retry exhaustion moves a job to the dead-letter state.

## Minimal operator UI

Managers can open `/cos` to inspect Events, Decisions, proposed Actions, Approvals, execution Results, dead jobs, and Audit records. A Deal card shows its latest AI recommendation, confidence, risk, Action status, and result. `Execute`, `Approve`, and `Reject` are POST-only operations; Execute cannot bypass Policy, and an approved Action plus its `ACTION_EXECUTION` job are persisted in one transaction.
