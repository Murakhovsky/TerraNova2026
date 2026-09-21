---
title: Unified UIAction Platform
description: Канонічна модель бізнес-дій для Workspace, DataGrid, context menu, mobile, AI та notification surfaces.
status: active
updated: 2026-09-21
kind: architecture
---

# Unified UIAction Platform

Wave 12.6 вводить один presentation contract для дій, які можуть бути показані на різних COS surfaces.

```text
Domain module
    ↓ web.actions
ActionProvider
    ↓
UIActionRegistry
    ↓ duplicate-safe action catalog
UIActionResolver
    ├─ tenant / role consistency
    ├─ placement
    └─ permission projection
```

UIAction є presentation model. Він не виконує business mutation.

## Канонічний UIAction

```text
UIAction
├── id
├── label
├── icon
├── intent
├── permission
├── enabled
├── disabledReason
├── confirmation
├── command
├── async
├── dangerLevel
├── priority
└── placements
```

`id` має бути стабільним namespaced identifier. `command` посилається на існуючий Application/Kernel action identifier, але Web platform не виконує його напряму.

## Placements

Канонічні placements: `workspace`, `workspace.primary`, `workspace.secondary`, `datagrid.row`, `datagrid.bulk`, `context_menu`, `command_palette`, `mobile.primary`, `mobile.menu`, `ai_proposal`, `notification`.

Одна action може бути доступна на кількох surfaces без створення локальних копій.

## Confirmation та danger

Danger levels: `0 none`, `1 caution`, `2 destructive`, `3 critical`.

Dangerous action завжди вимагає confirmation contract. Critical action додатково вимагає `stepUp=true`.

Це presentation guard. Authorization, idempotency, audit та execution policy залишаються backend responsibility.

## Permissions

`UIAction.permission` не є окремою ACL-системою.

```text
permission identifier
    ↓
UIActionPermissionResolver
    ↓
registered permission checker
```

Platform-level tenant permissions перевіряє `TenantUIActionPermissionChecker`.

Domain-specific capability system підключає окремий Web adapter. Sales використовує `SalesUIActionPermissionChecker`, який працює через `SalesAccessControlInterface`, а не через persistence напряму.

Якщо checker для permission не зареєстрований, resolver працює fail-closed і action стає disabled.

Backend authorization залишається авторитетним незалежно від UI state.

## Registry і Resolver

`UIActionRegistry` бере actions лише з active module providers, відхиляє duplicate ids і сортує за `priority + id`.

`UIActionResolver` перевіряє tenant organization, role consistency, placement та permissions. Він не виконує command, не обходить Kernel Policy і не робить persistence access.

## Execution boundary

```text
UIAction
    ↓ user confirms
Interaction/Form layer
    ↓
Application Command / ActionProposal
    ↓
Policy
    ↓
Approval if required
    ↓
Queue / execution
    ↓
Audit / events
```

Wave 12.6 закриває presentation action model. Workflow-generated actions будуть окремо підключені у Wave 12.11.

## Reference implementation

Перший reference provider: Sales.

Для `sales.deal`: Change stage → `sales.change_stage`; Assign owner → `sales.assign_owner`; Request document → `sales.request_document`.

Для `sales.lead`: Create follow-up → `sales.create_lead_followup_task`.

Sales permission projection використовує реальні `SalesCapability` і `SalesAccessControlInterface`.

## Архітектурні інваріанти

- `Web/Experience/Action` не залежить від Domains.
- Domain-specific permission adapters залежать від Application contracts, не від persistence implementation.
- `web.actions` ownership декларує module manifest.
- unknown permission працює fail-closed.
- critical action без step-up confirmation невалідна.
- mobile actions є placement того самого UIAction, а не окремою моделлю.
- UI enabled/disabled state ніколи не замінює backend authorization.
