---
title: ADR-0006 — Deployed modules і tenant activation є різними станами
description: Рішення відокремити факт deployment модуля від його встановлення, активації та readiness для tenant.
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

У multi-tenant COS наявність module code у deployment не означає, що module встановлений, current, enabled або operationally ready для конкретної organization.

Якщо discovery, installation, configuration і request-time availability звести до одного boolean `enabled`, deployment та tenant lifecycle стають нерозрізненими. Це особливо небезпечно під час upgrades і schema migrations.

# Рішення

**Deployed module catalog і tenant-specific activation/lifecycle є окремими станами.**

Модель розрізняє щонайменше:

```text
deployed      module definition є в ModuleCatalog
installed     існує installation state для organization
current       installed code/schema versions узгоджені з deployed manifest
configured    tenant configuration просить module enable
active        effective resolver дозволяє module з урахуванням dependencies
ready         operational diagnostic не бачить schema/version/dependency blockers
```

`ActiveModuleResolver` визначає request-time availability. `ModuleReadinessDiagnostic` є read-only control-plane diagnostic і не запускає migrations.

# Обґрунтування

Розділення потрібне, щоб:

- deploy code без автоматичного ввімкнення capability для всіх tenants;
- робити staged installation/upgrade;
- бачити version/schema drift;
- не запускати migrations у звичайному request path;
- коректно блокувати module, якщо dependency недоступний;
- підтримувати compatibility semantics для історичних tenants.

# Розглянуті альтернативи

## Модуль існує в коді = модуль активний

Відхилено: неможливі tenant-specific enablement та безпечні upgrades.

## Один `enabled` flag

Відхилено: він не відрізняє configuration, installation, version currentness, schema readiness і dependencies.

## Auto-migrate під час activation/request

Відхилено: migration I/O та schema mutation не повинні бути hidden side effect normal runtime access.

# Наслідки

Позитивні:

- безпечніший deployment lifecycle;
- зрозуміла operational diagnostics;
- tenant-level control;
- dependency-aware activation.

Вартість:

- більше lifecycle states;
- UI/admin мають показувати їх окремо;
- deployment tooling має синхронізувати manifest, installation і migrations.

# Сумісність і міграція

Для pre-lifecycle tenants compatibility behavior може відрізнятися від нових installations, але нові control-plane features мають описувати стан явно.

Readiness diagnostic використовується для пояснення operational state, тоді як runtime authorization/access завжди проходить normal module resolver/guards.

# Перевірка

Readiness classification розрізняє:

- `UNINSTALLED`;
- `UPGRADE_REQUIRED`;
- `SCHEMA_STATUS_UNAVAILABLE`;
- `SCHEMA_NOT_READY`;
- `DEPENDENCY_NOT_READY`;
- `DISABLED`;
- `READY`.

Diagnostic не виконує migrations, не повертає raw DB errors і не замінює runtime module guard.

# Пов’язані матеріали

- `docs/10-operations/module-readiness.md`
- `docs/03-architecture/extension-runtime.md`
- `app/Kernel/Module/ActiveModuleResolver.php`
- `app/Kernel/Module/ModuleReadinessDiagnostic.php`
