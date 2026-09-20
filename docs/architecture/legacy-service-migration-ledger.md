# Legacy service migration ledger

Audit date: 2026-08-28.

## Result

`app/modules` and `app/common` have been removed after all production callers were migrated. `Modules\\` and `Common\\` are absent from Composer and the Phalcon loader.

| Legacy area | Implementations moved to | Compatibility state |
|---|---|---|
| `modules/frontend/services` | `Domains/*/Application`, `Interfaces/Web/Service`, `Interfaces/Web/Page`, `Infrastructure/Persistence/MySql`, `Infrastructure/Persistence/MySql/ReadModel`, `Infrastructure/Integration/N8n` | legacy files removed |
| `modules/spatial/services` | `Domains/Spatial/Application/Contract`, `Infrastructure/Persistence/MySql/Spatial`, `Infrastructure/Media`, `Infrastructure/Security`, `Infrastructure/Spatial` | legacy files removed |
| `modules/TgAdmin` | outbound `Infrastructure/Integration/Telegram` notification channel | inbound Longman/Phalcon runtime and command tree removed |
| `modules/Users` | `Domains/Identity`, `Infrastructure/Identity`, `Infrastructure/Persistence/Phalcon/Identity` | legacy module removed |
| `modules/economy/services` | — | removed: no supported runtime flow or database schema |
| `modules/Games` | — | removed by product decision |
| `common/services` | `Infrastructure/Persistence/MySql/Database/Connection`, `Infrastructure/Media`, `Infrastructure/Integration/Telegram`, `Infrastructure/Identity`, `Infrastructure/Framework` | legacy files removed |

MySQL repositories, query projections and generic Kernel persistence are consolidated under PDO/MySQL adapters. The final Telegram/Identity Phalcon ActiveRecord quarantine has been removed. See `persistence.md` for the responsibility map and enforced boundaries.

## Delivery boundaries

- All former `modules/frontend/controllers` classes now live in `Interfaces/Web/Controller`; `FrontendRoutes` targets only the canonical namespace.
- Spatial API delivery lives in Symfony `App\\Http\\Api\\Spatial\\SpatialController`, and Spatial Web delivery lives in `App\\Web\\Spatial\\SpatialPageController`. The Phalcon `Bootstrap\\SpatialModule` is retired; remaining Property SSR receives only the framework-neutral Spatial scene compatibility services from `WebApplicationServices` until Property Web itself is cut over.
- Symfony Console owns operational/admin CLI commands; the Phalcon `Interfaces/Cli` surface is retired.
- No `Modules/*` class is registered or autoloadable.

## Browser assets

All former `public/js` and `public/css` source files live in `frontend`. Vite builds nine entrypoints, writes hashed files and `.vite/manifest.json` under `public/build`, and the SSR layout resolves scripts and styles through `ViteAssetManifest`.

## Verification contract

- `tests/architecture/layer_dependencies.php` protects canonical layer direction.
- `tests/architecture/persistence_boundaries.php` protects Domain-owned persistence ports and the ActiveRecord quarantine.
- `tests/architecture/legacy_service_facades.php` protects the legacy service boundary.
- `tests/architecture/telegram_migration.php` prevents restoration of the retired Phalcon inbound bot while preserving framework-neutral outbound automation.
- `tests/architecture/frontend_assets.php` verifies every browser entrypoint and generated manifest file.
- Integration coverage exercises ClientCase, Content/N8n, Spatial, Telegram automation, MySQL and HTTP composition.


### Final Web retirement slices

- Architecture Explorer delivery: Symfony `App\\Web\\Visualization\\ArchitecturePageController`.
- Diagnostic Methodology Studio/report delivery: Symfony `App\\Web\\Diagnostic\\DiagnosticPageController`.
- Spatial manager/editor/public viewer delivery: Symfony `App\\Web\\Spatial\\SpatialPageController`.
- The old Phalcon route/controller files for these surfaces are deleted and guarded against restoration.
