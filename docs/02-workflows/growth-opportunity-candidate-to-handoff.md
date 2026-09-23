---
title: Signal → Qualified Opportunity Handoff
description: "Канонічний Growth V0.1 процес від перевіреного сигналу через research, rationale та explainable scoring до handoff-ready Opportunity Candidate."
status: active
updated: 2026-09-23
kind: workflow
contract: workflow-v2
process_state: to-be
process_id: growth.opportunity-candidate-to-handoff
---

# Signal → Qualified Opportunity Handoff

## Бізнес-мета

Перетворити зовнішні або внутрішні бізнес-сигнали на **конкретні, досліджені та пояснювані Opportunity Candidates**, не підміняючи Growth звичайною базою Lead-ів.

Growth відповідає за **FIND VALUE**:

```text
Signal
  ↓
Research
  ↓
Rationale
  ↓
Score
  ↓
Qualification
  ├─ not now → Monitoring
  └─ qualified → Opportunity Handoff
```

Після handoff ownership майбутньої угоди, проєкту, кандидата, закупівлі або іншого execution lifecycle переходить у відповідний target Domain.

## Учасники

- growth operator;
- Growth automation;
- target Domain owner.

## Тригер

COS отримує спостережуваний факт або набір фактів, які можуть означати нову бізнес-можливість: зміна керівництва, hiring, funding, expansion, нова потреба існуючого клієнта, tender, supplier event, property event або інший signal.

## Межа домену

```text
MARKET / BUSINESS STATE
        ↓
      Signal
        ↓
OpportunityCandidate
        ↓
 Research + Rationale
        ↓
 Explainable Score
        ↓
 Qualification
   ┌────┴─────┐
   ↓          ↓
Monitor    Handoff Package
               ↓
          TARGET DOMAIN
```

Growth **не** створює Sales deal, invoice, project або service ticket. Він створює достатньо обґрунтований package, який target Domain може прийняти або відхилити через окремий міждоменний контракт у наступній фазі.

## Процес

<ProcessDiagram process-id="growth.opportunity-candidate-to-handoff" />

## Представлення відповідальності

<ProcessDiagram process-id="growth.opportunity-candidate-to-handoff" view="ownership" direction="LR" />

Growth automation може знаходити факти, enrichment і score evidence. Qualification та handoff залишаються явними бізнесовими рішеннями Growth до появи policy-controlled auto-qualification.

## Представлення доменів

<ProcessDiagram process-id="growth.opportunity-candidate-to-handoff" view="domain" direction="LR" />

V0.1 є внутрішньодоменним процесом. Межа з Sales, Procurement, HR, Finance, Real Estate та іншими Domains завершується на `OpportunityHandoff`; прямого читання або mutation чужого persistence немає.

## Представлення можливостей

<ProcessDiagram process-id="growth.opportunity-candidate-to-handoff" view="capability" direction="LR" />

Кожен canonical step має Growth-owned capability без capability debt.

## Інваріанти

1. **Signal = observable fact.** Інтерпретація не записується назад у Signal.
2. **Rationale ≠ fact.** `OpportunityRationale` явно зберігає WHY IT MATTERS, problem hypothesis, WHY NOW, evidence, counter-evidence, assumptions, unknowns і confidence.
3. Candidate не може існувати без хоча б одного Signal reference.
4. Candidate не може бути scored до research.
5. Candidate не може бути qualified до score.
6. Handoff неможливий без rationale, score, expected value, recommended play і recommended action.
7. Growth не може disqualify або expire candidate після передачі ownership у target Domain.
8. Один агрегований «AI score» не є canonical truth. Fit, Need, Timing, Access і Value залишаються окремими explainable dimensions.
9. Усі core objects tenant-scoped через `OrganizationId`.
10. Target Domain не отримує mutation authority над Growth state через shared таблицю або framework model.

## Lifecycle

```text
DETECTED
  ↓
ENRICHING
  ↓
RESEARCHED
  ↓
SCORED
  ↓
QUALIFIED
  ↓
READY_FOR_HANDOFF
  ↓
HANDOFF_PENDING
  ├─ accepted → HANDED_OFF
  ├─ rejected → REJECTED_BY_TARGET_DOMAIN
  └─ technical failure → READY_FOR_HANDOFF
```

