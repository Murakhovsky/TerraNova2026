# Persistence architecture

Audit date: 2026-08-28.

## Decision

The project uses PDO as the database driver. Phalcon ActiveRecord has been retired from application persistence.

Every bounded context is a vertical slice:

```text
Domains/<Domain>/Application       use cases and ports
Domains/<Domain>/Model             rules and domain objects
Domains/<Domain>/Infrastructure    PDO repositories, read models, legacy AR mappings
```

Shared technical persistence lives in `Infrastructure/Platform/Persistence`. It contains the PDO connection, transaction/migration support, COS repositories and adapters for shared tables. There is no central `Infrastructure/Persistence/MySql/<every-domain>` tree.

The write path is:

```text
Interface -> Application use case -> owner-defined port -> owner persistence adapter
```

Cross-domain writes always call an explicit port implemented by the owner. Current examples are Property analytics, location resolution, media relation synchronization, Spatial tour publication, integration outbox and external-reference storage.

## Data ownership

`Infrastructure/Platform/Persistence/TableOwnership.php` is the canonical registry. Owners are `Identity`, `Property`, `Sales`, `Content`, `Spatial`, `Media`, `Reference`, and `Platform`. Technical telemetry (`tn_analytics_events`) is Platform-owned; it is not modeled as a standalone business Domain.

- A Domain adapter may write only tables owned by that Domain.
- Reads across tables are temporarily allowed only in dedicated read models. They must remain query-only and are migration seams for future projections/API calls.
- Shared tables are accessed through narrow Platform adapters, not copied into every Domain.
- Transactions use the same injected PDO connection, so owner adapters can participate in the caller transaction without global DI lookups.

`tests/architecture/table_ownership.php` extracts SQL write targets from Domain MySQL adapters and rejects foreign ownership.

## PDO foundation

`Infrastructure/Platform/Persistence/Pdo/PdoConnection` is the low-level connection gateway. Application services and delivery code do not receive it. Concrete MySQL adapters may use PDO and implement narrow contracts.

PDO was selected over Phalcon ActiveRecord for new persistence because dependencies, SQL, tenant predicates, transaction boundaries and locks remain explicit. This does not prohibit Phalcon as the HTTP framework.

## Phalcon ActiveRecord retirement

The final Telegram-only ActiveRecord quarantine was deleted during runtime retirement.

The following trees must not return:

- `Domains/*/Infrastructure/Persistence/Phalcon/Telegram`
- `Infrastructure/Integration/Telegram/ActiveRecord`

Historical Telegram command models were not canonical Domain persistence. Any future Telegram transport must call existing Application ports and PDO-backed adapters rather than restore ActiveRecord mappings.

## Enforcement

- Domain Application/Model code cannot import Infrastructure, Interfaces, Phalcon or PDO.
- MySQL adapters cannot import delivery code or concrete integration services.
- Domain MySQL write ownership is checked automatically.
- Phalcon ActiveRecord must not be restored anywhere in application persistence.
- Old `Infrastructure/Persistence/MySql`, `Infrastructure/Database`, `Infrastructure/ReadModel` and `Infrastructure/Operations` roots cannot be restored.
- Composition is performed only under `Bootstrap`, CLI entry points and tests.

Primary checks: `tests/architecture/persistence_boundaries.php`, `tests/architecture/table_ownership.php`, `tests/architecture/layer_dependencies.php`, and `tests/integration/persistence_di.php`.
