<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\CapabilityContract;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;
use Platform\Orchestration\Goal\GoalPlanValidator;

$spec = new GoalSpecification('goal-1', 'tenant-1', 'user-1', 'Qualify 10 clients', [
    ['id' => 'accepted_leads', 'operator' => 'at_least', 'expected' => 10],
], ['sales.lead.qualify'], budgetMinorUnits: 10000, currency: 'USD');
$evaluator = new GoalOutcomeEvaluator();
$incomplete = $evaluator->evaluate($spec, ['accepted_leads' => ['value' => 6, 'evidence' => ['sales:lead:6']]]);
$complete = $evaluator->evaluate($spec, ['accepted_leads' => ['value' => 10, 'evidence' => ['sales:lead:10']]]);
$unverified = $evaluator->evaluate($spec, ['accepted_leads' => ['value' => 50, 'evidence' => []]]);
if ($incomplete['result'] !== 'partial' || $complete['result'] !== 'satisfied'
    || $unverified['result'] !== 'unverifiable') {
    throw new RuntimeException('Goal evaluator confuses task completion, measured outcome and evidence.');
}
$contract = new CapabilityContract(
    'sales.lead.qualify', '1.0.0', 'sales', 'command', 'QualificationInputV1', 'QualificationResultV1',
    'salesQualificationHandler', false, 'internal', 'sales.lead.qualify', 'none', 'required',
    ['seconds' => 30], ['max_attempts' => 1], 'QualificationErrorV1',
);
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('sales', 'Sales', '1.0.0'), new ModuleContributions(
        capabilities: ['sales.lead.qualify'], capabilityContracts: [$contract],
    )),
]));
$validator = new GoalPlanValidator($catalog);
$steps = [['id' => 'qualify', 'capability_id' => 'sales.lead.qualify', 'capability_version' => '1.0.0']];
$valid = $validator->validate($spec, $steps, ['sales.lead.qualify']);
$invalid = $validator->validate($spec, $steps, []);
$unknown = $validator->validate($spec, [['id' => 'injected', 'capability_id' => 'system.sql.raw', 'capability_version' => '1.0.0']], ['system.sql.raw']);
if (!$valid['valid'] || $invalid['valid'] || $unknown['valid']
    || $valid['steps'][0]['owner_domain'] !== 'sales') {
    throw new RuntimeException('Goal plan validation bypassed capability ownership or availability.');
}
$dagSteps = [
    ['id' => 'research', 'capability_id' => 'sales.lead.qualify',
        'capability_version' => '1.0.0', 'depends_on' => []],
    ['id' => 'verify', 'capability_id' => 'sales.lead.qualify',
        'capability_version' => '1.0.0', 'depends_on' => ['research']],
];
$dagResult = $validator->validate($spec, $dagSteps, ['sales.lead.qualify']);
if (!$dagResult['valid'] || ($dagResult['steps'][1]['depends_on'] ?? null) !== ['research']) {
    throw new RuntimeException('Goal Plan validator lost approved DAG prerequisites.');
}
$cyclic = $dagSteps;
$cyclic[0]['depends_on'] = ['verify'];
if ($validator->validate($spec, $cyclic, ['sales.lead.qualify'])['valid']) {
    throw new RuntimeException('Goal Plan validator allowed a cyclic execution graph.');
}
$implicit = $dagSteps;
unset($implicit[1]['depends_on']);
if (($validator->validate($spec, $implicit, ['sales.lead.qualify'])['steps'][1]['depends_on'] ?? null)
    !== ['research']) {
    throw new RuntimeException('Goal Plan validator broke legacy implicit dependency order.');
}
echo "Federation GoalSpecification, safe planning, DAG normalization and deterministic outcomes passed.\n";