Бічні стани:

```text
MONITORING
DISQUALIFIED
DUPLICATE
EXPIRED
REJECTED_BY_TARGET_DOMAIN
```

## Growth modes

```text
ACQUIRE
EXPAND
REACTIVATE
DISCOVER
```

Це дозволяє одному Domain шукати не лише клієнтів, а й expansion opportunities, старі можливості для reactivation, партнерів, suppliers, investors, candidates, tenders, properties, acquisitions, projects та technologies.

## WHY NOW

WHY NOW є частиною rationale, а не декоративним текстовим полем CRM.

```text
Observed facts
      ↓
Interpretation
      ↓
Problem hypothesis
      ↓
WHY IT MATTERS
      ↓
WHY NOW
      ↓
Unknowns / counter evidence
      ↓
Qualification decision
```

## Signal Collector runtime

V0.5 формалізує вхід зовнішніх та внутрішніх джерел:

```text
Provider / internal source
   ↓
SignalCollectorInterface
   ↓
CollectedSignal
   ↓
source fingerprint + dedupe receipt
   ├─ same source + same payload → duplicate
   └─ same source + changed payload → conflict
   ↓
canonical Signal
```

Collector run зберігає status, request/next cursor, collected/accepted/duplicate/failed counters та error summary. Зовнішній provider call не тримає відкриту DB transaction; кожний item ingestиться окремо, тому failure одного item не відкочує інші accepted signals.

## External Signal Intake

V0.13 додає push integration path для зовнішніх джерел:

```text
raw JSON envelope
+ X-TN-Timestamp
+ X-TN-Signature
+ X-TN-Idempotency-Key
        ↓
GrowthExternalSignalWebhook
        ↓
organization/source/module validation
        ↓
GrowthApplicationBoundary::ingestExternalSignal
        ↓
canonical Signal
```

Signature: `HMAC_SHA256(timestamp + "." + raw_body, GROWTH_SIGNAL_WEBHOOK_SECRET)`. External ingress має окремий idempotency namespace, SYSTEM event/audit provenance та configured service actor. Edge не залежить від Growth repository або SQL.

## Signal Operations Workspace

V0.12 робить collector runtime операційно видимим:

```text
Registered SignalCollectorInterface adapters
        ↓
/growth/collectors
        ├─ run history
        ├─ accepted / duplicate / failed counters
        ├─ cursor / next cursor
        └─ partial / failed error summary
        ↓
POST /api/v1/growth/collectors/{name}/run
        ↓
/growth/signals
        └─ observable evidence stream
```

SSR layer лише читає projections. Запуск collector виконується через canonical API із CSRF та idempotency key.

## Account Intelligence перед Opportunity

V0.3 додає upstream intelligence layer:

```text
ICP revision
   ↓
GrowthAccount
   ↓
AccountSnapshot (immutable evidence)
   ↓
ICP Match
   ↓
Account Brief
   ↓
Signal / OpportunityCandidate
```

Кожний ICP Match фіксує конкретні `profile_id + profile_revision + model_version + evidence`. Активація нової revision архівує попередню active revision того самого ICP. Це дозволяє відтворити історичне рішення замість перерахунку минулого поточними правилами.

Account enrichment зберігається append-only snapshots. Нові дані не перезаписують попередні факти заднім числом.

## Buying Committee Intelligence

V0.4 додає people layer між Account Brief та Opportunity:

```text
GrowthAccount
   ↓
GrowthContact identity + provenance
   ↓
ContactSnapshot per account
   ↓
Buying Roles + Relationship Strength
   ↓
Buying Committee Assessment
   ├─ role coverage
   ├─ gaps
   ├─ champions
   ├─ blockers
   └─ weak relationship risk
   ↓
Committee Brief
   ↓
Opportunity research / play
```

Контактна identity не містить «вічної» посади або buying role. Title, department, seniority, buying roles і relationship strength є спостереженнями в контексті конкретного Account і зберігаються append-only snapshots із source references.

