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