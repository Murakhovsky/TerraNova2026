---
title: ADR-0004 — Modules own extension contributions; shared layers do not hardcode Domains
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

COS Domains мають додавати API routes, tenant configuration, Web navigation та інші surfaces. Центральний shared bootstrap із ручним списком `Sales`, `Property`, `Diagnostic`, ... створює compile-time знання про конкретні Domains і робить кожне розширення зміною platform layer.

Kernel V0.9 ввів generic extension runtime.

# Decision

**Module декларує власні extension contributions через module contract, а shared runtime збирає їх через `ModuleExtensionRegistry`.**

Поточний pattern:

```text
module.php / ModuleContributions
    ↓
ModuleCatalog
    ↓
ModuleExtensionRegistry
    ↓
extension point consumer
    ↓
service resolution
    ↓
organization/module guard where required
```

Стандартні extension points включають:

- `api.routes`;
- `tenant.configuration`;
- `web.navigation` як module-owned custom extension.

Kernel знає extension point + service id, але не hardcode-ить implementation конкретного Domain.

# Rationale

Це дає справжню modularity всередині modular monolith:

- новий Domain може додати surface через manifest/contribution;
- Web/API/config consumers не потребують `if sales`/`if property`;
- module activation залишається tenant-aware;
- extension contracts можна перевіряти окремо від business semantics.

# Alternatives considered

## Central arrays у Bootstrap/Web

Відхилено: прості спочатку, але кожен Domain змінює shared assembly і створює hidden coupling.

## Framework plugin/module mechanism як основна extension model

Відхилено: COS module lifecycle має працювати однаково для Web, API, workers і configuration, а не залежати від одного delivery framework.

## Service discovery за naming convention

Відхилено: implicit discovery гірше контролюється, складніше валідовується і робить ownership неявним.

# Consequences

Позитивні:

- module-owned surfaces;
- менше hardcoded Domain knowledge у shared layers;
- один extension mechanism для різних consumers;
- легше enable/disable modules per organization.

Вартість:

- extension point names стають довгоживучими contracts;
- service ids/contributions мають бути валідними;
- consumer повинен чітко визначити activation/authorization semantics.

# Compatibility / Migration

Hardcoded Domain contributor assembly у shared Web/API/bootstrap code має мігрувати до module contributions. Existing global route registration може залишатися, якщо request-time module guard не дозволяє використати surface для неактивного module.

# Verification

`ModuleExtensionRegistry`:

- збирає standard і custom extension points;
- відхиляє invalid extension point names;
- відхиляє duplicate contribution для того самого module/service;
- не містить business-specific branching.

Architecture/CI guards мають не дозволяти повертати hardcoded Domain navigation/route assembly у shared layers.

# Related

- `docs/03-architecture/extension-runtime.md`
- `docs/08-ui/interface-surfaces.md`
- `app/Kernel/Module/ModuleExtensionRegistry.php`
