---
title: Module and Capability Reference
description: Поточні module manifests та capability vocabulary.
status: active
updated: 2026-09-11
kind: reference
---

# Module and Capability Reference

## Kernel contract version

`Kernel\\Module\\KernelVersion::VERSION = 0.7.1`.

## Sales module

Manifest:

- id: `sales`;
- version: `0.7.1`;
- schema: `0.7.1`;
- kernel constraint: `^0.7.1`;
- enabled by default: yes;
- runtime module service: `salesDomainModule`;
- job handler: `salesCrmInboxJobHandler`.

### Sales capabilities

Canonical enum містить:

- `sales.workspace.use`;
- `sales.director.view`;
- `sales.deal.assign`;
- `sales.approval.decide`;
- `sales.approval.any_team`;
- `sales.admin.view`;
- `sales.admin.pipeline.manage`;
- `sales.admin.rules.manage`;
- `sales.admin.agents.manage`;
- `sales.admin.policies.manage`;
- `sales.admin.teams.manage`;
- `sales.admin.integrations.manage`;
- `sales.admin.audit.view`.

Примітка: module manifest contributions і `SalesCapability` enum треба тримати синхронно. Enum наразі ширший за manifest capability list для operational authority (`deal.assign`, approvals).

## Diagnostic module

- id: `diagnostic`;
- version: `0.5.4`;
- schema: `0.5.4`;
- kernel constraint: `^0.7.1`;
- enabled by default: yes;
- runtime module service: `null`;
- capabilities: none declared.

## Property module

- id: `property`;
- version: `0.1.0`;
- schema: `0.1.0`;
- kernel constraint: `^0.7.1`;
- enabled by default: yes;
- runtime module service: `null`;
- capabilities: none declared.

## Identity / Content / Spatial

У поточній структурі вони не мають такого ж manifest contract у корені domain, як Sales/Diagnostic/Property.

## Capability rule

Capability — stable permission/capability vocabulary, а не UI label. UI може приховати кнопку, але server-side use case/interface усе одно повинен перевірити authority.