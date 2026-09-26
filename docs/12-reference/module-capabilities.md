---
title: Модулі та capabilities
description: Згенерований довідник із module manifests та задекларованих capabilities.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУЙТЕ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Модулі та capabilities

> Джерело істини: `app/Domains/*/module.php` та `app/Kernel/Module/KernelVersion.php`.

## Версія контракту Kernel

`Kernel\Module\KernelVersion::VERSION = 0.11.9`.

## Зареєстровані модулі

| ID | Назва | Версія | Schema | Обмеження Kernel | За замовчуванням | Залежності | Джерело |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `construction` | Construction | `0.1.0` | `0.1.0` | `>=0.11.0 <0.12.0` | ні | — | `app/Domains/Construction/module.php` |
| `diagnostic` | Diagnostics | `0.6.1` | `0.6.0` | `>=0.11.0 <0.12.0` | так | — | `app/Domains/Diagnostic/module.php` |
| `finance` | Finance | `0.1.0` | `0.1.0` | `>=0.11.0 <0.12.0` | ні | — | `app/Domains/Finance/module.php` |
| `growth` | Growth | `0.50.0` | `0.50.0` | `>=0.11.0 <0.12.0` | ні | — | `app/Domains/Growth/module.php` |
| `hr` | HR | `0.1.0` | `0.1.0` | `>=0.11.0 <0.12.0` | ні | — | `app/Domains/HR/module.php` |
| `procurement` | Procurement | `0.1.0` | `0.1.0` | `>=0.11.0 <0.12.0` | ні | — | `app/Domains/Procurement/module.php` |
| `property` | Property | `0.12.0` | `0.12.0` | `>=0.11.0 <0.12.0` | так | — | `app/Domains/Property/module.php` |
| `real_estate` | Real Estate | `0.2.0` | `0.2.0` | `>=0.11.0 <0.12.0` | так | property, sales | `app/Domains/RealEstate/module.php` |
| `sales` | Sales | `0.8.6` | `0.8.6` | `>=0.11.0 <0.12.0` | так | — | `app/Domains/Sales/module.php` |
| `service` | Service | `0.2.0` | `0.2.0` | `>=0.11.0 <0.12.0` | так | — | `app/Domains/Service/module.php` |

## Construction (`construction`)

**Опис із manifest:** Construction delivery boundary for projects, sites, objects, estimates, contractors, work, materials, milestones and inspections.

- runtime service модуля: —;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: —.

### Задекларовані capabilities

Manifest capabilities не задекларовані.

## Diagnostics (`diagnostic`)

**Опис із manifest:** Business diagnostics, methodology, interviews, reporting and closed-loop recommendations.

- runtime service модуля: `diagnosticDomainModule`;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: `app/migrations/20260914_000049_diagnostic_runtime_v060.sql`.

### Задекларовані capabilities

Manifest capabilities не задекларовані.

## Finance (`finance`)

**Опис із manifest:** Finance boundary for accounts, transactions, invoices, payments, budgets, expenses and revenue.

- runtime service модуля: —;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: —.

### Задекларовані capabilities

Manifest capabilities не задекларовані.

## Growth (`growth`)

**Опис із manifest:** Opportunity intelligence from observable signals to qualified business opportunity handoff.

