---
title: Engineering Runtime V2.0 Final Master Specification
description: Канонічна технічна специфікація Engineering Runtime V2.0 та моделі інженерних агентів COS.
status: current
generated: true
updated: 2026-10-06
kind: developer
---

# ENGINEERING RUNTIME V2.0  
## Complex Domain Development Framework

**Status:** FINAL MASTER SPECIFICATION  
**Target:** COS Engineering / Dev Agents  
**Version:** 2.0 FINAL  
**Primary goal:** забезпечити автономну розробку великих складних Domain у COS через керований набір взаємопов'язаних feature workflows.

**Canonical authority:** цей документ є єдиним нормативним Master Specification для Engineering Runtime V2.0. Він замінює попередні фрагментарні описи агентної моделі та має пріоритет у разі суперечності з ранніми чернетками.

**Normative language:** `MUST / повинен`, `MUST NOT / не повинен`, `REQUIRED / обов'язково` є нормативними вимогами. `SHOULD / бажано` описує рекомендовану поведінку, яку можна відхилити лише з зафіксованим rationale.

---

# 1. Мета

Engineering Runtime має перейти від legacy feature-only моделі:

```text
Feature
→ Specification
→ Architecture
→ Development
→ Review
→ QA
→ Merge
```

до дворівневої моделі:

```text
DOMAIN DEVELOPMENT RUNTIME
        │
        ├── Capability / Module
        │       │
        │       ├── Feature Workflow
        │       ├── Feature Workflow
        │       └── Feature Workflow
        │
        ├── Capability / Module
        │       ├── Feature Workflow
        │       └── Feature Workflow
        │
        └── Domain Integration / Release
```

Existing Feature Engineering Runtime залишається базовою одиницею виконання, але його canonical V2 execution path визначається Agent Model:

```text
Manager
→ Product / Requirements
→ QA Planner
→ Principal Architect
→ Developer
→ Reviewer
→ QA Executor
→ Manager
```


Над ним створюється **Domain Development Runtime**, який:

- планує Domain;
- розбиває його на частини;
- контролює залежності;
- забезпечує єдину архітектуру;
- запускає feature workflows;
- дозволяє часткову паралельну розробку;
- контролює інтеграцію;
- виконує Domain-level QA;
- формує Domain Release.

---

# 2. Основний архітектурний принцип

Engineering Agents:

> **BUILD domains and runtimes.**

Business / operational agents:

> **OPERATE inside those domains and runtimes.**

Engineering Agents не повинні мати бізнес-функції майбутнього Domain.

Наприклад, для Capital Markets:

```text
Engineering Agent
    ↓ builds
Capital Markets Domain
    ↓ contains
Market Data Runtime
Trading Runtime
Risk Runtime
Research Runtime
```

Developer Agent не повинен сам:

- торгувати;
- аналізувати ринок;
- вибирати біржі;
- прогнозувати ціну;
- виконувати стратегію;
- використовувати production trading credentials.

Він реалізує визначений контракт.

---

# 3. Рівні Engineering Runtime

Запровадити три рівні:

```text
LEVEL 1
Domain Initiative

LEVEL 2
Capability / Module

LEVEL 3
Feature
```

Приклад:

```text
Capital Markets Domain
│
├── Instrument Model
│   ├── InstrumentDescriptor
│   ├── InstrumentFamily
│   └── InstrumentRegistry
│
├── Venue Layer
│   ├── Venue abstraction
│   ├── VenueRegistry
│   └── VenueCapabilities
│
├── Market Data
│   ├── Quote
│   ├── OrderBook
│   ├── Trade
│   └── MarketSnapshot
│
├── Execution
│   ├── Order
│   ├── Execution
│   └── Position
│
└── Risk
    ├── Limits
    ├── Exposure
    └── RiskPolicy
```

Кожен leaf Feature реалізується існуючим feature workflow.

---

# 4. Новий Domain Development Runtime

Створити окремий runtime для великих Domain.

Умовна назва:

```text
EngineeringDomainRuntime
```

або:

```text
DomainDevelopmentRuntime
```

Він не замінює `EngineeringAutonomousProgressionService`.

Він керує множиною feature workflows.

Архітектура:

```text
DomainDevelopmentRuntime
        ↓
DomainExecution
        ↓
CapabilityExecution[]
        ↓
FeatureExecution[]
        ↓
existing Engineering Workflow
```

---

# 5. Domain Initiative

Створити сутність:

```php
DomainInitiative
```

Мінімальні поля:

```text
id
domain_key
name
description

status
version

business_goal
domain_scope
out_of_scope

architecture_principles
technical_constraints

capabilities
dependencies

target_repository
target_branch

created_at
updated_at
```

Domain Initiative є головним контейнером усієї розробки Domain.

---

# 6. Domain статуси

Запровадити:

```text
DRAFT
ANALYSIS
DECOMPOSITION
ARCHITECTURE
READY_FOR_IMPLEMENTATION
IMPLEMENTATION
INTEGRATION
DOMAIN_QA
HUMAN_APPROVAL
RELEASE_READY
COMPLETED
BLOCKED
FAILED
CANCELLED
```

Приблизний lifecycle:

```text
DRAFT
 ↓
ANALYSIS
 ↓
DECOMPOSITION
 ↓
ARCHITECTURE
 ↓
READY_FOR_IMPLEMENTATION
 ↓
IMPLEMENTATION
 ↓
INTEGRATION
 ↓
DOMAIN_QA
 ↓
HUMAN_APPROVAL
 ↓
RELEASE_READY
 ↓
COMPLETED
```

---

# 7. Domain Specification

Для складного Domain створюється окремий артефакт:

```text
DOMAIN_SPECIFICATION
```

Він повинен описувати:

```yaml
domain:
  key:
  name:
  purpose:

business_context:

scope:
  included:
  excluded:

actors:

capabilities:

core_entities:

value_objects:

aggregates:

domain_services:

repositories:

external_dependencies:

integration_boundaries:

events:

commands:

queries:

permissions:

audit_requirements:

security_requirements:

data_requirements:

performance_requirements:

availability_requirements:

migration_requirements:

compatibility_requirements:

observability_requirements:

known_constraints:

future_extensions:
```

Domain Specification не є feature task.

---

# 8. Domain Decomposition

Після Domain Specification система повинна створити:

```text
DOMAIN_DECOMPOSITION
```

Структура:

```yaml
capabilities:

dependencies:

implementation_units:

parallelization_groups:

critical_path:

shared_foundations:

integration_points:

domain_acceptance_criteria:
```

Кожна implementation unit повинна перетворюватися на Feature.

---

# 9. Feature Dependency Graph

Необхідно реалізувати DAG:

```text
FeatureDependencyGraph
```

Типи залежностей:

```text
REQUIRES
BLOCKS
EXTENDS
IMPLEMENTS
USES
MIGRATES
INTEGRATES_WITH
```

Приклад:

```text
Money
  ↓
Price
  ↓
InstrumentDescriptor
  ↓
Venue
  ↓
MarketData
  ↓
Order
  ↓
Execution
```

Runtime не повинен запускати feature, поки required dependency не завершена.

---

# 10. Паралельне виконання

Domain Runtime має підтримувати:

```text
SEQUENTIAL
PARALLEL
DEPENDENCY_DRIVEN
```

Default:

```text
DEPENDENCY_DRIVEN
```

Наприклад:

```text
Money ─────┐
Quantity ──┼──→ Instrument
Rate ──────┘

Instrument ──→ Venue
Instrument ──→ MarketData

Venue + MarketData
        ↓
Execution
```

Незалежні feature можуть розроблятися паралельно.

---

# 11. Shared Foundation Features

Для складного Domain необхідно відрізняти:

```text
FOUNDATION
CORE
INTEGRATION
APPLICATION
UI
INFRASTRUCTURE
```

Приклад Capital Markets:

```text
FOUNDATION
Money
Price
Quantity
Rate

CORE
Instrument
Venue
Order

INTEGRATION
Exchange adapters

APPLICATION
Scanner
Execution services

UI
Market dashboard
```

Foundation features мають реалізовуватися першими.

---

# 12. Domain Architecture Pack

До старту основної розробки Architect Agent повинен створити:

```text
DOMAIN_ARCHITECTURE
```

який містить:

```yaml
bounded_context:

module_structure:

namespace_structure:

domain_layers:

aggregate_boundaries:

entity_relationships:

database_boundaries:

API_boundaries:

event_contracts:

integration_contracts:

dependency_rules:

security_boundaries:

permissions_model:

audit_model:

feature_flags:

observability:

failure_model:

migration_strategy:

testing_strategy:
```

---

# 13. Architecture Constitution

