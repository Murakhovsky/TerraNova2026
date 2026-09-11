---
title: Adding a Domain
description: Практична інструкція створення нового bounded context у COS.
status: active
updated: 2026-09-11
kind: how-to
---

# Adding a Domain

Новий Domain створюється не тому, що з'явилась нова таблиця або сторінка.

## Коли потрібен Domain

Має бути більшість із цього:

- власний business vocabulary;
- власні invariants;
- state/lifecycle;
- meaningful use cases;
- ownership даних;
- власні business events;
- окремі policies/automation;
- потреба в незалежній activation/configuration.

## Standard path

```text
app/Domains/<Name>/
├── Model
├── Application
│   ├── Contract
│   ├── DTO
│   └── UseCase
├── Automation       # лише якщо є automation responsibility
├── Infrastructure  # domain-owned adapters
├── Bootstrap        # якщо domain contributes runtime module
└── module.php       # якщо installable module
```

Не створюйте порожні папки «для архітектури».

## Кроки

1. Визначити ownership і те, чого Domain **не** володіє.
2. Описати vocabulary/value objects/enums.
3. Виділити application use cases.
4. Визначити smallest outbound ports у `Application/Contract`.
5. Реалізувати domain-owned adapters в `Infrastructure`.
6. Якщо state change важливий для інших процесів — створити Domain Event.
7. Якщо потрібна automation — додати Rules/Agent/Actions/Policies.
8. Якщо Domain pluggable — додати `DomainModuleInterface` implementation + `module.php`.
9. Зареєструвати concrete dependencies у `app/Bootstrap`, не у Web module.
10. Додати architecture/smoke/integration tests.

## module.php

Manifest має визначати id, version, schema version, Kernel constraint, dependencies, default activation та contributions.

Contributions можуть включати runtime module service, job handlers, API route contributors, configuration provisioners, migrations, capabilities.

## Versioning

Kernel compatibility перевіряється executable `KernelVersion` + `VersionConstraint`. Commit label не є contract version.

## Dependency rule

```text
Domain → Kernel contracts + same Domain
Domain -X-> concrete Infrastructure
Domain -X-> Web/Telegram controllers
Domain -X-> PDO/Phalcon framework APIs у core logic
```

## Cross-domain relation

Domain A не читає таблиці Domain B «бо так швидше». Потрібен explicit application contract, integration event/read projection або інший declared boundary.

## Definition of done

Domain boundary зрозуміла, tenant scope explicit, writes atomic із events де потрібно, external side effects idempotent, capability/access rules визначені, architecture tests не дозволяють випадковий dependency leak.