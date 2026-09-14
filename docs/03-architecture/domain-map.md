---
title: COS Domain Map
description: Карта installable Domains, supporting areas, Kernel та delivery/infrastructure boundaries.
status: active
updated: 2026-09-14
kind: architecture
---

# COS Domain Map

Ця карта описує **AS-IS гілки `COS`**. Documentation source живе в `main`; executable authority — у `COS`.

## System map

```text
                         Kernel 0.11.8
        Event / Rule / Agent / Action / Policy / Queue / Audit
                                  │
                                  │ runtime contracts
                                  ▼
          ┌───────────────────────┼───────────────────────┐
          │                       │                       │
        Sales                 Diagnostic              Property
       0.8.6                    0.5.4                  0.1.1
   reference runtime        partial runtime        partial runtime
          │                       │                       │
          └───────────────────────┼───────────────────────┘
                                  │ ports/contracts
                                  ▼
                           Infrastructure
                    MySQL / CRM / LLM / Media / etc.

Supporting bounded areas: Identity / Content / Spatial
Interfaces: Web / API / Telegram / CLI
Bootstrap: composition root
```

## Installable Domain vs directory

У COS важливо розрізняти:

```text
Domain directory
≠
installable module
≠
fully integrated runtime Domain
```

`Sales`, `Diagnostic` і `Property` мають `module.php` та потрапляють у generated module reference.

`Identity`, `Content`, `Spatial` фізично відокремлені як bounded areas, але наразі не мають такого ж installable/runtime contract.

## Kernel

Kernel володіє **механізмами**, а не бізнес-семантикою.

Він може знати про:

- Event;
- Rule;
- Agent;
- Action;
- Policy;
- Approval;
- Queue;
- Audit;
- Tenant;
- Module;
- LLM;
- Observability.

Kernel не повинен знати, що таке qualified lead, diagnostic finding чи property moderation.

## Sales

Sales володіє lead-to-deal operational lifecycle:

```text
Lead
→ Client Case / Deal
→ Pipeline
→ Activities / Follow-up
→ Outcome
```

Також Sales володіє своїми events, rules, agent/actions/policies, CRM translation, workspace/read models і Sales authority.

Sales є reference implementation повного COS module/runtime pattern.

## Diagnostic

Diagnostic володіє evidence-based diagnostic lifecycle:

```text
Methodology
→ Session
→ Evidence
→ Facts / Metrics
→ Evaluation
→ Findings / Hypotheses
→ Recommendations
```

Diagnostic AI допомагає extraction/interpretation, але deterministic scoring не повинен перетворювати LLM output на недоторканну істину.

## Property

Property у поточному COS володіє real-estate application boundary:

- catalogue;
- management;
- submission;
- moderation;
- media;
- presentation;
- location references;
- analytics contracts;
- Sales-facing presentation integration.

Property уже виділений у власний bounded context, але module/runtime maturity нижча за Sales: manifest має WEB navigation contribution, але не має runtime module service чи capability catalogue.

## Supporting areas

### Identity

Identity-oriented application/infrastructure boundary.

### Content

Content workflows та integration boundary.

### Spatial

Spatial/3D application/infrastructure boundary.

Їх не слід автоматично прирівнювати до installable Domains лише через те, що в filesystem уже є красиві директорії. Файлова система, на щастя, ще не отримала право проектувати архітектуру.

## Interfaces

`Web`, `API`, `Telegram`, `CLI` — delivery adapters.

Вони можуть:

- приймати input;
- встановлювати auth/tenant context;
- переводити transport DTO;
- викликати application/runtime service;
- повертати response/view.

Вони не визначають domain ownership і business transitions.

## Infrastructure

Infrastructure реалізує technical adapters і ports:

```text
Persistence
Provider clients
LLM transport
Media
Security
Observability
Integration
```

## Bootstrap

`app/Bootstrap` — composition root. Саме тут concrete implementations збираються у working application.

## Dependency direction

```text
Kernel         → generic PHP/platform contracts
Domain         → Kernel + same Domain
Infrastructure → Domain/Kernel contracts
Interfaces     → exposed application/runtime services
Bootstrap      → concrete assembly
```

## Машинна карта

Точні versions, capabilities, migrations і extension contributions не дублюємо вручну. Для цього є:

- [Module and Capability Reference](../12-reference/module-capabilities.md)
- [Module Extension Points](../12-reference/extension-points.md)
- [Application Use Cases](../12-reference/application-use-cases.md)
- [Event Types](../12-reference/event-types.md)
- [Command DTO Reference](../12-reference/commands.md)

## Критерій нового Domain

Окремий Domain виправданий, коли з'являються власні:

- vocabulary;
- invariants;
- lifecycle/state;
- use cases;
- data ownership;
- event ownership;
- authority boundaries.

Telegram, email transport, file storage або telemetry самі по собі Domains не утворюють.