Для кожного Domain створюється набір незмінних правил:

```text
DOMAIN_ARCHITECTURE_CONSTITUTION
```

Наприклад:

```yaml
rules:

  - domain code cannot depend directly on infrastructure

  - external exchanges must be accessed only through VenueAdapter

  - monetary values cannot use float

  - cross-domain communication must use declared contracts

  - every external operation must be auditable

  - production credentials cannot enter domain layer

  - execution commands must be idempotent
```

Developer Agent не має права самостійно змінювати Constitution.

Потрібна повторна Architect review.

---

# 14. Domain Context Pack

Feature Agent не повинен отримувати весь репозиторій і 500 сторінок документації.

Для кожної feature система формує:

```text
FEATURE_CONTEXT_PACK
```

Містить тільки:

```text
Domain Specification
relevant Domain Architecture
Architecture Constitution
relevant capability
direct dependencies
interfaces
related entities
related events
coding rules
security constraints
QA Test Plan
allowed paths
forbidden paths
```

---

# 15. Контекст залежностей

Перед розробкою Feature автоматично збирати:

```yaml
dependency_context:
  completed_features:
  exported_interfaces:
  domain_events:
  database_contracts:
  public_services:
  API_contracts:
```

Developer повинен використовувати реальні реалізовані контракти, а не придумувати їх повторно.

---

# 16. Contract Registry

Створити централізований:

```text
EngineeringContractRegistry
```

Типи:

```text
DOMAIN_INTERFACE
APPLICATION_INTERFACE
API_CONTRACT
EVENT_CONTRACT
DATABASE_CONTRACT
INTEGRATION_CONTRACT
PERMISSION_CONTRACT
```

Кожен контракт повинен мати:

```text
id
name
version
owner_domain
producer
consumers
schema
compatibility
status
```

---

# 17. Contract Versioning

Підтримати:

```text
v1
v1.1
v2
```

Breaking changes повинні бути явно позначені:

```text
BACKWARD_COMPATIBLE
BREAKING
DEPRECATED
```

Feature не може тихо змінити public contract.

---

# 18. Domain Module Ownership

Кожна feature повинна мати:

```text
owned_paths
shared_paths
forbidden_paths
```

Наприклад:

```yaml
owned_paths:
  - symfony/src/CapitalMarkets/Instrument/

shared_paths:
  - symfony/config/

forbidden_paths:
  - symfony/src/CRM/
  - symfony/src/HR/
```

Це повинно перевірятися runtime.

---

# 19. Shared File Collision Control

При паралельній роботі агентів необхідний механізм:

```text
PathReservation
```

Feature резервує файли або directories.

При конфлікті:

```text
WAIT
SERIALIZE
ARCHITECT_DECISION
```

Заборонено дозволяти двом Developer Agents незалежно переписувати той самий architectural file.

---

# 20. Branch Strategy

Для Domain:

```text
domain/<domain-key>
```

Для feature:

```text
feature/<domain-key>/<feature-key>
```

Feature PR:

```text
feature branch
      ↓
domain integration branch
```

Після Domain QA:

```text
domain branch
      ↓
main
```

Альтернативно runtime може залишити прямі PR у `main`, якщо feature повністю незалежна.

Стратегія задається Domain Architecture.

---

# 21. Integration Branch

Для великих Domain підтримати:

```text
Domain Integration Branch
```

Призначення:

- інтеграція кількох feature;
- domain-level tests;
- contract validation;
- migrations validation;
- regression;
- smoke.

Це не production branch.

---

# 22. Engineering Agent Model

Engineering Runtime V2.0 повинен використовувати чітко визначену систему агентів.

Базовий набір:

```text
DOMAIN LEVEL

1. Engineering Manager / Coordinator
2. Product / Requirements Agent
3. Principal Architect
8. Integration & Release Agent


FEATURE LEVEL

1. Engineering Manager / Coordinator
2. Product / Requirements Agent
3. QA Planner
4. Principal Architect
5. Developer
6. Reviewer
7. QA Executor
```

Не кожен Agent обов'язково повинен бути окремою фізичною LLM-конфігурацією.

Agent є логічною роллю, яка має:

```text
role
responsibilities
allowed_actions
forbidden_actions
required_inputs
required_outputs
decision_scope
escalation_rules
tool_permissions
completion_criteria
```

Одна модель може виконувати різні Agent Roles у різних ізольованих execution context.

Але один execution не повинен одночасно виконувати конфліктні ролі.

Наприклад:

```text
Developer
```

не може в межах одного execution сам:

```text
implement
→ review
→ QA approve
→ merge
```

Це порушує незалежність контролю.

---

# 23. Agent №1 — Engineering Manager / Coordinator

## 23.1. Призначення

Engineering Manager є основним orchestration agent.

Він не пише production code і не визначає самостійно domain architecture.

Його задача:

> перетворити Engineering Initiative на контрольований процес виконання.

Engineering Manager працює на двох рівнях:

```text
DOMAIN_COORDINATION_MODE

FEATURE_COORDINATION_MODE
```

---

## 23.2. Domain Coordination Mode

На рівні Domain Manager:

```text
отримує Master Specification

↓
ініціює Domain Analysis

↓
передає requirements Product Agent

↓
отримує Domain Specification

↓
ініціює Architecture

↓
отримує Domain Architecture

↓
організовує Decomposition

↓
створює Capability / Feature executions

↓
контролює Dependency Graph

↓
запускає scheduler

↓
контролює implementation

↓
організовує integration

↓
запускає Domain QA

↓
готує Human Approval

↓
передає Domain у Release
```

Manager не повинен сам вигадувати:

```text
business requirements
architecture
acceptance criteria
test results
implementation decisions
```

Він координує інших агентів.

---

## 23.3. Feature Coordination Mode

Для кожної Feature Manager контролює цикл:

```text
Feature Candidate
↓
Requirements
↓
QA Planning
↓
Architecture
↓
Ready For Development
↓
Development
↓
Review
↓
QA
↓
Complete
```

Manager визначає:

```text
current_state
next_agent
blocking_reason
required_artifacts
required_revalidation
retry_strategy
escalation
```

---

## 23.4. Inputs

```text
Domain Initiative
Domain Specification
Domain Architecture
Domain Decomposition
Feature Dependency Graph
Execution Policies
Agent Capability Registry
current workflow state
agent reports
findings
QA results
repository state
CI state
```

---

## 23.5. Outputs

```text
Execution Plan
Agent Assignment
Feature Execution Request
Rework Request
Escalation Request
Blocked Decision
Integration Request
QA Request
Human Approval Request
```

---

## 23.6. Manager decision scope

Manager може:

```text
start eligible Agent Run
stop Agent Run
retry infrastructure failure
return task for rework
change execution priority
schedule ready feature
pause blocked feature
request Architect revalidation
request QA revalidation
request Human Decision
```

Manager не може:

```text
change Acceptance Criteria
change Domain Architecture
approve own implementation
ignore BLOCKER
override failed QA
merge despite failed policy
silently change scope
```

---

# 24. Agent №2 — Product / Requirements Agent

## 24.1. Призначення

Product / Requirements Agent відповідає на питання:

> Що саме система повинна робити?

Він не вирішує:

> Як це технічно реалізувати?

---

## 24.2. Domain Mode

Створює:

```text
DOMAIN_SPECIFICATION

DOMAIN_SCOPE

DOMAIN_ACCEPTANCE_CRITERIA

CAPABILITY_MAP

ACTOR_MAP

BUSINESS_RULES
```

Agent повинен витягнути з Master Specification:

```text
business goal
actors
use cases
scope
out of scope
capabilities
business rules
constraints
expected behavior
acceptance criteria
unknown requirements
```

---

## 24.3. Feature Mode

Для конкретної Feature створює:

```text
FEATURE_SPEC

ACCEPTANCE_CRITERIA

BUSINESS_RULES

CONTEXT_MAP
```

Feature Specification повинна бути достатньо конкретною, щоб Architect і QA Planner могли працювати незалежно.

---

## 24.4. Requirements quality checks

Agent повинен перевірити:

```text
requirement is testable

requirement is unambiguous

scope is bounded

expected result exists

failure behavior is defined where required

permissions are identified

important edge cases are identified

out-of-scope is explicit
```

---

## 24.5. Заборонено

Product Agent не може:

```text
визначати namespace structure

вибирати database schema

вигадувати REST endpoints без functional requirement

визначати concrete implementation class

писати production code

змінювати Domain Architecture

позначати Feature QA PASS
```

---

# 25. Agent №3 — QA Planner

## 25.1. Призначення

QA Planner працює ДО Developer.

Його головне питання:

