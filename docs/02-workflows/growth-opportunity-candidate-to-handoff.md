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
HANDED_OFF
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

## Статус V0.4

`process_state: to-be` поки навмисний. V0.4 поверх lifecycle runtime, ICP та Account Intelligence додає evidence-backed Contact identity, immutable ContactSnapshot, Buying Roles, relationship strength, committee coverage/gaps та deterministic Buying Committee Assessment. External collectors, engagement, AI agents, cross-domain acceptance, API та production UI додаються окремими хвилями.

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
app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthBuyingCommitteeRepository.php
app/Domains/Growth/Automation/Event/GrowthEventType.php
app/Domains/Growth/Bootstrap/GrowthDomainModule.php
app/migrations/20260921_000067_growth_v020_runtime.sql
app/migrations/20260921_000068_growth_v030_account_intelligence.sql
app/migrations/20260922_000069_growth_v040_buying_committee.sql
resources/processes/growth-opportunity-candidate-to-handoff.json
```
