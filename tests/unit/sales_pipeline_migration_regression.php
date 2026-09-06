<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$migration=(string)file_get_contents($root.'/app/migrations/20260905_000022_sales_v03_integrity.sql');
if(str_contains($migration,'c.pipeline_id<>p.id')||str_contains($migration,'c.stage_id<>s.id'))throw new RuntimeException('Sales backfill still overwrites valid non-default pipelines.');
if(!str_contains($migration,'c.pipeline_id IS NULL AND c.stage_id IS NULL'))throw new RuntimeException('Sales backfill is not limited to missing canonical identifiers.');
$corrective=(string)file_get_contents($root.'/app/migrations/20260906_000024_sales_pipeline_integrity_correction.sql');
if(!str_contains($corrective,'INNER JOIN sales_pipeline_stages s ON s.id=c.stage_id')||!str_contains($corrective,'SET c.pipeline_id=s.pipeline_id'))throw new RuntimeException('Corrective migration does not trust the existing canonical stage.');
echo "Sales pipeline migration regression passed.\n";
