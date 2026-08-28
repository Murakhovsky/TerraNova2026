# Legacy entrypoint inventory

Inventory date: 2026-08-26. This file is the migration ledger for externally reachable entrypoints. An entry may be removed only after its callers and replacement are verified.

| Entrypoint | Runtime | Owner | Decision | Replacement / condition |
|---|---|---|---|---|
| `public/index.php` | Web | Platform | keep | canonical HTTP front controller |
| `public/router.php` | Local web | Platform | keep | PHP built-in-server router |
| `public/tgAdmin_webhook.php` | Telegram | Sales/Operations | keep | stable URL routed through `Interfaces\\Telegram\\Controller\\WebhookController` |
| `public/games.php` | Games | Games | removed | product and dedicated entrypoint removed 2026-08-28 |
| `public/webtools.php` | DevTools | none | removed | Phalcon DevTools is not a Composer dependency and must not be public |
| `app/bootstrap_web.php` | Web | Platform | keep | composition root for public web/API |
| `app/bootstrap_tg.php` | Telegram | Platform | keep | canonical Telegram composition with `Interfaces\\Telegram\\Module` |
| `app/bootstrap_games.php` | Games | Games | removed | Games runtime removed 2026-08-28 |
| `app/bootstrap_cli.php` | CLI/worker | Platform | keep | target bootstrap for `Interfaces/Cli` |
| `bin/telegram-webhook.php` | Telegram | Operations | keep | explicit webhook runner |
| `bin/telegram-worker.php` | Telegram | Operations | keep | supervised worker |
| `bin/integration-worker.php` | integrations | Operations | keep | durable integration processing |
| `bin/spatial-worker.php` | Spatial | Spatial | keep | migrate implementation behind Spatial application port |
| `bin/apply-migration.php` | deployment | Platform | keep | migration runner wrapper |

## Registered web module owners

| Module | Current registration | Target |
|---|---|---|
| `frontend` | `Interfaces\Web\Module` | canonical web controllers/routes/views |
| `spatial` | `Bootstrap\SpatialModule` | canonical Spatial contracts/infrastructure with `Interfaces\Api\Controller\SpatialController` |
| `users` | not registered in main web | Identity contracts and canonical Phalcon adapters |
| `games` | removed | `/games` remains an explicit HTTP 410 boundary |
| `economy` | removed | `/economy` remains an explicit HTTP 410 boundary |

## Public directory policy

Only front controllers, generated immutable browser assets, curated static assets, and configured runtime upload mounts may be reachable under `public`. Developer tools, secrets, environment configuration, business services and source assets are forbidden.

`public/uploads/**` is ignored for all new runtime data. Historical media already tracked by Git is retained to avoid destructive removal during the architecture migration; deployment must mount/persist this path and a separately approved data migration may untrack the historical files after backup verification.
