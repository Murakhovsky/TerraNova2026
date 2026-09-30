# TerraNova COS

TerraNova COS is a modular-monolith Company Operating System. **Symfony 7.4 is the canonical application runtime**; business code remains framework-independent under `app/`.

The current production baseline is PHP 8.3, MySQL, Symfony Messenger/Scheduler, Twig/UX and the COS Kernel/Domain runtime.

```text
Business transaction
  → Domain use case
  → State change + Event + Outbox
  → Rule / Agent when needed
  → Policy / Approval
  → durable execution
  → Domain-owned port / adapter
  → Result Event + Audit + Metrics
```

## Start

```bash
cp .env.docker.example .env.docker
docker compose --env-file .env.docker up -d --build
docker compose exec php php bin/console cos:schema:migrate
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

Health:

```text
GET /health/dependencies
GET /api/v1/health
```

## Verification

COS V1 uses one verification entrypoint:

```bash
bash bin/verify fast
bash bin/verify smoke
bash bin/verify full
bash bin/verify integration
```

Historical Wave/version tests are retained under `tests/history/` as migration evidence and are **not part of the active suite**.

## Documentation

Canonical documentation lives in `docs/`.

- Current product state: [docs/01-product/current-scope.md](docs/01-product/current-scope.md)
- System architecture: [docs/03-architecture/system-map.md](docs/03-architecture/system-map.md)
- Kernel: [docs/03-architecture/kernel-overview.md](docs/03-architecture/kernel-overview.md)
- Domain map: [docs/03-architecture/domain-map.md](docs/03-architecture/domain-map.md)
- Testing and verification: [docs/09-development/testing.md](docs/09-development/testing.md)
- Operations: [docs/10-operations/deployment-and-health.md](docs/10-operations/deployment-and-health.md)
- Generated executable reference: [docs/12-reference/README.md](docs/12-reference/README.md)
- ADRs: [docs/11-decisions/README.md](docs/11-decisions/README.md)

Historical migration/release documentation is outside the published knowledge surface under `archive/documentation/`.

## Source-of-truth order

```text
current code + tests
        ↓
machine-readable manifests / process definitions
        ↓
generated reference
        ↓
current narrative documentation
        ↓
ADR for durable decisions
```

Git history and `archive/` preserve migration evidence. Active documentation describes the current system, not every step used to reach it.
