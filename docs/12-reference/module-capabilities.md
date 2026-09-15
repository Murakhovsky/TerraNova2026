---
title: Module and Capability Reference
description: Generated reference з module manifests та declared capabilities.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Module and Capability Reference

> Джерело істини: `app/Domains/*/module.php` та `app/Kernel/Module/KernelVersion.php`.

## Kernel contract version

`Kernel\Module\KernelVersion::VERSION = 0.11.8`.

## Registered modules

| ID | Name | Version | Schema | Kernel constraint | Default | Dependencies | Source |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `diagnostic` | Diagnostics | `0.6.1` | `0.6.0` | `>=0.11.0 <0.12.0` | yes | — | `app/Domains/Diagnostic/module.php` |
| `property` | Property | `0.12.0` | `0.12.0` | `>=0.11.0 <0.12.0` | yes | — | `app/Domains/Property/module.php` |
| `sales` | Sales | `0.8.6` | `0.8.6` | `>=0.11.0 <0.12.0` | yes | — | `app/Domains/Sales/module.php` |

## Diagnostics (`diagnostic`)

Business diagnostics, methodology, interviews, reporting and closed-loop recommendations.

- runtime module service: `diagnosticDomainModule`;
- job handlers: —;
- API route contributors: `diagnosticRouteContributor`;
- configuration provisioners: —;
- migrations: `app/migrations/20260914_000049_diagnostic_runtime_v060.sql`.

### Declared capabilities

Manifest capabilities не задекларовані.

## Property (`property`)

Canonical registry and real-estate asset runtime with tenant-safe Asset, Inventory, Listing/Publication writes, domain events, history, intelligence, network interoperability and one-way legacy compatibility projection.

- runtime module service: `propertyDomainModule`;
- job handlers: —;
- API route contributors: `propertyRouteContributor`;
- configuration provisioners: `propertyModuleConfigurationProvisioner`;
- migrations: `app/migrations/20260914_000048_web_v041_property_tenancy.sql`, `app/migrations/20260914_000050_property_v022_tenant_boundary.sql`, `app/migrations/20260914_000051_property_v030_asset_registry.sql`, `app/migrations/20260914_000052_property_v040_identity_provenance.sql`, `app/migrations/20260914_000053_property_v050_inventory.sql`, `app/migrations/20260914_000054_property_v060_listings_publication.sql`, `app/migrations/20260914_000055_property_v070_history_contracts.sql`, `app/migrations/20260914_000056_property_v090_intelligence.sql`, `app/migrations/20260914_000057_property_v0100_external_network.sql`, `app/migrations/20260914_000058_property_v0110_hardening.sql`, `app/migrations/20260915_000059_property_v0120_runtime_cutover.sql`.

### Declared capabilities

- `property.analytics`;
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

## Sales (`sales`)

Sales operations, CRM workflow, intelligence and automation.

- runtime module service: `salesDomainModule`;
- job handlers: `salesCrmInboxJobHandler`;
- API route contributors: `salesRouteContributor`;
- configuration provisioners: `salesModuleConfigurationProvisioner`;
- migrations: `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql`, `app/migrations/20260913_000044_sales_v081_historical_stage_history.sql`, `app/migrations/20260913_000045_sales_v082_funnel_metrics.sql`, `app/migrations/20260913_000046_sales_v083_operational_performance.sql`, `app/migrations/20260913_000047_sales_v086_hardening.sql`.

### Declared capabilities

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

## Scope

Ця сторінка описує тільки факти з installable module manifests. Domain directories без `module.php` сюди не потрапляють. Capability enum або runtime authority можуть мати ширший vocabulary і повинні документуватися окремим generated reference.
