---
title: COS for developers
description: Technical entry point to COS architecture, domains, runtime and the development and verification model.
status: active
updated: 2026-10-01
kind: development
---

# COS for developers

This is the technical entry point for engineers who need to understand, change or extend COS safely.

## Recommended route

1. [Architecture](./architecture.md)
2. [Domains and boundaries](./domains.md)
3. [Runtime](./runtime.md)
4. [Development and verification](./development.md)

## Core principle

COS separates domain-owned business meaning from generic execution mechanisms.

Domains own vocabulary, state and business rules. Kernel and Platform provide reusable mechanisms for execution, events, policies, integrations, automation and observability.

## Before changing code

Identify:

- the semantic owner of the behavior;
- the business process or use case that changes;
- the data and state transitions involved;
- external contracts that may change;
- tenant and authorization implications;
- the tests that prove the new behavior.

The English developer guide provides a complete architectural orientation. The deeper Ukrainian technical corpus and executable source remain available for implementation-level details while additional technical translations can be added through the same locale structure.
