---
title: COS Federation — actual execution authority audit, 2026-10-15
description: Verified PR #346 technical execution owners, duplicate Run/Step persistence and safe cutover blockers.
status: active
updated: 2026-10-15
kind: architecture
---

# Actual execution authority audit (PR #346)

**Reviewed head:** \`a8b2cd5cb921ef96dacaf648af7e5f5767937e4a\`, branch \`feature/cos-federation-foundation-phase1-20261008\`. Review is based on GitHub source, existing contract/CI evidence and DI wiring. **No production database read, runtime traffic trace or deployment verification was performed in this audit.** This audit is not a full runtime call graph.

## Verified observations

1. **Platform Workflow:** \`DoctrineWorkflowExecutionStore\` writes \`cos_workflow_runs/steps\`; its tenant-bound create, CAS transitions, claim/checkpoint, receipt and recovery foundation exists. The generic \`WorkflowExecutionCoordinatorInterface\` is defined, but the active federation endpoint does not delegate start/advance to a canonical coordinator.
2. **Goal/Federation:** \`FederationRunController\` calls \`FederationSequentialOrchestrator\`, which invokes \`FederationGoalStore\` for persisted \`cos_federation_runs/steps\` and \`FederationRunFinalizer\` for completion. This is an active second technical execution state authority, **not merely a projection**. Goal/Plan/Outcome data are legitimate application-owned concepts and must survive.
3. **Shared technical primitives:** \`FederationStepDependencyGraph\` delegates to \`WorkflowDependencyGraph\`, \`FederationStepCursor\` to \`WorkflowStepScheduler\`, and \`ExecutionRunPolicy\` to \`DurableWorkflowTransitionPolicy\`. These are real partial consolidations, not yet a single execution lifecycle.
4. **Engineering:** \`EngineeringCanonicalTechnicalExecutionGateway\` directly writes to canonical \`cos_workflow_runs/steps\` for human-allowlisted AgentRuns, bypassing the generic Store API. It also creates an Engineering technical link. The Engineering business Workflow and human gates remain separate by design. \`EngineeringCanonicalTechnicalExecutionAdmission\` requires real shadow parity. This technical writer is a migration debt, not proof of universal execution convergence.
5. **Worker safety:** \`DoctrineWorkflowActionWorkerAdmission\`, \`WorkflowPredecessorReceiptVerifier\` and registered non-Action evidence adapters exist; generic receipt/claim defaults remain fail-closed in \`symfony/config/services.yaml\`. Safety adapters should be retained during cutover.
6. **Read-only parity:** \`DoctrineWorkflowShadowParityReader\` and \`cos:workflow:shadow-audit\` can compare real persisted sources. Code and tests do not establish parity for every live tenant/legacy run. The previous duplication registry still records all 20 entries as \`NOT_STARTED\`, and all 25 planned parity cases as \`NOT_IMPLEMENTED\`.
7. **CI:** At the reviewed head, GitHub reported completed/success for \`CI\`, \`Runtime\`, \`Frontend\`, \`Documentation\`. Successful checks do not prove actual dual-writer removal or production DB cutover.

## Current authority / cutover matrix

| Source | Existing technical writer | Source of truth today | Target |
| --- | --- | --- | --- |
| Legacy Kernel Workflow | \`Kernel/Workflow/Service/WorkflowEngine\` | PHP execution objects | Adapter or retire only after consumer parity |
| Federation Goals | \`FederationGoalStore\`, \`FederationRunFinalizer\` | \`cos_federation_runs/steps\` | \`Platform Workflow\` (Goal/Plan/Outcome remain in Goal Management) |
| Generic durable Workflow | \`DoctrineWorkflowExecutionStore\`, \`DoctrineWorkflowRecoveryManager\` | \`cos_workflow_runs/steps\` | One canonical execution persistence and admission |
| Engineering technical AgentRun | \`EngineeringCanonicalTechnicalExecutionGateway\` | Canonical Run/Step for allowlisted AgentRuns plus Engineering legacy business stage | Store-backed technical execution; preserve Engineering domain stages, audit and human decisions |

**Stage 0 implemented by this increment:** \`resources/architecture/cos-execution-authority-freeze.json\` is a machine-readable, reviewed allowlist of existing direct execution writers. \`tests/architecture/cos_federation_execution_authority_freeze.php\` scans runtime PHP for new direct Run/Step SQL/DBAL writes and parallel Federation engines. It fails CI for new sources rather than legitimizing them. All existing writers are **grandfathered debt**, not approved target design. It is a static regression gate, **not** a database migration, complete AST/SQL dataflow scan, run-time ownership proof, or authorization to delete legacy storage.

## Mandatory next cutover work (no automatic deletion)

1. Implement and wire a **single** durable \`WorkflowExecutionCoordinatorInterface\` on top of the canonical Workflow Store, Action/Agent/Tool handlers and Queue, preserving tenant CAS, approval revalidation, receipts and uncertainty safety.
2. Produce complete consumer and DB write graph, including web/CLI/queue/Engineering, and lock down tenant+plan identity uniqueness across old/new runtimes. The registry must record verified consumers before migration status advances.
3. Add additive legacy-to-canonical ID mapping, transactional read-only backfill, independent receipt attestation and **fleet-wide** shadow parity, with explicit \`NO_TARGET/DRIFT/AMBIGUOUS\` quarantine.
4. Fence legacy submissions and drain/reconcile in-flight side effects; switch one tenant/plan cohort at a time. Never dual dispatch, dual write or blindly retry an unknown external outcome.
5. Move \`FederationSequentialOrchestrator\` behind a Goal-to-Workflow adapter, retire its old Run/Step writer after parity, then remove the inert read-only bridge. Keep historical rows immutable until retention decision.
6. Move Engineering canonical AgentRun write/claim/checkpoint paths into the shared Workflow Store while retaining Engineering business state, human merge/approval, AgentRun artifacts and transition history.
7. Validate Golden 50 and all installed business Domains through published capabilities with trusted native evidence. Require live CI MySQL/failure-injection evidence and a verified operations rollback strategy before declaring completion.

**Do not conflate an architectural freeze or green tests with production convergence.**
