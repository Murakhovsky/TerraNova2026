---
title: Готовність модулів
description: Операційна діагностика deployed/installed/schema/dependency стану модулів COS.
status: active
updated: 2026-09-16
kind: operations
---

# Готовність модулів

`ModuleReadinessDiagnostic` дає admin/control-plane view стану модулів без migration side effects у normal activation/runtime path.

Його задача — відповісти на конкретне питання: **чи готовий певний deployed module реально працювати для organization?**

## Що перевіряється

Для кожного module diagnostic зіставляє:

- deployed manifest/version;
- installed module state;
- configured enabled state;
- Kernel/module version compatibility;
- deployed vs installed schema version;
- declared migration files vs applied migrations;
- availability required module dependencies.

Також повертається поточна `KernelVersion`.

## Статуси

Поточні status identifiers є частиною executable contract і не перекладаються:

| Status | Значення |
| --- | --- |
| `UNINSTALLED` | module code deployed, але module не встановлений |
| `UPGRADE_REQUIRED` | installed version/schema state не відповідає current deployed contract |
| `SCHEMA_STATUS_UNAVAILABLE` | migration status не вдалося безпечно прочитати для module з migration requirements |
| `SCHEMA_NOT_READY` | є declared migrations, яких немає серед applied |
| `DEPENDENCY_NOT_READY` | required dependency module недоступний або не enabled |
| `DISABLED` | module встановлений/current, але configuration вимикає його |
| `READY` | installation, version, schema, dependencies та enabled state узгоджені |

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

Більш фундаментальний failure може приховати менш пріоритетну причину в summary status. Детальні поля все одно мають дозволяти побачити versions, missing migrations і dependencies.

## Діагностика schema

Migration status читається один раз на diagnostic request.

Якщо migration backend кидає exception:

- raw database error не повертається control-plane consumer;
- global `schema_status` стає `UNAVAILABLE`;
- modules із migration requirements отримують `SCHEMA_STATUS_UNAVAILABLE` відповідно до пріоритету.

Readiness diagnostic **не запускає migrations**. Діагностика, яка сама лікує production schema під час читання status page, була б уже окремим жанром пригодницької літератури.

## Формат результату

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

## Installed, enabled, active та ready

Ці поняття не можна стискати в одну зелену галочку:

- **installed** — module має installation state;
- **configured_enabled** — organization configuration просить його ввімкнути;
- **current** — installed/deployed contract узгоджений;
- **active** — module реально доступний через effective module resolution;
- **`READY`** — operational diagnostic не бачить version/schema/dependency blockers і module enabled.

UI має показувати різницю між цими станами, бо «OK» є дивовижно неінформативним словом для distributed system.

## Коли використовувати

Readiness view потрібен:

- перед і після deployment;
- після module upgrade;
- після migration rollout;
- коли module visible у code, але недоступний tenant;
- в admin/control center;
- під час incident diagnostics.

Не використовуйте його на кожному звичайному business request. Це operational/control-plane diagnostic, а не request-time authorization mechanism.

## Інваріанти

1. Diagnostic є read-only.
2. Diagnostic не запускає migrations.
3. Raw DB exception не витікає в API output.
4. Module manifest є deployed expectation.
5. Installed state і schema state перевіряються окремо.
6. Dependencies оцінюються через effective module availability.
7. Runtime access усе одно використовує normal module guards, а не кешований readiness report.

## Карта коду

```text
app/Kernel/Module/ModuleReadinessDiagnostic.php
app/Kernel/Module/ModuleCatalog.php
app/Kernel/Module/ActiveModuleResolver.php
app/Kernel/Database/MigrationRunnerInterface.php
app/Interfaces/Api/...
app/Bootstrap/ModuleServices.php
```

## Пов’язані сторінки

- [Розгортання та перевірка стану](./deployment-and-health.md)
- [Дані та міграції](./data-and-migrations.md)
- [Спостережуваність та інциденти](./observability-and-incidents.md)
