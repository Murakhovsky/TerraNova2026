---
title: COS Actors & Authority
description: Human, system and agent actors and the authority model that governs business mutations.
status: active
updated: 2026-09-15
kind: product
---

# COS Actors & Authority

COS розділяє **хто ініціює/пропонує дію** і **хто має право дозволити mutation**. Це принципово, особливо коли в систему входять Agents та integrations.

## Actor classes

| Actor | Typical role | Authority source |
|---|---|---|
| End user / employee | initiates business operation | identity + permissions + Domain rules |
| Manager / approver | resolves controlled decisions | permissions + approval policy |
| System automation | deterministic reaction to fact/time | rule + policy + runtime capability |
| AI Agent | interprets context and proposes action | no inherent mutation authority |
| Worker / executor | performs approved queued work | runtime lease/job + registered handler |
| External integration | sends/receives provider data | authenticated adapter + explicit contract |
| Administrator | configures platform/module surface | administrative permissions, not automatic Domain ownership |

## Authority path

```text
Actor / Event
    ↓
Requested or proposed Action
    ↓
Identity / tenant context
    ↓
Permission / capability
    ↓
Domain invariant
    ↓
Policy
 ├─ AUTO
 ├─ APPROVAL_REQUIRED
 └─ DENIED
    ↓
Approved execution
```

## Agent rule

Agent output є proposal/evidence, не permission. Навіть переконливий JSON не стає повноваженням лише тому, що модель написала його з упевненістю.

## Human approval

Approval є окремим lifecycle. Approver має бути визначений system authority model; Agent не затверджує власну пропозицію, а executor не вирішує постфактум, що його job «напевно був дозволений».

## External systems

Webhook/API caller не отримує authority називати внутрішні Events або встановлювати Domain state напряму. Adapter автентифікує source, нормалізує input і викликає canonical application boundary.

## Product roles vs implementation roles

User-facing role names можуть відрізнятися між companies. COS не повинен hard-code кожну org chart посаду в Kernel. Runtime працює через permissions/capabilities/policies, а Domain визначає semantic operation.

## Read next

- [Policies & Approvals](../05-runtime/policies-and-approvals.md)
- [Permissions Reference](../12-reference/permissions-capabilities.md)
- [Agent Runtime](../06-ai-agents/agent-runtime.md)
