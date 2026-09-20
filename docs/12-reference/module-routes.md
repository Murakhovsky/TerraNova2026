---
title: Маршрути модулів
description: Згенерована карта ownership для module API route contributors і файлів джерел маршрутів.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Маршрути модулів

> Джерело істини: module manifests + явний registry джерел маршрутів у поточному checkout.

| Модуль | Contributor | Файлів джерел маршрутів |
| --- | --- | ---: |
| `construction` | — | 0 |
| `diagnostic` | `diagnosticRouteContributor` | 1 |
| `finance` | — | 0 |
| `hr` | — | 0 |
| `procurement` | — | 0 |
| `property` | `propertyRouteContributor` | 2 |
| `real_estate` | — | 0 |
| `sales` | — | 0 |
| `service` | — | 0 |

## `diagnostic`

- Phalcon Web route contributor: retired;
- canonical API + SSR route source: `symfony/config/routes.yaml`;
- HTML owners: `App\Web\Diagnostic\DiagnosticPageController`.

## `property`

- contributor: `app/Interfaces/Web/Routing/PropertyModuleRouteContributor.php`;
- джерело маршрутів: `app/Interfaces/Web/Routing/PublicPropertyRoutes.php`;
- джерело маршрутів: `symfony/config/routes.yaml`;