> Як ми доведемо, що Feature реалізована правильно?

QA Planner не тестує готову реалізацію.

Він визначає критерії перевірки до написання коду.

---

## 25.2. Inputs

```text
Feature Specification
Acceptance Criteria
Domain Specification
Domain Architecture
Architecture Constitution
Risk Classification
related contracts
existing regression suite
```

---

## 25.3. Outputs

Основний artifact:

```text
QA_TEST_PLAN
```

Приклад структури:

```yaml
feature:

acceptance_criteria:

test_scenarios:

positive_cases:

negative_cases:

edge_cases:

permission_cases:

tenant_isolation_cases:

contract_tests:

integration_tests:

regression_tests:

migration_tests:

security_tests:

smoke_tests:

required_evidence:

blocking_failures:
```

---

## 25.4. QA Planner повинен визначити

```text
що тестуємо

на якому рівні тестуємо

який результат PASS

який результат FAIL

які AC покриває кожний test case

які існуючі сценарії можуть отримати regression

які tests Developer повинен додати

які tests QA Executor повинен виконати незалежно
```

---

## 25.5. Requirement Testability Gate

Якщо Acceptance Criteria неможливо однозначно протестувати:

```text
QA_PLAN_BLOCKED
```

Feature повертається Product Agent.

QA Planner не повинен сам переписувати business requirement.

---

## 25.6. Architecture Testability Gate

Якщо architecture не дозволяє нормально протестувати behavior:

```text
ARCHITECTURE_TESTABILITY_REVIEW_REQUIRED
```

Task повертається Architect Agent.

---

# 26. Agent №4 — Principal Architect

## 26.1. Призначення

Architect відповідає на питання:

> Як Feature або Domain повинні бути вбудовані в COS?

Architect не реалізує feature замість Developer.

---

## 26.2. Domain Architecture Mode

Architect створює:

```text
DOMAIN_ARCHITECTURE

DOMAIN_ARCHITECTURE_CONSTITUTION

SHARED_FOUNDATION_PLAN

CONTRACT_MODEL

PERSISTENCE_BOUNDARIES

INTEGRATION_BOUNDARIES
```

Визначає:

```text
bounded context

module structure

namespace structure

dependency direction

aggregate boundaries

entities

value objects

domain services

repository interfaces

public contracts

events

cross-domain dependencies

persistence ownership

migration strategy

security boundaries

permissions model

observability requirements

failure model

testing boundaries
```

---

## 26.3. Feature Architecture Mode

Для Feature Architect створює:

```text
FEATURE_ARCHITECTURE
```

Мінімальна структура:

```yaml
feature:

architecture_version:

affected_modules:

owned_paths:

shared_paths:

forbidden_paths:

entities:

value_objects:

services:

repositories:

interfaces:

events:

commands:

queries:

database_changes:

migrations:

API_changes:

integration_points:

security_implications:

permission_changes:

testing_implications:

implementation_constraints:
```

---

## 26.4. Existing Code Analysis

Перед створенням нової abstraction Architect зобов'язаний перевірити:

```text
existing classes

existing interfaces

existing SharedKernel primitives

existing services

existing contracts

existing events

existing database structures

existing APIs

existing cross-domain capabilities
```

Правило:

> reuse before create.

---

## 26.5. Architect Decision Records

Важливі рішення повинні створювати:

```text
ARCHITECTURE_DECISION_RECORD
```

Мінімально:

```text
decision
reason
alternatives
consequences
affected_components
architecture_version
```

---

## 26.6. Заборонено

Architect не може:

```text
вигадувати business requirements

змінювати Acceptance Criteria

маскувати requirement gap архітектурним рішенням

самостійно approve власну implementation

ігнорувати Architecture Constitution

створювати приховані cross-domain dependencies
```

---

# 27. Agent №5 — Developer

## 27.1. Призначення

Developer реалізує затверджений контракт.

Основний принцип:

> Developer має вирішувати implementation problems, але не product або architecture problems.

---

## 27.2. Modes

```text
FEATURE_IMPLEMENTATION_MODE

REWORK_MODE

DOMAIN_INTEGRATION_MODE
```

---

## 27.3. Required Context

Developer отримує:

```text
Feature Specification

Acceptance Criteria

QA Test Plan

Domain Architecture

Feature Architecture

Architecture Constitution

Dependency Context

Contract Registry

Repository Context

Allowed Paths

Shared Paths

Forbidden Paths

Risk Classification
```

---

## 27.4. Feature Implementation Mode

Developer:

```text
аналізує існуючий код

реалізує Feature Architecture

створює/змінює production code

створює migrations

створює required tests

оновлює configuration

реєструє contracts/events

запускає local validation

формує implementation report

створює commit/PR
```

---

## 27.5. Developer Implementation Report

Після завершення Developer формує:

```text
IMPLEMENTATION_REPORT
```

Наприклад:

```yaml
implemented:

changed_files:

new_files:

tests_added:

migrations:

contracts_changed:

events_changed:

deviations:

known_limitations:

validation_results:

repository_revision:
```

---

## 27.6. Architecture ambiguity

Якщо Developer бачить, що Feature Architecture недостатня:

```text
ARCHITECTURE_CLARIFICATION_REQUIRED
```

Він не повинен сам непомітно змінювати architecture.

---

## 27.7. Requirement ambiguity

Якщо проблема знаходиться в requirements:

```text
REQUIREMENT_CLARIFICATION_REQUIRED
```

---

## 27.8. Developer forbidden actions

Developer не має права:

```text
придумувати нові requirements

змінювати Acceptance Criteria

змінювати bounded context

змінювати Architecture Constitution

змінювати public contract без approval

видаляти failing tests для отримання green CI

обходити permissions

відключати security checks

ігнорувати tenant isolation

писати у forbidden paths

змінювати інший Domain напряму

merge власний PR

позначати власну реалізацію QA PASS
```

---

# 28. Agent №6 — Reviewer

## 28.1. Призначення

Reviewer відповідає на питання:

> Чи реалізовано Feature технічно правильно і відповідно до затвердженої архітектури?

Reviewer не дублює QA.

Reviewer перевіряє implementation correctness.

QA перевіряє behavior correctness.

---

## 28.2. Inputs

```text
Feature Specification
Acceptance Criteria
QA Test Plan
Domain Architecture
Feature Architecture
Architecture Constitution
Implementation Report
repository diff
tests
contracts
dependency graph
```

---

## 28.3. Review dimensions

Reviewer перевіряє:

```text
CODE_CORRECTNESS

ARCHITECTURE_COMPLIANCE

DOMAIN_ARCHITECTURE_COMPLIANCE

DEPENDENCY_RULES

MODULE_BOUNDARIES

CROSS_DOMAIN_DEPENDENCIES

DATABASE_BOUNDARIES

CONTRACT_COMPATIBILITY

EVENT_COMPATIBILITY

SECURITY

PERMISSIONS

TENANT_ISOLATION

MIGRATION_SAFETY

ERROR_HANDLING

IDEMPOTENCY

OBSERVABILITY

TEST_QUALITY

MAINTAINABILITY

UNNECESSARY_COMPLEXITY
```

---

## 28.4. Reviewer Findings

Кожна проблема повинна бути структурованою:

```yaml
id:

severity:

category:

location:

description:

expected:

actual:

evidence:

recommended_action:

blocking:
```

Severity:

```text
INFO
MINOR
MAJOR
BLOCKER
```

---

## 28.5. Review Result

```text
APPROVED

APPROVED_WITH_MINOR_FINDINGS

CHANGES_REQUIRED

BLOCKED

ARCHITECT_REVIEW_REQUIRED

REQUIREMENTS_REVIEW_REQUIRED
```

---

## 28.6. Reviewer independence

Reviewer:

```text
не змінює implementation самостійно

не виправляє код потайки

не змінює requirements

не змінює architecture

не переписує QA plan
```

Він формує findings.

Manager повертає їх відповідному Agent.

---

# 29. Agent №7 — QA Executor

## 29.1. Призначення

QA Executor є незалежним behavior verifier.

Його питання:

> Чи реально система поводиться так, як було визначено?

QA Executor не повинен вважати:

```text
code exists
```

еквівалентом:

```text
feature works
```

Людство вже витратило достатньо років, щоб довести протилежне.

---

## 29.2. Modes

```text
FEATURE_QA_MODE

DOMAIN_QA_MODE

REGRESSION_MODE

REVALIDATION_MODE
```

---

## 29.3. Feature QA

QA Executor бере:

```text
Feature Specification
Acceptance Criteria
QA Test Plan
Implementation
Review Result
repository revision
running environment
```

і виконує незалежні перевірки.

---

## 29.4. QA Evidence