Committee Assessment фіксує конкретний набір required roles, snapshot ids і `model_version`, тому історичне рішення можна відтворити.

## Evidence-bound Research Intelligence

V0.7 додає AI-assisted research без mutation authority:

```text
Candidate
+ referenced Signals
+ sanitized Account / Committee context
        ↓
governed Kernel\Llm
        ↓
ResearchProposal
        ↓
validate evidence ids ⊆ Candidate.signalIds
        ↓
explicit Accept
        ↓
OpportunityRationale
```

Prompt і structured schema мають власні versions. Research run фіксує exact sanitized context snapshot, provider/model, token usage, cost, status і error summary; proposal зберігає rationale fields, confidence та inference metadata.

Контактні email/LinkedIn identifiers у LLM context не передаються. Модель отримує лише `contact_id` та account-specific role/relationship snapshot.

LLM output не може створити або переписати Signal. Навіть після генерації proposal evidence references перевіряються server-side, а перед acceptance перевіряються повторно всередині transaction.

## Decision Intelligence

V0.6 формалізує qualification decision:

```text
OpportunityRationale
+ Fit / Need / Timing / Access / Value
+ rationale confidence
+ score confidence
        ↓
QualificationPolicy revision
        ↓
hard reject?
   ├─ yes → DISQUALIFIED
   └─ no
        ↓
all qualification minimums + confidence met?
   ├─ yes → QUALIFIED
   └─ no  → MONITOR
        ↓
QualificationEvaluation snapshot
```

Policy не згортає dimensions в один synthetic score. Кожна evaluation зберігає exact rationale, exact score payload, policy revision, failed criteria, outcome, reason та `model_version`. Це дозволяє відтворити історичне рішення навіть після зміни ICP, policy або scoring model.

## Engagement Intelligence / Next Best Action

V0.14 формалізує «що робити далі» окремо від execution:

```text
Candidate
+ Signals
+ Rationale / Score
+ Account / Buying Committee
        ↓
governed structured LLM
        ↓
EngagementRecommendation
        ↓
validate evidence ids ⊆ Candidate Signals
validate contact id ⊆ Candidate account contacts
validate action ↔ channel
        ↓
Accept / Dismiss
```

Одночасно для Candidate може бути лише один `proposed` recommendation; новий supersede-ить старий із збереженням історії. Prompt/model/context snapshot і confidence фіксуються для replay та learning.

Recommendation не є `ActionProposal` і не має mutation authority. Поки немає concrete handler та Policy, Growth не відправляє email, LinkedIn message, call або meeting автоматично.

## Outcome Feedback / Growth Learning

V0.15 повертає фактичний результат назад у Growth:

```text
Growth handoff accepted
        ↓
sales_lead:<id>
        ↓
Sales durable events
        ↓
LeadChanged.client_case_id.to
        ↓
sales_deal:<id>
        ↓
contacted / qualified / disqualified / reply / meeting / won / lost
        ↓
GrowthOutcomeObservation
        ↓
Learning Brief
```

Correlation працює через Growth-owned handoff reference та learning bindings, не через SQL у Sales tables. Один source event може створити максимум один normalized outcome. Unsupported aggregate type не вгадується.

`deal.won` може додати `economic_value + currency`; це pipeline/business outcome, а не Finance-recognized revenue. Lost reason зберігається лише якщо Sales event його фактично передав.

## Learning Workspace

V0.16 додає операційну проєкцію поверх Growth-owned outcomes:

```text
GrowthOutcomeObservation
        ↓
read-only Growth Workspace projection
        ├─ /growth/learning
        │   ├─ outcome funnel
        │   ├─ won value by currency
        │   ├─ lost/disqualified reasons
        │   └─ recent outcomes
        └─ /growth/candidates/{id}
            └─ Candidate outcome history
```

Workspace не читає Sales persistence і не створює Sales mutations. Його завдання — зробити feedback loop видимим для оператора та придатним для наступного Learning/Optimization cycle.

## Experiment Workspace

V0.20 робить V0.19 runtime операційним:

```text
/growth/experiments
        ↓
create draft through API
        ↓
DRAFT → RUNNING ↔ PAUSED → COMPLETED → ARCHIVED
        ↓
/growth/experiments/{id}
  ├─ assignments
  └─ attribution report
        ↓
operator interpretation
```

