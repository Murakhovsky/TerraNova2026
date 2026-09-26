<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$template=(string)file_get_contents($root.'/app/Interfaces/Web/View/growth/candidate.phtml');
$js=(string)file_get_contents($root.'/frontend/features/growth/workspace.js');

function expectGrowthV0230(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

expectGrowthV0230(str_contains($template,'Growth stores only the execution link and payload fingerprint.'),'Workspace must explain body persistence boundary.');
expectGrowthV0230(str_contains($template,'data-growth-engagement-execution'),'Workspace execution form missing.');
expectGrowthV0230(str_contains($template,'data-growth-engagement-decision="accept"'),'Workspace accept decision missing.');
expectGrowthV0230(str_contains($template,'data-growth-engagement-decision="dismiss"'),'Workspace dismiss decision missing.');
expectGrowthV0230(str_contains($js,"{body},root,form"),'Workspace must send human-provided body through canonical endpoint.');
expectGrowthV0230(!str_contains($js,'/api/v1/sales/actions/'),'Growth Workspace must not call Sales action execution directly.');
expectGrowthV0230(!str_contains($js,'/api/v1/sales/approvals/'),'Growth Workspace must not call Sales approval endpoints directly.');

echo "Growth V0.23 Engagement Execution Workspace contracts passed.\n";
