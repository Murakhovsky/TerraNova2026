---
title: Module Routes
description: Generated ownership map for module API route contributors and their route source files.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Module Routes

> Джерела істини: `api_route_contributor_services` у module manifests та explicit route source registry у documentation generator.

Маршрути є Web-layer contribution. Generator перевіряє, що explicit route catalogue збігається з manifest contributor і що всі зареєстровані source files існують.

## Summary

| Module | Manifest contributor | Route source files |
| --- | --- | ---: |
| `diagnostic` | — | 0 |
| `property` | — | 0 |
| `sales` | `salesRouteContributor` | 5 |

## `sales`

- manifest contributor service: `salesRouteContributor`;
- contributor implementation: `app/Interfaces/Web/Routing/SalesModuleRouteContributor.php`;
- route sources:
  - `app/Interfaces/Web/Routing/SalesAdministrationRoutes.php`;
  - `app/Interfaces/Web/Routing/SalesDirectorRoutes.php`;
  - `app/Interfaces/Web/Routing/SalesIntegrationRoutes.php`;
  - `app/Interfaces/Web/Routing/SalesRoutes.php`;
  - `app/Interfaces/Web/Routing/SalesTeamRoutes.php`;

## Scope

Цей шар документує ownership і source-of-truth для module routes без виконання Phalcon runtime та без парсингу довільного PHP. Endpoint-level table можна будувати окремим typed/structured route catalogue, коли route contract буде формалізований.
