---
title: ADR-0006 — Deployed module discovery is separate from tenant activation
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

У multi-tenant COS наявність module code у deployment не означає, що module встановлений, current, enabled або operationally ready для конкретної organization.

Якщо discovery, installation, configuration і request-time availability звести до одного boolean `enabled`, deployment та tenant lifecycle стають нерозрізненими. Це особливо небезпечно під час upgrades і schema migrations.

# Decision

**Deployed module catalog і tenant-specific activation/lifecycle є окремими станами.**

Модель розрізняє щонайменше:

```text
deployed      module definition є в ModuleCatalog
installed     існує/допускається installation state для organization
current       installed code/schema versions узгоджені з deployed manifest
configured    tenant configuration просить module enable
active        effective resolver дозволяє module з урахуванням dependencies
ready         operational diagnostic не бачить schema/version/dependency blockers
```

`ActiveModuleResolver` визначає request-time availability. `ModuleReadinessDiagnostic` є read-only control-plane diagnostic і не запускає migrations.

# Rationale

Розділення потрібне, щоб:

- deploy code без автоматичного ввімкнення feature для всіх tenants;
- робити staged installation/upgrade;
- бачити version/schema drift;
- не запускати migrations у звичайному request path;
- коректно блокувати module, якщо dependency недоступний;
- підтримувати pre-lifecycle tenants через explicit compatibility semantics.

# Alternatives considered

## Module exists in code = module active

Відхилено: неможливі tenant-specific enablement та безпечні upgrades.

## Один `enabled` flag

Відхилено: він не відрізняє configuration, installation, version currentness, schema readiness і dependencies.

## Auto-migrate під час activation/request

Відхилено: migration I/O та schema mutation не повинні бути hidden side effect normal runtime access.

# Consequences

Позитивні:

- безпечніший deployment lifecycle;
- зрозуміла operational diagnostics;
- tenant-level control;
- dependency-aware activation.

Вартість:

- більше lifecycle states;
- UI/admin повинні показувати їх окремо;
- deployment tooling має синхронізувати manifest, installation і migrations.

# Compatibility / Migration

Для pre-lifecycle tenants відсутність explicit installation record може трактуватися як deployment-current compatibility state, доки tenant не отримав explicit lifecycle record. Це compatibility behavior, а не модель для нових installations.

Нові control-plane features повинні використовувати readiness diagnostic для пояснення стану, але runtime authorization/access завжди використовує normal module resolver/guards.

# Verification

Readiness classification розрізняє:

- `UNINSTALLED`;
- `UPGRADE_REQUIRED`;
- `SCHEMA_STATUS_UNAVAILABLE`;
- `SCHEMA_NOT_READY`;
- `DEPENDENCY_NOT_READY`;
- `DISABLED`;
- `READY`.

Diagnostic не виконує migrations і не повертає raw DB errors.

# Related

- `docs/10-operations/module-readiness.md`
- `docs/03-architecture/extension-runtime.md`
- `app/Kernel/Module/ActiveModuleResolver.php`
- `app/Kernel/Module/ModuleReadinessDiagnostic.php`
