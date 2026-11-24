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

## S1/P3 — ідемпотентність приймання лідів

Authenticated createLead keeps the existing `api.lead.create` receipt namespace. Completed receipts store SHA-256 of the normalized persisted payload and actor plus the resulting lead ID; existing numeric receipts fail closed (manual reconciliation, never create a replacement). A conflicting key never changes the lead. New receipt, lead and event/outbox share the transaction. Provider/public ingestion and CRM Inbox retain their separate trust boundaries. The repository defaults request_intent to general_contact, matching the NOT NULL schema. No migration required.

`sales.lead.create` is the narrow authenticated internal command (no caller-supplied tenant, owner or provider identity); it exposes a redacted lead ID and replay flag after native readback. The broader `sales.lead.intake` process gap stays open until public/provider ingestion and durable inbox semantics are covered. Actor-derived owner and existing key namespace are preserved. CI integration now exercises replay, payload conflict and legacy receipts using the real Sales CommandBus and MySQL.

## S1/P4 — стійкість CRM Inbox

Claim має унікальний токен спроби, lease 300 секунд, 10 спроб, retry backoff 30 секунд. Перед бізнес-записом worker блокує і перевіряє claim у тій самій транзакції, що й event/outbox/completion. Прострочений PROCESSING доступний існуючому sweep; остання покинута спроба переходить у DEAD. Застарілі токени не можуть завершити або провалити нову спробу. Обробник не повертає processed=true, коли claim не отримано. Ручний replay DEAD потребує окремої політики.

Обидва webhook entrypoints вимагають event_id та event_type у підписаному JSON body і їх точного збігу з параметрами. Header-only event ID більше не приймається; інтеграції мають адаптувати envelope. CRM не має права напряму змінювати lead status, client_case_id, deal status або owner; такі повідомлення відхиляються до появи відповідних governed commands. Approval-required CRM stage transition блокується до мутації. Не заявляємо, що синхронізація всіх CRM lifecycle events завершена.

No-op CRM updates do not emit synthetic changed events. Stage notifications must include an unambiguous stage; priority/date input is validated before writing. Active MySQL acceptance covers two independent connections and native deal update, Event/Outbox, exhausted lease and denied lifecycle mutations. The existing Messenger process/sweep command is async; direct Capability Gateway requires synchronous verified business results, so the `sales.crm.inbox.process` gap remains open until an explicit supported governed async binding is designed. No generic direct worker trigger is exposed.

## S1/P5 — контрольоване відновлення CRM

`sales.crm.inbox.status` повертає редаговане tenant-scoped спостереження: стан, спроби, provider, event_type, signature_verified і opaque revision; без payload, зовнішніх ID та помилок. `sales.crm.inbox.recover` є синхронною командою лише для human manager: DEAD + verified signature + observed revision + reason code + idempotency key. Receipt і аудит атомарні з переходом DEAD → RECEIVED; повтор того самого ключа не поновлює бюджет спроб. Результат означає лише durable requeue, а не завершення; існуючий sweep/worker виконує обробку. AI/CRON direct mutation заблокована; окремий governed Action/async processing contract ще потрібен. Process gap `sales.crm.inbox.process` не закриваємо підміною recovery-командою.

Recovery acceptance exercises canonical Gateway → actual Messenger handler → MySQL receipt/audit/inbox → existing ProcessCrmInbox; verifies stale revision, foreign tenant, AI denial, payload conflict, exactly-once audit, sweep visibility and replay after completion. Audit uses persisted metadata and changes fields, not unpersisted arbitrary data.

## S1/P5 — ідемпотентність публічної заявки

Публічна форма має окремий 128-bit `submission_id` (не credential і не identity особи). Чотири production форми отримують випадковий server-generated nonce, HTML із nonce не кешується спільно; стару форму без nonce треба оновити. Public namespace `public.lead.create` відокремлений від authenticated intake. Перший claim перед person/case/lead/event, receipt із digest нормалізованого payload + lead ID і tenant native readback в одній транзакції. Той самий nonce+payload не створює новий кейс; інший payload із тим самим nonce відхиляється. Новий nonce дозволяє нову реальну заявку, навіть від того самого клієнта. `request_intent` зберігається у lead, property reference має бути активним і tenant-scoped. Honeypot не створює receipt або lead. Public controller не повертає lead ID. Це idempotency подання, а не антиспам чи глобальна дедуплікація людей.