Кожен blocking test result повинен мати evidence.

Наприклад:

```text
command executed
HTTP request
HTTP response
database state
browser evidence
log evidence
test report
CI job
repository revision
```

---

## 29.5. QA Result

```text
PASS

PASS_WITH_NON_BLOCKING_FINDINGS

FAIL

BLOCKED

ENVIRONMENT_FAILURE
```

---

## 29.6. QA Findings

Severity:

```text
INFO
MINOR
MAJOR
BLOCKER
```

Feature не може перейти в COMPLETE при:

```text
BLOCKER

MAJOR
```

якщо policy явно не визначає інше.

---

## 29.7. QA Executor заборонено

```text
виправляти production code

змінювати Acceptance Criteria

знижувати severity для проходження gate

видаляти failing test

пропускати blocking AC

позначати неперевірений AC як PASS
```

---

# 30. Agent №8 — Integration & Release Agent

## 30.1. Призначення

Integration & Release Agent працює переважно на Domain Level.

Він відповідає за:

> складання завершених Feature у один працездатний Domain.

Це окрема задача від написання окремих Feature.

---

## 30.2. Integration Mode

Agent отримує:

```text
completed features

feature branches

Domain Architecture

Dependency Graph

Contract Registry

Event Registry

Migration Plan

Feature QA Reports
```

Він:

```text
інтегрує feature branches

перевіряє dependency order

вирішує integration wiring

перевіряє DI registration

перевіряє routing

перевіряє event wiring

перевіряє migrations ordering

перевіряє contracts

створює integration build

передає результат Domain QA
```

---

## 30.3. Integration Agent не може

```text
змінювати domain semantics

вигадувати нові feature

змінювати requirements

робити breaking contract workaround

ігнорувати failed Feature QA
```

Якщо інтеграція виявила structural problem:

```text
ARCHITECT_REVALIDATION_REQUIRED
```

---

## 30.4. Domain Release Mode

Після Domain QA Agent формує:

```text
DOMAIN_RELEASE_MANIFEST

RELEASE_READINESS_REPORT
```

Перевіряє:

```text
mandatory features complete

blocking Domain AC PASS

Feature QA PASS

Domain QA PASS

Reviewer PASS

CI green

Architecture current

Contracts current

Migrations valid

Smoke PASS

No BLOCKER

No MAJOR

Rollback Plan exists

Required documentation exists
```

---

## 30.5. Release Result

```text
RELEASE_READY

NOT_READY

HUMAN_APPROVAL_REQUIRED
```

Integration & Release Agent не повинен сам давати фінальний production approval, якщо policy визначає Human Gate.

---

# 31. Agent Interaction Model

Основний Feature workflow:

```text
Engineering Manager
        ↓
Product / Requirements
        ↓
QA Planner
        ↓
Principal Architect
        ↓
Engineering Manager
        ↓
Developer
        ↓
Reviewer
        ↓
QA Executor
        ↓
Engineering Manager
        ↓
COMPLETE
```

---

# 32. Rework Loops

Workflow не повинен бути одностороннім pipeline.

Допустимі controlled loops:

```text
Developer
↓
Reviewer
↓ CHANGES_REQUIRED
Developer
```

```text
Developer
↓
Reviewer
↓ ARCHITECT_REVIEW_REQUIRED
Architect
↓
Developer
```

```text
QA
↓ FAIL
Developer
↓
Reviewer
↓
QA
```

```text
QA Planner
↓ REQUIREMENT_NOT_TESTABLE
Product
↓
QA Planner
```

```text
Developer
↓ REQUIREMENT_CLARIFICATION_REQUIRED
Product
↓
Architect revalidation
↓
Developer
```

Кожний loop повинен збільшувати:

```text
agent_cycle_count
```

і підпадати під Loop Protection.

---

# 33. Domain Agent Workflow

Для Domain Initiative:

```text
Human
↓
Engineering Manager
↓
Product / Requirements Agent
↓
DOMAIN_SPECIFICATION
↓
QA Planner
↓
DOMAIN_QA_PRELIMINARY_PLAN
↓
Principal Architect
↓
DOMAIN_ARCHITECTURE
↓
DOMAIN_ARCHITECTURE_CONSTITUTION
↓
Domain Decomposition
↓
Engineering Manager
↓
Feature Dependency Graph
↓
Domain Scheduler
↓
Feature Engineering Workflows
↓
Integration & Release Agent
↓
DOMAIN INTEGRATION
↓
QA Executor
↓
DOMAIN_QA
↓
Integration & Release Agent
↓
RELEASE_READINESS
↓
Human Approval
↓
RELEASE
```

---

# 34. Separation of Responsibilities

Обов'язкові правила:

```text
Product defines WHAT.

QA Planner defines HOW TO VERIFY.

Architect defines HOW IT FITS.

Developer defines IMPLEMENTATION DETAILS.

Reviewer verifies TECHNICAL CORRECTNESS.

QA Executor verifies ACTUAL BEHAVIOR.

Manager controls FLOW.

Integration & Release Agent controls SYSTEM ASSEMBLY.
```

Жоден Agent не повинен непомітно забирати responsibility іншого Agent.

---

# 35. Agent Execution Contract

Кожний Agent Run повинен мати стандартний envelope:

```yaml
run_id:

agent_role:

execution_mode:

domain_id:

capability_id:

feature_id:

input_artifacts:

repository_revision:

allowed_tools:

allowed_actions:

forbidden_actions:

token_budget:

time_budget:

expected_outputs:

completion_conditions:
```

---

# 36. Agent Structured Result

Кожен Agent повинен повертати structured result:

```yaml
status:

summary:

artifacts_created:

artifacts_updated:

findings:

decisions:

repository_changes:

tests:

blocking_issues:

required_next_action:

recommended_next_agent:

evidence:
```

Допустимі status:

```text
COMPLETED

COMPLETED_WITH_FINDINGS

REWORK_REQUIRED

BLOCKED

HUMAN_DECISION_REQUIRED

FAILED
```

---

# 37. No Self Approval Rule

Заборонені execution chains:

```text
Developer → Developer Review

Developer → Developer QA

Architect → Architecture Approval

QA Planner → QA PASS

Integration Agent → Production Approval
```

Для HIGH та CRITICAL risk Feature обов'язкова незалежність:

```text
implementation_actor != review_actor

implementation_actor != qa_actor
```

---

# 38. Specialist Agents

V2.0 не повинен вимагати окремого Agent для кожної спеціальності у кожному workflow.

Runtime повинен підтримувати policy-driven підключення Specialist Agent, коли ризик, тип зміни або вимоги до release цього потребують.

Підтримувані specialist roles:

```text
SECURITY_SPECIALIST
DATABASE_MIGRATION_SPECIALIST
PERFORMANCE_SPECIALIST
DEVOPS_SPECIALIST
DOCUMENTATION_SPECIALIST
API_SPECIALIST
```

Specialist Agent не замінює mandatory role. Його результат є додатковим evidence/gate у відповідному workflow.

Specialist запускається, якщо:

```text
risk requires specialist
Architect requests specialist
Reviewer requests specialist
QA Planner requires specialist validation plan
QA Executor raises specialist-related finding
Integration & Release Agent detects specialist release risk
EngineeringPolicyEngine requires specialist
Human explicitly requests specialist review
```

Кожний Specialist execution MUST використовувати стандартний Agent Execution Contract і Structured Result із секцій 35–36.

Мінімально Specialist result повинен містити:

```text
role
mode
status
scope
findings[]
evidence[]
blocking_issues[]
recommended_actions[]
required_revalidation[]
escalation_required
```

Specialist не має права самостійно змінювати requirements, architecture, acceptance criteria або release policy поза своїм decision scope.

---

# 39. Security Specialist

## 39.1. Призначення

Для HIGH/CRITICAL security-related Feature або Domain запускається:

```text
SECURITY_REVIEW
```

Security Specialist перевіряє:

```text
authentication
authorization
permissions
tenant isolation
secret handling
input validation
injection risks
unsafe external calls
auditability
data exposure
security-sensitive logging
privilege escalation
cross-domain trust boundaries
```

## 39.2. Required inputs

```text
Feature / Domain specification
Architecture Constitution
security-relevant contracts
changed paths / diff
permission model
external integration contracts
QA Plan
repository evidence
```

## 39.3. Required outputs

```text
SECURITY_REVIEW_REPORT
security findings
severity
exploitability rationale
evidence
required remediation
required tests
release blocking decision
```

## 39.4. Allowed / forbidden

Security Specialist може створювати findings та вимагати remediation/revalidation.

Security Specialist не може:

