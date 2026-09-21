---
title: Проєкція runtime Actions у Web
description: Підключення workflow-, rule- та agent-generated Kernel Actions до канонічного UIAction без дублювання execution authority.
status: active
updated: 2026-09-21
kind: architecture
---

# Проєкція runtime Actions у Web

Wave 12.11 підключає runtime-generated Kernel Actions до `UIAction`.

До цієї хвилі `UIActionRegistry` містив лише статично оголошені module actions. Kernel водночас уже створював Action через Rule, Agent, Policy та Approval runtime.

Тепер обидва джерела сходяться в одному presentation registry.

## Канонічний потік

```text
Rule / Agent / Workflow
        ↓
ActionProposal
        ↓
Policy
        ↓
Kernel Action
        ↓
RuntimeActionReadModelInterface
        ↓
RuntimeUIActionProvider
        ↓
UIActionRegistry
        ↓
UIActionResolver
        ↓
Workspace / Context menu / Mobile / AI
```

Web не читає `cos_actions` напряму.

## Модель читання

Kernel визначає presentation-neutral contract:

`RuntimeActionReadModelInterface`.

MySQL adapter реалізує tenant-scoped projection за:

- organization;
- target type;
- target id.

Projection повертає `RuntimeActionProjection`, а не raw SQL row.

Web залежить лише від цього contract.

## Сумісність типів сутностей

Нові Web entity refs використовують namespaced identifiers на кшталт:

```text
sales.deal
```

Історичні Kernel Actions можуть містити short target type:

```text
deal
```

Runtime provider читає exact canonical type і його legacy final segment.

Це compatibility read behavior. Новий runtime code має віддавати перевагу canonical namespaced target types.

## Стани Action

Projection не робить вигляд, що всі runtime states є кнопками виконання.

| Kernel state | Web projection |
| --- | --- |
| `PROPOSED` | Run |
| `FAILED` | Retry |
| `PENDING_APPROVAL` | Approve + Reject, якщо pending approval відомий |
| `QUEUED` | disabled lifecycle state |
| `RUNNING` | disabled lifecycle state |
| `COMPLETED` | не показується в actionable surface |
| `REJECTED` | не показується в actionable surface |

Це presentation projection. Authoritative lifecycle лишається в Kernel.

## Approval

Якщо Action має pending approval, Web створює два `UIAction`:

```text
operations.approve
operations.reject
```

Їх `resourceId` є id Approval, а не Action.

Critical approval успадковує critical risk і вимагає step-up confirmation.

Reject не отримує execution authority поза існуючим Approval runtime.

## Ризик

Runtime risk проєктується в канонічний `UIActionDangerLevel`:

- low або unknown → none;
- medium → caution;
- high → destructive;
- critical → critical + step-up.

Dangerous UIAction завжди має explicit confirmation contract.

## Resource identity

Wave 12.11 додає `UIAction.resourceId`.

`UIAction.id` описує presentation action identity.

`resourceId` вказує на конкретний server-owned runtime resource:

- Kernel Action id;
- Approval id.

Workspace browser event може передати обидва значення далі у interaction/form layer.

Browser не отримує права вирішувати, який Application Command дозволено виконати.

## Виконання

Stimulus диспатчить:

```text
cos:workspace-action
{
  actionId,
  resourceId,
  entityKey
}
```

Він не викликає API, не виконує `operations.execute_action` і не обходить CSRF/permission/Policy.

Server interaction layer повторно резолвить action і використовує вже існуючі Operations Application Commands.

Наявні server routes для execution/approval лишаються authority boundary.

## Registry

`UIActionRegistry` тепер об'єднує:

```text
module-owned web.actions
+
runtime-generated Actions
```

Після об'єднання діють ті самі правила:

- duplicate id fail;
- priority sorting;
- placement filtering;
- permission projection через `UIActionResolver`.

Runtime actions не створюють нового `web.*` extension point, бо їх owner — Kernel runtime, а не Domain module contribution.

## Permission

Runtime operational actions використовують `cos.tenant.manage` як presentation permission baseline.

Це не замінює Policy або Approval.

Навіть enabled button не є доказом execution authority.

## Межі

Заборонено:

- читати `cos_actions` із Twig або Stimulus;
- підключати PDO до Web provider;
- виконувати mutation з `RuntimeUIActionProvider`;
- запускати Action напряму з browser controller;
- вважати UI enabled state authorization;
- створювати другий action registry;
- дублювати Kernel lifecycle у JavaScript.

## Перевірка

`cos:web:runtime-actions:smoke`:

1. створює реальну ephemeral Kernel Action;
2. читає її через MySQL runtime projection;
3. знаходить її в canonical `UIActionRegistry`;
4. перевіряє Action resource identity;
5. перевіряє critical Approve/Reject projection;
6. перевіряє queued lifecycle state;
7. перевіряє, що terminal action не потрапляє в actionable surface.

Architecture gate окремо перевіряє dependency direction і відсутність browser execution logic.
