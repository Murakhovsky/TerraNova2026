---
title: Domains and boundaries
description: "How COS assigns business ownership to domains, controls dependencies and coordinates cross-domain behavior."
status: active
updated: 2026-10-01
kind: architecture
---

# Domains and boundaries

A Domain in COS is not just a folder. It is the **owner of a specific area of business meaning**.

## Domain ownership

A domain owns:

- its vocabulary;
- entities and state;
- business rules;
- allowed transitions;
- domain commands and events;
- its persistence ownership;
- use cases that change its state.

For example, Sales may own the lifecycle of a deal. It should not directly mutate internal Property state.

## What a domain should not duplicate

Generic mechanisms belong outside a business domain when they contain no domain-specific meaning.

Examples include event delivery, queue transport, generic authorization plumbing, observability and common execution machinery.

## Cross-domain interaction

Preferred patterns are:

1. an event announces a fact that already happened;
2. an application use case coordinates several contracts;
3. an explicit integration contract provides required data;
4. process orchestration manages a long-running business flow.

Direct access to another domain's private table or repository breaks the boundary even when it appears convenient.

## Data ownership

Every table or aggregate needs a semantic owner.

Another domain may use a projection, read model or synchronized copy, but it should not silently become a second writer of the same business state.

## Module contract

An installable domain declares its identity and capabilities.

Capability-specific interfaces expose events, actions, rules, policies and other extension points only when the module actually provides them.

This avoids a universal interface full of meaningless empty methods.

## Before adding behavior

Ask:

- which domain owns the new behavior;
- whether its vocabulary changes;
- which facts are events and which intentions are commands;
- whether synchronous response is required;
- who owns persistence;
- which cross-domain dependencies appear;
- which test proves the boundary.

Continue: [runtime](./runtime.md).