```text
silently rewrite product requirements
approve its own remediation implementation
replace Reviewer
replace QA Executor
override Human security gate
use production secrets as test data
```

Completion criteria: усі blocking security findings або закриті доказами, або переведені в explicit Human Gate.

---

# 40. Database / Migration Specialist

## 40.1. Призначення

Для складних migration запускається:

```text
MIGRATION_REVIEW
```

Перевіряє:

```text
migration ordering
backward compatibility
locking risk
data preservation
rollback
large-table impact
zero-downtime requirements
foreign key integrity
index strategy
schema ownership
cross-domain data boundaries
```

## 40.2. Required inputs

```text
migration files
schema diff
Domain Architecture
persistence boundaries
existing schema metadata
deployment strategy
rollback strategy
expected data volume
```

## 40.3. Required outputs

```text
MIGRATION_REVIEW_REPORT
ordering decision
compatibility findings
locking/data-loss risks
rollback verification
required migration tests
release blockers
```

Database / Migration Specialist не може змінювати business semantics або обходити schema ownership rules.

Completion criteria: migration chain має визначений порядок, rollback/forward strategy та доказ відсутності неприйнятного data-loss/locking risk.

---

# 41. Documentation Specialist

## 41.1. Призначення

Documentation Specialist є post-processing та release-support Agent.

Він працює тільки на основі approved artifacts та фактичної implementation.

Створює або оновлює:

```text
PUBLIC_DOCUMENTATION
INTEGRATOR_DOCUMENTATION
DEVELOPER_DOCUMENTATION
CHANGELOG / RELEASE NOTES when required
```

## 41.2. Required inputs

```text
approved specifications
architecture artifacts
actual implementation evidence
public contracts
release manifest
known limitations
```

## 41.3. Required outputs

```text
DOCUMENTATION_UPDATE_SET
documentation coverage report
broken/stale documentation findings
required translations/localizations
```

Documentation Specialist не може вигадувати функціональність, якої немає в implementation, або описувати planned behavior як current behavior.

Completion criteria: документація узгоджена з release candidate та не містить known material drift.

---

# 42. Performance Specialist

## 42.1. Призначення

Performance Specialist запускається для performance-sensitive або HIGH-load змін у режимі:

```text
PERFORMANCE_REVIEW
```

Перевіряє:

```text
latency
throughput
memory usage
CPU usage
N+1 / query amplification
cache behavior
queue pressure
concurrency behavior
resource limits
hot paths
performance regression risk
```

## 42.2. Required inputs

```text
performance requirements / SLOs
architecture and changed paths
baseline metrics when available
load profile
query/IO evidence
QA plan
```

## 42.3. Required outputs

```text
PERFORMANCE_REVIEW_REPORT
baseline comparison
measured evidence
regression findings
capacity risks
required benchmarks/tests
release blockers
```

Performance Specialist не може замінювати functional QA або оголошувати behavior correct лише через прийнятні performance metrics.

Completion criteria: critical performance criteria мають measured evidence або explicit Human acceptance of residual risk.

---

# 43. DevOps Specialist

## 43.1. Призначення

DevOps Specialist перевіряє deployment/runtime impact у режимах:

```text
DEPLOYMENT_REVIEW
RUNTIME_OPERABILITY_REVIEW
```

Перевіряє:

```text
CI/CD compatibility
container/runtime configuration
environment variables
secrets references
health checks
startup/shutdown behavior
rollback/deploy ordering
observability readiness
resource configuration
operational runbooks
```

## 43.2. Required inputs

```text
release candidate
deployment manifests/config
migration plan
runtime dependencies
health/readiness contracts
rollback plan
observability requirements
```

## 43.3. Required outputs

```text
DEVOPS_REVIEW_REPORT
deployment blockers
runtime configuration changes
rollback validation
operability findings
required runbook changes
```

DevOps Specialist не може deploy у production, змінювати production credentials або обходити Human Release Gate.

Completion criteria: release candidate має reproducible deployment path, health verification і rollback strategy.

---

# 44. API Specialist

## 44.1. Призначення

API Specialist запускається для public/cross-domain API або integration-contract змін у режимі:

```text
API_CONTRACT_REVIEW
```

Перевіряє:

```text
request/response contract
schema compatibility
versioning
idempotency
authentication/authorization boundary
error semantics
pagination/filtering conventions
rate/usage constraints
backward compatibility
OpenAPI / machine-readable contract drift
```

## 44.2. Required inputs

```text
API specification
Contract Registry
changed endpoints/schemas
consumer dependencies
architecture rules
integration tests
```

## 44.3. Required outputs

```text
API_REVIEW_REPORT
compatibility decision
breaking-change findings
consumer impact
required contract tests
versioning/escalation recommendation
```

API Specialist не може самостійно схвалити breaking public contract change, якщо policy вимагає Architect або Human Gate.

Completion criteria: API contract versioning і compatibility мають evidence, а consumer-impact визначений.

---

# 45. Agent Assignment Policy

Agent selection повинен контролюватися:

```text
AgentCapabilityRegistry
```

і:

```text
EngineeringPolicyEngine
```

Перед запуском Runtime перевіряє:

```text
Does agent support required role?
Does agent support required mode?
Does agent have required tools?
Does agent have repository access?
Does risk level permit this agent?
Does independence rule permit this agent?
Does context budget permit execution?
Does policy require a specialist?
```

Agent assignment повинен бути записаний в audit trail до початку execution.

---

# 46. Agent Context Isolation

Кожний Agent отримує тільки необхідний context.

Наприклад Developer не повинен отримувати:

```text
irrelevant Domain documentation
unrelated secrets
unrelated repository areas
production credentials
hidden Reviewer conclusions from future steps
```

Reviewer при цьому повинен отримувати implementation evidence.

QA Executor повинен отримувати expected behavior, але не використовувати Developer explanation як доказ правильності behavior.

Specialist отримує тільки context, який стосується його review scope.

---

# 47. Agent Escalation Matrix

```text
Requirement ambiguity
→ Product Agent

Testability ambiguity
→ QA Planner

Architecture ambiguity
→ Architect

Implementation defect
→ Developer

Architecture violation
→ Architect + Developer

Behavior failure
→ Developer → Reviewer → QA Executor

Security uncertainty
→ Security Specialist + Architect

Migration/data integrity uncertainty
→ Database / Migration Specialist + Architect

Performance uncertainty
→ Performance Specialist + Architect

Deployment/operability uncertainty
→ DevOps Specialist + Integration & Release Agent

API contract uncertainty
→ API Specialist + Architect

Documentation drift
→ Documentation Specialist

Integration conflict
→ Integration & Release Agent + Architect

Policy exception
→ Human

Breaking public contract
→ Architect + relevant Specialist + Human Gate if required

Critical production risk
→ Human
```

---

# 48. Agent Independence Matrix

Логічна роль і фізична модель не є одним і тим самим. Одна LLM/model configuration може виконувати різні ролі лише у різних ізольованих execution context.

Нормативні правила незалежності:

| Role A | Role B | Same execution actor allowed? | Rule |
|---|---|---:|---|
| Engineering Manager | Product / Requirements | NO | Manager не визначає requirements, лише оркеструє |
| Product / Requirements | QA Planner | NO for HIGH/CRITICAL | testability має незалежно перевіряти requirements |
| Product / Requirements | Principal Architect | NO for HIGH/CRITICAL | WHAT і HOW IT FITS мають бути розділені |
| Principal Architect | Developer | CONDITIONALLY | допустимо лише LOW risk і якщо policy це дозволяє; decision trail обов'язковий |
| Developer | Reviewer | NO | self-review заборонений |
| Developer | QA Executor | NO | self-verification заборонена |
| Reviewer | QA Executor | NO for HIGH/CRITICAL | technical review і behavior verification незалежні |
| Developer | Security Specialist | NO when security review is blocking | remediation не може самостійно себе security-approve |
| Developer | Database/Migration Specialist | NO when migration review is blocking | migration implementation не self-approves |
| Integration & Release | QA Executor | NO for HIGH/CRITICAL | system assembly не self-validates final behavior |
| Integration & Release | Human Release Gate | NO | Agent не є Human approver |

Додатково:

```text
implementation_actor != review_actor
implementation_actor != qa_actor
```

є обов'язковим для HIGH/CRITICAL risk.

Якщо Runtime не може знайти незалежного actor, execution переходить у:

```text
BLOCKED
or
HUMAN_APPROVAL_REQUIRED
```

але не послаблює правило автоматично.

---

# 49. Mandatory Agent Set

Engineering Runtime V2.0 вважається повноцінним тільки якщо підтримує:

