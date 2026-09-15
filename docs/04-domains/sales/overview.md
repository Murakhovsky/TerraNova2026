---
title: Sales Domain Overview
description: Ownership, runtime contributions, use-case model і boundaries Sales domain.
status: active
updated: 2026-09-15
kind: domain
---

# Sales Domain Overview

Sales — reference bounded context COS і найповніша реалізація domain/module pattern у current `main`.

## Purpose

```text
Lead → Client Case / Deal → Pipeline → Activities / Follow-up → Outcome
```

Sales володіє demand lifecycle, а не generic CRM і не canonical Property.

## Read this domain

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Sales knowledge path</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="./domain-model.html"><strong>Domain Model</strong><span>Vocabulary, ownership, entities та invariants.</span></a>
      <a class="cos-map-node" href="./lifecycle-and-automation.html"><strong>Lifecycle & Automation</strong><span>Intake, pipeline, follow-up, decisions та Agent loop.</span></a>
      <a class="cos-map-node" href="./contracts-and-code-map.html"><strong>Contracts & Code</strong><span>Ports, adapters, legacy boundary та implementation map.</span></a>
    </div>
  </div>
</div>

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

## Related workflow and reference

- [Sales Lead → Managed Case](../../02-workflows/sales-lead-to-managed-case.md)
- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Commands](../../12-reference/commands.md)
- [Events](../../12-reference/event-types.md)
- [Module Routes](../../12-reference/module-routes.md)
