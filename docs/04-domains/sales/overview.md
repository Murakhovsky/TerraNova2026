---
title: Sales Domain Overview
description: Межі, структура, runtime contributions і development rules Sales domain.
status: active
updated: 2026-09-11
kind: domain
---

# Sales Domain Overview

Sales — reference bounded context COS і найбільш повна реалізація domain/module pattern.

## Ownership

Sales володіє:

- inbound leads;
- Sales relationship із person;
- client cases/deals;
- pipeline definition/stages/transitions;
- assignments;
- activities;
- follow-ups;
- property matches;
- communication-oriented Sales operations;
- Sales events/rules/agent/actions/policies;
- CRM mapping/synchronization;
- Sales workspace/read models;
- Sales admin capabilities.

Sales не володіє Property listings, generic queue, LLM transport, Telegram transport, auth framework або telemetry.

## Structure

```text
Sales/
├── Model
├── Domain/Policy
├── Application
│   ├── Contract
│   ├── DTO
│   ├── Service
│   ├── Support
│   └── UseCase
├── Automation
│   ├── Event
│   ├── Rule
│   ├── Agent
│   ├── Action
│   ├── Job
│   ├── Integration
│   └── Policy
├── Infrastructure
│   ├── Persistence
│   └── ReadModel
├── Bootstrap
└── module.php
```

## Canonical model

Typed vocabulary включає `PipelineStage`, `ClientCaseStatus`, `ClientCaseType`, `LeadStatus`, `PropertyMatchStatus`, `SalesActivityType`, `SalesPriority`, `SalesCurrency`, communication types та capability enum.

Adapter не має права приносити власний статус і робити його новим внутрішнім стандартом.

## Application layer

Use cases окремо моделюють meaningful operations, а repositories/gateways задаються contracts.

Приклад: `ChangeDealStage` не є `UPDATE deals SET stage=...`; він має пройти transition policy/governance, persistence та event publication.

## Automation layer

Sales має deterministic rules, `SalesIntelligenceAgent`, action handlers, policy catalogs, CRM inbox job handler і Sales-owned events.

Agent runtime — decision support, не persistence API.

## Runtime module

`SalesDomainModule` декларує contributions у Kernel runtime. `module.php` описує installable module metadata.

Sales manifest version: `0.7.1`, kernel constraint: `^0.7.1`.

## Capabilities

Business/UI authority додатково типізована через `SalesCapability`. Повний список див. `12-reference/module-capabilities.md`.

## Read model

Workspace та operational dashboard читають dedicated projections, а не domain write repositories.

## Legacy boundary

`Infrastructure/Persistence/Phalcon/Telegram` — quarantined compatibility adapter для історичних таблиць/моделей. Новий business code туди не додається.

## Definition of done

Sales change має бути tenant-scoped, atomic із Event/Outbox для writes, idempotent для external side effects, policy-gated для Actions і покритий architecture/smoke/integration tests.