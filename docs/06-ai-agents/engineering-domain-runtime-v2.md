# Engineering Runtime V2.0 — Complex Domain Development

Status: implementation foundation  
Scope: Engineering / Dev Agents only

## Purpose

Engineering Runtime V2.0 adds a Domain Development Runtime above the existing Feature Engineering Runtime.

The separation is explicit:

- Engineering Agents BUILD domains and runtimes.
- Operational agents later OPERATE inside those domains.
- Trading, market research, exchange access, SDK access and similar business capabilities belong to the future Capital Markets Runtime, not to Engineering Agents.

## Runtime hierarchy

COS Meta Runtime
- Engineering Domain Development Runtime
  - Domain Initiative
  - Domain Manager mode
  - Domain Architect mode
  - Capability / Feature dependency graph
  - Contract Registry
  - Architecture Constitution
  - Domain Feature Scheduler
  - Domain QA / release gates
  - existing Feature Engineering Runtime
    - Engineering Manager
    - QA Planner
    - Principal Architect
    - Developer
    - Reviewer
    - QA Executor
- Capital Markets Runtime
- CRM Runtime
- Finance Runtime
- future runtimes

## Implemented domain lifecycle

DRAFT
→ ANALYSIS
→ DECOMPOSITION
→ ARCHITECTURE / READY_FOR_IMPLEMENTATION
→ IMPLEMENTATION
→ INTEGRATION
→ DOMAIN_QA
→ HUMAN_APPROVAL
→ RELEASE_READY
→ COMPLETED

BLOCKED, FAILED and CANCELLED remain explicit non-happy states.

## Domain Manager mode

Domain Manager receives a Master Domain Specification and returns:

- normalized Domain Specification;
- capability map;
- leaf implementation features;
- feature kinds;
- risk and priority;
- path ownership boundaries;
- dependency DAG;
- Domain Acceptance Criteria;
- unresolved questions.

A leaf feature must be small enough to pass independently through the existing Feature Engineering Runtime.

The Domain Manager does not decide detailed architecture.

## Principal Architect domain mode

Domain Architect receives the Master Specification plus decomposition and defines:

- bounded context;
- module / namespace / layer rules;
- aggregate boundaries;
- persistence, API and event boundaries;
- dependency rules;
- security, permission and tenant boundaries;
- audit and observability;
- failure model;
- migration / rollback strategy;
- testing strategy;
- Architecture Constitution;
- Contract Registry;
- implementation concurrency policy and human gates.

## Dependency-driven scheduling

DomainFeatureScheduler:

1. loads persisted Domain state;
2. reconciles child Engineering Feature workflows;
3. validates the dependency graph;
4. finds features whose blocking dependencies are COMPLETED;
5. checks path collisions against active feature work;
6. atomically claims an eligible feature;
7. builds a Domain Feature Context Pack;
8. creates/binds a standard Engineering Feature when needed;
9. queues the existing Engineering Runtime;
10. repeats until all required features are integrated.

Default orchestration is dependency-driven. Independent features can run in parallel within the configured scheduler limit.

## Context Pack

Every scheduled leaf feature receives:

- Domain identity and version;
- Domain Architecture revision;
- Architecture Constitution;
- Domain Acceptance Criteria;
- direct completed dependencies;
- Contract Registry;
- owned/shared/forbidden paths;
- non-negotiable rules.

A feature workflow must not silently change Domain Architecture or public contracts.

## Contract Registry

Supported contract classes:

- DOMAIN_INTERFACE
- APPLICATION_INTERFACE
- API_CONTRACT
- EVENT_CONTRACT
- DATABASE_CONTRACT
- INTEGRATION_CONTRACT
- PERMISSION_CONTRACT

Compatibility:

- BACKWARD_COMPATIBLE
- BREAKING
- DEPRECATED

A breaking version change marks declared consumers for revalidation when they are not actively running.

## Architecture drift

Domain Architecture has a monotonic architecture_version.

Leaf features record the version they were scheduled against.

When architecture changes:

- completed affected work becomes STALE;
- inactive unfinished work becomes REVALIDATION_REQUIRED;
- running work is not silently rewritten;
- release readiness fails until every required feature is validated against the current architecture revision.

## Path ownership

Every Domain feature may declare:

- owned_paths
- shared_paths
- forbidden_paths

The scheduler serializes overlapping active path reservations. This protects parallel feature workflows from independently rewriting the same module boundary.

## Release gate

A Domain cannot become RELEASE_READY unless:

- Domain Architecture exists;
- every required feature is COMPLETED;
- every required feature matches the current architecture_version;
- Domain QA status is PASS;
- no registered contract is BROKEN.

The generated DOMAIN_RELEASE_MANIFEST contains the Domain/version, architecture revision, included Engineering Features, contracts, QA state and rollback information.

## Persistence

Migration Version20261005190000 adds:

- cos_engineering_domains
- cos_engineering_domain_capabilities
- cos_engineering_domain_features
- cos_engineering_domain_dependencies
- cos_engineering_domain_contracts
- cos_engineering_domain_artifacts
- cos_engineering_domain_audit

The existing feature workflow tables remain unchanged. Engineering Runtime V2.0 is additive and preserves standalone feature development.

## Safety boundary

Engineering Domain Runtime does not get production business credentials.

For a Capital Markets Domain this means Engineering Agents may build exchange adapters and trading runtimes from approved contracts, but production exchange credentials and live order execution remain capabilities of the Capital Markets Runtime with its own policy and risk controls.

## Acceptance target

The target workflow is:

Master Domain Specification
→ Domain Manager decomposition
→ Principal Architect Domain Architecture
→ dependency-driven Feature Engineering workflows
→ integration
→ Domain QA
→ release gate
→ human-controlled release

This makes the existing feature workflow a worker-runtime and the new Domain Development Runtime its orchestrator.
