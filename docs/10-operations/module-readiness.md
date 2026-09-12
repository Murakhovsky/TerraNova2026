---
title: Module Readiness
description: Operational diagnostics для deployed/installed/schema/dependency стану COS modules.
status: active
updated: 2026-09-12
kind: operations
---

# Module Readiness

`ModuleReadinessDiagnostic` дає admin/control-plane view стану модулів без додавання migration side effects у normal activation/runtime path.

Його задача — відповісти: **чи готовий конкретний deployed module реально працювати для organization?**

## Що перевіряється

Для кожного module diagnostic зіставляє:

- deployed manifest/version;
- installed module state;
- configured enabled state;
- kernel/module version compatibility;
- deployed vs installed schema version;
- declared migration files vs applied migrations;
- availability module dependencies.

Також повертається поточна `KernelVersion`.

## Статуси

Поточні statuses мають конкретну семантику:

| Status | Meaning |
| --- | --- |
| `UNINSTALLED` | module code deployed, але module не встановлений |
| `UPGRADE_REQUIRED` | installed version/schema state не відповідає current deployed contract |
| `SCHEMA_STATUS_UNAVAILABLE` | migration status не вдалося безпечно прочитати для module з migration requirements |
| `SCHEMA_NOT_READY` | є declared migrations, яких немає серед applied |
| `DEPENDENCY_NOT_READY` | required dependency module недоступний/не enabled |
| `DISABLED` | module встановлений/current, але configuration вимикає його |
| `READY` | installation, version, schema, dependencies і enabled state узгоджені |

## Порядок класифікації

AS-IS classification має пріоритет:

```text
UNINSTALLED
→ UPGRADE_REQUIRED
→ SCHEMA_STATUS_UNAVAILABLE
→ SCHEMA_NOT_READY
→ DEPENDENCY_NOT_READY
→ DISABLED
→ READY
```

Це означає, що один більш фундаментальний failure може приховати менш пріоритетні причини в summary status. Детальні поля все одно дозволяють побачити versions, missing migrations і dependencies.

## Schema diagnostics

Migration status читається один раз на diagnostic request.

Якщо migration backend кидає exception:

- raw database error не повертається control-plane consumer;
- global `schema_status` стає `UNAVAILABLE`;
- modules із migration requirements отримують `SCHEMA_STATUS_UNAVAILABLE` за відповідного пріоритету.

Readiness diagnostic **не запускає migrations**.

## Output shape

Верхній рівень містить:

```text
organization_id
kernel_version
schema_status
modules[]
```

Для module доступні щонайменше:

```text
id
status
active
installed
configured_enabled
current
installed_version
deployed_version
installed_schema_version
deployed_schema_version
missing_migrations[]
unavailable_dependencies[]
```

## Active vs enabled vs ready

Ці поняття не треба змішувати:

- **installed** — module має installation state;
- **configured_enabled** — organization configuration просить його ввімкнути;
- **current** — installed/deployed contract узгоджений;
- **active** — module реально доступний через effective module resolution;
- **READY** — operational diagnostic не бачить version/schema/dependency blockers і module enabled.

UI не повинен перетворювати всі ці поля в одну зелену галочку. Людство й без того достатньо шкоди завдало статусом «OK».

## Коли використовувати

Readiness view потрібен:

- перед/після deployment;
- після module upgrade;
- після migration rollout;
- коли module visible у code, але недоступний tenant;
- у admin control center;
- для incident diagnostics.

Не треба викликати його на кожному звичайному business request. Це operational/control-plane diagnostic, не request-time authorization mechanism.

## Invariants

1. Diagnostic є read-only.
2. Diagnostic не запускає migrations.
3. Raw DB exception не витікає в API output.
4. Module manifest є deployed expectation.
5. Installed state і schema state перевіряються окремо.
6. Dependencies оцінюються через effective module availability.
7. Runtime access усе одно має використовувати normal module guards, а не кешований readiness report.

## Code map

```text
app/Kernel/Module/ModuleReadinessDiagnostic.php
app/Kernel/Module/ModuleCatalog.php
app/Kernel/Module/ActiveModuleResolver.php
app/Kernel/Database/MigrationRunnerInterface.php
app/Interfaces/Api/...
app/Bootstrap/ModuleServices.php
```
