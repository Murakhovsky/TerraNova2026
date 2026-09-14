<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Sales\Application\Support\ClientCaseInput;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$expected = [
    'new' => 'NEW',
    'contacted' => 'CONTACTED',
    'qualified' => 'QUALIFIED',
    'viewing_planned' => 'MEETING',
    'viewing' => 'MEETING',
    'negotiation' => 'NEGOTIATION',
    'won' => 'WON',
    'lost' => 'LOST',
];
foreach ($expected as $leadStatus => $stageCode) {
    $state = ClientCaseInput::caseStateForLead($leadStatus);
    $assert(($state['stage'] ?? null) === $stageCode, 'Lead status ' . $leadStatus . ' must map to ' . $stageCode . '.');
}

$root = dirname(__DIR__, 2);
$repo = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php') ?: '';
$inbound = file_get_contents($root . '/app/Domains/Sales/Application/Service/SalesInboundService.php') ?: '';
$commands = file_get_contents($root . '/app/Domains/Sales/Application/Service/ClientCaseCommandService.php') ?: '';
$readModel = file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlClientCaseReadModel.php') ?: '';
$routes = file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php') ?: '';

$assert(!str_contains($repo, "'stage' => strtolower((string) \$initial['code'])"), 'New deals must not persist lowercase legacy stage mirrors.');
$assert(str_contains($repo, "'stage' => strtoupper((string) \$initial['code'])"), 'New deals must mirror the canonical configured stage code.');
$assert(!str_contains($inbound, "canonicalStageCode(\$state['stage'])"), 'Canonical Lead stage codes must not pass through a legacy mapper.');
$assert(str_contains($inbound, "'pipeline_id' => \$created['pipeline_id'] ?? null"), 'Create events must expose the actual configured pipeline.');
$assert(str_contains($inbound, "'stage_id' => \$created['stage_id'] ?? null"), 'Create events must expose the actual configured stage id.');
$assert(!str_contains($commands, "\$status = \$this->allowed((string) (\$input['status']"), 'QuickUpdate must not synthesize a status mutation.');
$assert(str_contains($commands, '$simpleChanges = [];'), 'QuickUpdate must build events only from persisted simple changes.');
$assert(!str_contains($readModel, 'use Domains\\Sales\\Model\\PipelineStage;'), 'Legacy ClientCase read model must not depend on hardcoded PipelineStage values.');
$assert(str_contains($readModel, 'sales_pipeline_stages'), 'ClientCase read model must resolve configured stages.');
foreach (['/sales/leads', '/sales/deals/{id:[0-9]+}', '/sales/director', '/sales/admin'] as $route) {
    $assert(str_contains($routes, $route), 'Missing Sales V0.4 route: ' . $route);
}
$assert(is_file($root . '/app/Interfaces/Cli/Task/SalesTask.php'), 'Sales monitoring CLI entrypoint is required.');

if ($failures !== []) {
    fwrite(STDERR, "Sales V0.4 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Sales V0.4 checks passed.\n";
