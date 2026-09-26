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
| `manifest-only` | 123 | Manifest декларує capability, але explicit runtime catalogue її не містить. |

## Каталог

| Модуль | Capability | Runtime | Manifest | Класифікація | Runtime source | Manifest source |
| --- | --- | --- | --- | --- | --- | --- |
| `growth` | `growth.account.brief` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.account.discover` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.account.enrich` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.account.score` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.api.v1` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.attribution` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.buying_committee.assess` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.buying_committee.brief` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.candidate.decision_brief` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.candidate.evaluate` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.candidate.monitor` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.candidate.qualify` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.candidate.research` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.candidate.score` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.contact.discover` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.contact.enrich` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.activation_policy` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.atomic_capacity_admission` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.autonomous_content_drafting` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.autonomous_content_review` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.autonomous_content_scheduler` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.autonomous_trigger` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.autonomy_payload_staging` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.autonomy_policy` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.channel_quotas` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.contact_suppression` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.conversation_routing` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.delivery_feedback` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.email_complaint_suppression` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.email_conversation_feedback` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.email_delivery_feedback` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.execution` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.execution_limits` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.execution_workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.inbound_response` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.intelligence` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.limit_profile` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.pre_handoff_execution` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.pre_handoff_linkedin_call_execution` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.response_classification` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.response_webhook` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.routing_target.sales` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.routing_target.service` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.sequence_policy` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.sequence_scheduler` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.engagement.sequence_state_machine` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.experiments` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.experiments.decision` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.experiments.workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.handoff.brief` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.handoff.dispatch` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.handoff.prepare` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.handoff.target.sales` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.handoff.target.service` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.handoff.targets` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.icp.manage` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.learning.brief` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.learning.conversation_binding` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.learning.feedback` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.learning.optimization_workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.learning.optimize` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.learning.workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.automated_sourcing` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.discovery` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.monitoring` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.opportunity_detection` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.partial_retry` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.resumable_discovery` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.universe.manage` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.market.workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.qualification.policy` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.research.accept` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.research.brief` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.research.generate` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.collect` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.collector.credentialed_json` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.collector.rss_atom` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.dedupe` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.detect` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.external_webhook` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.feed.manage` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.feed.workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.ingest` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.json_source.manage` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.json_source.workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.operations` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.polling_alerts` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.polling_health` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.polling_incidents` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.polling_workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.signal.scheduled_polling` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
| `growth` | `growth.workspace` | ні | так | `manifest-only` | — | `app/Domains/Growth/module.php` |
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
| `service` | `service.api.v1` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |
| `service` | `service.assignment` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |
| `service` | `service.escalation` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |
| `service` | `service.request` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |
| `service` | `service.resolution` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |
| `service` | `service.sla` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |
| `service` | `service.ticket` | ні | так | `manifest-only` | — | `app/Domains/Service/module.php` |

## Реєстр runtime-каталогів

Runtime vocabularies підключаються до генератора **явно**, а не через regex-сканування PHP. Це робить джерело authority передбачуваним і не змушує documentation tooling вгадувати семантику довільних класів.

| Модуль | Symbol | Джерело |
| --- | --- | --- |
| `sales` | `Domains\Sales\Model\SalesCapability` | `app/Domains/Sales/Model/SalesCapability.php` |

## Тлумачення

- `runtime-only` не означає автоматично помилку: capability може бути внутрішньою authorization vocabulary і свідомо не входити до exposed module surface.
- `manifest-only` також не виправляється генератором: це сигнал перевірити, чи існує runtime authority для задекларованого permission.
- Зміни до будь-якого з двох джерел мають змінити цей generated файл; `npm run docs:generate:check` ловить stale reference у CI.