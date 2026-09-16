---
title: Життєвий цикл модулів
description: Виявлення, встановлення, активація та внески доменних модулів COS.
status: active
updated: 2026-09-16
kind: runtime
---

# Життєвий цикл модулів

Domain module (доменний модуль) у COS є одиницею середовища виконання з manifest, capabilities, contributions і власним lifecycle. Це не Phalcon module і не просто директорія з кодом.

## Компоненти Kernel

`app/Kernel/Module` містить, зокрема:

- `DomainModuleInterface`;
- `DomainModuleRegistry`;
- `ModuleManifest`;
- `ModuleDiscovery`;
- `ModuleCatalog`;
- `ModuleInstallation`;
- `ModuleLifecycleManager`;
- `ActiveModuleResolver`;
- `ModuleCapabilityRegistry`;
- `ModuleContributions`;
- `ModuleDefinition`;
- `VersionConstraint`;
- `KernelVersion`.

## Життєвий цикл

```text
code/package exists
   ↓
Discovery
   ↓
Manifest validation
   ↓
Catalog
   ↓
Install
   ↓
Activate for organization
   ↓
Resolve active module
   ↓
Register contributions/capabilities
   ↓
Runtime routing
```

Деактивація або видалення модуля повинні симетрично забирати його доступність у runtime, не видаляючи історичні аудиторські чи бізнесові дані, якщо політика міграцій прямо не визначає інше.

## Маніфест

Manifest є машинозчитуваним контрактом модуля. Він має описувати щонайменше:

- ідентичність модуля;
- версію;
- сумісну версію Kernel;
- залежності;
- capabilities;
- вимоги до конфігурації та provisioning;
- entrypoint або module definition.

Runtime не повинен визначати сумісність за назвою папки чи гарним настроєм автора.

## Внески

Активний Domain module може реєструвати в Kernel:

- власність на Events;
- власність на Actions;
- handlers;
- rules;
- policies;
- agents;
- context builders;
- capabilities.

Contributions повинні мати однозначного власника там, де неоднозначність зробила б маршрутизацію небезпечною.

## Активація для окремої організації

`ActiveModuleResolver` принципово важливий для SaaS-моделі COS: наявність коду модуля в deployment не означає, що модуль активний для кожної organization.

```text
Installed globally
≠
Enabled for tenant
```

Це дозволяє різним компаніям мати різні набори бізнесових можливостей без форків коду.

## Реєстр можливостей

UI та інші modules повинні запитувати систему про capability, а не вгадувати її за існуванням route або class.

Добре:

```text
module sales active
+ capability sales.pipeline.read
→ show workspace
```

Погано:

```text
class_exists(SalesController::class)
→ мабуть функція доступна
```

## Сумісність версій

Версії Module і Kernel мають перевірятися до активації.

Несумісний module повинен завершитися помилкою на межі lifecycle, а не випадковим `Call to undefined method` через три запити після deployment.

## Додавання Domain

Стандартний маршрут:

```text
1. Domains/<Name>/Domain + Model
2. Application contracts/use cases
3. Automation events/rules/agents/actions/policies
4. Infrastructure adapters
5. Bootstrap DomainModule implementation
6. module.php / manifest
7. register/discover
8. install
9. activate for organization
10. verify capabilities and runtime ownership
```

## Наслідок для UI

Майбутній менеджер модулів має бути проєкцією `ModuleCatalog + ModuleLifecycleManager + ActiveModuleResolver`, а не окремим списком прапорців зі своєю паралельною логікою.

Саме тут реалізується потрібна модель: Domain можна підключити або вимкнути як системний модуль, а UI, runtime та API бачать один і той самий стан.

## Інваріант

> Ні Kernel, ні Interface не повинні мати жорстко закодованого знання, що конкретний Domain «завжди існує».
