<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$renderer = new class {
    public object $url;
    public function __construct() {
        $this->url = new class { public function get(string $path = ''): string { return '/' . ltrim($path, '/'); } };
    }
    public function render(string $file, array $variables): string {
        extract($variables, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};

$overview = [
    'stats' => ['events' => 2, 'decisions' => 1, 'open_actions' => 1, 'pending_approvals' => 1, 'completed_results' => 1, 'dead_jobs' => 0],
    'events' => [[
        'id' => 'event000000000000000000000000000', 'type' => 'sales.call.completed', 'aggregate_type' => 'deal',
        'aggregate_id' => '184', 'payload' => '{"result":"client_thinking"}', 'metadata' => '{}',
        'correlation_id' => 'correlation-184', 'occurred_at' => '2026-08-22 10:00:00',
    ]],
    'decisions' => [[
        'type' => 'sales-intelligence.decision', 'decision' => 'FOLLOW_UP', 'confidence' => 0.91,
        'reason' => '<script>alert(1)</script>', 'agent_name' => 'sales-intelligence', 'model' => 'fake',
        'source_type' => 'AGENT', 'source_id' => 'run-1',
    ]],
    'actions' => [[
        'id' => str_repeat('a', 32), 'type' => 'sales.send_followup', 'target_type' => 'deal', 'target_id' => '184',
        'source_type' => 'AGENT', 'status' => 'QUEUED', 'risk_level' => 'MEDIUM', 'parameters' => '{"channel":"telegram"}',
        'approval_id' => null, 'approval_status' => null,
    ]],
    'approvals' => [[
        'id' => str_repeat('b', 32), 'action_type' => 'sales.send_message', 'target_type' => 'deal', 'target_id' => '184',
        'status' => 'PENDING', 'risk_level' => 'HIGH', 'reason' => 'Manager review',
    ]],
    'results' => [[
        'action_type' => 'sales.send_followup', 'target_type' => 'deal', 'target_id' => '184', 'attempt' => 1,
        'status' => 'COMPLETED', 'result' => '{"message_id":"msg-1"}', 'error' => null, 'finished_at' => '2026-08-22 10:01:00',
    ]],
    'audit' => [[
        'category' => 'ACTION_EXECUTION', 'action' => 'sales.send_followup', 'subject_type' => 'deal', 'subject_id' => '184',
        'reason' => null, 'actor_type' => 'WORKER', 'actor_id' => 'worker-1', 'created_at' => '2026-08-22 10:01:00',
    ]],
];

$html = $renderer->render($root . '/app/modules/frontend/views/cos/index.phtml', [
    'overview' => $overview, 'actionStatus' => 'Queued', 'pageStatus' => null,
]);

foreach (['Events', 'Decisions', 'Proposed Actions', 'Approvals', 'Results', 'Audit', 'Execute', 'Approve', 'Reject'] as $expected) {
    if (!str_contains($html, $expected)) throw new RuntimeException('COS UI is missing: ' . $expected);
}
if (str_contains($html, '<script>alert(1)</script>') || !str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')) {
    throw new RuntimeException('COS UI did not escape decision content.');
}
if (!str_contains($html, '/cos/action/' . str_repeat('a', 32) . '/execute')) {
    throw new RuntimeException('COS UI Execute form is not wired.');
}

echo "COS minimal UI smoke test passed.\n";
