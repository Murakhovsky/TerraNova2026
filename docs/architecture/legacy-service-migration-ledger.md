# Legacy service migration ledger

Audit date: 2026-08-28.

## Result

`app/modules` has been removed after all production callers were migrated. `Modules\\` and `Common\\` are absent from Composer and the Phalcon loader. The remaining physical `app/common` files are outside autoload and have no production callers.

| Legacy area | Implementations moved to | Compatibility state |
|---|---|---|
| `modules/frontend/services` | `Domains/Sales/Application`, `Interfaces/Web/Service`, `Interfaces/Web/Page`, `Infrastructure/Persistence/MySql`, `Infrastructure/ReadModel/MySql`, `Infrastructure/Integration/N8n` | legacy files removed |
| `modules/spatial/services` | `Domains/Spatial/Application/Contract`, `Infrastructure/Persistence/MySql/Spatial`, `Infrastructure/Media`, `Infrastructure/Security`, `Infrastructure/Spatial` | legacy files removed |
| `modules/TgAdmin` | `Interfaces/Telegram`, `Infrastructure/Integration/Telegram`, `Infrastructure/Persistence/Phalcon` | 23 commands discoverable; legacy module removed |
| `modules/Users` | `Domains/Identity`, `Infrastructure/Identity`, `Infrastructure/Persistence/Phalcon/Identity` | legacy module removed |
| `modules/economy/services` | — | removed: no supported runtime flow or database schema |
| `modules/Games` | — | removed by product decision |
| `common/services` | `Infrastructure/Database/Connection`, `Infrastructure/Media`, `Infrastructure/Integration/Telegram`, `Infrastructure/Identity`, `Infrastructure/Framework` | no production caller or autoload mapping |

`Infrastructure/Legacy` is now an inactive quarantine with no production callers. It is outside the completed module migration and can be physically removed in a separately authorized cleanup.

## Delivery boundaries

- All former `modules/frontend/controllers` classes now live in `Interfaces/Web/Controller`; `FrontendRoutes` targets only the canonical namespace.
- Spatial HTTP delivery now lives in `Interfaces/Api/Controller/SpatialController`, composed by `Bootstrap/SpatialModule`.
- `Interfaces/Cli/Task` owns the CLI tasks and dispatcher namespace.
- No `Modules/*` class is registered or autoloadable.

## Browser assets

All former `public/js` and `public/css` source files live in `resources/frontend`. Vite builds nine entrypoints, writes hashed files and `.vite/manifest.json` under `public/build`, and the SSR layout resolves scripts and styles through `ViteAssetManifest`.

## Verification contract

- `tests/architecture/layer_dependencies.php` protects canonical layer direction.
- `tests/architecture/legacy_service_facades.php` protects the legacy service boundary.
- `tests/architecture/telegram_migration.php` loads the migrated Telegram surface and verifies command discovery.
- `tests/architecture/frontend_assets.php` verifies every browser entrypoint and generated manifest file.
- Integration coverage exercises ClientCase, Content/N8n, Spatial, Telegram automation, MySQL and HTTP composition.
