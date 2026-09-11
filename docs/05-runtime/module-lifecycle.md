---
title: Module Lifecycle
description: Discovery, installation, activation і contributions Domain modules.
status: active
updated: 2026-09-11
kind: runtime
---

# Module Lifecycle

COS Domain module — це runtime unit з manifest, capabilities, contributions та lifecycle. Це не Phalcon module і не просто директорія з кодом.

## Реальні Kernel components

`app/Kernel/Module` уже містить:

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

## Lifecycle

Conceptual state path:

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

Deactivate/uninstall повинні симетрично забрати runtime availability без видалення історичного audit/business data, якщо migration policy прямо не каже інше.

## Manifest

Manifest є machine-readable contract модуля. Він повинен описувати щонайменше:

- module identity;
- version;
- compatible Kernel version;
- dependencies;
- capabilities;
- configuration/provisioning requirements;
- entrypoint/module definition.

Runtime не повинен визначати сумісність за назвою папки або добрим настроєм автора.

## Contributions

Активний Domain module може внести в Kernel registry:

- event ownership;
- action ownership;
- handlers;
- rules;
- policies;
- agents;
- context builders;
- capabilities.

Contributions мають унікальне ownership там, де двозначність зробила б routing небезпечним.

## Per-organization activation

`ActiveModuleResolver` принципово важливий для COS SaaS model: наявність code module у deployment не означає, що він активний для кожної organization.

```text
Installed globally
≠
Enabled for tenant
```

Це дозволяє мати різні набори business capabilities для різних компаній без форків коду.

## Capability registry

UI та інші modules мають питати систему про capability, а не вгадувати її за route/class existence.

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

## Version compatibility

Module version і Kernel version мають перевірятися до activation.

Несумісний module повинен fail fast на lifecycle boundary, а не падати випадковим `Call to undefined method` через три запити після deployment.

## Adding a Domain

Стандартний шлях:

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

## UI implication

Admin module manager у майбутньому повинен бути проєкцією `ModuleCatalog + ModuleLifecycleManager + ActiveModuleResolver`, а не окремим списком checkbox-ів зі своєю логікою.

Саме тут реалізується потрібна модель: Domain можна підключити/вимкнути як системний модуль, а UI, runtime та API бачать один і той самий стан.

## Invariant

> Ні Kernel, ні Interface не повинні мати hard-coded знання, що конкретний Domain «завжди існує».
