---
title: Довідник конфігурації
description: Згенерована карта ownership для module configuration provisioners і declarations можливостей.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Довідник конфігурації

> Джерело істини: `app/Domains/*/module.php` у поточному checkout.

| Модуль | Версія | Configuration provisioners | Можливостей |
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