SSR page controller лише читає `GrowthExperimentBoundary`. Browser mutations використовують `/api/v1/growth/experiments/*` з CSRF та idempotency. Workspace показує conversion rates і outcome evidence, але не має endpoint або UI action для winner selection чи automatic execution.

## Governed Pre-Handoff Engagement Execution

V0.24 розширює V0.22 execution bridge без створення фіктивного Sales state:

```text
Accepted EngagementRecommendation
        ↓
resolve existing Growth learning bindings
        ├─ 1 sales_deal
        │     ↓
        │   sales.send_message → deal
        │
        ├─ 0 sales_deal + email GrowthContact
        │     ↓
        │   growth.send_message → growth_contact
        │     ↓
        │   APPROVAL_REQUIRED
        │     ↓
        │   Platform Notification → n8n outbox
        │
        └─ >1 sales_deal
              ↓
            reject ambiguity
```

Pre-handoff action не містить email address у Kernel Action parameters. `GrowthSendMessageHandler` повторно читає GrowthContact під час execution, перевіряє email identity і лише тоді викликає Growth-owned outbound port.

Durable queue acceptance і provider delivery не змішуються: успішний Kernel Action означає, що Platform Notification прийняла повідомлення у durable integration runtime. Фактична доставка n8n/provider має власний status/retry lifecycle.

## Cross-domain Handoff Protocol

V0.8 робить handoff окремим resumable protocol:

```text
OpportunityHandoff package
        ↓
persist attempt + package fingerprint
        ↓
Candidate: READY_FOR_HANDOFF → HANDOFF_PENDING
        ↓
GrowthHandoffTargetInterface
        ├─ accept(reference) → HANDED_OFF
        ├─ reject(reason)    → REJECTED_BY_TARGET_DOMAIN
        └─ technical error   → READY_FOR_HANDOFF
```

Target call виконується поза DB transaction. Target-side idempotency key стабільний по Candidate, тому retry після timeout не повинен створити duplicate execution object у target Domain.

Running attempt можна resume з persisted `package_json`. Resolution серіалізується Candidate row lock; concurrent resume після першого завершення повертає persisted attempt замість повторного lifecycle transition.

Growth не знає persistence Sales/HR/Procurement/Service і не створює їх aggregates напряму. Конкретний target adapter реалізує Growth-owned port та повертає target-owned reference. V0.9 реалізує Sales target, V0.25 — Service target через його application boundary.

## Sales target adapter

V0.9 підключає перший concrete target:

```text
Growth OpportunityHandoff
        ↓
target_domain = sales
        ↓
contact subject
  └─ valid email required

account subject
  ↓
latest Buying Committee
  ↓
exactly one champion
  ↓
valid champion email
        ↓
SalesWriteService::createLead()
        ↓
sales_lead:<id>
```

Sales сам створює свій execution object через власний application boundary. Growth лише передає package та стабільний Candidate-level idempotency key. Multiple champions, відсутній committee або non-email identity дають explicit target rejection замість евристичного вибору людини.

## Service target adapter

V0.25 додає другий concrete handoff target і підтверджує універсальність `OpportunityHandoff`:

```text
Growth OpportunityHandoff
        ↓
target_domain = service
        ↓
Service module enabled?
  ├─ no  → explicit rejection
  └─ yes
        ↓
ServiceApplicationBoundary::createRequest()
        ↓
service_request:<id>
```

Mapping навмисно зупиняється на Service Request. Growth передає WHY NOW, problem hypothesis, expected value, recommended play/action і Candidate provenance, але не створює Ticket, не призначає виконавця, не встановлює SLA і не керує Service lifecycle.

Target-side idempotency key лишається Candidate-stable з V0.8, тому retry handoff не має створювати дубльовані Service Requests.

## Tenant RSS/Atom Signal Collector

V0.26 додає перший production-shaped pull source:

