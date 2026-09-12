---
title: Module Extension Points
description: Generated registry of Kernel and module-defined extension points.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Module Extension Points

> Джерело істини: `ModuleExtensionRegistry` та `extension_services` у `app/Domains/*/module.php`.

| Extension point | Kind | Contributions |
| --- | --- | ---: |
| `api.routes` | built-in | 1 |
| `tenant.configuration` | built-in | 1 |
| `web.navigation` | module-defined | 3 |

## `api.routes`

Kind: **built-in**.

| Module | Service |
| --- | --- |
| `sales` | `salesRouteContributor` |

## `tenant.configuration`

Kind: **built-in**.

| Module | Service |
| --- | --- |
| `sales` | `salesModuleConfigurationProvisioner` |

## `web.navigation`

Kind: **module-defined**.

| Module | Service |
| --- | --- |
| `diagnostic` | `diagnosticNavigationContributor` |
| `property` | `propertyNavigationContributor` |
| `sales` | `salesNavigationContributor` |

## Registration semantics

Built-in points `api.routes` і `tenant.configuration` створюються Kernel registry з typed contribution lists. Інші точки реєструються через manifest `extension_services`. Runtime registry зберігає трійку `module_id + extension_point + service_id`.
