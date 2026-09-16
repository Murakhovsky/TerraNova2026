---
title: Довідник бази даних
description: Згенерована карта module-owned міграцій і статично визначених звернень до SQL-таблиць.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Довідник бази даних

> Джерело істини: `migration_files` у module manifests + текст SQL-міграцій. SQL-коментарі відкидаються перед аналізом; dynamic SQL навмисно не вгадується.

| Модуль | Міграція | Задіяні таблиці |
| --- | --- | --- |
| `diagnostic` | `app/migrations/20260914_000049_diagnostic_runtime_v060.sql` | `diagnostic_measurements` |
| `diagnostic` | `app/migrations/20260914_000049_diagnostic_runtime_v060.sql` | `diagnostic_rediagnostic_schedules` |
| `diagnostic` | `app/migrations/20260914_000049_diagnostic_runtime_v060.sql` | `diagnostic_reports` |
| `diagnostic` | `app/migrations/20260914_000049_diagnostic_runtime_v060.sql` | `diagnostic_runtime_recommendations` |
| `diagnostic` | `app/migrations/20260914_000049_diagnostic_runtime_v060.sql` | `diagnostic_runtime_sessions` |
| `diagnostic` | `app/migrations/20260914_000049_diagnostic_runtime_v060.sql` | `diagnostic_sessions` |
| `property` | `app/migrations/20260914_000048_web_v041_property_tenancy.sql` | `tn_properties` |
| `property` | `app/migrations/20260914_000050_property_v022_tenant_boundary.sql` | `tn_properties` |
| `property` | `app/migrations/20260914_000050_property_v022_tenant_boundary.sql` | `tn_property_activities` |
| `property` | `app/migrations/20260914_000050_property_v022_tenant_boundary.sql` | `tn_property_features` |
| `property` | `app/migrations/20260914_000050_property_v022_tenant_boundary.sql` | `tn_property_groups` |
| `property` | `app/migrations/20260914_000050_property_v022_tenant_boundary.sql` | `tn_property_images` |
| `property` | `app/migrations/20260914_000050_property_v022_tenant_boundary.sql` | `tn_property_submissions` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_addresses` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_geo_boundaries` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_geo_points` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_location_nodes` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_property_asset_relations` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_property_building_specs` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_property_commercial_specs` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_property_land_specs` |
| `property` | `app/migrations/20260914_000051_property_v030_asset_registry.sql` | `tn_property_residential_specs` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_external_references` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_identity_resolutions` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_party_relations` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_provenance` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_sources` |
| `property` | `app/migrations/20260914_000052_property_v040_identity_provenance.sql` | `tn_property_submissions` |
| `property` | `app/migrations/20260914_000053_property_v050_inventory.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000053_property_v050_inventory.sql` | `tn_property_inventory_items` |
| `property` | `app/migrations/20260914_000053_property_v050_inventory.sql` | `tn_property_inventory_price_history` |
| `property` | `app/migrations/20260914_000053_property_v050_inventory.sql` | `tn_property_inventory_reservations` |
| `property` | `app/migrations/20260914_000053_property_v050_inventory.sql` | `tn_property_inventory_status_history` |
| `property` | `app/migrations/20260914_000053_property_v050_inventory.sql` | `tn_property_sources` |
| `property` | `app/migrations/20260914_000054_property_v060_listings_publication.sql` | `tn_property_channels` |
| `property` | `app/migrations/20260914_000054_property_v060_listings_publication.sql` | `tn_property_inventory_items` |
| `property` | `app/migrations/20260914_000054_property_v060_listings_publication.sql` | `tn_property_listing_media` |
| `property` | `app/migrations/20260914_000054_property_v060_listings_publication.sql` | `tn_property_listing_publication_history` |
| `property` | `app/migrations/20260914_000054_property_v060_listings_publication.sql` | `tn_property_listings` |
| `property` | `app/migrations/20260914_000054_property_v060_listings_publication.sql` | `tn_property_publications` |
| `property` | `app/migrations/20260914_000055_property_v070_history_contracts.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000055_property_v070_history_contracts.sql` | `tn_property_lifecycle_history` |
| `property` | `app/migrations/20260914_000055_property_v070_history_contracts.sql` | `tn_property_relation_history` |
| `property` | `app/migrations/20260914_000056_property_v090_intelligence.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000056_property_v090_intelligence.sql` | `tn_property_intelligence_comparables` |
| `property` | `app/migrations/20260914_000056_property_v090_intelligence.sql` | `tn_property_intelligence_snapshots` |
| `property` | `app/migrations/20260914_000056_property_v090_intelligence.sql` | `tn_property_inventory_items` |
| `property` | `app/migrations/20260914_000057_property_v0100_external_network.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000057_property_v0100_external_network.sql` | `tn_property_network_connectors` |
| `property` | `app/migrations/20260914_000057_property_v0100_external_network.sql` | `tn_property_network_records` |
| `property` | `app/migrations/20260914_000057_property_v0100_external_network.sql` | `tn_property_network_sync_runs` |
| `property` | `app/migrations/20260914_000057_property_v0100_external_network.sql` | `tn_property_sources` |
| `property` | `app/migrations/20260914_000057_property_v0100_external_network.sql` | `tn_property_submissions` |
| `property` | `app/migrations/20260914_000058_property_v0110_hardening.sql` | `tn_property_asset_legacy_links` |
| `property` | `app/migrations/20260914_000058_property_v0110_hardening.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260914_000058_property_v0110_hardening.sql` | `tn_property_identity_resolutions` |
| `property` | `app/migrations/20260914_000058_property_v0110_hardening.sql` | `tn_property_identity_review_audit` |
| `property` | `app/migrations/20260915_000059_property_v0120_runtime_cutover.sql` | `tn_property_assets` |
| `property` | `app/migrations/20260915_000059_property_v0120_runtime_cutover.sql` | `tn_property_compatibility_projection_state` |
| `property` | `app/migrations/20260915_000059_property_v0120_runtime_cutover.sql` | `tn_property_residential_specs` |
| `sales` | `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql` | `cos_configuration_revisions` |
| `sales` | `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql` | `cos_organizations` |
| `sales` | `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql` | `cos_policies` |
| `sales` | `app/migrations/20260910_000030_sales_v071_configuration_ownership.sql` | `cos_rules` |
| `sales` | `app/migrations/20260913_000044_sales_v081_historical_stage_history.sql` | `cos_organizations` |
| `sales` | `app/migrations/20260913_000044_sales_v081_historical_stage_history.sql` | `sales_deal_stage_history` |
| `sales` | `app/migrations/20260913_000045_sales_v082_funnel_metrics.sql` | `cos_organizations` |
| `sales` | `app/migrations/20260913_000045_sales_v082_funnel_metrics.sql` | `sales_deal_stage_history` |
| `sales` | `app/migrations/20260913_000045_sales_v082_funnel_metrics.sql` | `sales_pipeline_stages` |
| `sales` | `app/migrations/20260913_000045_sales_v082_funnel_metrics.sql` | `sales_stage_metric_thresholds` |
| `sales` | `app/migrations/20260913_000046_sales_v083_operational_performance.sql` | `cos_organizations` |
| `sales` | `app/migrations/20260913_000046_sales_v083_operational_performance.sql` | `sales_communications` |
| `sales` | `app/migrations/20260913_000046_sales_v083_operational_performance.sql` | `sales_deal_owner_history` |
| `sales` | `app/migrations/20260913_000046_sales_v083_operational_performance.sql` | `sales_deal_stage_history` |
| `sales` | `app/migrations/20260913_000046_sales_v083_operational_performance.sql` | `tn_client_case_activities` |
| `sales` | `app/migrations/20260913_000047_sales_v086_hardening.sql` | `sales_deal_owner_history` |
| `sales` | `app/migrations/20260913_000047_sales_v086_hardening.sql` | `sales_deal_stage_history` |
| `sales` | `app/migrations/20260913_000047_sales_v086_hardening.sql` | `tn_client_cases` |
