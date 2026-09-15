---
title: Sales Contracts & Code Map
description: Application ports, infrastructure boundaries, runtime composition and code map for Sales.
status: active
updated: 2026-09-15
kind: domain
---

# Sales Contracts & Code Map

## Dependency direction

```text
Interfaces
   ↓
Application Use Cases
   ↓
Application Contracts ← Domain Model
   ↑
Infrastructure adapters
```

Sales business rules не повинні залежати від Web, Telegram, конкретного CRM provider або Phalcon ActiveRecord.

## Main surfaces

| Surface | Responsibility |
|---|---|
| `Model/` | typed vocabulary та business invariants |
| `Application/DTO` | immutable input/output across boundaries |
| `Application/Contract` | repositories, gateways та outbound ports |
| `Application/UseCase` | transactional orchestration |
| `Automation/Event` | Sales-owned facts |
| `Automation/Rule` | deterministic reactions |
| `Automation/Agent` | proposal-producing agent definitions |
| `Automation/Action` | controlled delegation to application ports |
| `Automation/Policy` | AUTO / APPROVAL_REQUIRED / DENIED |
| `Infrastructure/` | persistence, CRM adapters, read models |
| `Bootstrap/` | runtime contribution registration |

## Composition root

Common Sales services compose in:

```text
app/Bootstrap/SalesServices.php
```

Web, API, CLI, Telegram і workers повинні resolve ті самі use cases, а не створювати окрему business logic per interface.

## Legacy compatibility boundary

`Infrastructure/Persistence/Phalcon/Telegram` залишається quarantined compatibility adapter для історичних `request_*` records. Нові business rules, rendering чи Telegram behavior туди не додаються.

## Cross-domain contracts

Sales може reference Property, але не модифікує Property storage. Generic runtime mechanisms отримуються від Kernel. External CRM sync реалізується adapter-ами за Sales-owned contracts.

## Exact executable facts

- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Module Routes](../../12-reference/module-routes.md)
- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Events](../../12-reference/event-types.md)

## Code root

```text
app/Domains/Sales/
app/Bootstrap/SalesServices.php
```