```text
1. Engineering Manager / Coordinator
2. Product / Requirements Agent
3. QA Planner
4. Principal Architect
5. Developer
6. Reviewer
7. QA Executor
8. Integration & Release Agent
```

Мінімальний Feature execution path:

```text
Manager
→ Product
→ QA Planner
→ Architect
→ Developer
→ Reviewer
→ QA Executor
→ Manager
```

Мінімальний Domain execution path:

```text
Manager
→ Product
→ QA Planner [preliminary Domain QA plan]
→ Architect
→ Decomposition / Dependency Graph / Scheduler
→ Feature Workflows
→ Integration & Release [INTEGRATION_MODE]
→ QA Executor [DOMAIN_QA]
→ Integration & Release [DOMAIN_RELEASE_MODE]
→ Human Release Gate
→ RELEASE
```

Specialist Agents підключаються policy-driven і не замінюють жоден mandatory gate.

---

# 50. Головний принцип Agent Runtime

Engineering Runtime не повинен бути просто послідовністю LLM prompts.

Він повинен бути:

```text
persistent state machine
+
artifact graph
+
agent roles
+
policy engine
+
repository state
+
independent verification
+
controlled rework loops
+
human control plane
```

Agent не вирішує, що робити далі, лише тому що йому так здалося.

Наступний крок визначається:

```text
workflow state
artifact state
dependency graph
agent result
policy
risk
findings
```

Саме це перетворює набір AI-агентів із групового чату дуже впевнених стажерів на Engineering Runtime.

---

# 51. Domain QA

Domain QA перевіряє:

```text
cross-feature workflows
cross-module workflows
contracts
database integration
event propagation
permissions
tenant isolation
regression
migration
rollback
performance
critical smoke
```

Приклад:

```text
Instrument
→ Market Data
→ Strategy
→ Order
→ Execution
→ Position
→ Audit
```

Повинен тестуватися як один workflow.

---

# 52. Domain Acceptance Criteria

Окрім feature AC повинні існувати:

```text
DOMAIN_ACCEPTANCE_CRITERIA
```

Приклад:

```yaml
- id: CM-DAC-001
  description: Domain can register and resolve financial instruments.

- id: CM-DAC-002
  description: Venue can publish normalized market data.

- id: CM-DAC-003
  description: Orders pass through risk validation.

- id: CM-DAC-004
  description: All execution actions are auditable.
```

Domain не може отримати статус COMPLETE, поки Domain AC не PASS.

---

# 53. Migration orchestration

Складний Domain може створити багато migrations.

Необхідно:

```text
MigrationPlan
```

Має містити:

```text
migration_order
dependencies
forward_validation
rollback_strategy
data_migration
compatibility_window
```

QA Executor перевіряє фактичну migration behavior; Database / Migration Specialist перевіряє migration design та operational risk, якщо цього вимагає policy.

---

# 54. Database ownership

Domain Architecture має визначати ownership таблиць.

Наприклад:

```text
capital_markets_instruments
capital_markets_venues
capital_markets_orders
```

Один Domain не повинен напряму змінювати таблиці іншого без Integration Contract.

---

# 55. Event architecture

Створити:

```text
DomainEventRegistry
```

Кожна подія:

```yaml
name:
version:
producer:
consumers:
payload_schema:
delivery:
idempotency:
ordering:
```

Приклад:

```text
InstrumentCreated
MarketDataReceived
OrderSubmitted
OrderExecuted
PositionChanged
```

---

# 56. Cross-domain contracts

Наприклад:

```text
Capital Markets
      ↓
Finance

Capital Markets
      ↓
Documents

Capital Markets
      ↓
Identity
```

Заборонити приховані залежності.

Вони повинні існувати через:

```text
CrossDomainContract
```

---

# 57. External integration boundary

Engineering Runtime повинен підтримувати реалізацію адаптерів через заздалегідь визначені contracts.

Наприклад:

```php
interface VenueAdapter
{
}
```

Різні implementation:

```text
KrakenVenueAdapter
BybitVenueAdapter
BinanceVenueAdapter
```

Domain logic не повинен залежати від конкретного provider.

---

# 58. Mock / Sandbox / Production separation

Для external integration обов'язково підтримати environments:

```text
MOCK
SANDBOX
PRODUCTION
```

Engineering Agents можуть використовувати:

```text
MOCK
SANDBOX
```

Production execution повинен бути недоступний Development Runtime за замовчуванням.

---

# 59. Secret isolation

Developer Agent не повинен бачити production secrets.

Runtime передає лише:

```text
secret_reference
```

а не actual secret.

Наприклад:

```text
secret://capital-markets/kraken/api-key
```

---

# 60. Domain Feature Flags

Для складних Domain необхідні:

```text
DOMAIN_ENABLED

FEATURE_ENABLED

INTEGRATION_ENABLED

PRODUCTION_EXECUTION_ENABLED
```

Новий Domain можна deploy, не активуючи всі його функції.

---

# 61. Human Gates

Human approval має бути можливий на рівнях:

```text
Domain Specification
Domain Architecture
Breaking Contract
Security Boundary
Migration Risk
External Production Integration
Domain Release
```

Не потрібно human approval для кожної дрібної feature, якщо policy цього не вимагає.

---

# 62. Risk classification

Кожна feature отримує:

```text
LOW
MEDIUM
HIGH
CRITICAL
```

Наприклад:

```text
UI filter → LOW
Instrument model → MEDIUM
Database migration → HIGH
Trading execution → CRITICAL
```

Від risk залежить:

- depth review;
- QA;
- human approval;
- required CI;
- release policy.

---

# 63. Execution Policies

Створити policy layer:

```text
EngineeringPolicyEngine
```

Він вирішує:

```text
Can agent start task?
Can agent modify path?
Can feature run in parallel?
Is human approval required?
Can PR be merged?
Can contract change?
Can migration run?
```

---

# 64. Agent Capability Registry

Створити:

```text
AgentCapabilityRegistry
```

Наприклад:

```yaml
DEVELOPER:
  repository_read: true
  repository_write: branch_only
  commit: true
  pull_request: true
  merge: false
  production: false

REVIEWER:
  repository_read: true
  repository_write: false

QA:
  repository_read: true
  test_write: true
  production_write: false
```

---

# 65. Runtime Capability Registry

Окремо від Agent Capability:

```text
RuntimeCapabilityRegistry
```

Наприклад:

```text
EngineeringRuntime:
    GitHub
    CI
    Repository
    Test Runner
    Static Analysis

CapitalMarketsRuntime:
    Venue Gateway
    Market Data
    Portfolio
    Risk Engine
```

Це не повинно змішуватися.

---

# 66. Artifact Graph

Артефакти повинні мати взаємозв'язки.

```text
Domain Specification
       ↓
Domain Architecture
       ↓
Capability
       ↓
Feature Spec
       ↓
Feature Architecture
       ↓
Implementation
       ↓
Review
       ↓
QA
```

Створити:

```text
ArtifactDependencyGraph
```

---

# 67. Artifact versioning

Кожен architecture / specification artifact:

```text
version
revision
hash
created_by
supersedes
```

Feature повинна знати, на якій версії Domain Architecture вона була створена.

---

# 68. Architecture drift detection

Перед merge перевіряти:

```text
Feature architecture_version
vs
Current Domain architecture_version
```

Якщо змінилася:

```text
ARCHITECTURE_REVALIDATION_REQUIRED
```

---

# 69. Contract drift detection

Аналогічно:

```text
CONTRACT_REVALIDATION_REQUIRED
```

Якщо dependency змінила public contract.

---

# 70. Feature invalidation

Якщо foundational feature змінила contract:

```text
Money v1
↓
Price
↓
Order
```

і `Money` став v2 breaking:

Runtime повинен позначити залежні feature:

```text
STALE
REVALIDATION_REQUIRED
```

---

# 71. Domain progress model

Domain Runtime повинен показувати:

```text
total capabilities
completed capabilities

total features
completed features

blocked features

active workflows

critical path

architecture status
integration status
domain QA status
release readiness
```

---

# 72. Domain Development Workspace

Потрібен UI/API workspace:

```text
/admin/engineering/domains
/admin/engineering/domains/{id}
```

Мінімально:

```text
Overview
Architecture
Capabilities
Features
Dependency Graph
Contracts
Artifacts
Executions
Findings
QA
Release
```

---

# 73. Domain Dependency Graph UI

Відображати DAG:

```text
[Money]
   ↓
[Instrument]
  ↙     ↘
Venue   MarketData
   \     /
   Execution
```

Statuses:

```text
NOT_STARTED
READY
RUNNING
BLOCKED
FAILED
COMPLETED
```

---

# 74. Runtime observability

Кожен run повинен мати:

