# TerraNova COS

TerraNova is a modular-monolith implementation of the Company Operating System kernel. The runtime turns business events into deterministic or agent-assisted decisions, passes every proposed mutation through policy, executes it through a Domain-owned adapter, and records the result.

```text
Business transaction
  -> Event + Outbox
  -> durable consumer
  -> Rule or Agent
  -> Action
  -> Policy (AUTO / APPROVAL_REQUIRED / DENIED)
  -> durable Job
  -> Domain handler
  -> CRM/MySQL adapter
  -> Result Event + Audit + Metric
```

The Kernel contains reusable mechanisms only. `Domains/Sales` supplies Sales vocabulary and use cases through composition; it does not inherit from the Kernel. Phalcon MVC is retained at the HTTP delivery edge under `Interfaces`, while legacy `modules` are migrated incrementally.

The supported runtime baseline is PHP 8.2 or newer with Phalcon 5.9 or newer. The production image currently uses PHP 8.3 and Phalcon 5.19; the same codebase and Composer lock also work with the prepared native PHP 8.2 runtime.

## Production start

1. Copy `.env.docker.example` to `.env.docker` and replace every placeholder/secret.
2. Start the stack:

```bash
docker compose --env-file .env.docker up -d --build
```

The `migrate` one-shot service applies SQL migrations under a MySQL advisory lock before `php` and `worker` start. The worker continuously processes the event outbox and job queue. Redis is intentionally not required: MySQL is the durable source of truth.

Useful endpoints and commands:

```text
GET  /api/health
GET  /cos/control-center
POST /api/integrations/{organization}/crm/{provider}/webhook

php app/bootstrap_cli.php migration status
php app/bootstrap_cli.php config validate default
php app/bootstrap_cli.php config provision default cos-bootstrap
php app/bootstrap_cli.php outbox run
php app/bootstrap_cli.php outbox replay default <event-id>
php app/bootstrap_cli.php queue replayDead default <job-id>
php app/bootstrap_cli.php agent purgeInputs
php app/bootstrap_cli.php worker run
```

For a native development server without Docker, configure the extensions used by the project, apply migrations with `php app/bootstrap_cli.php migration up`, and run `php -S 127.0.0.1:8080 -t public public/router.php`. MySQL remains the only required durable service.

CRM webhooks require `X-CRM-Event-Id` and `X-CRM-Signature: sha256=<HMAC>`. Store the secret in the environment variable referenced by `cos_integrations.credentials_reference` (`env:VARIABLE_NAME`) or in a fallback such as `CRM_WEBHOOK_SECRET_DEFAULT_AIDA`. Providers send canonical external event names (`deal.updated`, `deal.stage_changed`, `lead.updated`, `lead.changed`); the inbound adapter maps them to Domain-owned Sales events instead of trusting callers to name internal events.

## Architecture and verification

- Canonical architecture: [`docs/architecture/cos-kernel.md`](docs/architecture/cos-kernel.md)
- Implementation report: [`docs/architecture/implementation-report-2026-08-26.md`](docs/architecture/implementation-report-2026-08-26.md)
- Automated checks: `tests/architecture`, `tests/smoke`, and `tests/integration`