## S1/P5 — завершення обробки CRM

`sales.crm.inbox.process` invokes the existing ProcessCrmInbox through a separate synchronous human command (not the async Messenger envelope). Expected revision is checked under the native claim row lock; claim commits before the business transaction. COMPLETED is immutable per signed inbox identity, so another key cannot duplicate effects; in-flight, failed or stale noncompleted requests never return a completion receipt. Process Registry maps the exact processing operation; the automatic worker still invokes the same use case. Federation currently admits only `external` Action mutations (see FederatedActionAdmission and external receipt reconciler). CRM processing is internal: do not mislabel it external or bypass approval. Internal governed Action support remains an explicit S6 platform dependency, not solved by this operator binding.

## S1/P6 — базові правила кваліфікації

UpdateRequest locks the tenant lead before reading/validating changes; absent next contact/owner/note stay unchanged. Early qualification supports expected_status compare-and-set and natural desired-state replay without duplicate activity/events. Terminal lead reopening/conversion is denied, early statuses cannot regress. Lead disqualification no longer emits for a generic LOST status. A linked deal stage transition uses canonical ChangeDealStage before compatibility synchronization; note-only updates preserve independent case stage/owner/followup. Early qualification does not regress an already advanced deal. Full lead lifecycle and AI governed binding remain pending.

Typed `sales.lead.qualify` now exposes the existing locked qualification path for human operators. Input requires expected and target state, no arbitrary notes/owner/tenant; native lead readback required. Natural desired-state replay, not a generic receipt for unrelated operations. Disqualified/spam/terminal leads cannot be silently converted or attached; attachment failure after validation throws to roll back. The broad `sales.lead.state.manage` debt remains until all lifecycle transitions are governed and tested; qualification does not claim that wider operation.

## S1/P6 та S2 — межі життєвих циклів

LeadLifecyclePolicy explicitly enumerates allowed transitions; terminal states cannot reopen. Lead WON/linked LOST are classifications of an independently recorded Deal outcome, not shortcuts to close it. Lead status updates no longer write deal owner, status or followup via compatibility SQL; forward early qualification may mirror configured stage via ChangeDealStage with expected-stage compare-and-set. Stage read is locked in its own transaction, expected stage and requested outcome fenced, non-user approval-required transitions denied (no false approval evidence), terminal LOST requires explicit active reason, WON requires positive value/valid currency/owner, terminal event snapshots use decimal string. Unknown transition predicates fail closed. Combined quick update rolls back on assignment/write failure. Currency changes must go through dedicated commercial operation rather than generic case edit.

Commercial terms are an exact DECIMAL(14,2) string plus supported currency, atomically set by SetDealCommercialTerms under deal row lock and immutable after terminal outcome. Generic case edit cannot overwrite currency; legacy automation using DealChangeSet must supply both and run inside a transaction. New cases must start active. Owner assignment validates active tenant membership and target assignable active team, team lead cannot move a deal from outside their current team without global Sales assign capability; terminal owner changes denied. Runtime DI explicitly supplies the native assignment authority.

Six new typed contracts expose full Lead lifecycle and Deal state/owner/commercial/stage/outcome through the existing Gateway/CommandBus. Deal state query exposes only business state plus a state fingerprint (not a monotonic database revision); commands lock native row, compare observed state, bind receipt to actor and canonical input, invoke native use case, verify state, and commit receipt atomically. Replay does not mutate and fails if the required native target is no longer present. Lead lifecycle command uses durable receipt to fence reversible viewing/negotiation transitions. Process gaps for canonical lead lifecycle, owner, stage and outcome removed; broad public/provider intake, activity, followup, property match gaps remain. No claim of AI Workflow support for internal commands. 

Approval-required pipeline edges now fail closed for every actor until the runtime can supply independently verified approval evidence (a USER label is not such evidence). Existing configured unconditional transitions remain usable. Team membership update locks the team, checks optional observed configuration_version, atomically updates member and team version, writes revision, avoids no-op revisions, and disables assignment/approval on inactive membership. Capability grants serialize on the user and skip equal-set no-ops. Existing UI/admin path is retained. Round-robin assignment execution is not implemented here.

Team read/manage now have native admin-authority checked capabilities. Management covers create, versioned update/disable/archive, and versioned set-member; strict per-operation validation and receipts, no email/PII in team snapshot. Capability grants remain existing privileged admin operations; direct team management does not grant permissions. Manual mode only; no claim of automated weighted round-robin execution. 

