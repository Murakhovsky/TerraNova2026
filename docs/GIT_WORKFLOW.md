# COS Git workflow

COS uses a trunk-based workflow with short-lived task branches. Architectural boundaries such as Kernel, Domains, Infrastructure, Frontend, and Documentation are repository/module boundaries, not long-lived Git branches.

## Permanent branches

- `main` — stable, deployable source of truth.
- `migration/symfony` — temporary integration branch used only while the Symfony migration is active. Delete it after the migration is complete.

## Short-lived branch prefixes

- `feat/<scope>-<feature>` — product capability.
- `fix/<scope>-<problem>` — bug fix.
- `refactor/<scope>-<change>` — internal refactoring without intended behavior change.
- `migration/<scope>` — migration slice.
- `infra/<change>` — CI/CD, Docker, runtime, deployment, observability.
- `docs/<topic>` — documentation only.
- `test/<scope>` — tests and test infrastructure.
- `experiment/<topic>` — disposable research/spikes; do not merge unfinished experiments into `main`.

Examples:

- `feat/sales-lead-scoring`
- `feat/property-import`
- `fix/security-tenant-isolation`
- `refactor/kernel-event-dispatcher`
- `migration/operations-read-api`
- `infra/messenger-workers`
- `docs/cos-architecture`

## Branch rule

A branch represents one complete system change, not one technical layer. A single feature may legitimately change Kernel, Domain, Infrastructure, Symfony transport, frontend, and tests together when they form one vertical slice.

Do not create permanent `kernel`, `domains`, `frontend`, `documentation`, or `infrastructure` branches.

## Normal development

1. Create a short-lived branch from `main`.
2. Make one coherent change.
3. Open a PR to `main`.
4. CI must pass.
5. Prefer squash merge for a normal task PR.
6. Delete the task branch after merge.

Do not commit directly to `main`.

## Symfony migration workflow

Symfony Foundation was closed on 2026-09-18 after migration points 1–38 were merged to `main`. From this point onward, migration work is business-cutover work: each new slice must correspond to a concrete executable business scenario. Foundation-only refactoring requires a demonstrated need from an active slice, security/correctness issue, production incident, or measured runtime problem.

During the migration only:

1. `migration/symfony` is the integration stream.
2. Create each migration slice from `migration/symfony`, for example `migration/operations-read-api`.
3. Open the slice PR back to `migration/symfony`.
4. CI must pass before merge.
5. Prefer squash merge for slice PRs.
6. When a migration milestone is coherent and verified, open a milestone PR from `migration/symfony` to `main`.
7. Prefer a merge commit for the milestone PR so the individual migration-slice commits remain visible in main history.
8. If `main` changes while migration work is in progress, merge `main` into `migration/symfony`; do not force-rebase a shared integration branch.

`main` remains the only branch allowed to deploy the shared AWS dev runtime automatically. Migration branches validate in CI but must not automatically replace the shared runtime.

## Definition of ready to merge

A PR should be small enough to review as one change and must leave its target branch buildable. Relevant architecture, unit, integration, contract, and smoke tests must pass. Business/domain behavior belongs in Kernel/Domains/Application services, not in Symfony controllers or framework glue.

## End state

After Symfony fully replaces the old runtime, merge the final migration milestone to `main`, delete `migration/symfony`, and return to `main` plus short-lived task branches only.
