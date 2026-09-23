<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

$root=dirname(__DIR__,2);
$controller=(string)file_get_contents($root.'/symfony/src/Web/Growth/GrowthPageController.php');
$view=(string)file_get_contents($root.'/app/Interfaces/Web/View/growth/learning.phtml');
$js=(string)file_get_contents($root.'/frontend/features/growth/workspace.js');

function expectGrowthV0180(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

expectGrowthV0180(str_contains($controller,'GrowthOptimizationBoundary'),'Growth Learning page must depend on optimization boundary.');
expectGrowthV0180(str_contains($controller,"'optimization'=>\$this->optimization->optimizationBrief"),'Growth Learning page must load optimization brief.');

foreach([
    'data-growth-learning',
    'Learning → governed draft',
    'Current active criteria',
    'Proposed draft criteria',
    'data-growth-optimization-generate',
    'data-growth-optimization-decision="accept"',
    'data-growth-optimization-decision="dismiss"',
    'data-growth-optimization-materialize',
    'Activation remains a separate human-controlled operation',
] as $needle){
    expectGrowthV0180(str_contains($view,$needle),'Growth Optimization workspace view missing: '.$needle);
}

foreach([
    "data-growth-optimization-generate",
    "data-growth-optimization-decision",
    "data-growth-optimization-materialize",
    "/api/v1/growth/learning/optimization/recommendations",
    "X-Idempotency-Key",
] as $needle){
    expectGrowthV0180(str_contains($js,$needle),'Growth Optimization workspace JS missing: '.$needle);
}

expectGrowthV0180(!str_contains($view,'/activate'),'Optimization workspace must not expose policy activation.');
expectGrowthV0180(!str_contains($js,"'/activate'")&&!str_contains($js,'"/activate"'),'Optimization JS must not call activation endpoints.');

echo "Growth V0.18 Optimization Workspace contracts passed.\n";
