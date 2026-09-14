CREATE OR REPLACE VIEW diagnostic_areas AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='AREA';
CREATE OR REPLACE VIEW diagnostic_criteria AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='CRITERION';
CREATE OR REPLACE VIEW diagnostic_facts AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='FACT';
CREATE OR REPLACE VIEW diagnostic_metrics AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='METRIC';
CREATE OR REPLACE VIEW diagnostic_questions AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='QUESTION';
CREATE OR REPLACE VIEW diagnostic_evidence_requirements AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='EVIDENCE_REQUIREMENT';
CREATE OR REPLACE VIEW diagnostic_rules AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='RULE';
CREATE OR REPLACE VIEW diagnostic_dependencies AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='DEPENDENCY';
CREATE OR REPLACE VIEW diagnostic_recommendation_templates AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='RECOMMENDATION';
CREATE OR REPLACE VIEW diagnostic_benchmarks AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='BENCHMARK';
CREATE OR REPLACE VIEW diagnostic_scoring AS SELECT organization_id,pack_id,methodology_version,entity_id,position,enabled,payload_json,updated_at FROM diagnostic_methodology_entities WHERE entity_type='SCORING';

INSERT IGNORE INTO tn_migrations(migration) VALUES('20260906_000026_diagnostic_methodology_views');
