<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$contains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException($message . ' Missing: ' . $needle);
    }
};

$repository = $read('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php');
$contains($repository, 'INSERT INTO sales_deal_stage_history', 'Deal creation must seed durable stage history.');
$contains($repository, 'VALUES (:organization_id,:deal_id,:pipeline_id,:stage_id,NOW(),NULL,0)', 'Initial stage history must be exact, open and non-backfill.');

$projection = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php');
$contains($projection, '$visits[$pipelineId][$dealId][$stageId] ??= $enteredAt;', 'Historical funnel must retain the first exact stage timestamp.');
$contains($projection, 'strtotime((string) $currentEnteredAt) >= strtotime((string) $previousEnteredAt)', 'Historical funnel conversion must prove chronological forward order.');

$controller = $read('app/Interfaces/Web/Controller/SalesController.php');
$contains($controller, "'contract_version' => 'sales.intelligence.v1'", 'Deal intelligence must expose a versioned Sales contract.');
foreach (['deal_health', 'customer_intent', 'objections', 'missing_information', 'next_best_action', 'recommended_timing', 'confidence'] as $key) {
    $contains($controller, "'{$key}' =>", 'Deal intelligence contract is incomplete.');
}

$browser = $read('tests/browser/sales_workspace.mjs');
foreach ([
    'Qualified Lead must persist after reload',
    'Completed activity must leave Today after reload',
    'Approved action must leave pending approvals after reload',
    'did not persist target stage',
    'Sent canonical WEB message must persist after reload',
    'Executed COS action must not remain executable after reload',
] as $postcondition) {
    $contains($browser, $postcondition, 'Mutation E2E must assert persisted postconditions.');
}

fwrite(STDOUT, "Sales V0.6.8 Epic 2 Closure contract: OK\n");
