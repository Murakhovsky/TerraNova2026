<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\FederationSmallTaskPlanFactory;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Orchestration\Goal\GoalSpecification;

$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery(dirname(__DIR__, 2) . '/app/Domains'))->discover(),
));
$factory = new FederationSmallTaskPlanFactory();
$validator = new GoalPlanValidator($catalog);
$goal = new GoalSpecification('goal-small','tenant-a','42','Three real sales tasks',
    [['id'=>'sales.local_tasks_created','operator'=>'at_least','expected'=>10]],
    ['sales.create_task']);
$tasks = [
    ['deal_id'=>'deal-1','title'=>'Call client','due_in_minutes'=>60],
    ['deal_id'=>'deal-2','title'=>'Send offer','due_in_minutes'=>120],
    ['deal_id'=>'deal-3','title'=>'Arrange viewing','due_in_minutes'=>240],
];
foreach ([1,2,3] as $count) {
    $built = $factory->build($goal, array_slice($tasks,0,$count));
    $validated = $validator->validate($goal,$built['steps'],['sales.create_task']);
    if (!$validated['valid'] || count($validated['steps']) !== $count
        || $built !== $factory->build($goal,array_slice($tasks,0,$count))) {
        throw new RuntimeException('Small Goal canonical plan validation or idempotency failed.');
    }
    foreach ($built['steps'] as $i=>$step) {
        if ($step['depends_on'] !== ($i===0 ? [] : ['task-'.$i])
            || $step['input']['target_id'] !== $tasks[$i]['deal_id']) {
            throw new RuntimeException('Small Goal operation ordering/identity changed.');
        }
    }
}
$reject = static function ($goal,$tasks) use ($factory): void {
    try {$factory->build($goal,$tasks);} catch (DomainException) {return;}
    throw new RuntimeException('Unsafe small Goal plan was accepted.');
};
$reject($goal,[]);
$reject($goal,array_merge($tasks,[$tasks[0]]));
$invalid = $tasks;
$invalid[0]['deal_id'] = '../other-tenant';
$reject($goal,$invalid);
$invalid = $tasks;
$invalid[0]['title'] = '';
$reject($goal,$invalid);
$invalid = $tasks;
$invalid[0]['due_in_minutes'] = 0;
$reject($goal,$invalid);
$wrong = new GoalSpecification('goal-wrong','tenant-a','42','Wrong metric',
    [['id'=>'federation.verified_proposals','operator'=>'at_least','expected'=>50]],
    ['sales.create_task']);
$reject($wrong,[$tasks[0]]);
$missing = new GoalSpecification('goal-missing','tenant-a','42','No grant',
    [['id'=>'sales.local_tasks_created','operator'=>'at_least','expected'=>1]],[]);
$reject($missing,[$tasks[0]]);
echo "Federation small Goal 1-3 typed canonical plans PASS.\n";
