---
title: Перенесення читання Sales
description: "Перша хвиля другої фази міграції: перенесення канонічних сценаріїв читання Sales у Symfony API v1 без дублювання даних."
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перенесення читання Sales

## Мета

Wave 1 переносить основні Sales read scenarios у канонічний Symfony runtime. Бізнес-дані та Sales projection не дублюються: Symfony споживає `SalesWorkspaceReadModelInterface`, а поточний MySQL залишається за Infrastructure adapter boundary.

## Канонічний шлях

```text
HTTP /api/v1/sales/*
        ↓
SalesReadController
        ↓
QueryBusInterface
        ↓
Sales Application Query Handler
        ↓
SalesWorkspaceReadModelInterface
        ↓
MysqlSalesWorkspaceReadModel
        ↓
legacy MySQL (read-only)
```

Controller не бачить PDO, SQL або legacy connection. Application handlers не залежать від Symfony чи Infrastructure.

## Сценарії Wave 1

| Scenario | Symfony API |
| --- | --- |
| Sales dashboard | `GET /api/v1/sales/dashboard` |
| Lead list | `GET /api/v1/sales/leads` |
| Lead detail | `GET /api/v1/sales/leads/{id}` |
| Opportunity list | `GET /api/v1/sales/opportunities` |
| Opportunity workspace | `GET /api/v1/sales/opportunities/{id}` |
| Pipelines | `GET /api/v1/sales/pipelines` |

Lead detail includes the Lead projection, recent Lead activities, linked Opportunity and available communications.

Opportunity workspace combines the canonical Opportunity/ClientCase projection with its timeline.

## Pagination і sorting

Lead та Opportunity lists use bounded `page` / `per_page`. Application handlers request `per_page + 1` records and return `has_more`, avoiding a mandatory full `COUNT(*)` on every operational read.

SQL sorting remains whitelist-only inside the Infrastructure read model. User input is never concatenated directly into an arbitrary column or direction.

## Ізоляція орендарів

Organization scope comes only from `TenantContext`. The client cannot select an organization through URL or query parameters.

Wave 1 integration gates seed multiple organizations and verify:

- tenant-owned Lead and Opportunity are readable;
- foreign Lead and Opportunity resolve as not found;
- pipeline lists do not expose another tenant;
- unauthenticated access remains blocked by Symfony Security.

## Сумісність

Existing `/api/sales/*` and current frontend remain unchanged during this wave. Wave 1 creates the versioned read path required for later frontend cutover. Old endpoints are retired only after their consumers move to `/api/v1/sales/*`.

## Критерії завершення

Wave 1 is complete when:

- all six read scenarios pass through Symfony QueryBus;
- no Symfony Controller/Application handler contains SQL or direct legacy access;
- pagination/filter/sorting behavior is bounded;
- tenant isolation is verified against real MySQL fixtures;
- architecture, application, Docker integration and existing regression gates are green.
