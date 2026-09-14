---
title: Sales Lead → Managed Case
description: Канонічний Sales workflow від публічного ліда до керованої угоди та automation loop.
status: active
updated: 2026-09-11
kind: workflow
---

# Sales Lead → Managed Case

Це один із головних наскрізних workflow COS.

## Вхід

Public intake входить через `ReceivePublicLead`.

CRM inbound іде окремим шляхом:

```text
external CRM webhook
→ ReceiveCrmWebhook
→ durable CRM inbox
→ ProcessCrmInbox
→ canonical Sales state
```

Provider payload перекладається на boundary і не стає внутрішньою моделлю напряму.

## Sales state

Основні поняття: Lead, Person у Sales relationship, ClientCase/Deal, PipelineStage, LeadStatus, ClientCaseStatus, SalesPriority, Activity, Follow-up, Property Match, Assignment.

`Person ≠ Lead ≠ ClientCase`.

## Application layer

Канонічні use cases:

- `ReceivePublicLead`;
- `AssignDealOwner`;
- `ChangeDealStage`;
- `ScheduleDealFollowup`;
- `CompleteSalesCall`;
- `ReceiveCrmWebhook`;
- `ProcessCrmInbox`.

Для command-heavy ClientCase операцій є `ClientCaseCommandService`.

## Business events

Sales володіє подіями:

- `LeadCreated`, `LeadChanged`;
- `ClientCaseCreated`, `ClientCaseChanged`;
- `DealCreated`, `DealStageChanged`;
- `CallCompleted`;
- `FollowupOverdue`;
- `ActionOutcomeMeasured`.

State change + Event + Outbox мають бути атомарними.

## Automation

```text
Sales Event
   ↓
Rule context / Agent context
   ↓
Rule або SalesIntelligenceAgent
   ↓
ActionProposal
   ↓
Policy
   ↓
AUTO | APPROVAL_REQUIRED | DENIED
   ↓
Queue / Action handler
   ↓
Sales application port
   ↓
Result Event + Audit
```

Agent не має прямого mutation access.

## Поточні automation actions

Handlers існують для assign owner, change deal stage, create follow-up task, request document, schedule follow-up, schedule meeting, send message, update deal.

## Read side

Workspace використовує dedicated read models:

- `SalesWorkspaceReadModelInterface`;
- `SalesWorkspaceOperationalReadModelInterface`;
- `ClientCaseReadModelInterface`;
- administration read models.

## Інваріанти

1. Усе tenant-scoped.
2. External provider vocabulary перекладається на adapter boundary.
3. Pipeline transition проходить domain governance.
4. Automation handler не виконує SQL напряму.
5. Agent лише пропонує дію.
6. Side effects idempotent.
7. Business Event належить Sales, не Kernel.

## Code map

```text
app/Domains/Sales/Model
app/Domains/Sales/Application
app/Domains/Sales/Automation
app/Domains/Sales/Infrastructure
app/Domains/Sales/Bootstrap/SalesDomainModule.php
app/Bootstrap/Sales*.php
```