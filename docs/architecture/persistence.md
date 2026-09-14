# Persistence architecture

Audit date: 2026-08-28.

## Decision

The project uses PDO as the database driver. Phalcon ActiveRecord is a quarantined compatibility adapter for the remaining legacy Telegram flow; it is not the application persistence API.

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

## Phalcon ActiveRecord quarantine

Remaining AR mappings are located only under:

- `Domains/Identity/Infrastructure/Persistence/Phalcon/Telegram`
- `Domains/Property/Infrastructure/Persistence/Phalcon/Telegram`
- `Domains/Sales/Infrastructure/Persistence/Phalcon/Telegram`
- `Infrastructure/Integration/Telegram/ActiveRecord` for shared abstract bases

Duplicate mappings of `estate_objects_disabled` were consolidated into `ObjectsDisabled`. New ActiveRecord models are forbidden. Existing Telegram command callers are the final compatibility surface and are migrated behind command/query gateways incrementally.

## Enforcement

- Domain Application/Model code cannot import Infrastructure, Interfaces, Phalcon or PDO.
- MySQL adapters cannot import delivery code or concrete integration services.
- Domain MySQL write ownership is checked automatically.
- ActiveRecord cannot exist outside the explicit Telegram quarantine.
- Old `Infrastructure/Persistence/MySql`, `Infrastructure/Database`, `Infrastructure/ReadModel` and `Infrastructure/Operations` roots cannot be restored.
- Composition is performed only under `Bootstrap`, CLI entry points and tests.

Primary checks: `tests/architecture/persistence_boundaries.php`, `tests/architecture/table_ownership.php`, `tests/architecture/layer_dependencies.php`, and `tests/integration/persistence_di.php`.