- runtime service модуля: `growthDomainModule`;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: `app/migrations/20260921_000067_growth_v020_runtime.sql`, `app/migrations/20260921_000068_growth_v030_account_intelligence.sql`, `app/migrations/20260922_000069_growth_v040_buying_committee.sql`, `app/migrations/20260922_000070_growth_v050_signal_collectors.sql`, `app/migrations/20260922_000071_growth_v060_decision_intelligence.sql`, `app/migrations/20260922_000072_growth_v070_research_intelligence.sql`, `app/migrations/20260922_000073_growth_v080_handoff_protocol.sql`, `app/migrations/20260922_000074_growth_v0100_api_surface.sql`, `app/migrations/20260922_000075_growth_v0110_workspace.sql`, `app/migrations/20260922_000076_growth_v0120_signal_operations.sql`, `app/migrations/20260922_000077_growth_v0130_external_signal_webhook.sql`, `app/migrations/20260922_000078_growth_v0140_engagement_intelligence.sql`, `app/migrations/20260923_000079_growth_v0150_learning_feedback.sql`, `app/migrations/20260923_000080_growth_v0160_learning_workspace.sql`, `app/migrations/20260923_000081_growth_v0170_learning_optimization.sql`, `app/migrations/20260923_000082_growth_v0180_optimization_workspace.sql`, `app/migrations/20260923_000083_growth_v0190_experiments_attribution.sql`, `app/migrations/20260923_000084_growth_v0200_experiment_workspace.sql`, `app/migrations/20260923_000085_growth_v0210_experiment_decision_intelligence.sql`, `app/migrations/20260923_000086_growth_v0220_engagement_execution_bridge.sql`, `app/migrations/20260923_000087_growth_v0230_engagement_execution_workspace.sql`, `app/migrations/20260923_000089_growth_v0240_pre_handoff_execution.sql`, `app/migrations/20260923_000090_growth_v0250_service_handoff_adapter.sql`, `app/migrations/20260923_000091_growth_v0260_rss_atom_collector.sql`, `app/migrations/20260923_000092_growth_v0270_signal_feed_workspace.sql`, `app/migrations/20260924_000093_growth_v0280_credentialed_json_collector.sql`, `app/migrations/20260924_000094_growth_v0290_json_signal_source_workspace.sql`, `app/migrations/20260924_000095_growth_v0300_signal_polling_scheduler.sql`, `app/migrations/20260924_000096_growth_v0310_polling_operations_workspace.sql`, `app/migrations/20260924_000097_growth_v0320_collector_health_backoff.sql`, `app/migrations/20260924_000098_growth_v0330_collector_incidents.sql`, `app/migrations/20260924_000099_growth_v0340_collector_alert_subscriptions.sql`, `app/migrations/20260924_000100_growth_v0350_linkedin_call_execution.sql`, `app/migrations/20260924_000101_growth_v0360_engagement_delivery_feedback.sql`, `app/migrations/20260924_000102_growth_v0370_outreach_guardrails.sql`, `app/migrations/20260924_000103_growth_v0380_tenant_outreach_limits.sql`, `app/migrations/20260924_000104_growth_v0390_channel_outreach_quotas.sql`, `app/migrations/20260924_000105_growth_v0400_atomic_outreach_capacity.sql`, `app/migrations/20260924_000106_growth_v0410_outreach_activation_policy.sql`, `app/migrations/20260925_000107_growth_v0420_autonomous_outreach.sql`, `app/migrations/20260926_000108_growth_v0430_autonomous_content_review.sql`, `app/migrations/20260926_000109_growth_v0440_outreach_sequences.sql`, `app/migrations/20260926_000110_growth_v0450_inbound_responses.sql`, `app/migrations/20260926_000111_growth_v0460_conversation_routing.sql`, `app/migrations/20260926_000112_growth_v0470_email_delivery_parity.sql`, `app/migrations/20260926_000113_growth_v0480_market_discovery.sql`, `app/migrations/20260926_000114_growth_v0490_cos_for_cos_vertical_slice.sql`, `app/migrations/20260926_000115_growth_v0500_release_hardening.sql`.

### Задекларовані capabilities

