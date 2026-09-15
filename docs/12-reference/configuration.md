---
title: Configuration Reference
description: Generated ownership map for module configuration provisioners and capability declarations.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Configuration Reference

> Джерело істини: `app/Domains/*/module.php` у current checkout.

| Module | Version | Configuration provisioners | Capabilities |
| --- | --- | --- | ---: |
| `diagnostic` | `0.6.1` | — | 0 |
| `property` | `0.12.0` | `propertyModuleConfigurationProvisioner` | 16 |
| `sales` | `0.8.6` | `salesModuleConfigurationProvisioner` | 10 |

## `diagnostic`

- manifest: `app/Domains/Diagnostic/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `property`

- manifest: `app/Domains/Property/module.php`;
- configuration provisioners: `propertyModuleConfigurationProvisioner`;
- capabilities: `property.registry`, `property.read`, `property.write`, `property.intake`, `property.media`, `property.catalog`, `property.inventory`, `property.listing`, `property.publish`, `property.history`, `property.reference`, `property.analytics`, `property.intelligence`, `property.network`, `property.identity.review`, `property.runtime.canonical`.

## `sales`

- manifest: `app/Domains/Sales/module.php`;
- configuration provisioners: `salesModuleConfigurationProvisioner`;
- capabilities: `sales.workspace.use`, `sales.director.view`, `sales.admin.view`, `sales.admin.pipeline.manage`, `sales.admin.rules.manage`, `sales.admin.agents.manage`, `sales.admin.policies.manage`, `sales.admin.teams.manage`, `sales.admin.integrations.manage`, `sales.admin.audit.view`.
