---
title: COS Reading Paths
description: Short, role-oriented routes through COS documentation for understanding, building and operating the system.
status: active
updated: 2026-09-15
kind: concept
---

# COS Reading Paths

Документацію не треба читати від першої сторінки до останньої. Це не роман, і навіть у романах люди іноді пропускають нудні глави.

## Understand COS

Для продукту, архітектора або нового розробника:

```text
What is COS
  ↓
Mental Model
  ↓
Current Scope
  ↓
System Map
  ↓
Business Workflow
  ↓
Domain Model
  ↓
Runtime Lifecycle
```

Почніть з [What is COS](./what-is-cos.md), потім [Mental Model](./mental-model.md) і [System Map](../03-architecture/system-map.md).

## Build with COS

Для розробника, який змінює систему:

```text
Local Setup
  ↓
Repository Map
  ↓
Domain / Module boundary
  ↓
Workflow or Agent
  ↓
Integration
  ↓
Testing
  ↓
Generated Reference / docs check
```

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Build route</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../09-development/local-setup.html"><strong>Local Setup</strong><span>Run runtime and documentation locally.</span></a>
      <a class="cos-map-node" href="../09-development/adding-a-domain.html"><strong>Add Domain</strong><span>Create a semantic owner and its boundaries.</span></a>
      <a class="cos-map-node" href="../09-development/adding-a-module.html"><strong>Add Module</strong><span>Package runtime contributions without moving ownership.</span></a>
      <a class="cos-map-node" href="../09-development/adding-a-workflow.html"><strong>Add Workflow</strong><span>Connect business goal, Domain, Runtime, UI and code.</span></a>
      <a class="cos-map-node" href="../09-development/adding-an-agent.html"><strong>Add Agent</strong><span>Introduce governed decision logic.</span></a>
      <a class="cos-map-node" href="../09-development/adding-an-integration.html"><strong>Add Integration</strong><span>Keep provider details behind ports and adapters.</span></a>
      <a class="cos-map-node" href="../09-development/testing.html"><strong>Testing</strong><span>Verify architecture, behavior and docs integrity.</span></a>
    </div>
  </div>
</div>

## Operate COS

Для deployment/operations задач:

```text
Current Scope
  ↓
Module Readiness
  ↓
Data & Migrations
  ↓
Audit / Diagnostics
  ↓
Deployment-specific runbooks
```

Почніть з [Current Scope](../01-product/current-scope.md), [Module Readiness](../10-operations/module-readiness.md) та [Data & Migrations](../10-operations/data-and-migrations.md).

## Verify exact facts

Якщо питання звучить «які саме events/routes/capabilities/use cases є зараз?», не шукайте відповідь у narrative page. Ідіть у [Generated Reference](../12-reference/README.md).
