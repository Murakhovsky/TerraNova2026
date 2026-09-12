---
title: Permissions and Capabilities
description: Generated comparison of runtime capability vocabularies and module manifest declarations.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Permissions and Capabilities

> Джерела істини: explicit runtime capability catalogues та `capabilities` у `app/Domains/*/module.php`.

Ця сторінка навмисно **не виправляє** розбіжності між runtime vocabulary і module manifest. Вона робить drift видимим, щоб архітектурне рішення залишалося явним.

## Summary

| Classification | Count | Meaning |
| --- | ---: | --- |
| `both` | 10 | Capability присутня і в runtime catalogue, і в manifest. |
| `runtime-only` | 3 | Runtime може перевіряти capability, але manifest її не декларує. |
| `manifest-only` | 0 | Manifest декларує capability, але explicit runtime catalogue її не містить. |

## Catalogue

| Module | Capability | Runtime | Manifest | Classification | Runtime source | Manifest source |
| --- | --- | --- | --- | --- | --- | --- |
| `sales` | `sales.admin.agents.manage` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.audit.view` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.integrations.manage` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.pipeline.manage` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.policies.manage` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.rules.manage` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.teams.manage` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.view` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.approval.any_team` | yes | no | `runtime-only` | `app/Domains/Sales/Model/SalesCapability.php` | — |
| `sales` | `sales.approval.decide` | yes | no | `runtime-only` | `app/Domains/Sales/Model/SalesCapability.php` | — |
| `sales` | `sales.deal.assign` | yes | no | `runtime-only` | `app/Domains/Sales/Model/SalesCapability.php` | — |
| `sales` | `sales.director.view` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.workspace.use` | yes | yes | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |

## Runtime catalogue registry

Runtime vocabularies підключаються до генератора **явно**, а не через regex-сканування PHP. Це робить джерело authority передбачуваним і не змушує documentation tooling вгадувати семантику довільних класів.

| Module | Symbol | Source |
| --- | --- | --- |
| `sales` | `Domains\Sales\Model\SalesCapability` | `app/Domains/Sales/Model/SalesCapability.php` |

## Interpretation

- `runtime-only` не означає автоматично помилку: capability може бути внутрішньою authorization vocabulary і свідомо не входити до exposed module surface.
- `manifest-only` також не виправляється генератором: це сигнал перевірити, чи існує runtime authority для задекларованого permission.
- Зміни до будь-якого з двох джерел мають змінити цей generated файл; `npm run docs:generate:check` ловить stale reference у CI.
