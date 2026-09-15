---
title: Diagnostic Contracts & Code Map
description: Diagnostic repositories, methodology engine, target-neutral boundaries and runtime code map.
status: active
updated: 2026-09-15
kind: domain
---

# Diagnostic Contracts & Code Map

## Dependency model

```text
API / Interfaces
      ↓
Application Use Cases
      ↓
Diagnostic Model + Methodology
      ↓
Application Contracts
      ↑
Infrastructure repositories
```

Target Domain integrations повинні входити через neutral identifiers/contracts. Diagnostic не отримує прямої залежності на Sales aggregate лише тому, що methodology діагностує Sales.

## Main surfaces

| Surface | Responsibility |
|---|---|
| `Model/` | packs, sessions, records, invariants, policies |
| `Methodology/` | loading, validation, deterministic evaluation |
| `Application/DTO` | immutable operation inputs |
| `Application/Contract` | pack/session repository ports |
| `Application/UseCase` | lifecycle orchestration |
| `Automation/Event` | Diagnostic-owned facts |
| `Infrastructure/` | tenant-scoped persistence |
| `Bootstrap/` | runtime module contribution |

## Runtime contributions

Current module contributes `diagnosticDomainModule`, API route contribution, `diagnosticActionOutcomeHandler`, Web navigation and Diagnostic migrations.

## Persistence guarantees

Published methodology must remain reproducible. Session persistence therefore keeps tenant scope, exact pack/version identity, canonical methodology hash and optimistic locking/concurrency guarantees.

## Code root

```text
app/Domains/Diagnostic/
```

## Exact executable facts

- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Module Routes](../../12-reference/module-routes.md)
- [Event Types](../../12-reference/event-types.md)
