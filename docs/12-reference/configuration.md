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
| `construction` | `0.1.0` | — | 0 |
| `diagnostic` | `0.6.1` | — | 0 |
| `finance` | `0.1.0` | — | 0 |
| `growth` | `0.34.0` | — | 57 |
| `hr` | `0.1.0` | — | 0 |
| `procurement` | `0.1.0` | — | 0 |
| `property` | `0.12.0` | `propertyModuleConfigurationProvisioner` | 18 |
| `real_estate` | `0.2.0` | — | 6 |
| `sales` | `0.8.6` | `salesModuleConfigurationProvisioner` | 10 |
| `service` | `0.2.0` | — | 7 |

## `construction`

- manifest: `app/Domains/Construction/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `diagnostic`

- manifest: `app/Domains/Diagnostic/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `finance`

- manifest: `app/Domains/Finance/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `growth`

- manifest: `app/Domains/Growth/module.php`;
- configuration provisioners: —;
- capabilities: `growth.signal.polling_alerts`, `growth.signal.polling_incidents`, `growth.signal.polling_health`, `growth.signal.polling_workspace`, `growth.signal.scheduled_polling`, `growth.signal.json_source.workspace`, `growth.signal.collector.credentialed_json`, `growth.signal.json_source.manage`, `growth.signal.feed.workspace`, `growth.signal.collector.rss_atom`, `growth.signal.feed.manage`, `growth.handoff.target.service`, `growth.engagement.pre_handoff_execution`, `growth.engagement.execution_workspace`, `growth.engagement.execution`, `growth.experiments.decision`, `growth.experiments.workspace`, `growth.experiments`, `growth.attribution`, `growth.learning.optimization_workspace`, `growth.learning.optimize`, `growth.learning.workspace`, `growth.learning.feedback`, `growth.learning.brief`, `growth.engagement.intelligence`, `growth.signal.external_webhook`, `growth.workspace`, `growth.signal.operations`, `growth.api.v1`, `growth.handoff.target.sales`, `growth.handoff.dispatch`, `growth.handoff.brief`, `growth.handoff.targets`, `growth.research.accept`, `growth.research.brief`, `growth.research.generate`, `growth.qualification.policy`, `growth.candidate.evaluate`, `growth.candidate.decision_brief`, `growth.signal.collect`, `growth.signal.dedupe`, `growth.signal.ingest`, `growth.buying_committee.assess`, `growth.buying_committee.brief`, `growth.contact.discover`, `growth.contact.enrich`, `growth.icp.manage`, `growth.account.discover`, `growth.account.enrich`, `growth.account.score`, `growth.account.brief`, `growth.signal.detect`, `growth.candidate.research`, `growth.candidate.score`, `growth.candidate.qualify`, `growth.handoff.prepare`, `growth.candidate.monitor`.

## `hr`

- manifest: `app/Domains/HR/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `procurement`

- manifest: `app/Domains/Procurement/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `property`

- manifest: `app/Domains/Property/module.php`;
- configuration provisioners: `propertyModuleConfigurationProvisioner`;
- capabilities: `property.registry`, `property.read`, `property.write`, `property.intake`, `property.media`, `property.catalog`, `property.inventory`, `property.listing`, `property.publish`, `property.history`, `property.reference`, `property.analytics`, `property.intelligence`, `property.network`, `property.identity.review`, `property.runtime.canonical`, `property.api.v1`, `property.business.cutover`.

## `real_estate`

- manifest: `app/Domains/RealEstate/module.php`;
- configuration provisioners: —;
- capabilities: `real_estate.brokerage`, `real_estate.property_match`, `real_estate.offer`, `real_estate.viewing`, `real_estate.reservation`, `real_estate.api.v1`.

## `sales`

- manifest: `app/Domains/Sales/module.php`;
- configuration provisioners: `salesModuleConfigurationProvisioner`;
- capabilities: `sales.workspace.use`, `sales.director.view`, `sales.admin.view`, `sales.admin.pipeline.manage`, `sales.admin.rules.manage`, `sales.admin.agents.manage`, `sales.admin.policies.manage`, `sales.admin.teams.manage`, `sales.admin.integrations.manage`, `sales.admin.audit.view`.

## `service`

- manifest: `app/Domains/Service/module.php`;
- configuration provisioners: —;
- capabilities: `service.request`, `service.ticket`, `service.assignment`, `service.sla`, `service.escalation`, `service.resolution`, `service.api.v1`.
