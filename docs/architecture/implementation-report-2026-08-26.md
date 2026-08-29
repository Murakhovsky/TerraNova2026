# COS Kernel implementation report — 2026-08-26

## Outcome

The repository now contains a production-oriented modular-monolith baseline for the complete COS loop:

```text
business state + Event + Outbox (one transaction)
    -> durable Event consumer
    -> deterministic Rule or Agent job
    -> Action proposal
    -> Policy / Approval
    -> durable execution job
    -> Domain handler
    -> MySQL or routed CRM adapter
    -> Result Event + Audit + operational metric
```

Domains extend the Kernel through contracts and `DomainModuleInterface`; they do not inherit from Kernel classes. `Sales` is the first reference Domain and its folder topology is the standard for the next Domains.

## Implemented work

1. **Durable event delivery.** `EventBus` only persists immutable events and outbox rows inside the caller transaction. `OutboxPublisher` claims rows with leases, retries failures, records durable consumer checkpoints, supports dead letters, and exposes coordinated replay.
2. **Tenant isolation.** Organizations and memberships were added. The active organization comes from an authorized session context. Sales repositories, context builders, legacy transition services, read models, CRM mappings, events, actions, and jobs are organization-scoped.
3. **Domain Application layer.** Sales now has explicit ports, DTOs, and transactional use cases (`CompleteSalesCall`, CRM receive/process). Business HTTP services delegate to those use cases instead of reproducing the Kernel loop.
4. **Interfaces and MVC boundary.** COS/Approval/Health/CRM delivery controllers live under `Interfaces`. Controllers call application services/read models, CSRF protects state-changing browser calls, and legacy Phalcon modules remain only as a compatibility delivery layer.
5. **Rules and policies.** Sales owns versioned rule/policy manifests. `ConfigurationValidator` rejects unknown events, actions, agents, operators, or cross-domain ownership. Provisioning writes validated defaults to MySQL, which remains the runtime source. Obsolete example policies were disabled.
6. **External CRM integration.** The outbound gateway routes task, message, follow-up, and Deal update operations by organization. Inbound webhooks use HMAC, payload limits, a durable/idempotent inbox, queue retries, tenant-scoped mappings, and emit only Sales-owned events.
7. **Operations.** SQL migrations have an advisory-lock runner; Docker starts a one-shot migration service before PHP and the worker. The worker drains both outbox and job queue. Structured JSON logs, MySQL metrics, retention CLI, dead-letter replay, and `/api/health` are available. Redis and unused PHP libraries were removed; `composer.lock` is tracked.
8. **Agent hardening.** Context is redacted before persistence and LLM transmission, input snapshots have configurable retention, outputs have strict action/evidence/size limits, and the HTTP client has timeouts, bounded retries, jitter, a circuit breaker, an allowed-action JSON schema, and an untrusted-context boundary.
9. **ClientCase command vertical slice.** All controller-facing ClientCase writes and public inbound case resolution now run through tenant-scoped Sales use cases and persistence ports. The legacy frontend service is a deprecated compatibility facade with no SQL, transaction management, or domain event construction. Create/update, inbound-request synchronization, property matching, and public-lead case creation have fake-repository and real-MySQL regression coverage.
10. **Legacy module integration and frontend readiness.** `app/modules` and its autoload mapping are removed. Active web controllers/views are under `Interfaces/Web`, Spatial delivery is under `Interfaces/Api`, CLI tasks are under `Interfaces/Cli`, and 23 Telegram commands plus webhook/rendering/persistence are canonicalized under `Interfaces/Telegram` and `Infrastructure`. Games is removed. Vite owns all browser source entrypoints, emits a multi-entry manifest, and the SSR layout resolves hashed assets from it. The `/api/v1/properties` contract, stable JSON errors, frontend API client, design tokens and OpenAPI baseline are in place.
11. **Domain boundary audit and Sales readiness.** `Notification` and `Analytics` were removed as ceremonial Domains. Telegram linking is an Identity port, Property notifications remain a Property port, and technical funnel telemetry lives under `Infrastructure/Platform/Analytics`. Sales now has one shared composition root for every interface, a canonical backed-enum vocabulary, a development guide, and architecture tests that prevent Web-only registration or reintroduction of technical pseudo-domains.

## Database state

Migrations `20260826_000017_production_hardening`, `20260826_000018_remove_obsolete_cos_policies`, and `20260826_000019_tenant_public_identifiers` are applied to the local MySQL database. The schema includes organizations/memberships, CRM inbox, configuration provisions, operational metrics, tenant keys, tenant-scoped public identifiers, and Agent retention fields. The default Sales manifest is provisioned with 4 rules and 10 policies.

## Verification performed

- PHP syntax validation: 370 project PHP files passed.
- Architecture, smoke, and in-memory integration scenarios: 13 passed.
- Real MySQL integration: migrations, required tables/columns, tenant-scoped public identifiers, CRM mapping, atomic Event/Outbox persistence, obsolete-policy cleanup, and cross-tenant Deal mutation denial passed.
- Composer manifest and lock file validation passed.
- Native Composer bootstrap passed on PHP 8.2; the Docker PHP 8.3 runtime remains compatible. Docker Compose configuration validation also passed.
- Local health is `ok`: 19 migrations, 0 dead jobs/events/CRM messages, 0-second queue/outbox lag, 4 active rules, and 10 active policies.
- Native HTTP verification passed: `/api/health` returns HTTP 200 with JSON, and an invalid CRM HMAC returns HTTP 401 with JSON.
- The end-to-end reference flow passed: Event -> Rule -> Agent job -> Decision -> Action -> Policy -> execution job -> result Event -> Audit.
- Complete ClientCase command smoke coverage, real-MySQL command repository coverage, frontend DI wiring, authenticated ClientCase page HTTP checks, and the architecture guard for a SQL-free compatibility facade passed.
- Post-migration validation on 2026-08-27: 529/529 PHP files passed syntax validation; 4 architecture suites, 13 smoke suites, and all 9 integration suites passed. Frontend API unit checks and the Vite production build passed. HTTP checks passed for the main page, health, property catalog/featured APIs, Vite manifest, and the explicit 410 boundaries for Games/Economy/Users.
- Final module-removal validation on 2026-08-28: 468/468 PHP files, 5 architecture suites, 13 smoke suites and 9 integration suites passed. Telegram verification loaded 95 canonical types and discovered all 23 commands. Frontend API tests, Vite production build, HTTP 200 checks and the explicit Games/Economy/Users 410 boundaries passed with `app/modules` absent.

## Remaining work, in priority order

1. Run parallel-worker and forced-process-termination tests against MySQL to measure real lease/retry behavior and prove provider-side idempotency under crashes.
2. Implement the first non-AIDA CRM provider adapter, including OAuth/secret rotation, field mapping, rate-limit handling, and contract tests against its sandbox.
3. Add a versioned Sales Intelligence evaluation set with quality, forbidden-action, prompt-injection, latency, and cost thresholds before enabling automatic customer communication.
4. Export metrics/logs to the chosen monitoring stack and add alerts for dead jobs/events, outbox lag, webhook failures, Agent invalid-output rate, and approval age.
5. Add organization administration (invites, role changes, suspension) and API credentials so tenant lifecycle no longer depends on the default environment organization.

The next implementation milestone should be a sandboxed external CRM adapter plus crash/concurrency testing. That validates the two risks the current code intentionally abstracts but cannot prove with in-memory tests: third-party behavior and multi-worker failure timing.
