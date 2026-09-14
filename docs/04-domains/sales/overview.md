---
title: Sales Domain Overview
description: Ownership, runtime contributions, use-case model і boundaries Sales domain.
status: active
updated: 2026-09-14
kind: domain
---

# Sales Domain Overview

Sales — reference bounded context COS і найповніша реалізація domain/module pattern у current `main`.

## Purpose

```text
Lead → Client Case / Deal → Pipeline → Activities / Follow-up → Outcome
```

Sales володіє demand lifecycle, а не generic CRM і не canonical Property.

## Runtime manifest

```text
id: sales
version: 0.8.6
schema: 0.8.6
kernel: >=0.11.0 <0.12.0
```

Manifest декларує runtime module service, CRM inbox job handler, API route contributor, configuration provisioner, event consumer, Web navigation, migrations і capability catalogue.

## Automation

```text
Sales Event → Rule / Agent → Action Proposal → Policy → Kernel Execution
```

Generated inventories:

- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Commands](../../12-reference/commands.md)
- [Events](../../12-reference/event-types.md)
- [Module Routes](../../12-reference/module-routes.md)