```text
tenant SignalFeed config
  HTTPS URL
  subject_type / subject_id
  signal_type / confidence
        ↓
rss_atom collector
        ↓
SSRF-safe transport
  public IPv4 only
  DNS pinning
  redirects off
  2 MB cap
        ↓
RSS / Atom normalize
        ↓
CollectedSignal
        ↓
source receipt dedupe
        ↓
canonical Growth Signal
```

Feed configuration є Growth-owned і tenant-scoped. Collector не пише Signal напряму: він повертає `CollectedSignal`, а чинний `GrowthSignalCollectorService` виконує canonical ingestion, run accounting, Event/Audit і idempotent source receipt.

Collector не використовує cursor. Повторний polling є нормальним режимом роботи: зовнішня entry identity нормалізується в stable external key, а duplicate payload відсікається existing source receipt runtime.

## Signal Feed Workspace

V0.27 робить RSS/Atom configuration керованою з `/growth/collectors`:

```text
SSR read
  GrowthSignalFeedBoundary::feeds()
        ↓
feed list + status

Browser mutation
  create / enable / disable
        ↓
/api/v1/growth/signal-feeds/*
        ↓
canonical Growth API guards
```

Page controller не викликає `createFeed()`, `setEnabled()` або `runCollector()`. Mutations залишаються API-owned, із tenant permission, CSRF, correlation та idempotency.

## Executable API V1

V0.10 відкриває Growth runtime через 34 canonical routes під `/api/v1/growth/*`.

Контролер лишається thin adapter:

```text
HTTP
  ↓
tenant/module/security guard
  ↓
Growth Application Boundary
  ↓
Domain / Persistence / Events / Audit
```

Read surface використовує `cos.tenant.access`. Mutation surface використовує `cos.tenant.manage`, CSRF, `X-Idempotency-Key` та correlation id. Web layer не залежить від Growth repositories, PDO або target-domain persistence.

## Growth Workspace

V0.11 додає canonical Web surface:

```text
Growth Overview
  ├─ Opportunity Candidates
  │   └─ Candidate Workspace
  │       ├─ Evidence / Signals
  │       ├─ Research
  │       ├─ Decision
  │       └─ Handoff
  └─ Accounts
      └─ Account Workspace
          ├─ ICP fit
          ├─ Account snapshot
          ├─ Buying Committee
          └─ related Candidates
```

Lists читаються через `GrowthWorkspaceReadModelInterface`. Detail pages складаються з існуючих application briefs. UI mutations не дублюють lifecycle: frontend викликає `/api/v1/growth/*` із CSRF та idempotency key.

## Learning Optimization

V0.17 переводить feedback із «видимого» в «керовано застосовний»:

```text
terminal Candidate outcomes
        ↓
deterministic Growth metrics
  outcome counts / win rate
  score dimension deltas
  ICP fit delta
  signal-type performance
  loss/disqualification reasons
  won value by currency
        ↓
governed optimization recommendation
        ↓
Accept / Dismiss
        ↓
Materialize
        ↓
new DRAFT ICP / Qualification Policy revision
        ↓
separate Activate operation
```

LLM не отримує mutation authority і не рахує primary metrics із raw records. Він бачить server-computed evidence objects та active target snapshots і може цитувати лише `allowed_evidence_ids`.

Materialization не обходить Domain logic: вона викликає чинні `GrowthIntelligenceBoundary::reviseIcpProfile()` або `GrowthDecisionBoundary::reviseQualificationPolicy()`. Якщо base revision перестала бути active, recommendation переходить у `stale`, а не форкає застарілу policy branch.

Мінімальний terminal sample для генерації recommendation — 8 Candidates. Це safety floor, а не статистична гарантія достатності; risks/assumptions і confidence залишаються first-class частиною recommendation.

## Optimization Workspace

V0.18 не створює нового learning lifecycle. Він робить V0.17 керованим із Workspace:

```text
/growth/learning
  ├─ outcome KPIs
  ├─ deterministic optimization evidence
  ├─ latest recommendation
  ├─ current vs proposed criteria
  ├─ risks / assumptions / confidence
  └─ Generate / Accept / Dismiss / Materialize
```

Усі mutation actions йдуть через canonical `/api/v1/growth/learning/optimization/*` endpoints із CSRF та idempotency. SSR controller лише читає `GrowthOptimizationBoundary::optimizationBrief()`.

