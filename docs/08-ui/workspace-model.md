---
title: Workspace Model
description: COS operational workspace model connecting read models, commands, context and Domain-owned actions.
status: active
updated: 2026-09-15
kind: ui
---

# Workspace Model

COS workspace не є просто набором CRUD-екранів. Його задача — показати людині **поточний operational context, state, next decisions та дозволені actions**.

## Workspace anatomy

```text
Context / tenant / role
        ↓
Read model / projection
        ↓
Operational state
        ↓
Available actions
        ↓
Command / Use Case
        ↓
Result / event
        ↓
Refresh projection
```

## Read side

Workspace може потребувати denormalized projection, aggregate metrics, history timeline або search index. Це не причина перетворювати write repository на універсальний BI query object.

## Action side

UI action повинна map-итися на meaningful application operation:

```text
Change stage
Assign owner
Schedule follow-up
Moderate submission
Publish listing
Approve action
```

Кнопка не повинна означати «оновити три таблиці цими полями».

## Context

Workspace явно працює в active organization/tenant context. Додатковий context може включати Domain entity, case/deal, Property asset, diagnostic session, selected module або operational filter.

## State and feedback

UI має відрізняти:

- successful mutation;
- validation rejection;
- permission/policy deny;
- approval required;
- asynchronous queued state;
- external failure/retry;
- stale/concurrent update.

Це різні system states, а не один червоний toast «Something went wrong».

## Cross-domain composition

Workspace може показувати дані кількох Domains, але mutation authority не змішується. Sales screen може показувати Property reference, не отримуючи права напряму редагувати canonical Property storage.

## Related

- [Interface Surfaces](./interface-surfaces.md)
- [Navigation & Permissions](./navigation-and-permissions.md)
- [Execution Lifecycle](../05-runtime/execution-lifecycle.md)
