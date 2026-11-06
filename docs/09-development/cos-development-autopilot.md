# COS Development Autopilot: P0 operating contract

Status: implementation PR, NOT production-accepted.
Owner: COS Engineering. Last revised: 2026-11-06.

## Purpose

Convert a human-approved Master Roadmap into a bounded, auditable stream of Engineering feature workflows. The controller adds no new generic executor. It uses the existing EngineeringOrchestrator, feature/workflow persistence, agent role stages, Developer repository policy, GitHub gateway, QA and CI.

## Authority and control

- Master Roadmap and Issue acceptance criteria are owned by the human product/architecture owner.
- GitHub Issue intake requires both labels: cos:autopilot:ready and cos:autopilot:low-risk.
- P0 and security/high-risk, production, migration and breaking changes are never auto-admitted.
- Every Issue must contain ## Acceptance Criteria, ## Allowed Paths and ## Dependencies. Missing/invalid sections fail closed.
- Controller selects by priority P1 -> P2 -> P3 and then issue number; dependencies must be closed Issues.
- At most one active Engineering workflow is admitted per organization at a time in P0; no parallel implementation.
- A DB advisory lock serializes Autopilot controller ticks. Persisted external Issue identity avoids repeated intake and supports recovery of NEW features.
- Allowed Paths are persisted as feature metadata and deterministically checked for EVERY Developer+Architect change before commit.
- Engineering Runtime owns its existing agent steps, retries, budgets, reviewer, QA and READY_FOR_HUMAN_APPROVAL.
- Only a human may merge a PR, accept risky changes, approve release, or deploy production. Autopilot never does these actions.
- GitHub Issue body is untrusted input. It cannot change policies, architectural standards, credentials or authority.

## Usage

Run these commands from the existing Symfony runtime with its configured DB, GitHub credentials and tenant:

```bash
php bin/console cos:engineering:autopilot:tick
php bin/console cos:engineering:autopilot:tick --scan-limit=20
```

These calls are preview-only, with no Issue/Feature mutation. To run a one-off controlled admission after validation:

```bash
COS_ENGINEERING_AUTOPILOT_ENABLED=1 php bin/console cos:engineering:autopilot:tick --execute
```

To run continuously on the existing separate COS scheduler/Engineering workers, deliberately arm all THREE controls in the worker environment:

```dotenv
COS_ENGINEERING_AUTOPILOT_ENABLED=1
COS_ENGINEERING_AUTOPILOT_SCHEDULER_ENABLED=1
COS_ENGINEERING_AUTOPILOT_INTERVAL_MINUTES=5
COS_ENGINEERING_AUTONOMY_SCHEDULER_ENABLED=1
```

Keep the existing scheduler_cos consumer, engineering consumer, and Engineering runtime watchdog alive. Do not turn these on in production until the P0 acceptance tests below pass. A killed/disconnected scheduler cannot be replaced by this chat. Both enable flags default to 0. Set either to 0 to stop new unattended intake; stopping new admissions does not cancel an already running AgentRun.

Ensure COS_ORGANIZATION_ID is a real tenant, COS_ENGINEERING_GITHUB_REPOSITORY points to the approved repo, and COS_ENGINEERING_GITHUB_TOKEN is a least-privilege short-lived credential. Never place tokens in Issues, agent prompts, CI logs or code.

## Issue template

Use the GitHub issue template COS Autopilot Feature. Fill the outcome, immutable acceptance checklist, bounded repository paths, and explicit dependencies. An operator must review and manually apply both ready and low-risk labels. No label means no execution.

## Failure and restart behavior

- Busy Engineering workflow -> BUSY, no new work.
- Invalid/missing issue sections, risky labels, blocked dependency -> skipped and reported, no write.
- Feature created but workflow not queued (crash) -> next tick finds same external issue reference and queues existing NEW feature.
- Already imported feature beyond NEW -> never re-import.
- GitHub/API/DB outage -> fail closed. The scheduled worker may retry, but no privileged fallback is permitted.
- Path drift or agent-generated unauthorized file -> Developer commit fails before repository write.
- Human intervention or production deployment remains a separate operator gate.

## Master Roadmap and delivery gates

| Stage | Deliverable | Exit criteria |
| --- | --- | --- |
| P0-A Admission | Queue reader, strict issue contract, idempotency and lock | Unit/architecture tests and a true dry run |
| P0-B Execution bridge | Scheduler message to existing Engineering worker | Single low-risk Issue reaches PR with persisted evidence |
| P0-C QA/CI | Developer file-boundary check, reviewer and QA exact-SHA evidence | Tests green, no arbitrary scope mutation, human merge gate |
| P0-D Recovery | Crash between intake/queue, API outage, worker retry and lock collision | No duplicated Feature/PR or unsafe replay |
| P0-E Production acceptance | Real DB/tenant, least privilege, manual operator sign-off | End-to-end sandbox test + rollback exercise |
| P1-A Scaling | Path reservations, per-capability work queues, dependency DAG and safe concurrency | Two independent issues run without branch/path conflict |
| P1-B Governance | Budget ceilings, notifications, milestone summary, road map dashboard | Operator manages outcomes, not individual agent steps |
| P1-C Change control | Risk tiers and human-controlled batch merge | Security/production never bypass human control |

## P0 operator acceptance checklist

- [ ] PHP lint, unit policy test and architecture contract test succeed.
- [ ] Existing core CI / static quality / Engineering regression suites are green.
- [ ] Preview of an eligible Issue returns READY, but creates no Feature or PR.
- [ ] A P2 issue with one closed dependency and safe paths is imported once.
- [ ] Repeated tick after crash/timeout never creates a second Feature or PR.
- [ ] An issue with P0, production, high-risk, or a blocked dependency is rejected.
- [ ] A malicious Issue cannot push a path outside its explicit allowlist.
- [ ] On one real tenant, Engineering workflow proceeds through independent Reviewer and QA; CI is checked against exact head SHA.
- [ ] Engine stops at READY_FOR_HUMAN_APPROVAL and does not merge or deploy.
- [ ] Scheduler and workers restart with durable state; no duplicate side effects.
- [ ] Operator verifies role permissions, GitHub credential scope, cost/run budget, rollback and kill-switch behavior.

## Known limits of P0

Automatic work intake is strictly serial and can report BUSY when other Engineering workflows remain active. P0 has no automatic merge, production deployment, per-path parallelization or end-to-end production acceptance. Current claim/recovery is serialized through the COS DB advisory lock; if the manual Issue command is used concurrently from outside Autopilot, full cross-entrypoint uniqueness requires an explicit DB uniqueness constraint and migration before enabling broad multi-runner use.
