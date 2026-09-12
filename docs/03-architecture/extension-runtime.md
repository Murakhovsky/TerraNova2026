---
title: Extension Runtime
description: Як COS модулі підключають API, UI та інші extension surfaces без hardcoded Domain assembly.
status: active
updated: 2026-09-12
kind: architecture
---

# Extension Runtime

Extension Runtime з'явився в Kernel V0.9, щоб shared runtime не знав наперед усі Domains і всі поверхні, які вони можуть розширювати.

Його задача проста: **модуль декларує contribution, Kernel реєструє ownership, concrete layer споживає service через contract**.

## Навіщо це потрібно

Без extension model shared bootstrap швидко перетворюється на список:

```text
if Sales → add Sales navigation
if Property → add Property navigation
if Diagnostic → add Diagnostic navigation
...
```

Це працює, доки система маленька. Потім кожен новий Domain змушує змінювати Kernel/Web bootstrap, і «модульність» стає декоративною наклейкою.

Extension Runtime прибирає цю залежність.

## Основний flow

```text
Domain/module.php
    ↓
ModuleContributions
    ↓
ModuleCatalog
    ↓
ModuleExtensionRegistry
    ↓
extension point lookup
    ↓
Bootstrap resolves service id
    ↓
consumer validates concrete interface
    ↓
request/runtime use
```

Kernel зберігає тільки:

- `moduleId`;
- `extensionPoint`;
- `serviceId`.

Він не повинен імпортувати Web/Sales/Property-specific implementation types.

## Поточні extension points

Kernel має два нормалізовані built-in extension points:

- `api.routes`;
- `tenant.configuration`.

Generic `extension_services` дозволяє модулю оголосити інші точки розширення. Поточний реальний приклад:

- `web.navigation`.

Sales, Property і Diagnostic декларують свої Web navigation services у власних `module.php` manifests.

## ModuleExtensionRegistry

`Kernel\\Module\\ModuleExtensionRegistry` будується з `ModuleCatalog` і збирає contributions усіх deployed modules.

Registry дозволяє:

- отримати всі contributions для одного extension point;
- перелічити доступні extension points;
- отримати повну карту extensions;
- відхилити duplicate contribution того самого `moduleId + extensionPoint + serviceId`;
- перевірити синтаксис extension point identifier.

Формат extension point:

```text
^[a-z][a-z0-9_.:-]*$
```

## Deployment ≠ activation

Registry описує **deployed capabilities**, але це не означає, що кожен module contribution активний для кожної organization.

Наприклад Web navigation додатково перевіряє `ActiveModuleResolver` у request time:

```text
extension exists
    ↓
organization request
    ↓
module enabled?
    ├─ no  → contribution ignored
    └─ yes → contribution used
```

Це важлива різниця між code discovery і tenant activation.

## Хто перевіряє concrete contract

Kernel не повинен знати `ModuleNavigationContributorInterface`.

Тому схема така:

```text
Kernel registry
    ↓ generic service id
Web bootstrap
    ↓ resolve service
Web layer
    ↓ validate ModuleNavigationContributorInterface
```

Те саме правило діє для майбутніх extension points: generic registration у Kernel, concrete semantics у layer, який extension споживає.

## Коли створювати новий extension point

Новий extension point виправданий, якщо:

1. кілька незалежних модулів можуть підключати одну й ту саму surface;
2. shared layer не повинен знати список Domains;
3. contribution можна описати stable service contract;
4. enable/disable модуля має впливати на доступність contribution;
5. extension не є прихованим способом перенести business logic у Kernel.

Не треба створювати extension point для кожного callback. Інакше замість архітектури вийде телефонна книга з dependency injection.

## Поточні invariants

- Module володіє декларацією contribution.
- Kernel володіє registry mechanics.
- Consumer layer володіє concrete interface.
- Tenant activation перевіряється окремо від deployment discovery.
- Extension registry не виконує business mutations.
- Domain-specific branching у shared Kernel/Bootstrap має зменшуватися, а не маскуватися іншою назвою.

## Code map

```text
app/Kernel/Module/ModuleContributions.php
app/Kernel/Module/ModuleExtensionContribution.php
app/Kernel/Module/ModuleExtensionRegistry.php
app/Bootstrap/ModuleServices.php
app/Bootstrap/WebApplicationServices.php
app/Interfaces/Web/Navigation/ModuleAwareNavigationService.php
app/Domains/*/module.php
```

## Версія

Extension Runtime введений у **Kernel V0.9** і є частиною поточного Kernel `0.10.0`.
