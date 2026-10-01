---
title: Development and verification
description: "Practical COS change workflow from ownership and contracts to tests, documentation, pull requests and production readiness."
status: active
updated: 2026-10-01
kind: development
---

# Development and verification

A successful change is not merely code that passes one test. It must preserve architecture boundaries, tenant isolation, runtime semantics and understandable documentation.

## Before coding

Define:

1. the behavior being changed;
2. its semantic owner;
3. the process or use case affected;
4. the data and state transitions involved;
5. external contracts that change;
6. the evidence that will prove correctness.

If ownership is unclear, creating another Shared folder is not yet the answer.

## Verification entry point

The main runner is:

~~~bash
bash bin/verify fast
bash bin/verify contract
bash bin/verify unit
bash bin/verify integration
bash bin/verify smoke
bash bin/verify full
~~~

Active tests verify current invariants and behavior. Historical release gates are not part of the active suite.

## Choosing the test level

**Contract tests** protect long-lived architecture boundaries.

**Unit tests** verify local behavior without the full runtime.

**Integration tests** verify real persistence, transport or framework infrastructure.

**Smoke tests** prove a small critical vertical slice.

**Browser and E2E tests** validate production-like interaction through the real interface.

Do not prove the same fact five times just because five test directories exist.

## Persistence changes

Schema changes need explicit ownership, migrations, safe deployment order, handling for existing data and a recovery plan.

## Documentation changes

If behavior changes, update the audience level that explains it.

Narrative Markdown should not become an application test dependency. Generated reference should continue to come from executable source.

## Before merge

Check boundaries, tenant context, authorization, idempotency, failure paths, observability, tests, documentation, migrations and compatibility of external contracts.

Continue: [architecture](./architecture.md).
