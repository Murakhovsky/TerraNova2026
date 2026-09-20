<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('app/migrations/20260917_000061_platform_agent_trace.sql');
$expect(str_contains($migration, 'CREATE TABLE IF NOT EXISTS cos_agent_trace_events'), 'Agent trace table migration is missing.');
$expect(str_contains($migration, 'REFERENCES cos_agent_runs (id) ON DELETE CASCADE'), 'Agent trace must be owned by an Agent Run.');

$composition = $read('symfony/config/services.yaml');
foreach ([
    'Infrastructure\\Platform\\Persistence\\MySql\\Audit\\MysqlAgentTraceRepository:',
    'Platform\\Audit\\Contract\\AgentTraceRepositoryInterface:',
    'Infrastructure\\Audit\\KernelAuditSink:',
    'Platform\\Audit\\Service\\AuditRecorder:',
    'Infrastructure\\Audit\\PlatformAgentAudit:',
    'Kernel\\Agent\\Contract\\AgentAuditInterface:',
    'Kernel\\Agent\\Service\\AgentRuntime:',
] as $service) {
    $expect(str_contains($composition, $service), 'Missing canonical audit runtime service: ' . $service);
}

$tool = $read('app/Infrastructure/Audit/PlatformToolAudit.php');
$expect(str_contains($tool, 'findByCorrelationId('), 'Tool trace attachment must resolve by tenant-scoped correlation id.');

$sink = $read('app/Infrastructure/Audit/KernelAuditSink.php');
$expect(str_contains($sink, "'agent' => 'AGENT'"), 'Platform actor types must be normalized for the audit enum.');
$expect(str_contains($sink, "'result' => \$record->output"), 'Platform audit output must survive the Kernel audit bridge.');

foreach (['app/Bootstrap/InfrastructureServices.php','app/Bootstrap/KernelServices.php'] as $retired) {
    $expect(!is_file($root . '/' . $retired), 'Retired bootstrap composition returned: ' . $retired);
}

echo "Platform Audit runtime wiring OK on canonical Symfony composition.\n";
