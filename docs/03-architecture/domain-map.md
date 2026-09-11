---
title: COS Domain Map
description: Карта bounded contexts, platform mechanisms, interfaces та infrastructure boundaries.
status: active
updated: 2026-09-11
kind: architecture
---

# COS Domain Map

## System map

```text
                    ┌──────── Kernel ────────┐
                    │ Event / Rule / Agent   │
                    │ Action / Policy        │
                    │ Approval / Queue       │
                    │ Audit / Tenant         │
                    │ Module / LLM / Ops     │
                    └──────────┬──────────────┘
                               │ contracts
       ┌───────────────┬───────┼─────────┬───────────────┐
       │               │       │         │               │
     Sales         Diagnostic Property  Identity      Content/Spatial
       │               │       │         │               │
       └───────────────┴───────┴─────────┴───────────────┘
                               │ ports
                         Infrastructure
                               │
                 MySQL / CRM / LLM / Media / etc.

Interfaces: Web / API / Telegram / CLI
Bootstrap: composition root
```

## Kernel

Kernel володіє механізмами, а не бізнес-словником.

Він може знати, що існує Action і Policy. Він не повинен знати, що таке «кваліфікований лід» або «модерація квартири».

## Sales

Володіє lead-to-deal lifecycle, pipeline, activities, follow-up, Sales automation, CRM translation та Sales-specific authority/capabilities.

Reference domain для повного COS module/runtime contract.

## Diagnostic

Володіє diagnostic methodology lifecycle, sessions, evidence traceability, deterministic evaluation та diagnostic AI use cases.

Не залежить від Sales model. Sales methodology є data/fixture, а не compile-time dependency.

## Property

Володіє real-estate catalogue/management/submission/moderation/presentation contracts та persistence.

Property не повинен знати implementation details Sales або Web.

## Identity

Відповідає за identity-oriented application/infrastructure boundaries. Поточна структура ще компактна і не має повного module manifest.

## Content

Винесений application/infrastructure area для content workflows/integration.

## Spatial

Винесений application/infrastructure area для 3D/spatial workflows.

## Interfaces

`Web`, `Api`, `Telegram`, `Cli` — delivery adapters. Вони:

- приймають input;
- встановлюють auth/tenant context;
- переводять transport DTO;
- викликають use case/service;
- повертають response/view.

Вони не визначають domain transitions.

## Infrastructure

Infrastructure реалізує ports і технічні механізми: provider clients, persistence, security adapters, observability, LLM transport, media, integration routing.

## Bootstrap

`app/Bootstrap` — composition root. Тільки він має право бачити всі concrete dependencies і збирати їх разом.

## Dependency direction

```text
Kernel         → PHP only
Domain         → Kernel + same Domain
Infrastructure → Domain/Kernel contracts
Interfaces     → exposed application/runtime services
Bootstrap      → all, because it assembles
```

## Критерій нового Domain

Створювати новий Domain варто лише коли є власні бізнес-інваріанти, lifecycle/state, vocabulary, use cases і ownership даних/подій.

Telegram, email, LLM transport, file storage та telemetry самі по собі не є Domains.