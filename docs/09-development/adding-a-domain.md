---
title: Adding a Domain
description: Практична інструкція створення нового bounded context у COS.
status: active
updated: 2026-09-15
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
8. Якщо Domain pluggable/installable — додати `DomainModuleInterface` implementation + `module.php`.
9. Для installable Domain додати щонайменше один canonical Process Registry workflow або явний process-coverage exemption.
10. Зареєструвати concrete dependencies у `app/Bootstrap`, не у Web module.
11. Додати architecture/smoke/integration tests і пройти documentation gates.

## module.php

Manifest має визначати id, version, schema version, Kernel constraint, dependencies, default activation та contributions.

Contributions можуть включати runtime module service, job handlers, API route contributors, configuration provisioners, migrations, capabilities.

Сам факт появи `module.php` робить Domain installable для documentation coverage gate. Папка Domain без manifest лишається supporting/non-installable area і не отримує process requirement автоматично.

## Canonical process coverage

Installable Domain не вважається knowledge-complete лише тому, що його class-и компілюються. Він має бути пов'язаний із реальним бізнес-процесом через Process Registry.

```text
module.php
   ↓
installable Domain
   ↓
Process Registry domain ownership
   ↓
Workflow + capability/runtime evidence
```

Нормальний шлях — створити canonical workflow через [Adding a Workflow](adding-a-workflow.md).

Якщо Domain свідомо не має process model, exception реєструється в:

```text
docs/.vitepress/process-coverage-exemptions.json
```

Exemption мусить назвати `domain`, відповідального `owner` і змістовний `reason`. Це architecture exception, а не спосіб пройти CI до обіду. Коли process з'являється, exemption стає stale і checker його відхиляє.

Поточне покриття: [Domain Process Coverage](../12-reference/domain-process-coverage.md).

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

Process Registry `domain` також не може посилатися на неіснуючий installable Domain. Supporting area спочатку має отримати свідомий module boundary, якщо її справді потрібно зробити installable.

## Definition of done

Domain boundary зрозуміла, tenant scope explicit, writes atomic із events де потрібно, external side effects idempotent, capability/access rules визначені, installable Domain має canonical process coverage або явний exemption, architecture/documentation tests не дозволяють випадковий dependency чи knowledge leak.
