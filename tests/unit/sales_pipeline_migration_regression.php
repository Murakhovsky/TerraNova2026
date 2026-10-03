<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$migration=(string)file_get_contents($root.'/app/migrations/20260905_000022_sales_v03_integrity.sql');
if(str_contains($migration,'c.pipeline_id<>p.id')||str_contains($migration,'c.stage_id<>s.id'))throw new RuntimeException('Sales backfill still overwrites valid non-default pipelines.');
if(!str_contains($migration,'c.pipeline_id IS NULL AND c.stage_id IS NULL'))throw new RuntimeException('Sales backfill is not limited to missing canonical identifiers.');
$corrective=(string)file_get_contents($root.'/app/migrations/20260906_000024_sales_pipeline_integrity_correction.sql');
if(!str_contains($corrective,'INNER JOIN sales_pipeline_stages s ON s.id=c.stage_id')||!str_contains($corrective,'SET c.pipeline_id=s.pipeline_id'))throw new RuntimeException('Corrective migration does not trust the existing canonical stage.');

$originalQualificationRepair=(string)file_get_contents($root.'/app/migrations/20261003_000121_sales_lead_qualification_transition.sql');
if(!str_contains($originalQualificationRepair,'ON DUPLICATE KEY UPDATE'))throw new RuntimeException('Applied migration 000121 must remain immutable; remediation belongs in a new migration.');

$governanceRepair=(string)file_get_contents($root.'/app/migrations/20261003_000123_sales_lead_qualification_governance_repair.sql');
foreach([
    'cos_configuration_revisions',
    "configuration_type='TRANSITION'",
    'JSON_TABLE',
    'tmp_sales_latest_transition_revisions',
    'tmp_sales_transition_revision_policy',
    'DELETE current_transition',
    'current_transition.requires_approval=intended.requires_approval',
    'current_transition.conditions=intended.conditions',
    'latest.pipeline_id IS NULL',
    'NOT EXISTS',
] as $marker){
    if(!str_contains($governanceRepair,$marker))throw new RuntimeException('Lead qualification governance remediation is incomplete: '.$marker);
}

$salesModule=require $root.'/app/Domains/Sales/module.php';
$migrationFiles=$salesModule['contributions']['migration_files']??[];
foreach([
    'app/migrations/20261003_000121_sales_lead_qualification_transition.sql',
    'app/migrations/20261003_000122_sales_followup_activity_type.sql',
    'app/migrations/20261003_000123_sales_lead_qualification_governance_repair.sql',
] as $requiredMigration){
    if(!in_array($requiredMigration,$migrationFiles,true))throw new RuntimeException('Sales readiness manifest is missing repair migration: '.$requiredMigration);
}

echo "Sales pipeline migration regression passed.\n";
