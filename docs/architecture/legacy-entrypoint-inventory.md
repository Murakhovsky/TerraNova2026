# Legacy entrypoint inventory

Inventory date: 2026-09-19. This file is the migration ledger for externally reachable entrypoints. An entry may be removed only after its callers and replacement are verified.

| Entrypoint | Runtime | Owner | Decision | Replacement / condition |
|---|---|---|---|---|
| `public/index.php` | Web | Platform | keep | canonical HTTP front controller |
| `public/router.php` | Local web | Platform | keep | PHP built-in-server router |
| `public/tgAdmin_webhook.php` | Telegram | Operations | tombstone | HTTP 410 only; stale webhook configurations cannot boot application code |
| `public/games.php` | Games | Games | removed | product and dedicated entrypoint removed 2026-08-28 |
| `public/webtools.php` | DevTools | none | removed | Phalcon DevTools is not a Composer dependency and must not be public |
| `app/bootstrap_web.php` | Web | Platform | keep | composition root for public web/API |
| `app/bootstrap_tg.php` | Telegram | Platform | removed | legacy inbound bot runtime retired |
| `app/bootstrap_games.php` | Games | Games | removed | Games runtime removed 2026-08-28 |
| `app/bootstrap_cli.php` | CLI | Platform | removed | Symfony Console owns operational/admin commands |
| `bin/telegram-webhook.php` | Telegram | Operations | removed | inbound bot/webhook retired |
| `bin/telegram-worker.php` | Telegram | Operations | removed | Symfony `cos:telegram:notifications:process` owns outbound delivery |
| `bin/migrate.php` | deployment | Platform | keep | framework-neutral schema migration bootstrap for compatibility stack |
| `bin/integration-worker.php` | integrations | Operations | removed | Symfony `cos:integration:n8n:process` + `integration-worker` service |
| `bin/spatial-worker.php` | Spatial | Spatial | removed | Symfony `cos:spatial:process` + `spatial-worker` service |
| `bin/apply-migration.php` | deployment | Platform | removed | `bin/migrate.php` / Symfony `cos:legacy-schema:migrate` |

## Retired Phalcon API transports

The following HTTP/control-plane surfaces have completed cutover and must not return to the Phalcon router:

| Retired transport | Canonical replacement | Status |
|---|---|---|
| `/api/health` + `Interfaces\\Api\\Controller\\HealthController` | Symfony `GET /api/v1/health` | removed |
| `/api/platform/modules*` + `PlatformModuleController`/`PlatformRoutes` | Symfony `/api/v1/platform/modules*` | removed |
| `/api/admin/diagnostics/*` + legacy Methodology/Workbench controllers | Symfony `/api/v1/admin/diagnostics/*` | removed |
| legacy Diagnostic runtime HTTP controller | Symfony `/api/v1/diagnostics/*` | removed |
| legacy Property runtime/canonical HTTP controllers | Symfony `/api/v1/properties*` and inventory APIs | removed |
| legacy COS Operations/migration APIs | Symfony `/api/v1/operations/*` and canonical health/runtime endpoints | removed |

`Interfaces\\Api\\Controller\\SpatialController` is retired. `/api/spatial/*` upload/token/job delivery is owned by Symfony; only temporary SSR Spatial/Web compatibility remains on Phalcon.

## Registered web module owners

| Module | Current registration | Target |
|---|---|---|
| `frontend` | `Interfaces\Web\Module` | SSR/public web shell only; business/control-plane APIs are moving to Symfony |
| `spatial` | `Bootstrap\SpatialModule` | temporary SSR composition only; `/api/spatial/*` is canonical on Symfony |
| `users` | not registered in main web | Identity contracts and canonical Phalcon adapters |
| `games` | removed | `/games` remains an explicit HTTP 410 boundary |
| `economy` | removed | `/economy` remains an explicit HTTP 410 boundary |

## Public directory policy

Only front controllers, generated immutable browser assets, curated static assets, and configured runtime upload mounts may be reachable under `public`. Developer tools, secrets, environment configuration, business services and source assets are forbidden.

`public/uploads/**` is ignored for all new runtime data. Historical media already tracked by Git is retained to avoid destructive removal during the architecture migration; deployment must mount/persist this path and a separately approved data migration may untrack the historical files after backup verification.


## Retired standalone Phalcon-DI scripts

The following operator/runtime helpers no longer bootstrap a Phalcon DI container:

| Retired script | Canonical owner |
|---|---|
| `bin/sales-monitoring.php` | Symfony Scheduler + `cos:sales:automation:scan` |
| `bin/architecture-graph-smoke.php` | Symfony `cos:architecture:smoke` |
| `bin/import-sales-methodology-v02.php` | Symfony `cos:diagnostic:sales-methodology:import-v02` |
| `deploy/sales-monitoring.cron.example` | removed; Scheduler owns the five-minute automation cadence |

Framework-neutral build/health utilities remain valid when they do not compose application runtime through Phalcon.
