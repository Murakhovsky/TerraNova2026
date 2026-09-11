---
title: Repository Map
description: Карта актуальної структури COS branch.
status: active
updated: 2026-09-11
kind: reference
---

# Repository Map

Ця карта описує **гілку `COS`**, а не legacy `main` application.

## Верхній рівень

```text
app/
├── Kernel/
├── Domains/
├── Infrastructure/
├── Interfaces/
├── Bootstrap/
└── config/

docs/
tests/
bin/
```

## `app/Kernel`

Kernel містить generic runtime mechanisms:

```text
Kernel/
├── Action/
├── Agent/
├── Approval/
├── Audit/
├── Configuration/
├── Database/
├── Event/
├── Llm/
├── Module/
├── Observability/
├── Operations/
├── Policy/
├── Queue/
├── Rule/
├── Tenant/
└── Transaction/
```

Правило: Kernel не залежить від бізнес-Domains, Infrastructure, Interfaces, Phalcon або PDO.

## `app/Domains`

Business bounded contexts.

Sales є reference implementation і має структуру:

```text
Domains/Sales/
├── Domain/
├── Model/
├── Application/
│   ├── Contract/
│   ├── DTO/
│   └── UseCase/
├── Automation/
│   ├── Event/
│   ├── Rule/
│   ├── Agent/
│   ├── Action/
│   ├── Job/
│   └── Policy/
├── Infrastructure/
├── Bootstrap/
├── module.php
└── README.md
```

Domain володіє бізнес-семантикою й outbound ports.

## `app/Infrastructure`

Concrete adapters для Kernel та Domain contracts:

- shared persistence;
- analytics / telemetry;
- CRM integrations;
- messaging integrations;
- LLM providers;
- logging / observability;
- інші technical adapters.

Infrastructure може залежати від Kernel і Domain contracts. Зворотна залежність заборонена.

## `app/Interfaces`

Delivery layer:

```text
Interfaces/
├── Web/
├── Api/
├── Telegram/
├── Cli/
└── ...
```

Interface приймає зовнішній request, формує command/use-case input і повертає response/view model.

Controller не є місцем для SQL, policies, domain transitions або provider selection.

## `app/Bootstrap`

Composition root. Єдиний рівень, якому дозволено знати всі concrete dependencies.

Типова відповідальність:

- створити infrastructure adapters;
- зібрати Domain modules;
- зареєструвати module contributions;
- зібрати Kernel runtimes;
- під'єднати Interfaces.

## `Kernel/Module`

Це окрема важлива зона, бо вона робить Domains installable/runtime-manageable.

Ключові компоненти:

- `DomainModuleInterface`;
- `DomainModuleRegistry`;
- `ModuleManifest`;
- `ModuleDiscovery`;
- `ModuleCatalog`;
- `ModuleLifecycleManager`;
- `ModuleInstallation`;
- `ActiveModuleResolver`;
- `ModuleCapabilityRegistry`;
- `VersionConstraint`;
- `KernelVersion`.

## `docs/architecture`

Тут уже лежать детальні технічні документи, зокрема:

- `cos-kernel.md`;
- `domain-boundaries.md`;
- `persistence.md`;
- `diagnostic-domain-model.md`;
- `frontend-interface.md`;
- legacy migration plans та ADRs.

Новий верхньорівневий `/docs` не замінює їх, а дає систему навігації й mental model.

## Як знайти код за бізнес-процесом

Не починайте з пошуку випадкового `Service`.

```text
Business capability
 → Domain
 → Application/UseCase або Automation
 → Kernel mechanism
 → outbound Contract
 → Infrastructure implementation
 → Interface
```

Наприклад:

```text
Sales follow-up
 → Domains/Sales/Automation
 → Action / Policy / Agent
 → Kernel/Action + Kernel/Policy
 → MessageGatewayInterface
 → Infrastructure messaging/CRM adapter
```

Це набагато швидше, ніж читати 400 PHP-файлів у надії, що один із них раптом пояснить сенс життя.
