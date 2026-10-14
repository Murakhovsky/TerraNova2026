<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationStepCursor;
use Platform\Orchestration\Goal\FederationStepDependencyGraph;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowStepSelectionState;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\WorkflowDependencyGraph;
use Platform\Workflow\Service\WorkflowStepScheduler;

$definition = [
    ['id' => 'review', 'capability_id' => 'test.read', 'capability_version' => '1',
        'side_effect_level' => 'none', 'depends_on' => []],
    ['id' => 'alpha', 'capability_id' => 'test.mutate', 'capability_version' => '1',
        'side_effect_level' => 'external', 'depends_on' => ['review']],
    ['id' => 'beta', 'capability_id' => 'test.mutate', 'capability_version' => '1',
        'side_effect_level' => 'external', 'depends_on' => ['review']],
    ['id' => 'join', 'capability_id' => 'test.mutate', 'capability_version' => '1',
        'side_effect_level' => 'external', 'depends_on' => ['alpha','beta']],
];
$rows = [];
foreach (array_reverse($definition) as $step) {
    $rows[] = ['step_id' => $step['id'], 'capability_id' => $step['capability_id'],
        'capability_version' => $step['capability_version'], 'side_effect_level' => $step['side_effect_level'],
        'state' => 'pending'];
}
$copy = static fn(array $list): array => array_map(
    static fn(array $row): WorkflowStepSnapshot => WorkflowStepSnapshot::fromPersistedRow($row), $list);
$source = new WorkflowDefinitionSnapshot('test.execution', '1.0.0', $definition);
$shared = new WorkflowStepScheduler();
$legacy = new FederationStepCursor();
$compare = static function (array $states, bool $allowReadOnly = true) use ($shared, $legacy, $source, $copy, $definition): array {
    $canonical = $shared->select($source, $copy($states), $allowReadOnly ? ['external','none'] : ['external']);
    $adapted = $legacy->select($definition, $states, $allowReadOnly);
    if ($canonical->toLegacyArray() !== $adapted) {
        throw new RuntimeException('Federation and shared selector diverged.');
    }
    return $adapted;
};
$update = static function (array &$list, string $id, string $state): void {
    foreach ($list as &$row) {
        if ($row['step_id'] === $id) {
            $row['state'] = $state;
            return;
        }
    }
    throw new RuntimeException('Unknown fixture step.');
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$selection = $compare($rows);
$assert($selection['step_id'] === 'review' && $selection['state'] === 'pending', 'Read-only prerequisite not selected.');
$update($rows, 'review', 'completed');
$assert($compare($rows)['step_id'] === 'alpha', 'Canonical selector must use pinned Plan order.');
$update($rows, 'alpha', 'claimed');
$assert($compare($rows)['state'] === WorkflowStepSelectionState::CLAIMED->value, 'Claim not preserved.');
$update($rows, 'alpha', 'ambiguous');
$assert($compare($rows)['state'] === WorkflowStepSelectionState::AMBIGUOUS->value, 'Ambiguous claim must block replay.');
$update($rows, 'alpha', 'completed');
$assert($compare($rows)['step_id'] === 'beta', 'Independent branch not selected.');
$update($rows, 'beta', 'completed');
$assert($compare($rows)['step_id'] === 'join', 'Join must wait for all parents.');
$update($rows, 'join', 'completed');
$assert($compare($rows)['state'] === WorkflowStepSelectionState::COMPLETE->value, 'Run not completed.');

$deny = static function (array $def, array $rows, bool $allowReadOnly = true) use ($shared, $copy): void {
    try {
        $shared->select(new WorkflowDefinitionSnapshot('test.execution','1', $def), $copy($rows),
            $allowReadOnly ? ['external','none'] : ['external']);
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Shared Workflow selector accepted an unsafe fixture.');
};
$modified = $rows;
$update($modified, 'alpha', 'claimed');
$update($modified, 'beta', 'claimed');
$deny($definition, $modified);
$modified = $rows;
$update($modified, 'review', 'pending');
$deny($definition, $modified);
$modified = $rows;
$update($modified, 'join', 'ambiguous');
$deny($definition, $modified);
$modified = $rows;
$modified[0]['capability_version'] = 'forged';
$deny($definition, $modified);
$modified = $rows;
$modified[] = $modified[0];
$deny($definition, $modified);
$modified = $definition;
$modified[0]['depends_on'] = ['join'];
$deny($modified, $rows);
$modified = $definition;
$modified[3]['depends_on'][] = 'missing';
$deny($modified, $rows);
$deny($definition, $rows, false);
$legacyGraph = (new FederationStepDependencyGraph())->resolve($definition);
$sharedGraph = (new WorkflowDependencyGraph())->resolve($definition);
$assert($legacyGraph === $sharedGraph, 'Compatibility dependency graph diverged.');
echo "Shared Workflow DAG contract parity passed: canonical DTOs, Federation compatibility, ambiguity and negative fixtures.\n";
