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
| `growth` | `app/migrations/20260921_000067_growth_v020_runtime.sql` | `tn_growth_candidate_signals` |
| `growth` | `app/migrations/20260921_000067_growth_v020_runtime.sql` | `tn_growth_candidates` |
| `growth` | `app/migrations/20260921_000067_growth_v020_runtime.sql` | `tn_growth_operation_receipts` |
| `growth` | `app/migrations/20260921_000067_growth_v020_runtime.sql` | `tn_growth_signals` |
| `growth` | `app/migrations/20260921_000068_growth_v030_account_intelligence.sql` | `tn_growth_account_icp_matches` |
| `growth` | `app/migrations/20260921_000068_growth_v030_account_intelligence.sql` | `tn_growth_account_snapshots` |
| `growth` | `app/migrations/20260921_000068_growth_v030_account_intelligence.sql` | `tn_growth_accounts` |
| `growth` | `app/migrations/20260921_000068_growth_v030_account_intelligence.sql` | `tn_growth_icp_profiles` |
| `growth` | `app/migrations/20260922_000069_growth_v040_buying_committee.sql` | `tn_growth_account_contacts` |
| `growth` | `app/migrations/20260922_000069_growth_v040_buying_committee.sql` | `tn_growth_buying_committee_assessments` |
| `growth` | `app/migrations/20260922_000069_growth_v040_buying_committee.sql` | `tn_growth_contact_snapshots` |
| `growth` | `app/migrations/20260922_000069_growth_v040_buying_committee.sql` | `tn_growth_contacts` |
| `growth` | `app/migrations/20260922_000070_growth_v050_signal_collectors.sql` | `tn_growth_signal_collector_runs` |
| `growth` | `app/migrations/20260922_000070_growth_v050_signal_collectors.sql` | `tn_growth_signal_source_receipts` |
| `growth` | `app/migrations/20260922_000071_growth_v060_decision_intelligence.sql` | `tn_growth_candidate_evaluations` |
| `growth` | `app/migrations/20260922_000071_growth_v060_decision_intelligence.sql` | `tn_growth_qualification_policies` |
| `growth` | `app/migrations/20260922_000072_growth_v070_research_intelligence.sql` | `tn_growth_research_proposals` |
| `growth` | `app/migrations/20260922_000072_growth_v070_research_intelligence.sql` | `tn_growth_research_runs` |
| `growth` | `app/migrations/20260922_000073_growth_v080_handoff_protocol.sql` | `tn_growth_handoff_attempts` |
| `growth` | `app/migrations/20260922_000074_growth_v0100_api_surface.sql` | — |
| `growth` | `app/migrations/20260922_000075_growth_v0110_workspace.sql` | — |
| `growth` | `app/migrations/20260922_000076_growth_v0120_signal_operations.sql` | — |
| `growth` | `app/migrations/20260922_000077_growth_v0130_external_signal_webhook.sql` | — |
| `growth` | `app/migrations/20260922_000078_growth_v0140_engagement_intelligence.sql` | `tn_growth_engagement_recommendations` |
| `growth` | `app/migrations/20260922_000078_growth_v0140_engagement_intelligence.sql` | `tn_growth_engagement_runs` |
| `growth` | `app/migrations/20260923_000079_growth_v0150_learning_feedback.sql` | `tn_growth_learning_bindings` |
| `growth` | `app/migrations/20260923_000079_growth_v0150_learning_feedback.sql` | `tn_growth_outcomes` |
| `growth` | `app/migrations/20260923_000080_growth_v0160_learning_workspace.sql` | — |
| `growth` | `app/migrations/20260923_000081_growth_v0170_learning_optimization.sql` | `tn_growth_optimization_recommendations` |
| `growth` | `app/migrations/20260923_000081_growth_v0170_learning_optimization.sql` | `tn_growth_optimization_runs` |
| `growth` | `app/migrations/20260923_000082_growth_v0180_optimization_workspace.sql` | — |
| `growth` | `app/migrations/20260923_000083_growth_v0190_experiments_attribution.sql` | `tn_growth_experiment_assignments` |
| `growth` | `app/migrations/20260923_000083_growth_v0190_experiments_attribution.sql` | `tn_growth_experiments` |
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
| `real_estate` | `app/migrations/20260918_000062_real_estate_wave9_cutover.sql` | `tn_real_estate_cases` |
| `real_estate` | `app/migrations/20260918_000062_real_estate_wave9_cutover.sql` | `tn_real_estate_offers` |
| `real_estate` | `app/migrations/20260918_000062_real_estate_wave9_cutover.sql` | `tn_real_estate_operation_receipts` |
| `real_estate` | `app/migrations/20260918_000062_real_estate_wave9_cutover.sql` | `tn_real_estate_showings` |
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
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_assignments` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_cases` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_escalations` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_operation_receipts` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_requests` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_resolutions` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_slas` |
| `service` | `app/migrations/20260919_000064_service_wave11_cutover.sql` | `tn_service_tickets` |
