---
title: "COS Federation Foundation — baseline"
description: "Initial architecture reuse and remaining implementation gates."
status: active
updated: 2026-10-08
kind: architecture
---

# AS-IS, Reuse Matrix and Gap Register

Baseline inspected: main e110e36b1dfee8ed69a207b61eb2ee3c60b3084d.

| Area | Existing to reuse | Open gap |
| --- | --- | --- |
| Module ownership | ModuleDiscovery, ModuleCatalog, ModuleCapabilityRegistry | Runtime handler/tool binding evidence and vocabulary drift |
| Cross-domain | CrossDomainContract, dependency audit | Consumer-driven compatibility schemas and test matrix |
| Architecture | CrossDomainArchitectureGraphProvider | Evidence-grade graph, impact and targeted tests |
| Data | TableOwnership, PDO/Doctrine | Dynamic SQL, cross-domain write/read coverage |
| Execution | Workflow/Action/Agent, Messenger | Envelope, durable retries, recovery and trace |
| Goals | Domain capabilities and process runtime | Goal/plan/run/outcome persistence and cross-domain golden slice |
| Experience | WorkspaceCompositionResolver, UIActionResolver | Adaptive disclosure, three modes, tenant state, UA/EN, mobile |
| Engineering | Architecture tests and bash bin/verify | PHPStan/AST checks, Impact Gate and production E2E |

## Migration decisions

Keep module.php canonical; descriptive capability identities never become executable authority. Add typed capability_contracts as an optional contribution. Introduce CrossDomainContract V2 metadata with non-breaking constructor defaults, migrating each consumer independently. Do not create a new frontend runtime, workflow engine, agent runtime or parallel authorization store.

## Remaining P0 implementation backlog

1. Reconcile registered DI handlers, tools, permissions and module identities with actual evidence.
2. Consumer-driven schema compatibility and dependency-aware Impact Gate.
3. Authenticated execution envelope, async propagation, idempotency and worker recovery tests.
4. GoalSpecification, ExecutionPlan/Run, OutcomeEvaluator, artifacts and multi-domain scenario.
5. Result/Process/Expert, deterministic disclosure and persisted tenant-scoped preferences.
6. Golden paths, failure injection, browser, security, performance, rollout and rollback evidence.

**Status:** Initial foundation only; full P0 Definition of Done remains open.
