---
title: Policies and Approvals
description: Permission gate між Action proposal та mutation.
status: active
updated: 2026-09-11
kind: runtime
---

# Policies and Approvals

Policy layer відповідає на просте, але фундаментальне питання:

> **Чи має ця Action право бути виконаною в цьому context?**

## Policy decisions

Kernel використовує три outcomes:

```text
AUTO
APPROVAL_REQUIRED
DENIED
```

### AUTO

Action може перейти в execution path без ручного підтвердження.

### APPROVAL_REQUIRED

Action блокується до окремого людського рішення.

### DENIED

Action не виконується.

## Default deny

Якщо для Action немає явної matching policy, безпечна поведінка — `DENIED`.

Це особливо важливо для dynamically installed Domains та AI-generated proposals. Новий action type не повинен автоматично отримувати право на mutation просто тому, що хтось забув додати policy.

## ActionPolicy

Policy повинна оцінювати action + execution context, а не містити provider-specific code.

Типові фактори:

- action type;
- organization;
- actor;
- payload attributes;
- risk level;
- monetary threshold;
- external/internal destination;
- current business state.

## Policy is not permission UI

Role-based UI access і Action Policy — різні рівні.

```text
Can user open page?      → interface authorization
Can proposed mutation run? → Kernel Policy
```

Навіть admin UI не повинно обходити Policy, якщо action виконується через COS runtime.

## Approval lifecycle

Approval є окремою durable сутністю.

Conceptual flow:

```text
ActionProposal
   ↓
Action
   ↓
Policy = APPROVAL_REQUIRED
   ↓
Approval(PENDING)
   ↓
Human decision
   ├─ APPROVED → normal Action execution
   └─ REJECTED → terminal / no execution
```

Approval має містити достатній context, щоб людина розуміла:

- що саме буде зроблено;
- над якою сутністю;
- хто/що запропонував дію;
- чому Policy вимагає approval;
- ключові ризики / payload;
- correlation з Event/Agent run.

## Agent safety boundary

Agent не може:

- змінити PolicyDecision;
- створити собі AUTO permission;
- approve власну Action;
- викликати ActionExecutor напряму;
- обійти queue/idempotency path.

Agent лише генерує structured proposal.

## Approval is not a second workflow engine

Human Approval має бути вузьким gate усередині execution lifecycle.

Бізнес-процес, SLA, нагадування та escalation можуть реагувати на approval events, але сама Approval сутність не повинна знати весь бізнес-workflow.

## Audit requirements

Для policy/approval потрібно зберігати:

- evaluated policy;
- decision;
- reason;
- relevant context snapshot/reference;
- approver identity;
- decision timestamp;
- rejection/approval note, якщо є;
- Action identity;
- correlation ID.

## Recommended policy design

Domain володіє policy catalog для власних Actions. Kernel володіє generic evaluation mechanism.

```text
Sales action semantics → Sales policy definitions
Policy evaluation      → Kernel
Policy persistence     → Infrastructure adapter
Approval interface     → Web/API/etc.
```

## Invariant

> Жоден mutation path, що вважається COS Action, не повинен мати альтернативний «короткий шлях» повз Policy та Audit.
