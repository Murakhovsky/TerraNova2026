---
title: Середовище розширень
description: Як COS модулі підключають API, UI, event consumers та інші extension surfaces без hardcoded Domain assembly.
status: active
updated: 2026-09-16
kind: architecture
---

# Середовище розширень

Extension Runtime потрібен, щоб shared runtime не знав наперед усі Domains і всі поверхні, які вони можуть розширювати.

Його принцип: **модуль декларує contribution, Kernel реєструє ownership, concrete consumer layer отримує service через contract**.

## Навіщо це потрібно

Без extension model shared bootstrap швидко перетворюється на список:

```text
if Sales → add Sales navigation
if Property → add Property navigation
if Diagnostic → add Diagnostic navigation
...
```

Це працює, доки система маленька. Потім кожен новий Domain змушує змінювати Kernel/Web bootstrap, і «модульність» стає декоративною наклейкою.

## Канонічний flow

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

Kernel зберігає тільки generic extension identity:

- `moduleId`;
- `extensionPoint`;
- `serviceId`.

Він не повинен імпортувати Web/Sales/Property-specific implementation types.

## Поточні extension points

Поточний generated registry містить:

| Extension point | Тип | Призначення |
| --- | --- | --- |
| `api.routes` | built-in | module-owned API/Web route contributors |
| `tenant.configuration` | built-in | module configuration provisioning |
| `event.consumers` | module-defined | runtime consumers domain events/outcomes |
| `web.navigation` | module-defined | module-owned navigation contributions |

Актуальний список і contributors генерується в [Module Extension Points](../12-reference/extension-points.md).

## Поточні contributors

`api.routes`:

```text
propertyRouteContributor
```

Diagnostic HTTP/SSR routes, like Sales, are canonical in Symfony router and no longer use the Phalcon `api.routes` contribution.

`event.consumers`:

```text
diagnosticActionOutcomeHandler
salesHistoricalEventConsumer
```

`tenant.configuration`:

```text
propertyModuleConfigurationProvisioner
salesModuleConfigurationProvisioner
```

`web.navigation`:

```text
diagnosticNavigationContributor
propertyNavigationContributor
salesNavigationContributor
```

Sales HTTP/SSR routes після фінального cutover належать Symfony router напряму й тому більше не є Phalcon `api.routes` extension contribution.

Точний inventory не потрібно дублювати вручну поза generated reference; тут важлива архітектурна семантика.

## ModuleExtensionRegistry

`Kernel\Module\ModuleExtensionRegistry` будується з `ModuleCatalog` і збирає contributions deployed modules.

Registry дозволяє:

- отримати contributions для одного extension point;
- перелічити доступні extension points;
- отримати повну карту extensions;
- відхилити duplicate contribution одного `moduleId + extensionPoint + serviceId`;
- перевірити синтаксис extension point identifier.

Формат extension point:

```text
^[a-z][a-z0-9_.:-]*$
```

## Deployment не дорівнює activation

Registry описує **deployed contributions**, але це не означає, що кожний contribution активний для кожної organization.

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

Тобто code discovery та tenant activation є різними станами.

## Хто перевіряє concrete contract

Kernel не повинен знати `ModuleNavigationContributorInterface` або інший surface-specific interface.

```text
Kernel registry
    ↓ generic service id
Web / runtime bootstrap
    ↓ resolve service
Consumer layer
    ↓ validate concrete interface
```

Generic registration належить Kernel. Concrete semantics належать layer, який extension споживає.

## Cross-domain contracts не є extension points

`cross_domain_contracts` у module manifest вирішують іншу задачу: вони декларують дозволені synchronous/integration boundaries між Domains.

```text
extension_services       = хто розширює shared surface
cross_domain_contracts   = який Domain contract requires/provides інший Domain
```

Не потрібно змішувати ці механізми лише тому, що обидва живуть у `ModuleContributions`.

## Коли створювати новий extension point

Новий extension point виправданий, якщо:

1. кілька незалежних modules можуть підключати одну surface;
2. shared layer не повинен знати список Domains;
3. contribution можна описати stable service contract;
4. enable/disable module має впливати на доступність contribution;
5. extension не є прихованим способом перенести business logic у Kernel.

Не створюйте extension point для кожного callback. Інакше замість архітектури вийде телефонна книга dependency injection.

## Інваріанти

- Module володіє декларацією contribution.
- Kernel володіє registry mechanics.
- Consumer layer володіє concrete interface.
- Tenant activation перевіряється окремо від deployment discovery.
- Extension registry не виконує business mutations.
- Background runtime не залежить від Web/session context для resolution contribution semantics.
- Domain-specific branching у shared Kernel/Bootstrap має зменшуватися, а не маскуватися іншою назвою.

## Карта коду

```text
app/Kernel/Module/ModuleContributions.php
app/Kernel/Module/ModuleExtensionContribution.php
app/Kernel/Module/ModuleExtensionRegistry.php
app/Kernel/Module/CrossDomainContract.php
app/Bootstrap/ModuleServices.php
app/Bootstrap/WebApplicationServices.php
app/Interfaces/Web/Navigation/ModuleAwareNavigationService.php
app/Domains/*/module.php
```

## Поточна версія

Extension Runtime є частиною поточного Kernel `0.11.9`. Історичний номер версії, в якій механізм вперше з’явився, не використовується як поточний runtime contract.