Pipeline condition evaluation now supports a bounded deterministic AND list: `deal_value` eq/gte/lte exact decimal; `currency` eq supported currency; `assigned_user_id` present or positive integer eq; `status` eq active/paused. Invalid/unknown conditions fail closed at both admin configuration and runtime. Assignment now locks the current native deal before checking authority; relevant active-user/assignability/capability reads lock inside the transaction. Typed team update refuses silently converting an existing ROUND_ROBIN team to manual mode.

## S3 — активності та наступні контакти

Native follow-up scheduling locks the deal, verifies legacy replay against the actual activity, claims a unique receipt before writing, preserves the earliest open follow-up in next_contact_at, rejects terminal deals and propagates failures into the owning transaction. Activity completion/rescheduling lock the deal and activity; completed calls and unrelated completed notes no longer clear pending next contact. Property match updates preserve omitted values and publish native case change evidence. 

Five typed activity, schedule, completion, reschedule and Property Match commands use existing SalesWriteService/SalesOperationService, not a parallel writer. One bounded payload-bound receipt per tenant and operation, verified native records, explicit date offsets, Property reference checked through provider port, expected match status. Four process gaps are resolved at the operator contract level; `sales.lead.intake` remains distinct from authenticated lead creation until admission authority is modeled.

## S4 — комерційні пропозиції та переговори

Sales owns immutable quote terms, exact minor-unit calculation, revision-fenced negotiations and operator-recorded acceptance. A quote may include a manually described service, a verified Product variant or a verified Property inventory reference. Revalidate source snapshot before presentation and acceptance. Customer counteroffers must be adopted in a sales-authored revision before presenting. Acceptance updates native Deal commercial terms in the same transaction; WON is separate, as are invoice, stock reservation, signature, delivery and payment. Finance receives read-only accepted commercial evidence with settled=false. Provider CRM admission remains a distinct authenticated ingress; operator intake uses the native public lead use case with operator provenance and its own receipt namespace. No new execution engine.

S3/S4 acceptance candidates: 29 typed Sales contracts and zero gaps in the two existing Process Registry definitions; 10 descriptive legacy declarations remain. Native integration tests cover quote revision, customer counteroffer, acceptance, exact amounts, actor and tenant fences, idempotency, history and Finance evidence. Current checkout CI must pass before acceptance. Remaining: full operator workspace, agent Workflow approval path, lead-level followup, cancellation/reminder delivery, positive Property integration and production acceptance. No D3–D5 claim.

## S3/S4 — життєвий цикл активностей, доставка та звіряння

Lead followups use the existing native writer, tenant lock and payload-bound receipt. Cancellation is distinct from completion, excluded from overdue and completion metrics. Existing scheduled Sales scan emits a durable due event once per activity/due/owner; owner-email handoff is explicitly approved. Offer document renders exact immutable sales-authored terms as printable HTML and text, with document and recipient hashes. The approved offer delivery Action checks current version, reference snapshots, target and recipient, and records a single native delivery mapping per terms version. Platform Notification/n8n outbox provides durable handoff evidence. A queued or handed-off notification is not proof of mailbox receipt. Uncertain Sales notification transport is quarantined instead of automatically re-sent; provider idempotency and receiver evidence are required before replay. AIDA CRM is local only: reconciliation verifies the native action reference and activity/communication records. Other provider adapters are not registered; unsupported provider results must remain explicit. No fake external success.

## S5/P1 — фінансові підсумки та межі доступу

Open pipeline summary aggregates the full current Sales population in SQL (not a 250-row page). Only explicit Deal value and supported currency count as money; customer budget is not a quoted amount. Exact decimal strings, separate currencies, known-probability coverage, open-stage counts and no implicit FX. Existing Dashboard and Pipeline projections consume that same native summary. Stage header describes all-owner totals while the visible cards remain filtered and paginated. Owner dashboard no longer embeds tenant-wide metrics. Canonical `sales.workspace.summary.read` is PII-free, defaults to self, permits a named active manager only for self, authorized active team lead or director, and permits tenant-wide only with live `sales.director.view`. Existing Kernel manager fence remains unchanged; no grant to employee or AI mutation. Full scoped list/detail/search/export and historical/forecast improvements remain S5 follow-ups.
