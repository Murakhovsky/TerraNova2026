---
title: COS architecture
description: "Developer overview of COS layers, boundaries, dependency direction, execution, tenant isolation and sources of truth."
status: active
updated: 2026-10-01
kind: architecture
---

# COS architecture

This page gives a technical map of COS before you dive into individual classes and domain contracts.

## Core idea

COS separates **business meaning** from **execution mechanisms**.

A domain knows what a deal, property, diagnostic or other business concept means. The Kernel should not know those details. It provides reusable execution, event, policy, queue, extension, context and observability mechanisms.

## Main layers

A simplified view:

1. **Interfaces** receive HTTP requests, commands, messages or user actions.
2. **Application layer** coordinates use cases and carries organization and identity context.
3. **Domains** own business vocabulary, state, rules and allowed transitions.
4. **Kernel** provides generic execution, event, policy, automation and agent mechanisms.
5. **Infrastructure** implements database access, queues, external APIs, storage and other adapters.

Dependencies should point toward stable contracts and business meaning rather than forcing domain logic to depend on infrastructure details.

## Ownership boundaries

Every new behavior needs an owner.

If it has specific business meaning, it belongs to a domain. If it is a reusable mechanism with no domain vocabulary, it may belong to Kernel or Platform.

“Shared” is not an ownership model.

## Execution path

A typical path is:

~~~text
input
  ↓
tenant + identity context
  ↓
application use case
  ↓
domain rule / state change
  ↓
event or action
  ↓
policy / approval / execution
  ↓
persistence + audit + observability
~~~

Important side effects use explicit lifecycle and durable delivery mechanisms so business state and external execution do not silently drift apart.

## Multi-tenancy

Organization context is part of execution, not an optional query filter.

Tenant isolation must survive HTTP requests, repositories, events, background workers and integrations.

## Source of truth

The evidence order is:

~~~text
current code + active tests
        ↓
manifests / process declarations
        ↓
generated reference
        ↓
narrative documentation
        ↓
ADR
~~~

Narrative documentation explains the system. It does not override executable behavior.

## Continue

- [Domains and boundaries](./domains.md)
- [Runtime](./runtime.md)
- [Development and verification](./development.md)
