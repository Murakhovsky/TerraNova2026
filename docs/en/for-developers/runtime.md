---
title: COS runtime
description: Technical overview of COS execution context, actions, events, outbox, workers, retries, persistence and failure behavior.
status: active
updated: 2026-10-01
kind: architecture
---

# COS runtime

Runtime turns a business decision into controlled execution.

## Execution context

A meaningful use case usually carries:

- organization or tenant;
- user identity when relevant;
- correlation and causation context;
- authorization information;
- idempotency context for repeatable operations.

This context should be explicit or provided by an approved runtime mechanism rather than reconstructed from global state.

## Actions

An Action represents work the system intends to perform.

Its lifecycle may include creation, policy evaluation, human approval, worker claim, execution, completion and retry.

Execution must be able to distinguish a new action from another attempt to perform the same action.

## Events

An Event records a fact that already happened.

Events should not mean “please do this.” Intention belongs in a command or action.

Events support loose coupling, audit history and projections.

## Durable outbox

When a business state change and event publication must stay consistent, the outbox records the delivery intent close to the same transactional boundary.

A worker publishes it later. Re-delivery must be safe.

## Queues and workers

A background worker follows the same tenant, policy, logging and failure rules as the request runtime.

A worker should:

- claim work safely;
- use explicit retry rules;
- avoid duplicate side effects;
- leave visible failed state;
- support operational recovery.

## Failure semantics

The runtime distinguishes business rejection, policy denial, temporary technical failure, permanent technical failure and uncertain external outcomes.

Not every failure should retry. An infinite retry loop is not resilience. It is just denial with CPU usage.

## Verification

Runtime verification covers container and routes, health, schema and migrations, current contracts, tenant isolation and critical smoke scenarios.

Continue: [development and verification](./development.md).