```text
runtime_id
domain_id
feature_id
agent
state
started_at
finished_at
inputs
outputs
artifacts
repository_revision
cost
token_usage
errors
```

---

# 75. Audit trail

Повинен існувати незмінний audit:

```text
who
did what
when
why
based on which artifact
against which revision
with which result
```

---

# 76. Failure recovery

Runtime повинен переживати:

```text
process restart
CI timeout
agent timeout
provider failure
GitHub temporary error
database connection failure
```

Workflow повинен відновлюватися з останнього persisted state.

---

# 77. Idempotency

Повторний execution не повинен:

- створювати дублікати PR;
- дублювати artifacts;
- повторно виконувати migration;
- створювати дублікати feature;
- ламати state machine.

---

# 78. Retry policy

Для infrastructure failures:

```text
AUTO_RETRY
```

Для semantic failures:

```text
RETURN_TO_AGENT
```

Для ambiguity:

```text
HUMAN_DECISION_REQUIRED
```

---

# 79. Loop protection

Для кожного workflow:

```text
max_agent_cycles
max_review_cycles
max_qa_cycles
max_architecture_cycles
```

На Domain level:

```text
max_feature_retries
max_domain_integration_cycles
```

---

# 80. Token / cost controls

Для великих Domain додати:

```text
context_budget
token_budget
cost_budget
```

На рівні:

```text
Domain
Feature
Agent Run
```

---

# 81. Context compression

Старі artifacts не потрібно щоразу передавати повністю.

Потрібні:

```text
full artifact
canonical summary
hash
relevant sections
```

Feature отримує лише потрібний context.

---

# 82. Repository context index

Створити / використовувати repository index:

```text
classes
interfaces
services
entities
routes
migrations
tests
modules
dependencies
```

Agent не повинен сканувати весь repository з нуля при кожному run.

---

# 83. Existing code awareness

До створення нової abstraction Architect і Developer повинні перевіряти:

```text
Does equivalent class already exist?
Does shared primitive exist?
Does another Domain already expose contract?
```

Мета:

не створювати:

```text
CapitalMarketsMoney
FinanceMoney
BillingMoney
PaymentsMoney
```

коли достатньо одного canonical primitive.

---

# 84. Shared Kernel

Явно визначити:

```text
SharedKernel
```

Допустимі типи:

```text
Money
Currency
Identifier
Clock
TenantId
UserId
DomainEvent
```

Не можна скидати туди все, що агенту ліньки нормально розмістити.

---

# 85. Domain isolation validation

Architecture tests повинні перевіряти:

```text
forbidden namespace dependencies
forbidden database dependencies
forbidden infrastructure imports
cross-domain access
```

---

# 86. Test Pyramid

Feature може мати:

```text
unit
integration
functional
API
browser
E2E
smoke
```

Domain QA додатково:

```text
cross-feature
cross-domain
migration
contract
performance
resilience
```

---

# 87. Contract tests

Для interfaces між modules обов'язково підтримати:

```text
ContractTest
```

Наприклад:

```text
VenueAdapterContractTest
MarketDataProviderContractTest
OrderExecutionContractTest
```

Будь-який новий adapter повинен проходити той самий contract suite.

---

# 88. Architecture tests

Для кожного Domain автоматично перевіряти:

```text
namespace boundaries
dependency direction
forbidden imports
module ownership
public/private services
```

---

# 89. Domain smoke suite

Кожен Domain повинен мати curated smoke suite.

Не сотні тестів.

Наприклад:

```text
10–30 critical workflows
```

які дають швидку відповідь:

> Domain живий чи ми знову щось геніально поламали.

---

# 90. Release manifest

Після Domain QA створювати:

```text
DOMAIN_RELEASE_MANIFEST
```

Структура:

```yaml
domain:
version:

included_features:

commits:

migrations:

contracts:

new_events:

deprecated_contracts:

feature_flags:

known_limitations:

qa_result:

security_result:

rollback_plan:
```

---

# 91. Domain Release Gate

Перед `RELEASE_READY` необхідно:

```text
all mandatory features completed
all blocking Domain AC PASS
Reviewer checks PASS
Feature QA PASS
Domain QA PASS
CI green
migration validation PASS
no unresolved BLOCKER
no unresolved MAJOR
architecture current
contracts current
release manifest generated
```

---

# 92. Documentation generation

Engineering Runtime повинен генерувати три рівні документації.

## Public / Business

```text
що це
для чого
що вміє
```

## Integrator

```text
configuration
permissions
workflows
integration
deployment
```

## Developer

```text
architecture
interfaces
events
database
extension points
```

---

# 93. Multi-language documentation

Canonical content може бути однією мовою.

Translations повинні бути окремим layer:

```text
uk
en
future locales
```

Архітектура не повинна бути hardcoded під дві мови.

---

# 94. Definition of Done для Feature

Feature COMPLETE тільки якщо:

```text
implementation complete
architecture compliant
tests exist
CI green
Reviewer approved
QA passed
AC passed
contracts registered
documentation updated where required
no blocking findings
```

---

# 95. Definition of Done для Capability

Capability COMPLETE:

```text
all required features complete
integration tested
contracts consistent
capability acceptance criteria passed
```

---

# 96. Definition of Done для Domain

Domain COMPLETE:

```text
all mandatory capabilities complete

Domain Architecture approved

Domain Acceptance Criteria passed

Domain QA passed

architecture tests passed

contract tests passed

migration plan passed

security checks passed

critical smoke passed

release manifest generated

human approval completed
```

---

# 97. Мінімальний набір нових Domain Runtime artifacts

Реалізувати:

```text
DOMAIN_SPECIFICATION

DOMAIN_DECOMPOSITION

DOMAIN_ARCHITECTURE

DOMAIN_ARCHITECTURE_CONSTITUTION

CAPABILITY_SPECIFICATION

FEATURE_CONTEXT_PACK

FEATURE_DEPENDENCY_GRAPH

CONTRACT_REGISTRY

DOMAIN_EVENT_REGISTRY

DOMAIN_QA_PLAN

DOMAIN_QA_REPORT

DOMAIN_RELEASE_MANIFEST
```

---

# 98. Мінімальний набір нових domain runtime services

Орієнтовно:

```text
DomainDevelopmentCoordinator

DomainDecompositionService

FeatureDependencyResolver

FeatureScheduler

DomainContextBuilder

FeatureContextBuilder

EngineeringContractRegistry

DomainEventRegistry

ArchitectureDriftDetector

ContractDriftDetector

DomainIntegrationService

DomainQaCoordinator

DomainReleaseReadinessService
```

---

# 99. Новий scheduler

Створити:

```text
DomainFeatureScheduler
```

Алгоритм:

```text
1. Load Domain.
2. Load dependency graph.
3. Find incomplete features.
4. Find features whose dependencies are complete.
5. Check path conflicts.
6. Check policies.
7. Start eligible workflows.
8. Monitor results.
9. Unlock dependent features.
10. Continue until Domain integration.
```

---

# 100. Concurrency limits

Підтримати:

```text
max_parallel_features
max_parallel_developers
max_parallel_reviews
max_parallel_qa
```

Default значення задаються конфігурацією.

---

# 101. Необхідні нові orchestration events

```text
DomainCreated

DomainSpecificationReady

DomainDecompositionReady

DomainArchitectureApproved

CapabilityReady

FeatureReady

FeatureStarted

FeatureCompleted

FeatureBlocked

ContractChanged

ArchitectureChanged

DomainIntegrationStarted

DomainIntegrationCompleted

DomainQaStarted

DomainQaCompleted

DomainReleaseReady
```

---

# 102. Принцип Human Control Plane

Human не повинен вручну управляти кожним переходом.

Людина повинна працювати на рівні:

```text
goal
scope
architecture exceptions
risk
release
```

Все інше Runtime має виконувати автономно.

---

# 103. Очікуваний UX

Людина створює:

```text
Create Domain
```

Наприклад:

```text
Capital Markets
```

додає Master Specification.

Далі Runtime:

```text
Engineering Manager
↓
Product / Requirements Agent
↓
DOMAIN_SPECIFICATION
↓
QA Planner
↓
DOMAIN_QA_PRELIMINARY_PLAN
↓
Principal Architect
↓
DOMAIN_ARCHITECTURE + CONSTITUTION
↓
Decomposition + Dependency Graph
↓
Domain Scheduler
↓
Feature Workflows
↓
Integration & Release Agent [INTEGRATION_MODE]
↓
QA Executor [DOMAIN_QA]
↓
Integration & Release Agent [DOMAIN_RELEASE_MODE]
↓
Human Release Gate
↓
RELEASE_READY / RELEASE
```

---

# 104. Цільова автономність

