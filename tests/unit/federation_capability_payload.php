<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\GoalPlanValidator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover()));
$contract = $catalog->describe('sales.create_task');
if ($contract === null || $contract->ownerDomain !== 'sales' || $contract->executionBinding !== 'action:sales.create_task') {
    throw new RuntimeException('Real Sales Action contract missing from canonical catalog.');
}
$goal = new GoalSpecification('goal-sales', 'tenant-a', 'manager-a', 'Create verified follow-up', [
    ['id' => 'tasks_created', 'operator' => 'at_least', 'expected' => 1],
], ['sales.create_task']);
$validate = new GoalPlanValidator($catalog);
$validStep = [
    'id' => 'followup',
    'capability_id' => 'sales.create_task',
    'capability_version' => '1.0.0',
    'input' => [
        'target_type' => 'deal', 'target_id' => 'deal-123',
        'parameters' => ['title' => 'Follow up customer', 'due_in_minutes' => 60],
    ],
];
$valid = $validate->validate($goal, [$validStep], ['sales.create_task']);
if (!$valid['valid'] || ($valid['steps'][0]['input'] ?? null) !== $validStep['input']) {
    throw new RuntimeException('Approved Sales action input was not validated and preserved.');
}
$reject = static function (array $step, string $message) use ($validate, $goal): void {
    if ($validate->validate($goal, [$step], ['sales.create_task'])['valid']) {
        throw new RuntimeException('Goal planner accepted unsafe payload: ' . $message);
    }
};
$missing = $validStep;
unset($missing['input']);
$reject($missing, 'missing immutable approved action input');
$wrong = $validStep;
$wrong['input']['target_type'] = 'invoice';
$reject($wrong, 'cross-domain target type');
$injected = $validStep;
$injected['input']['parameters']['arbitrary_sql'] = 'DROP TABLE';
$reject($injected, 'unexpected input field');
$invalid = $validStep;
unset($invalid['input']['parameters']['title']);
$reject($invalid, 'missing required title');
$tooLarge = $validStep;
$tooLarge['input']['parameters']['body'] = str_repeat('x', 20000);
$reject($tooLarge, 'oversized input');
$prepareContract=$catalog->describe('growth.handoff.prepare');
if ($prepareContract === null || $prepareContract->sideEffectLevel !== 'internal'
    || $prepareContract->executionBinding !== 'action:growth.handoff.prepare') {
    throw new RuntimeException('Growth handoff preparation should be an internal governed Action.');
}
$growthGoal=new GoalSpecification('g-growth','tenant-a','manager-a','Prepare qualified candidate for Sales handoff',[
    ['id'=>'candidate_ready','operator'=>'at_least','expected'=>1],
],['growth.handoff.prepare']);
$growthStep=['id'=>'prepare','capability_id'=>'growth.handoff.prepare','capability_version'=>'1.0.0',
    'input'=>['target_type'=>'growth_candidate','target_id'=>'GC-1','parameters'=>[
        'expected_value'=>'consultation','recommended_play'=>'sales_call',
        'recommended_action'=>'contact owner',
    ]]];
$growthPlan=$validate->validate($growthGoal,[$growthStep],['growth.handoff.prepare']);
if (!$growthPlan['valid']) {
    throw new RuntimeException('Approved internal Growth Action was rejected: '.implode(',',$growthPlan['errors']));
}
$missingInternalActionInput=$growthStep;
unset($missingInternalActionInput['input']);
if ($validate->validate($growthGoal,[$missingInternalActionInput],['growth.handoff.prepare'])['valid']) {
    throw new RuntimeException('Internal Action bypassed immutable plan input validation.');
}
echo "Federation typed Sales Action payload validation and immutable planning passed.\n";
