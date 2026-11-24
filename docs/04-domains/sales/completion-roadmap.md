---
title: Sales — план повної реалізації домену
description: Бізнесова функціональність, контрольоване виконання та приймання Sales.
status: active
updated: 2026-11-24
kind: architecture
---

# Завершення домену Sales (#459)

## Цільова модель

Sales is the authoritative business domain for the commercial relationship, lead qualification, case/deal, pipeline, sales activities and commercial outcome. It must operate independently of an external CRM or LLM. Property-specific functionality is an optional integration, not a requirement for selling a Product or Service. Finance owns payments/accounting, Product Management owns catalog/stock, Property owns listings, Marketing owns campaigns and acquisition, Communication owns delivery where available, Kernel owns identities and authorization; verify each actual counterpart contract before adding a dependency. No cross-domain table access or invented integration contracts.

The same business operation has one Sales-owned implementation and one typed public contract per distinct semantic action. Human and AI entrypoints may have different permissions and approval paths, not different business rules. AI planning uses COS Goals/Federation; mutation requires approved canonical Platform Workflow/Action and persisted receipts. Human internal operations may use the existing canonical Gateway where its policy allows. Neither agent nor UI grants itself authority. Do not weaken the Gateway manager fence as a shortcut to salesperson access; design and test scoped permissions first.

## Початковий стан після #458 (2026-11-24)

Manifest Sales 1.0.0 / schema 0.8.6 declares three typed contracts: inbound request read, external create task, deal-won observation. Existing native operations include ReceivePublicLead, ReceiveCrmWebhook, ProcessCrmInbox, AssignDealOwner, ChangeDealStage, CompleteSalesCall, ScheduleDealFollowup and SalesInboundService. Their existence is not proof of canonical capability invocation, concurrency safety, or real tenant acceptance. The passport is UNKNOWN; preserve D3–D5 gates.

| Registry debt target | Existing implementation to audit | Package |
| --- | --- | --- |
| sales.lead.intake | ReceivePublicLead / ReceiveCrmWebhook (different trust boundaries) | S1 |
| sales.crm.inbox.process | ProcessCrmInbox | S1 |
| sales.lead.state.manage | SalesInboundService::updateRequest, qualification rules | S1 |
| sales.client_case.create (раніше sales.client-case.create) | SalesInboundService::createCaseFromRequest | S1 |
| sales.deal.owner.assign | AssignDealOwner | S2 |
| sales.pipeline.stage.manage | ChangeDealStage | S2 |
| sales.deal.outcome.record | ChangeDealStage terminal transition and events | S2 |
| sales.activity.record | CompleteSalesCall and case activity paths (two registry gaps) | S3 |
| sales.followup.schedule | ScheduleDealFollowup | S3 |
| sales.property-match.record | Sales-owned match persistence, PropertyReferencePort | S4 |

Audit semantic boundaries: canonical-state, activity events, property match and outcome may be atomic substeps rather than independent commands. If so, correct registry steps to reference the actual typed atomic operation and readback, instead of manufacturing a callable that bypasses invariants. Close gaps only with executable evidence in the same PR.

## Пакети робіт і критерії приймання

| Package | Scope and acceptance |
| --- | --- |
| S0 | Inventory native commands, queries, UI/API/automation entrypoints, permission scopes, transaction/idempotency guarantees, and gaps. Verify current contracts fail closed; establish separate main-based branch and unchanged debt baseline. |
| S1 | Intake (manual/public/provider paths), deduplication and trusted source identity, durable CRM processing, qualification, conversion and existing case attachment. Validate tenant-scoped lead/case receipt, duplicate/concurrent conversion, failure rollback and replay. A provider webhook is not a generic privileged capability. |
| S2 | Assigned owner/team, pipeline configuration and stage changes, priority and deal status, won/lost reason, commercial value/currency. Verify authorization, optimistic locking, required approval before execution, terminal event exactly once and native readback; don't equate a won deal to collected money. |
| S3 | Calls, notes, meetings and follow-ups; schedule/reschedule/complete/cancel, overdue views, deterministic reminder delivery through declared ports, audit and deduplication. Test stale action, retry and recovery. |
| S4 | Offers, negotiation and commercial line-item/reference model for Property/Product/Service as supported by verified contracts. Property matching stays Sales-owned. External CRM inbox/outbox reconciliation with provider idempotency; no assumed order, inventory reservation, invoice, or payment authority. Document blocked integrations instead of simulated success. |
| S5 | Operational workspace and director dashboards; case/lead search, pipeline, activity timeline, next actions, owner/team scope, conversion, aging, forecast, booked commercial outcome, export/PII boundaries. Measure by tenant, period, currency and stage; don't sum mixed currencies without an explicit conversion policy. Verify UI/API parity. |
| S6 | Discoverability in Capability Library, typed plans, human/agent policy, approval before effect, one canonical Workflow/Action path for automated commands, idempotency receipts, bounded retry and native observation. Test unauthorized agent, disabled module, revoked access, stale plan and unknown outcome. |
| S7 | Real MySQL E2E, operator UX, production-like worker/recovery tests, independent exact-SHA evidence and DAS-001/Golden 50 checks. Verify a human and an approved agent can perform the same allowed commercial operation through canonical contracts. No D3–D5 or production activation by inference. |

## Першочерговий аудит виконання

- CanonicalCapabilityGateway currently enforces a tenant-manager fence and human-only direct commands. Agent mutations must not be routed through direct invocation.
- ReceivePublicLead fixes its tenant at composition time and has no explicit request idempotency key in its signature. Separate trusted ingestion identity from generic capability inputs and prove duplicate behavior before exposing it.
- S1/P1 moved conversion and attachment under a tenant-scoped lead row lock. CI, Runtime and Frontend passed at 0e9df3a; real two-connection concurrency and rollback evidence remains required.
- ChangeDealStage executes business transitions and terminal events in one transaction. Audit caller-side approval enforcement and expected-stage concurrency; requiresApproval on a receipt is not evidence of prior approval.
- First safety patch: verify the inbound request read adapter rejects mismatched tenant or entity ID before exposing even its redacted summary. Keep registry debt unchanged.

## Критерії завершення

Each slice contains actual Sales implementation, typed input/output where public, declared capability ownership, permission/policy, appropriate idempotency and failure semantics, native independent readback, positive/denied/cross-tenant/replay/failure tests, CI wiring, and matching Process Registry/debt/passport/generated references if changed. No new executor, no direct foreign persistence, no false contracts. For uncertain external effects reconcile before retry. Release only after operator evidence from the target environment. Issue #459 remains open until all business packages are accepted, not merely when DAS-001 debt reaches zero.

## S1/P2 — канонічна конвертація ліда

Контракт `sales.client_case.create` приймає лише `lead_id`; owner за замовчуванням визначає наявний SalesWriteService з авторизованого actor. Окрема зміна owner/priority не є частиною конвертації. Ідентифікатор нормалізовано відповідно до правил Kernel (underscore замість hyphen). Gateway вимагає ключ запиту, але бізнесова ідемпотентність визначається парою tenant/lead, а не значенням ключа. Інший lead є іншим бізнес-наміром. Повторний виклик не змінює owner. Результат містить тільки lead_id, case_id та replayed після незалежного читання ліда і кейса. Помилка читання після commit не є підтвердженням rollback: повторно прочитати нативний стан. Пряме виконання обмежено авторизованим менеджером; AI Workflow binding ще не реалізовано. Закриття gap у гілці є кандидатом до приймання після CI та runtime evidence; D3–D5 не змінюються.
