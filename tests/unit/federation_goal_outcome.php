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
use Domains\Sales\Application\Service\SalesClosedOutcomeEvidenceProvider;
use Domains\Sales\Application\Contract\SalesHistoricalMetricsReadModelInterface;
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
// A Domain-owned read model supplies measured facts; Action receipts or
// free-form user input are not an authoritative business metric.
$fake = new class implements SalesHistoricalMetricsReadModelInterface {
    public ?string $tenant = null;
    public function pipelineMoney(string $organizationId, ?string $pipelineId = null): array { return []; }
    public function closedOutcomes(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $pipelineId = null): array {
        $this->tenant = $organizationId;
        return ['won' => 3, 'closed' => 5];
    }
    public function createdCohortOutcomes(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $pipelineId = null): array { return ['won' => 0, 'created' => 0]; }
    public function transitionFlow(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $pipelineId = null): array { return []; }
    public function cohortFunnel(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $pipelineId = null): array { return ['created' => 0, 'stages' => []]; }
    public function stageDurationSamples(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $pipelineId = null): array { return []; }
    public function salesCycleSamples(string $organizationId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $pipelineId = null): array { return []; }
    public function openDealRiskFacts(string $organizationId, \DateTimeImmutable $asOf, ?string $pipelineId = null): array { return []; }
};
$provider = new SalesClosedOutcomeEvidenceProvider($fake);
$from = new \DateTimeImmutable('2026-10-01T00:00:00+00:00');
$to = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');
$won = $provider->observe('tenant-1', 'sales.won_deals', $from, $to);
$closed = $provider->observe('tenant-1', 'sales.closed_deals', $from, $to);
if ($fake->tenant !== 'tenant-1' || $won['value'] !== 3 || $closed['value'] !== 5
    || $won['source'] !== 'sales.cos_events.closed_outcomes.v1'
    || count($won['evidence']) !== 1
    || $won['window_start'] !== $from->format('Y-m-d\TH:i:s.uP')) {
    throw new RuntimeException('Sales Goal evidence provider lost tenant scope or provenance.');
}
$trustedGoal = new GoalSpecification('goal-2', 'tenant-1', 'user-1', 'Win three deals',
    [['id' => 'sales.won_deals', 'operator' => 'at_least', 'expected' => 3]], []);
$trusted = $evaluator->evaluate($trustedGoal, ['sales.won_deals' => $won]);
if ($trusted['result'] !== 'satisfied'
    || $trusted['criteria'][0]['source'] !== $won['source']) {
    throw new RuntimeException('Domain evidence was not preserved in Goal evaluation.');
}
try {
    $provider->observe('tenant-1', 'tasks_created', $from, $to);
    throw new RuntimeException('Unverified CRM task count advertised as trusted.');
} catch (\DomainException) {
}
echo "Federation GoalSpecification, DAG planning and trusted Sales outcomes passed.\n";
