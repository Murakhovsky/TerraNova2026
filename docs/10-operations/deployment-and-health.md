---
title: Deployment & Health
description: Operational deployment contract, readiness and health verification for COS environments.
status: active
updated: 2026-09-15
kind: operations
---

# Deployment & Health

Deployment вважається завершеним не коли файли опинились на сервері, а коли **schema, runtime, workers, routes і critical health checks узгоджені з одним commit**.

## Deployment sequence

```text
Build / validate commit
        ↓
Apply compatible migrations
        ↓
Deploy application/runtime
        ↓
Start/reload services and workers
        ↓
Health / readiness checks
        ↓
Critical smoke checks
        ↓
Observe errors/queues
```

Exact environment commands можуть відрізнятися; ця сторінка визначає contract, а environment-specific deployment files залишаються executable authority.

## Health levels

### Process health

Процес/container живий. Це найслабший сигнал.

### Dependency readiness

Required DB/runtime dependencies доступні, configuration валідна, migrations сумісні.

### Application health

Canonical health endpoint відповідає і application bootstrap проходить.

### Business smoke

Кілька критичних read/write flows працюють через normal application boundary.

## Worker readiness

Queue/outbox/async consumers мають окремо перевірятися на bootability, lease/heartbeat semantics і backlog/error state. Зелений Web endpoint не доводить, що worker не помер учора ввечері.

## Rollback rule

Rollback application code не повинен автоматично означати blind rollback schema. Migration strategy має враховувати backward compatibility та irreversible data transforms.

## Documentation deployment

Documentation має власний build/deploy pipeline: [Documentation Build](./documentation-build.md). Narrative docs і generated reference повинні відповідати тому самому current commit.

## Related

- [Module Readiness](./module-readiness.md)
- [Data & Migrations](./data-and-migrations.md)
- [Observability & Incident Signals](./observability-and-incidents.md)
