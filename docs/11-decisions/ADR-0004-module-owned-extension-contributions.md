---
title: ADR-0004 — Modules володіють extension contributions, shared layers не hardcode-ять Domains
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

COS Domains мають додавати API routes, tenant configuration, Web navigation, event consumers та інші surfaces. Центральний shared bootstrap із ручним списком `Sales`, `Property`, `Diagnostic`, ... створює compile-time знання про конкретні Domains і робить кожне розширення зміною platform layer.

Generic extension runtime усуває цю залежність.

# Рішення

**Module декларує власні extension contributions через module contract, а shared runtime збирає їх через `ModuleExtensionRegistry`.**

Канонічний pattern:

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

Поточні extension points включають:

- `api.routes`;
- `tenant.configuration`;
- `event.consumers`;
- `web.navigation`.

Точний список і contributors генерує [Module Extension Points](../12-reference/extension-points.md).

Kernel знає extension point + service id, але не hardcode-ить implementation конкретного Domain.

`cross_domain_contracts` є окремим механізмом і не вважається extension point: він описує allowed Domain-to-Domain boundaries, а не підключення shared surface.

# Обґрунтування

Це дає реальну modularity усередині modular monolith:

- новий Domain може додати surface через manifest/contribution;
- Web/API/config consumers не потребують `if sales` / `if property`;
- module activation залишається tenant-aware;
- extension contracts можна перевіряти окремо від business semantics;
- shared layer не росте разом із кількістю Domains.

# Розглянуті альтернативи

## Центральні arrays у Bootstrap/Web

Відхилено: прості спочатку, але кожен Domain змінює shared assembly і створює hidden coupling.

## Framework plugin/module mechanism як основна extension model

Відхилено: COS module lifecycle має працювати однаково для Web, API, workers і configuration, а не залежати від одного delivery framework.

## Service discovery за naming convention

Відхилено: implicit discovery складніше валідовується і робить ownership неявним.

# Наслідки

Позитивні:

- module-owned surfaces;
- менше hardcoded Domain knowledge у shared layers;
- один extension mechanism для різних consumers;
- легше enable/disable modules per organization.

Вартість:

- extension point names стають довгоживучими contracts;
- service ids/contributions мають бути валідними;
- consumer має чітко визначити activation/authorization semantics.

# Сумісність і міграція

Hardcoded Domain contributor assembly у shared Web/API/bootstrap code має мігрувати до module contributions.

Existing global route registration допускається лише там, де runtime guards та ownership не створюють паралельну систему доступності.

Cross-domain interactions мігрують не в extension registry, а в `cross_domain_contracts` або Event/read-projection boundaries.

# Перевірка

`ModuleExtensionRegistry` має:

- збирати built-in і module-defined extension points;
- відхиляти invalid extension point names;
- відхиляти duplicate contribution для одного `moduleId + extensionPoint + serviceId`;
- не містити business-specific branching.

Architecture/CI guards не повинні дозволяти повернення hardcoded Domain navigation/route assembly у shared layers.

# Пов’язані матеріали

- `docs/03-architecture/extension-runtime.md`
- `docs/03-architecture/cross-domain-contracts.md`
- `docs/08-ui/navigation-and-permissions.md`
- `app/Kernel/Module/ModuleExtensionRegistry.php`