Workspace навмисно не має `Activate` action. Після materialization нова revision залишається `draft`, доки її окремо не активують через існуючий ICP / Qualification governance flow.

## Experiments & Attribution

V0.19 додає окремий measurement lifecycle:

```text
Hypothesis
  ↓
Experiment draft
  ↓
2–12 weighted variants
  ↓
RUNNING
  ↓
Candidate assignment
  ├─ deterministic weighted split
  └─ explicit manual variant
  ↓
GrowthOutcomeObservation
  ↓
Attribution Report
  ↓
COMPLETE → frozen ended_at window
```

Assignment immutable для пари `Experiment + Candidate`. Candidate з terminal Growth status або вже зафіксованим `won/lost/disqualified` outcome не може заднім числом увійти в новий experiment.

Attribution rules:

- outcome враховується лише якщо `observed_at >= assigned_at`;
- completed experiment додатково обмежує `observed_at <= ended_at`;
- funnel counts — distinct Candidates, а не кількість event rows;
- won value бере останній `won` observation Candidate у межах attribution window;
- один Candidate може брати участь у різних experiments, але лише в одному variant кожного experiment.

V0.19 не робить statistical winner selection і не виконує variant config. Це measurement runtime; execution та decision policy лишаються окремими шарами.

## Experiment Decision Intelligence

V0.21 закриває measurement loop керованим висновком:

```text
Completed Experiment
        ↓
Attribution Report
        ↓
deterministic evidence ids
        ↓
governed structured LLM
        ↓
Decision Recommendation
  promote_variant
  iterate
  continue
  stop
  inconclusive
        ↓
Accept / Dismiss
```

Модель не бачить raw outcome rows і не рахує conversion самостійно. Вона може цитувати лише `allowed_evidence_ids` та вибирати variant лише з `allowed_variant_keys`.

Для `promote_variant` діє server-side safety floor: щонайменше 20 assigned Candidates загалом і 5 у кожному variant. Це не statistical significance test і не подається як такий.

Accepted recommendation не має execution authority. Experiment status, variant config, outreach, ICP/Qualification activation та будь-яка інша mutation лишаються поза V0.21.

## Governed post-handoff engagement execution

V0.22 додає execution bridge без передачі execution authority Growth:

```text
Accepted EngagementRecommendation
        ↓
exactly one sales_deal binding
        ↓
human-provided body
        ↓
GrowthEngagementExecutionService
        ↓
GrowthActionProposalGateway
        ↓
Kernel ActionProposal
        ↓
Sales Policy
        ↓
Pending approval / Queued / Rejected
```

Для recommendation зберігається один canonical execution link. Payload fingerprint блокує повторне використання recommendation з іншим body навіть у crash-retry window. Kernel Action idempotency key стабільний по recommendation.

Growth persistence не дублює message body; зберігаються лише action/reference linkage, channel та payload fingerprint. Application service не залежить від Kernel Action/Policy implementation і не викликає `execute()` або command dispatch.

Поточний bridge підтримує `send_email`, `connect_linkedin`, `offer_diagnostic`, `send_case_study`, `ask_introduction`, `invite_webinar` лише для email/LinkedIn channels. Call, monitor, ignore та create_report execution лишаються поза V0.22.

## Engagement Execution Workspace

V0.23 робить V0.14 + V0.22 operational у Candidate Workspace без нової execution authority:

```text
Candidate Workspace
  ↓
Generate Next Best Action
  ↓
Accept / Dismiss recommendation
  ↓
server-side execution eligibility
  ↓
human-provided message body
  ↓
canonical V0.22 execution API
  ↓
Kernel Action status / target trace
```

Workspace не approve і не execute Sales Actions напряму. Він не викликає Sales approval/action endpoints і не дублює message body у Growth persistence. Якщо recommendation ще не accepted, не message-capable, channel не підтримується або немає рівно одного `sales_deal` binding, UI показує server-side eligibility reason замість імпровізації на клієнті.

## Credentialed JSON Signal Intake

V0.28 додає provider-neutral pull path:

