<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/symfony/vendor/autoload.php';

use App\Web\Experience\Component\CosDataGrid;
use App\Web\Experience\Component\CosOutcomeState;
use App\Web\Experience\Component\CosProgress;
use App\Web\Experience\Data\DataGridState;
use Symfony\Component\Yaml\Yaml;

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$en = Yaml::parseFile($root . '/symfony/translations/cuw_quality.en.yaml');
$uk = Yaml::parseFile($root . '/symfony/translations/cuw_quality.uk.yaml');
requireCondition(array_keys($en) === array_keys($uk), 'UK/EN key parity');
$states = [
    'loading', 'empty', 'disabled', 'error', 'permission_denied', 'queued', 'running',
    'waiting_approval', 'blocked', 'completed_unverified', 'completed_verified',
    'external_outcome_unknown', 'reconciliation_required', 'failed', 'cancelled', 'stale_data',
];
$component = new CosOutcomeState();
foreach ($states as $state) {
    $component->state = $state;
    requireCondition($component->stateKey() === $state, 'Lost state: ' . $state);
    requireCondition(($component->tone() === 'positive') === ($state === 'completed_verified'), 'Unverified outcome appears verified: ' . $state);
    foreach (['title', 'detail'] as $part) {
        $key = 'state.' . $state . '.' . $part;
        requireCondition(!empty($en[$key]) && !empty($uk[$key]) && $en[$key] !== $uk[$key], 'Untranslated state: ' . $key);
    }
}
foreach (['', 'done', 'success', 'unknown', '<script>'] as $state) {
    $component->state = $state;
    requireCondition($component->stateKey() === 'external_outcome_unknown' && $component->tone() === 'warning', 'Unsafe fallback');
}
$progress = new CosProgress();
foreach ([[-1, 100, 0, 0], [120, 100, 100, 100], [7, 10, 7, 70], [20, 0, 0, 0]] as [$value, $max, $clamped, $percent]) {
    $progress->value = $value; $progress->max = $max;
    requireCondition($progress->clampedValue() === $clamped && $progress->percent() === $percent, 'Invalid ARIA progress range');
}
$grid = new CosDataGrid();
$grid->baseUrl = '/dev/ui?quality=1&sort=ignored&columns%5B%5D=ignored';
requireCondition($grid->fixedQueryParameters() === ['quality' => '1'], 'Fixed query lost or collided with grid controls');
requireCondition(DataGridState::Denied->value === 'permission_denied', 'Explicit permission state missing');
echo "CUW-F outcome semantics, UK/EN translation coverage, ARIA progress and DataGrid query contracts PASS.\n";
