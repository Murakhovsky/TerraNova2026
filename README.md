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

The Kernel contains reusable mechanisms only. `Domains/Sales` supplies Sales vocabulary and use cases through composition; it does not inherit from the Kernel. Symfony is the canonical runtime for business and control-plane APIs. Phalcon is retained only for the remaining server-rendered/compatibility surfaces while those are retired slice by slice.

The supported runtime baseline is PHP 8.2 or newer with Phalcon 5.9 or newer. The production image currently uses PHP 8.3 and Phalcon 5.19; the same codebase and Composer lock also work with the prepared native PHP 8.2 runtime.

## Production start

1. Copy `.env.docker.example` to `.env.docker` and replace every placeholder/secret.
2. Start the compatibility/SSR stack:

```bash
docker compose --env-file .env.docker up -d --build
```

3. Start the canonical Symfony API runtime after the compatibility stack exists:

```bash
bash deploy/symfony-dev.sh
```

The compatibility stack remains responsible only for the shrinking SSR surface and the framework-neutral schema bootstrap. Canonical business/control-plane HTTP under `/api/v1/*`, Kernel queue/outbox processing, Spatial processing, n8n outbox processing, Messenger and Scheduler run on Symfony. Host Nginx routes canonical APIs to Symfony and leaves only the remaining web surface on the compatibility host. Database schema changes remain deployment-owned; the Symfony application account has DML privileges only.

Useful endpoints and commands:

```text
GET  /api/v1/health
GET  /cos/control-center
POST /api/integrations/{organization}/crm/{provider}/webhook

php bin/migrate.php status
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:legacy-schema:status
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:spatial:process --limit=10
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:integration:n8n:process --schedule-content --limit=25

# Temporary manual compatibility CLI, pending the next retirement slice:
php app/bootstrap_cli.php config validate default
php app/bootstrap_cli.php config provision default cos-bootstrap
php app/bootstrap_cli.php outbox run
php app/bootstrap_cli.php outbox replay default <event-id>
php app/bootstrap_cli.php queue replayDead default <job-id>
php app/bootstrap_cli.php agent purgeInputs
```

For a native development server without Docker, configure the extensions used by the project, apply migrations with `php bin/migrate.php up`, and run `php -S 127.0.0.1:8080 -t public public/router.php`. MySQL remains the only required durable service.

CRM webhooks require `X-CRM-Event-Id` and `X-CRM-Signature: sha256=<HMAC>`. Store the secret in the environment variable referenced by `cos_integrations.credentials_reference` (`env:VARIABLE_NAME`) or in a fallback such as `CRM_WEBHOOK_SECRET_DEFAULT_AIDA`. Providers send canonical external event names (`deal.updated`, `deal.stage_changed`, `lead.updated`, `lead.changed`); the inbound adapter maps them to Domain-owned Sales events instead of trusting callers to name internal events.

## Architecture and verification

- Canonical architecture: [`docs/architecture/cos-kernel.md`](docs/architecture/cos-kernel.md)
- Domain boundary audit: [`docs/architecture/domain-boundaries.md`](docs/architecture/domain-boundaries.md)
- Diagnostic domain model and methodology contract: [`docs/architecture/diagnostic-domain-model.md`](docs/architecture/diagnostic-domain-model.md)
- Sales development guide: [`app/Domains/Sales/README.md`](app/Domains/Sales/README.md)
- Implementation report: [`docs/architecture/implementation-report-2026-08-26.md`](docs/architecture/implementation-report-2026-08-26.md)
- Automated checks: `tests/architecture`, `tests/smoke`, and `tests/integration`
