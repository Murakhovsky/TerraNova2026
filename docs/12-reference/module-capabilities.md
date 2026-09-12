---
title: Module and Capability Reference
description: Generated reference з module manifests та declared capabilities.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Module and Capability Reference

> Джерело істини: `app/Domains/*/module.php` та `app/Kernel/Module/KernelVersion.php`.

## Kernel contract version

`Kernel\Module\KernelVersion::VERSION = 0.10.2`.

## Registered modules

| ID | Name | Version | Schema | Kernel constraint | Default | Dependencies | Source |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `diagnostic` | Diagnostics | `0.5.4` | `0.5.4` | `>=0.10.0 <0.11.0` | yes | — | `app/Domains/Diagnostic/module.php` |
| `property` | Property | `0.1.0` | `0.1.0` | `>=0.10.0 <0.11.0` | yes | — | `app/Domains/Property/module.php` |
| `sales` | Sales | `0.7.1` | `0.7.1` | `>=0.10.0 <0.11.0` | yes | — | `app/Domains/Sales/module.php` |

## Diagnostics (`diagnostic`)

Business diagnostics, methodology, interviews and reporting.

- runtime module service: —;
- job handlers: —;
- API route contributors: —;
- configuration provisioners: —;
- migrations: —.

### Declared capabilities

Manifest capabilities не задекларовані.

## Property (`property`)

Property catalog, presentation and real-estate workflows.

- runtime module service: —;
- job handlers: —;
- API route contributors: —;
- configuration provisioners: —;
- migrations: —.

### Declared capabilities

Manifest capabilities не задекларовані.

## Sales (`sales`)

Sales operations, CRM workflow, intelligence and automation.

- runtime module service: `salesDomainModule`;
- job handlers: `salesCrmInboxJobHandler`;
- API route contributors: `salesRouteContributor`;
- configuration provisioners: `salesModuleConfigurationProvisioner`;
- migrations: `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql`.

### Declared capabilities

- `sales.admin.agents.manage`;
- `sales.admin.audit.view`;
- `sales.admin.integrations.manage`;
- `sales.admin.pipeline.manage`;
- `sales.admin.policies.manage`;
- `sales.admin.rules.manage`;
- `sales.admin.teams.manage`;
- `sales.admin.view`;
- `sales.director.view`;
- `sales.workspace.use`;

## Scope

Ця сторінка описує тільки факти з installable module manifests. Domain directories без `module.php` сюди не потрапляють. Capability enum або runtime authority можуть мати ширший vocabulary і повинні документуватися окремим generated reference.
