---
title: Середовище розширень
description: Як COS модулі підключають API, UI, event consumers та інші extension surfaces без hardcoded Domain assembly.
status: active
updated: 2026-09-21
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

Kernel визначає стабільні ідентифікатори extension points, але не знає їхніх Web-контрактів.

| Extension point | Споживач | Призначення |
| --- | --- | --- |
| `api.routes` | runtime/router | module-owned route contributions |
| `tenant.configuration` | tenant runtime | module configuration provisioning |
| `event.consumers` | event runtime | module event/outcome consumers |
| `web.navigation` | Web Experience | навігаційні contributions |
| `web.search` | Web Experience | глобальний пошук |
| `web.commands` | Web Experience | command palette |
| `web.workspace` | Web Experience | Workspace definitions |
| `web.workspace.extensions` | Web Experience | slot extensions існуючих Workspace |
| `web.dashboard_widgets` | Web Experience | dashboard widgets |
| `web.entity_links` | Web Experience | canonical entity links |
| `web.notifications` | Web Experience | notification projections |
| `web.activity` | Web Experience | Activity Center projections |
| `web.actions` | Web Experience | unified UI actions |

Актуальний inventory deployed contributions генерується в [Module Extension Points](../12-reference/extension-points.md).

## Середовище Web-провайдерів

Конкретні Web-контракти належать Symfony Web layer:

```text
ModuleExtensionRegistry
        ↓ generic service ids
WebExtensionProviderRegistry
        ↓ one OrganizationModuleSnapshot
WebExtensionProviderSet
        ↓ concrete contract validation
Navigation / Search / Commands / Workspace / ...
```

Один `WebExtensionContext` створює один provider set і один effective module snapshot. Navigation, Commands та інші surfaces у межах однієї композиції не повинні повторно й незалежно визначати module activation.

Перші module-owned providers:

```text
salesNavigationContributor
propertyNavigationContributor
diagnosticNavigationContributor
```

Кожен із них може реалізувати кілька Web-контрактів. Service id у manifest лишається стабільною identity contribution, а consumer перевіряє потрібний interface для конкретного extension point.

Поточні реальні contributions використовують:

- `web.navigation`;
- `web.commands`;
- `web.workspace`.

Інші canonical Web points уже мають contracts і можуть отримувати contributions без зміни Kernel semantics.

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
app/Kernel/Module/ModuleExtensionPoint.php
app/Domains/*/module.php
symfony/src/Web/Experience/Extension/Contract/*
symfony/src/Web/Experience/Extension/WebExtensionProviderRegistry.php
symfony/src/Web/Experience/Extension/WebExtensionProviderSet.php
symfony/src/Web/Experience/Extension/WebExtensionContextCatalog.php
symfony/src/Web/Experience/Extension/Provider/*
```

## Поточна версія

Extension Runtime є частиною поточного Kernel `0.11.9`. Історичний номер версії, в якій механізм вперше з’явився, не використовується як поточний runtime contract.
