---
title: CI Performance Pack
description: Паралельні перевірки MySQL і Runtime, кешування образів Docker та вимірювання тривалості етапів без зниження контролю якості.
kind: architecture
status: proposed
updated: 2026-10-15
---

# CI Performance Pack: паралельне виконання та контроль якості

**Purpose:** reduce time to a useful failure on Federation without weakening the
whole-production runtime acceptance. The jobs below run independently when the
Runtime workflow runs for a PR, `main`, or an explicit `workflow_dispatch`.

| Job | Scope | External prerequisites |
| --- | --- | --- |
| `Federation MySQL` | Federation/Workflow MySQL Action, receipt, Goal E2E, historical no-replay cutover, non-Action | MySQL 8.4, Redis, Symfony Composer `--no-dev`, both real schema migration mechanisms |
| `Capital Markets Pack` | Capital Risk and Decision Workspace unit/integration acceptance | Root Composer/npm and built frontend parity |
| `runtime` | Production PHP/Nginx image, dependent services, all existing MySQL/health/routes/browser/vertical-slice acceptance | BuildKit and Docker Compose |

These jobs do **not** depend on each other: one failed Federation assertion no
longer prevents the other independent packs from running. The deployment job has
`needs: [runtime, federation-mysql, capital-markets-unit]` and is still
restricted to successful `main` pushes. A manual workflow dispatch never
deploys or modifies live production.

## Кешування Docker

The runtime job uses `docker/setup-buildx-action@v3` and two separate
`docker/build-push-action@v6` builds with `load: true`. Their
`type=gha` caches have separate, explicitly named scopes:

- `cos-runtime-php-v1`: PHP extensions and Symfony Composer layers.
- `cos-runtime-nginx-v1`: Nginx runtime and static site layers.

Existing `docker compose up` uses the exact local loaded tags
`cos-symfony-php:local` and `cos-nginx:latest`. This does **not**
publish or deploy container images. Cache exports have `ignore-error=true`,
so cache unavailability can only degrade build speed and cannot turn a
valid build into a false PASS or block acceptance. A changed `composer.lock`
will correctly invalidate the dependency layer. GitHub cache visibility is
branch-scoped; the first run on a cold cache still pays full build cost.

Root and Symfony Composer archives also have independent lockfile-keyed
`actions/cache@v4` caches. Node uses `setup-node`'s npm archive cache;
`npm ci` and asset builds are still executed when required.

## Вимірювання тривалості та спостережуваність

Every independent job invokes `bin/ci/step_timings.py` with `if: always()`.
It obtains finished *GitHub Actions step* durations using the read-only
Actions jobs endpoint, so it covers third-party actions such as Buildx as
well as shell tasks. Per-stage elapsed seconds and conclusions are written
to the job's Step Summary and uploaded as `ci-timings-*.json` artifacts.
If GitHub's metrics endpoint is temporarily unavailable, a warning is
emitted; no test result is reclassified as a success.

The report is observational: it is not a benchmark guarantee. Compare first
cold-cache and second warm-cache executions of the *same* lockfiles and
Dockerfiles before quoting improvements. Use the actual GitHub job conclusion
and commit SHA as the only acceptance signal.

## Межі застосування

The fast Federation gate uses the same MySQL 8.4 schema migrations and
independent Action/receipt/Plan adapters, but uses host PHP 8.3 rather than
the production Docker image. It is a **fast preflight**, never a replacement
for production-image Runtime. Docker, Mercure, browser and cross-domain
checks remain in the `runtime` job. Full `runtime` does not skip
historical cutover or non-Action receipts. Failed migration and uncertain
Action effects still fail closed.

When a PR has merge conflicts, a manual `workflow_dispatch` on its feature
branch can validate its HEAD, but does not itself resolve the merge conflicts.
