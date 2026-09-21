---
title: Точки розширення модулів
description: Згенерований registry точок розширення Kernel і module-defined extension points.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУЙТЕ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Точки розширення модулів

> Джерело істини: `ModuleExtensionRegistry` та `extension_services` у `app/Domains/*/module.php`.

| Точка розширення | Тип | Внески |
| --- | --- | ---: |
| `api.routes` | built-in | 0 |
| `event.consumers` | module-defined | 2 |
| `tenant.configuration` | built-in | 2 |
| `web.commands` | module-defined | 3 |
| `web.navigation` | module-defined | 3 |
| `web.search` | module-defined | 3 |
| `web.workspace` | module-defined | 3 |

## `api.routes`

Тип: **built-in**.

Поточних module contributions немає.

## `event.consumers`

Тип: **module-defined**.

| Модуль | Service |
| --- | --- |
| `diagnostic` | `diagnosticActionOutcomeHandler` |
| `sales` | `salesHistoricalEventConsumer` |

## `tenant.configuration`

Тип: **built-in**.

| Модуль | Service |
| --- | --- |
| `property` | `propertyModuleConfigurationProvisioner` |
| `sales` | `salesModuleConfigurationProvisioner` |

## `web.commands`

Тип: **module-defined**.

| Модуль | Service |
| --- | --- |
| `diagnostic` | `diagnosticNavigationContributor` |
| `property` | `propertyNavigationContributor` |
| `sales` | `salesNavigationContributor` |

## `web.navigation`

Тип: **module-defined**.

| Модуль | Service |
| --- | --- |
| `diagnostic` | `diagnosticNavigationContributor` |
| `property` | `propertyNavigationContributor` |
| `sales` | `salesNavigationContributor` |

## `web.search`

Тип: **module-defined**.

| Модуль | Service |
| --- | --- |
| `diagnostic` | `diagnosticNavigationContributor` |
| `property` | `propertyNavigationContributor` |
| `sales` | `salesNavigationContributor` |

## `web.workspace`

Тип: **module-defined**.

| Модуль | Service |
| --- | --- |
| `diagnostic` | `diagnosticNavigationContributor` |
| `property` | `propertyNavigationContributor` |
| `sales` | `salesNavigationContributor` |

## Семантика реєстрації

Built-in points `api.routes` і `tenant.configuration` створюються Kernel registry з typed contribution lists. Інші точки реєструються через manifest `extension_services`. Runtime registry зберігає трійку `module_id + extension_point + service_id`.