- `growth.account.brief`;
- `growth.account.discover`;
- `growth.account.enrich`;
- `growth.account.score`;
- `growth.api.v1`;
- `growth.attribution`;
- `growth.buying_committee.assess`;
- `growth.buying_committee.brief`;
- `growth.candidate.decision_brief`;
- `growth.candidate.evaluate`;
- `growth.candidate.monitor`;
- `growth.candidate.qualify`;
- `growth.candidate.research`;
- `growth.candidate.score`;
- `growth.contact.discover`;
- `growth.contact.enrich`;
- `growth.engagement.activation_policy`;
- `growth.engagement.atomic_capacity_admission`;
- `growth.engagement.autonomous_content_drafting`;
- `growth.engagement.autonomous_content_review`;
- `growth.engagement.autonomous_content_scheduler`;
- `growth.engagement.autonomous_trigger`;
- `growth.engagement.autonomy_payload_staging`;
- `growth.engagement.autonomy_policy`;
- `growth.engagement.channel_quotas`;
- `growth.engagement.contact_suppression`;
- `growth.engagement.conversation_routing`;
- `growth.engagement.delivery_feedback`;
- `growth.engagement.email_complaint_suppression`;
- `growth.engagement.email_conversation_feedback`;
- `growth.engagement.email_delivery_feedback`;
- `growth.engagement.execution`;
- `growth.engagement.execution_limits`;
- `growth.engagement.execution_workspace`;
- `growth.engagement.inbound_response`;
- `growth.engagement.intelligence`;
- `growth.engagement.limit_profile`;
- `growth.engagement.pre_handoff_execution`;
- `growth.engagement.pre_handoff_linkedin_call_execution`;
- `growth.engagement.response_classification`;
- `growth.engagement.response_webhook`;
- `growth.engagement.routing_target.sales`;
- `growth.engagement.routing_target.service`;
- `growth.engagement.sequence_policy`;
- `growth.engagement.sequence_scheduler`;
- `growth.engagement.sequence_state_machine`;
- `growth.experiments`;
- `growth.experiments.decision`;
- `growth.experiments.workspace`;
- `growth.handoff.brief`;
- `growth.handoff.dispatch`;
- `growth.handoff.prepare`;
- `growth.handoff.target.sales`;
- `growth.handoff.target.service`;
- `growth.handoff.targets`;
- `growth.icp.manage`;
- `growth.learning.brief`;
- `growth.learning.conversation_binding`;
- `growth.learning.feedback`;
- `growth.learning.optimization_workspace`;
- `growth.learning.optimize`;
- `growth.learning.workspace`;
- `growth.market.automated_sourcing`;
- `growth.market.discovery`;
- `growth.market.monitoring`;
- `growth.market.opportunity_detection`;
- `growth.market.partial_retry`;
- `growth.market.resumable_discovery`;
- `growth.market.universe.manage`;
- `growth.market.workspace`;
- `growth.qualification.policy`;
- `growth.research.accept`;
- `growth.research.brief`;
- `growth.research.generate`;
- `growth.signal.collect`;
- `growth.signal.collector.credentialed_json`;
- `growth.signal.collector.rss_atom`;
- `growth.signal.dedupe`;
- `growth.signal.detect`;
- `growth.signal.external_webhook`;
- `growth.signal.feed.manage`;
- `growth.signal.feed.workspace`;
- `growth.signal.ingest`;
- `growth.signal.json_source.manage`;
- `growth.signal.json_source.workspace`;
- `growth.signal.operations`;
- `growth.signal.polling_alerts`;
- `growth.signal.polling_health`;
- `growth.signal.polling_incidents`;
- `growth.signal.polling_workspace`;
- `growth.signal.scheduled_polling`;
- `growth.workspace`;

## HR (`hr`)

**Опис із manifest:** Human-resources boundary for employees, positions, candidates, recruitment, onboarding and performance.

- runtime service модуля: —;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: —.

### Задекларовані capabilities

Manifest capabilities не задекларовані.

## Procurement (`procurement`)

**Опис із manifest:** Procurement boundary for suppliers, purchase requests, quotes, orders and deliveries.

- runtime service модуля: —;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: —.

### Задекларовані capabilities

Manifest capabilities не задекларовані.

## Property (`property`)

