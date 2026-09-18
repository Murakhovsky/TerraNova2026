---
title: Дозволи та capabilities
description: Згенероване порівняння runtime capability vocabularies і декларацій у module manifests.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУЙТЕ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Дозволи та capabilities

> Джерела істини: explicit runtime capability catalogues та `capabilities` у `app/Domains/*/module.php`.

Ця сторінка навмисно **не виправляє** розбіжності між runtime vocabulary і module manifest. Вона робить drift видимим, щоб архітектурне рішення залишалося явним.

## Підсумок

| Класифікація | Кількість | Значення |
| --- | ---: | --- |
| `both` | 10 | Capability присутня і в runtime catalogue, і в manifest. |
| `runtime-only` | 3 | Runtime може перевіряти capability, але manifest її не декларує. |
| `manifest-only` | 24 | Manifest декларує capability, але explicit runtime catalogue її не містить. |

## Каталог

| Модуль | Capability | Runtime | Manifest | Класифікація | Runtime source | Manifest source |
| --- | --- | --- | --- | --- | --- | --- |
| `property` | `property.analytics` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.api.v1` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.business.cutover` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.catalog` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.history` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.identity.review` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.intake` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.intelligence` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.inventory` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.listing` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.media` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.network` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.publish` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.read` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.reference` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.registry` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.runtime.canonical` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `property` | `property.write` | ні | так | `manifest-only` | — | `app/Domains/Property/module.php` |
| `real_estate` | `real_estate.api.v1` | ні | так | `manifest-only` | — | `app/Domains/RealEstate/module.php` |
| `real_estate` | `real_estate.brokerage` | ні | так | `manifest-only` | — | `app/Domains/RealEstate/module.php` |
| `real_estate` | `real_estate.offer` | ні | так | `manifest-only` | — | `app/Domains/RealEstate/module.php` |
| `real_estate` | `real_estate.property_match` | ні | так | `manifest-only` | — | `app/Domains/RealEstate/module.php` |
| `real_estate` | `real_estate.reservation` | ні | так | `manifest-only` | — | `app/Domains/RealEstate/module.php` |
| `real_estate` | `real_estate.viewing` | ні | так | `manifest-only` | — | `app/Domains/RealEstate/module.php` |
| `sales` | `sales.admin.agents.manage` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.audit.view` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.integrations.manage` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.pipeline.manage` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.policies.manage` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.rules.manage` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.teams.manage` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.admin.view` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.approval.any_team` | так | ні | `runtime-only` | `app/Domains/Sales/Model/SalesCapability.php` | — |
| `sales` | `sales.approval.decide` | так | ні | `runtime-only` | `app/Domains/Sales/Model/SalesCapability.php` | — |
| `sales` | `sales.deal.assign` | так | ні | `runtime-only` | `app/Domains/Sales/Model/SalesCapability.php` | — |
| `sales` | `sales.director.view` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |
| `sales` | `sales.workspace.use` | так | так | `both` | `app/Domains/Sales/Model/SalesCapability.php` | `app/Domains/Sales/module.php` |

## Реєстр runtime-каталогів

Runtime vocabularies підключаються до генератора **явно**, а не через regex-сканування PHP. Це робить джерело authority передбачуваним і не змушує documentation tooling вгадувати семантику довільних класів.

| Модуль | Symbol | Джерело |
| --- | --- | --- |
| `sales` | `Domains\Sales\Model\SalesCapability` | `app/Domains/Sales/Model/SalesCapability.php` |

## Тлумачення

- `runtime-only` не означає автоматично помилку: capability може бути внутрішньою authorization vocabulary і свідомо не входити до exposed module surface.
- `manifest-only` також не виправляється генератором: це сигнал перевірити, чи існує runtime authority для задекларованого permission.
- Зміни до будь-якого з двох джерел мають змінити цей generated файл; `npm run docs:generate:check` ловить stale reference у CI.
