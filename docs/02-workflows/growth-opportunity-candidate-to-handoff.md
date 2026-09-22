---
title: Signal → Qualified Opportunity Handoff
description: "Канонічний Growth V0.1 процес від перевіреного сигналу через research, rationale та explainable scoring до handoff-ready Opportunity Candidate."
status: active
updated: 2026-09-22
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

Growth не знає persistence Sales/HR/Procurement/Service і не створює їх aggregates напряму. Конкретний target adapter реалізує Growth-owned port та повертає target-owned reference.

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

## Статус V0.11

`process_state: to-be` поки навмисний. V0.11 додає provider-backed SSR Growth Workspace поверх API/Application boundaries. Інші target adapters, signal provider adapters та engagement додаються окремими хвилями.

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
app/Domains/Growth/Automation/Event/GrowthEventType.php
app/Domains/Growth/Bootstrap/GrowthDomainModule.php
app/migrations/20260921_000067_growth_v020_runtime.sql
app/migrations/20260921_000068_growth_v030_account_intelligence.sql
app/migrations/20260922_000069_growth_v040_buying_committee.sql
app/migrations/20260922_000070_growth_v050_signal_collectors.sql
app/migrations/20260922_000071_growth_v060_decision_intelligence.sql
app/migrations/20260922_000072_growth_v070_research_intelligence.sql
app/migrations/20260922_000073_growth_v080_handoff_protocol.sql
resources/processes/growth-opportunity-candidate-to-handoff.json
```