**Опис із manifest:** Canonical registry for Property with tenant-safe Asset, Inventory and Listing runtime, Symfony business read/write cutover, history, intelligence, network interoperability and canonical public projection.

- runtime service модуля: `propertyDomainModule`;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: `propertyModuleConfigurationProvisioner`;
- міграції: `app/migrations/20260914_000048_web_v041_property_tenancy.sql`, `app/migrations/20260914_000050_property_v022_tenant_boundary.sql`, `app/migrations/20260914_000051_property_v030_asset_registry.sql`, `app/migrations/20260914_000052_property_v040_identity_provenance.sql`, `app/migrations/20260914_000053_property_v050_inventory.sql`, `app/migrations/20260914_000054_property_v060_listings_publication.sql`, `app/migrations/20260914_000055_property_v070_history_contracts.sql`, `app/migrations/20260914_000056_property_v090_intelligence.sql`, `app/migrations/20260914_000057_property_v0100_external_network.sql`, `app/migrations/20260914_000058_property_v0110_hardening.sql`, `app/migrations/20260915_000059_property_v0120_runtime_cutover.sql`.

### Задекларовані capabilities

- `property.analytics`;
- `property.api.v1`;
- `property.business.cutover`;
- `property.catalog`;
- `property.history`;
- `property.identity.review`;
- `property.intake`;
- `property.intelligence`;
- `property.inventory`;
- `property.listing`;
- `property.media`;
- `property.network`;
- `property.publish`;
- `property.read`;
- `property.reference`;
- `property.registry`;
- `property.runtime.canonical`;
- `property.write`;

## Real Estate (`real_estate`)

**Опис із manifest:** Brokerage orchestration over canonical Property assets: Opportunity → Property Match → Offer → Viewing → Reservation.

- runtime service модуля: `realEstateDomainModule`;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: `app/migrations/20260918_000062_real_estate_wave9_cutover.sql`.

### Задекларовані capabilities

- `real_estate.api.v1`;
- `real_estate.brokerage`;
- `real_estate.offer`;
- `real_estate.property_match`;
- `real_estate.reservation`;
- `real_estate.viewing`;

## Sales (`sales`)

**Опис із manifest:** Sales operations, CRM workflow, intelligence and automation.

- runtime service модуля: `salesDomainModule`;
- обробники jobs: `salesCrmInboxJobHandler`;
- внески API routes: —;
- постачальники конфігурації: `salesModuleConfigurationProvisioner`;
- міграції: `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql`, `app/migrations/20260913_000044_sales_v081_historical_stage_history.sql`, `app/migrations/20260913_000045_sales_v082_funnel_metrics.sql`, `app/migrations/20260913_000046_sales_v083_operational_performance.sql`, `app/migrations/20260913_000047_sales_v086_hardening.sql`.

### Задекларовані capabilities

- `sales.admin.agents.manage`;
- `sales.admin.audit.view`;
- `sales.admin.integrations.manage`;
- `sales.admin.pipeline.manage`;
- `sales.admin.policies.manage`;
- `sales.admin.rules.manage`;
- `sales.admin.teams.manage`;
- `sales.admin.view`;
- `sales.director.view`;
- `sales.workspace.use`;

## Service (`service`)

**Опис із manifest:** Executable service operations runtime for Request → Ticket → Assignment/SLA → Escalation → Resolution → Close.

- runtime service модуля: `serviceDomainModule`;
- обробники jobs: —;
- внески API routes: —;
- постачальники конфігурації: —;
- міграції: `app/migrations/20260919_000064_service_wave11_cutover.sql`.

### Задекларовані capabilities

- `service.api.v1`;
- `service.assignment`;
- `service.escalation`;
- `service.request`;
- `service.resolution`;
- `service.sla`;
- `service.ticket`;

## Межі довідника

Ця сторінка описує тільки факти з installable module manifests. Domain directories без `module.php` сюди не потрапляють. Capability enum або runtime authority можуть мати ширший vocabulary і документуються окремим generated reference.
