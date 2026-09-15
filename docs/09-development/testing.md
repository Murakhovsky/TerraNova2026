---
title: Testing COS
description: Test layers and verification strategy for architecture, domain behavior, integration, frontend and documentation.
status: active
updated: 2026-09-15
kind: how-to
---

# Testing COS

COS використовує кілька test layers. Один зелений endpoint не доводить, що Domain ownership, transaction boundary або idempotency залишилися здоровими.

## Test layers

### Architecture

`tests/architecture` перевіряє dependency direction, module/runtime contracts та version-specific architecture gates.

### Unit / domain behavior

Domain-specific unit scripts перевіряють model invariants, policies, deterministic evaluation і окремі services/use cases.

### Smoke

`tests/smoke` проходить meaningful runtime flows без повного зовнішнього середовища.

### Integration

`tests/integration` потрібен там, де важливі real MySQL semantics, migrations, locking, tenant isolation, transaction/outbox behavior або adapter boundaries.

### Frontend / browser

NPM scripts містять frontend API checks та Sales browser scenario:

```bash
npm run test:frontend
npm run test:sales-browser
```

### Documentation

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

## Minimum verification by change type

| Change | Minimum |
|---|---|
| Domain invariant | unit + relevant smoke |
| Persistence / migration | integration + architecture gate |
| Event / Outbox / Queue | smoke + integration/idempotency path |
| Module manifest / route | architecture + generated reference check |
| Agent / Policy | deterministic policy cases + invalid proposal/evaluation cases |
| UI workflow | frontend/browser + relevant API/use-case test |
| Documentation architecture | `docs:generate:check` + `docs:check` + `docs:build` |

## Failure cases are first-class

Перевіряйте forbidden transitions, duplicate delivery, stale/optimistic-lock writes, policy deny, approval-required path, external failure/retry і tenant boundary. Happy path без failure semantics для COS є лише демонстрацією оптимізму.
