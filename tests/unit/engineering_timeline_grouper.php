<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/symfony/src/Engineering/Application/Service/EngineeringTimelineGrouper.php';

use App\Engineering\Application\Service\EngineeringTimelineGrouper;

$runs = [
    ['id' => 'run-one', 'role' => 'PRINCIPAL_ARCHITECT', 'trace_id' => 'trace-one'],
    ['id' => 'run-two', 'role' => 'PRINCIPAL_ARCHITECT', 'trace_id' => 'trace-two'],
    ['id' => 'run-shared-a', 'role' => 'REVIEWER', 'trace_id' => 'shared'],
    ['id' => 'run-shared-b', 'role' => 'REVIEWER', 'trace_id' => 'shared'],
];
$events = [
    ['type' => 'AGENT', 'reference_id' => 'run-one', 'actor' => 'PRINCIPAL_ARCHITECT'],
    ['type' => 'LLM', 'reference_id' => 'llm-one', 'correlation_id' => 'trace-one'],
    ['type' => 'LLM', 'reference_id' => 'llm-two', 'correlation_id' => 'trace-two'],
    ['type' => 'LLM', 'reference_id' => 'llm-shared', 'correlation_id' => 'shared'],
    ['type' => 'MANAGER', 'actor' => 'run-one', 'details' => ['round' => 2]],
    ['type' => 'REPOSITORY', 'actor' => 'system', 'details' => ['round' => 'unverified']],
];
$grouped = (new EngineeringTimelineGrouper())->group($events, $runs);
if ($grouped[0]['group_key'] !== 'run:run-one' || $grouped[0]['attribution'] !== 'EXACT_RUN_ID'
    || $grouped[1]['group_key'] !== 'run:run-one' || $grouped[1]['attribution'] !== 'UNIQUE_TRACE'
    || $grouped[2]['group_key'] !== 'run:run-two') {
    throw new RuntimeException('AgentRun and LLM attribution failed.');
}
if ($grouped[3]['group_key'] !== 'unattributed-llm' || $grouped[3]['attribution'] !== 'UNATTRIBUTED') {
    throw new RuntimeException('Ambiguous LLM trace received false attribution.');
}
if ($grouped[4]['group_key'] !== 'run:run-one' || $grouped[4]['evidence_round'] !== 2
    || $grouped[5]['group_key'] !== 'system' || $grouped[5]['evidence_round'] !== null) {
    throw new RuntimeException('Evidence rounds or unassigned events incorrectly grouped.');
}
if (isset($events[4]['group_key'])) throw new RuntimeException('Original event mutated.');
echo "Engineering timeline grouping passed.\n";