```text
GrowthJsonSignalSource
  url
  auth_mode
  credential_reference
  subject mapping
        ↓
CredentialVaultInterface
        ↓
SafeHttpCredentialedJsonSignalReader
        ↓
CredentialedJsonSignalParser
        ↓
CredentialedJsonSignalCollector
        ↓
canonical GrowthSignalCollectorService
        ↓
Signal
```

Domain persistence містить лише opaque `credential_reference`. API read model його редагує до `credential_configured` + короткого hash reference. Raw credential material існує лише в transport call scope.

Поточний envelope: `items[]` із `id`, `occurred_at`, `source_reference`, `facts`. Collector cursorless; durable source receipts виконують dedupe між повторними polling runs.

## Credentialed Source Workspace

V0.29 додає UI без нового mutation authority:

```text
/growth/collectors
        ↓
GrowthJsonSignalSourceBoundary::sources()
        ↓
sanitized source rows

browser create / toggle
        ↓
/api/v1/growth/json-signal-sources/*
        ↓
V0.28 Application Boundary
```

Existing `credential_reference` не повертається у Workspace. Create form приймає reference як write-only configuration value; після створення UI показує лише `credential_configured`, auth mode/header та source mapping.

## Scheduled Signal Monitoring

V0.30 перетворює configured pull sources на безперервний monitoring loop без другого ingestion path:

```text
CosScheduleProvider
        ↓ due every configured interval
RedispatchMessage → async
        ↓
RunGrowthSignalPollingCommandHandler
        ↓
GrowthSignalPollingTargetRepositoryInterface
  organization + collector kinds only
        ↓
Growth module enabled?
        ↓
GrowthSignalCollectorBoundary::runCollector()
        ↓
existing collector run / dedupe / Signal / Event / Audit
```

Scheduler не читає URL або credentials і не виконує HTTP сам. Один collector failure ізолюється в межах конкретного target; інші organization/collectors продовжують polling.

Idempotency key формується з cadence bucket + collector name. Оскільки operation receipts tenant-scoped, однаковий key для того самого collector в різних organizations не конфліктує. Source receipt лишається другим, content-level dedupe шаром.

Polling default-off. Якщо scheduler enable flag увімкнено без positive system actor id, composition fail-closed.

## Polling Operations Workspace

V0.31 додає read-only operational projection поверх V0.30:

```text
current tenant
        ↓
GrowthSignalPollingStatusProvider
        ↓
targetForOrganization(organization_id)
        ↓
enabled collector kinds + source counts
        +
deployment scheduler config
        ↓
/growth/collectors status panel
```

Projection tenant-scoped. Він не використовує global `targets()`, не показує кількість інших organizations, raw system actor id, credential references або provider URLs. Browser не може enable/disable scheduler; для цього немає нового endpoint чи SSR mutation.

## Handoff contract

V0.1 формує `OpportunityHandoff` із:

- candidate / organization identity;
- opportunity type і Growth mode;
- subject та target Domain;
- Signal references;
- WHY IT MATTERS;
- problem hypothesis;
- WHY NOW;
- evidence та unknowns;
- explainable score dimensions;
- expected value;
- recommended play;
- recommended action.

Це не Sales Lead. Це **Opportunity Package**.

## Статус V0.29

`process_state: to-be` поки навмисний. V0.29 додає operational Workspace surface для credentialed JSON sources поверх V0.28 runtime. SSR controller лише читає sanitized source projection; create/enable/disable виконуються через canonical Growth API.

## Карта коду