Після реалізації V2.0 користувач повинен мати можливість передати системі великий документ:

```text
CAPITAL MARKETS DOMAIN V1.0
```

і отримати:

```text
Domain architecture
Feature decomposition
Implementation plan
Dependency graph
working code
migrations
tests
API
documentation
review reports
QA evidence
integration branch / PR
release report
```

без ручного створення десятків окремих задач.

---

# 105. Що НЕ входить у це ТЗ

Engineering Runtime V2.0 не повинен реалізовувати:

```text
market research agent
trading strategy
market prediction
arbitrage algorithm
order execution logic as business operation
portfolio management agent
news analysis agent
exchange monitoring agent
```

Це функціональність майбутнього Capital Markets Domain.

Engineering Runtime лише повинен бути здатний її створити.

---

# 106. Етапи реалізації

## Phase 1 — Domain orchestration foundation

Реалізувати:

```text
DomainInitiative
Capability
Feature dependency graph
Domain statuses
DomainDevelopmentCoordinator
DomainFeatureScheduler
AgentCapabilityRegistry
EngineeringPolicyEngine
mandatory AgentRole set
Agent Assignment / Independence enforcement
```

Без UI.

---

## Phase 2 — Architecture & contracts

Реалізувати:

```text
Domain Specification
Domain Architecture
Architecture Constitution
Contract Registry
Event Registry
Feature Context Pack
```

---

## Phase 3 — Multi-feature execution

Реалізувати:

```text
dependency-driven execution
parallel feature execution
path ownership
path collision prevention
integration branch support
```

---

## Phase 4 — Domain QA

Реалізувати:

```text
Domain QA Plan
cross-feature tests
contract tests
migration tests
domain smoke
Domain QA Report
```

---

## Phase 5 — Release lifecycle

Реалізувати:

```text
Integration & Release Agent
Domain Release Manifest
release readiness
human approval
versioning
architecture drift
contract drift
```

---

## Phase 6 — Workspace

Реалізувати:

```text
Domain overview
capabilities
features
dependency graph
contracts
artifacts
executions
QA
release
```

---

# 107. Priority

## P0 — критично

```text
DomainInitiative

Domain decomposition

Dependency graph

Domain architecture artifact

Architecture constitution

Feature scheduler

Feature context pack

Contract registry

Domain QA

Domain release gate

mandatory 8-role Agent Model

AgentCapabilityRegistry + Assignment Policy

No Self Approval enforcement
```

## P1

```text
parallel execution

path reservation

architecture drift

contract drift

integration branches

event registry

migration orchestration
```

## P2

```text
advanced UI

cost analytics

visual dependency editor

automatic documentation translation

historical architecture analytics
```

---

# 108. Acceptance Criteria Engineering Runtime V2.0

## ER2-AC-001

Система може створити Domain Initiative з Master Specification.

## ER2-AC-002

Domain може містити декілька capabilities та десятки feature.

## ER2-AC-003

Runtime будує dependency DAG між feature.

## ER2-AC-004

Feature не запускається до завершення required dependencies.

## ER2-AC-005

Незалежні feature можуть виконуватися паралельно.

## ER2-AC-006

Кожна feature отримує Domain Architecture та Architecture Constitution.

## ER2-AC-007

Developer не може змінити заборонені paths.

## ER2-AC-008

Runtime виявляє path collision між паралельними feature.

## ER2-AC-009

Public contracts реєструються та versionуються.

## ER2-AC-010

Breaking contract changes потребують revalidation.

## ER2-AC-011

Зміна Domain Architecture invalidates залежні feature, якщо це потрібно.

## ER2-AC-012

Reviewer перевіряє Domain architecture compliance.

## ER2-AC-013

QA Executor підтримує `DOMAIN_QA` mode та виконує Domain-level behavior verification незалежно від Developer і Integration & Release Agent.

## ER2-AC-014

Domain QA, що виконується QA Executor, включає cross-feature regression.

## ER2-AC-015

Domain Release неможливий при failed blocking AC.

## ER2-AC-016

Domain Release неможливий при BLOCKER або MAJOR defect.

## ER2-AC-017

Runtime може відновитися після interruption без втрати workflow state.

## ER2-AC-018

Повторний runtime execution є idempotent.

## ER2-AC-019

Production secrets недоступні Engineering Agents.

## ER2-AC-020

Domain Release Manifest формується автоматично.

## ER2-AC-021

Domain має повний audit trail від requirement до release.

## ER2-AC-022

Feature зберігає revision Domain Architecture, на якій вона була реалізована.

## ER2-AC-023

Runtime підтримує повний mandatory Agent Set:

```text
Engineering Manager
Product / Requirements Agent
QA Planner
Principal Architect
Developer
Reviewer
QA Executor
Integration & Release Agent
```

Feature workflow використовує перші сім execution roles плюс Manager orchestration; Domain lifecycle обов'язково використовує Integration & Release Agent.

## ER2-AC-024

Складний Domain може бути розроблений без ручного створення кожного feature workflow користувачем.

## ER2-AC-025

Існуючий Feature Engineering Runtime продовжує працювати без regression.

## ER2-AC-026

Engineering Manager не створює business requirements, architecture, implementation або QA evidence замість відповідних ролей.

## ER2-AC-027

QA Planning і QA Execution виконуються різними logical roles (`QA_PLANNER`, `QA_EXECUTOR`) та мають окремі artifacts/results.

## ER2-AC-028

Для HIGH/CRITICAL risk виконується правило `implementation_actor != review_actor` та `implementation_actor != qa_actor`.

## ER2-AC-029

Integration & Release Agent виконує окремо `INTEGRATION_MODE` до Domain QA і `DOMAIN_RELEASE_MODE` після успішного Domain QA.

## ER2-AC-030

Agent selection перевіряється через `AgentCapabilityRegistry` і `EngineeringPolicyEngine` до execution.

## ER2-AC-031

Runtime підтримує policy-driven specialist roles: Security, Database/Migration, Performance, DevOps, Documentation, API.

## ER2-AC-032

Якщо mandatory independence або specialist gate неможливо виконати, Runtime переходить у `BLOCKED` або `HUMAN_APPROVAL_REQUIRED`, а не обходить policy.

---

# 109. Кінцева архітектура

```text
COS META RUNTIME
│
├── Engineering Domain Development Runtime
│   │
│   ├── Engineering Manager / Coordinator
│   ├── Product / Requirements Agent
│   ├── QA Planner
│   ├── Principal Architect
│   │
│   ├── Domain Initiative
│   ├── Domain Specification
│   ├── Domain Architecture + Constitution
│   ├── Capability Graph
│   ├── Feature Dependency Graph
│   ├── Contract Registry
│   ├── Event Registry
│   ├── AgentCapabilityRegistry
│   ├── EngineeringPolicyEngine
│   ├── Feature Scheduler
│   │
│   ├── Feature Engineering Runtime[]
│   │   │
│   │   ├── Manager orchestration
│   │   ├── Product / Requirements
│   │   ├── QA Planner
│   │   ├── Principal Architect
│   │   ├── Developer
│   │   ├── Reviewer
│   │   └── QA Executor
│   │
│   ├── Specialist Agents [policy-driven]
│   │   ├── Security
│   │   ├── Database / Migration
│   │   ├── Performance
│   │   ├── DevOps
│   │   ├── Documentation
│   │   └── API
│   │
│   ├── Integration & Release Agent [INTEGRATION_MODE]
│   ├── QA Executor [DOMAIN_QA]
│   ├── Integration & Release Agent [DOMAIN_RELEASE_MODE]
│   └── Human Release Gate
│
├── Capital Markets Runtime
├── CRM Runtime
├── Finance Runtime
└── future runtimes
```

---

# 110. Головний результат

Engineering Runtime після V2.0 має працювати не за принципом:

> «дай мені наступну задачу».

А за принципом:

> «дай мені Master Specification, а Runtime через Manager → Product → QA Planner → Architect побудує контрольований план, розіб'є Domain на залежні feature, проведе кожну через Product → QA Planning → Architecture → Development → Review → QA Execution, інтегрує систему через Integration & Release Agent, виконає Domain QA і передасть людині release candidate з доказами готовності».

Саме це є мінімально необхідною основою, щоб COS Agents могли системно будувати Domain масштабу Capital Markets, Finance, Construction, Procurement, HR або інших великих бізнес-систем.

---

## V2.0 Freeze Rule

Після затвердження цього Master Specification зміни до mandatory agent responsibilities, Domain lifecycle, independence rules, release gates, artifact contracts або policy boundaries вважаються architecture-level changes і MUST супроводжуватися ADR та revalidation affected runtime tests.