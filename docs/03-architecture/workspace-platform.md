---
title: Платформа робочих просторів Web
description: Канонічна композиція Workspace, typed slots, module-owned extensions, UIAction та entity context у COS Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа робочих просторів Web

Wave 12.10 перетворює зарезервовані у Wave 12.5 `web.workspace` і `web.workspace.extensions` на реальний composition runtime.

Workspace не є новим Domain, окремим frontend application або generic business engine.

Його задача: **зібрати server-owned product content, entity context, дозволені actions і module-owned presentation extensions в одну стабільну поверхню**.

## Канонічний потік

```text
TenantContext + WebExtensionContext
        ↓
WebExtensionCatalog
        ├─ web.workspace
        └─ web.workspace.extensions
        ↓
WorkspaceCompositionResolver
        ├─ WorkspaceDefinition
        ├─ WorkspaceExtension slots
        └─ UIActionResolver
        ↓
WorkspaceViewModel
        ↓
CosWorkspace
        ↓
Twig SSR + small Stimulus behavior
```

Основний business content лишається product-owned Twig content.

## Типізовані області

`WorkspaceSlot` визначає канонічні області:

- `header`;
- `primary_actions`;
- `navigation`;
- `tabs`;
- `main`;
- `sidebar`;
- `activity`;
- `documents`;
- `ai`;
- `footer`.

Module extension більше не передає довільний рядок slot.

`WorkspaceExtension` містить:

- workspace id;
- typed `WorkspaceSlot`;
- Twig template;
- presentation props;
- priority.

Props не повинні містити executable business callbacks.

## Основний вміст

`CosWorkspace` є композиційною оболонкою.

Product page володіє основним content:

```twig
<twig:CosWorkspace :workspace="workspace">
    {# Product-owned server-rendered content #}
</twig:CosWorkspace>
```

Platform не переносить Product Query або business rules у shared component.

## Розширення модулів

Module manifest декларує:

```text
web.workspace
web.workspace.extensions
```

`WorkspaceProviderInterface` визначає доступні workspaces.

`WorkspaceExtensionProviderInterface` додає presentation fragments до вже існуючого workspace.

Перший real slot contribution належить Sales:

```text
sales/module.php
    ↓ web.workspace.extensions
SalesWebProvider
    ↓
sidebar / activity / ai
```

Enable/disable Sales module автоматично впливає і на Workspace definition, і на slot extensions через один OrganizationModuleSnapshot.

## Дії

Workspace не створює локальну action model.

`WorkspaceCompositionResolver` використовує `UIActionResolver` для:

- `workspace.primary`;
- `workspace.secondary`.

Permission resolution лишається server-side.

Stimulus лише диспатчить presentation event:

```text
cos:workspace-action
```

Event містить `actionId` та `entityKey`, але не виконує Application Command самостійно.

## Контекст сутності

Workspace може бути прив'язаний до `EntityRef`.

Якщо `WorkspaceDefinition.entityType` заданий, resolver відхиляє incompatible entity type.

```text
WorkspaceDefinition.entityType
        ↓
EntityRef.type
        ↓
must match
```

Organization і role завжди звіряються з authenticated `TenantContext`.

## Канонічні компоненти

Wave 12.10 вводить:

- `CosWorkspace`;
- `CosWorkspaceHeader`;
- `CosContextPanel`;
- `CosActivityPanel`;
- `CosAIContext`.

Ці компоненти не знають про Sales, Property або інший Domain.

Domain-specific content приходить лише через extension template.

## Мобільний режим

Desktop використовує main + context rail.

На планшеті rail переходить у горизонтальну panel grid.

На вузькому viewport:

- header actions стають touch-friendly;
- layout переходить в одну колонку;
- context/activity/AI panels розміщуються після main content;
- той самий `WorkspaceViewModel` використовується без окремого mobile API.

## AI-контекст

AI slot отримує той самий `EntityRef` і resolved `UIAction`, що human Workspace.

Це важливо: AI не отримує прихований паралельний список дій і не обходить permission model.

```text
EntityRef + UIAction
      ├─ Human UI
      └─ AI context
```

Фактичний Agent execution як і раніше проходить через Application/Kernel action runtime.

## Межі

Workspace Platform не може:

- звертатися до Doctrine Repository;
- виконувати generic SQL;
- викликати internal REST;
- зберігати authoritative state у browser storage;
- імпортувати Domain classes у shared Workspace runtime;
- виконувати business mutations у Twig або Stimulus;
- створювати другий module registry;
- обходити `UIActionResolver`;
- підміняти product-owned main content.

## Еталон

`/dev/workspace` показує server-rendered Sales Deal Workspace:

- canonical Shell;
- entity context;
- primary і secondary UIAction;
- product-owned main content;
- Sales-owned sidebar extension;
- Sales-owned activity extension;
- Sales-owned AI extension;
- responsive Workspace layout.

Route лишається manager-only через загальну `/dev/*` security boundary.

## Перевірка

CI перевіряє:

- typed slots;
- tenant/role/entity invariants;
- module-owned `web.workspace.extensions`;
- shared component/domain boundaries;
- browser transport boundary;
- semantic-token CSS;
- responsive composition;
- реальний resolver + Twig render через `cos:web:workspace:smoke`.