```text
app/Domains/Growth/module.php
app/Domains/Growth/Domain/Signal.php
app/Domains/Growth/Domain/OpportunityCandidate.php
app/Domains/Growth/Domain/OpportunityRationale.php
app/Domains/Growth/Domain/ScoreDimension.php
app/Domains/Growth/Domain/OpportunityScore.php
app/Domains/Growth/Application/DTO/OpportunityHandoff.php
app/Domains/Growth/Application/UseCase/PrepareOpportunityHandoff.php
app/Domains/Growth/Application/Service/GrowthWorkflowService.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthRepository.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthMutationReceipt.php
app/Domains/Growth/Application/Service/GrowthBuyingCommitteeService.php
app/Domains/Growth/Application/Service/GrowthSignalCollectorService.php
app/Domains/Growth/Application/Service/GrowthDecisionService.php
app/Domains/Growth/Application/Service/GrowthResearchService.php
app/Domains/Growth/Application/Service/GrowthHandoffService.php
app/Domains/Growth/Automation/Action/GrowthSendMessageHandler.php
app/Domains/Growth/Automation/Policy/GrowthPolicyCatalog.php
app/Domains/Growth/Infrastructure/Notification/PlatformNotificationGrowthOutboundMessageGateway.php
app/Domains/Growth/Application/Service/GrowthEngagementService.php
app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php
app/Domains/Growth/Infrastructure/Action/KernelGrowthActionProposalGateway.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementExecutionRepository.php
app/Domains/Growth/Application/Service/GrowthLearningService.php
app/Domains/Growth/Application/Service/GrowthOptimizationService.php
app/Domains/Growth/Application/AI/GrowthOptimizationPrompt.php
app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthOptimizationGateway.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthOptimizationRepository.php
app/Domains/Growth/Application/Service/GrowthExperimentService.php
app/Domains/Growth/Application/Service/GrowthExperimentDecisionService.php
app/Domains/Growth/Application/AI/GrowthExperimentDecisionPrompt.php
app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthExperimentDecisionGateway.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthExperimentDecisionRepository.php
symfony/src/Web/Growth/GrowthPageController.php
symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php
app/Interfaces/Web/View/growth/experiments.phtml
app/Interfaces/Web/View/growth/experiment.phtml
frontend/features/growth/workspace.js
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthExperimentRepository.php
app/Domains/Growth/Automation/Event/GrowthOutcomeFeedbackConsumer.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthLearningRepository.php
app/Domains/Growth/Application/AI/GrowthEngagementPrompt.php
app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthEngagementGateway.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementRepository.php
app/Domains/Growth/Application/Service/GrowthHandoffTargetRegistry.php
app/Domains/Growth/Infrastructure/Handoff/SalesGrowthHandoffTarget.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthHandoffRepository.php
app/Domains/Growth/Application/AI/GrowthResearchPrompt.php
app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthResearchGateway.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthResearchRepository.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthDecisionRepository.php
app/Domains/Growth/Application/Contract/SignalCollectorInterface.php
app/Domains/Growth/Application/Service/SignalCollectorRegistry.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthBuyingCommitteeRepository.php
app/Domains/Growth/Application/Service/GrowthJsonSignalSourceService.php
app/Domains/Growth/Infrastructure/Collector/CredentialedJsonSignalCollector.php
app/Domains/Growth/Infrastructure/Feed/SafeHttpCredentialedJsonSignalReader.php
app/Domains/Growth/Infrastructure/Feed/CredentialedJsonSignalParser.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthJsonSignalSourceRepository.php
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthSignalPollingTargetRepository.php
symfony/src/Application/Growth/Command/RunGrowthSignalPollingCommand.php
symfony/src/Application/Growth/Command/RunGrowthSignalPollingCommandHandler.php
symfony/src/Application/Growth/ReadModel/GrowthSignalPollingStatusProvider.php
app/Domains/Growth/Automation/Event/GrowthEventType.php
app/Domains/Growth/Bootstrap/GrowthDomainModule.php
app/migrations/20260921_000067_growth_v020_runtime.sql
app/migrations/20260921_000068_growth_v030_account_intelligence.sql
app/migrations/20260922_000069_growth_v040_buying_committee.sql
app/migrations/20260922_000070_growth_v050_signal_collectors.sql
app/migrations/20260922_000071_growth_v060_decision_intelligence.sql
app/migrations/20260922_000072_growth_v070_research_intelligence.sql
app/migrations/20260922_000073_growth_v080_handoff_protocol.sql
app/migrations/20260924_000093_growth_v0280_credentialed_json_collector.sql
app/migrations/20260924_000095_growth_v0300_signal_polling_scheduler.sql
app/migrations/20260924_000096_growth_v0310_polling_operations_workspace.sql
resources/processes/growth-opportunity-candidate-to-handoff.json
```
