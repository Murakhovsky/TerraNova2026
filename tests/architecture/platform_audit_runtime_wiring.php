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

$infrastructure = $read('app/Bootstrap/InfrastructureServices.php');
foreach (['cosAgentTraceRepository', 'cosPlatformAuditSink', 'cosPlatformAuditRecorder', 'cosPlatformAgentAudit'] as $service) {
    $expect(str_contains($infrastructure, "setShared('{$service}'"), "Missing {$service} runtime service.");
}

$kernel = $read('app/Bootstrap/KernelServices.php');
$expect(str_contains($kernel, '$this->getShared(\'cosPlatformAgentAudit\')'), 'AgentRuntime must receive the durable Platform audit adapter.');

$tool = $read('app/Infrastructure/Audit/PlatformToolAudit.php');
$expect(str_contains($tool, 'findByCorrelationId('), 'Tool trace attachment must resolve by tenant-scoped correlation id.');

$sink = $read('app/Infrastructure/Audit/KernelAuditSink.php');
$expect(str_contains($sink, "'agent' => 'AGENT'"), 'Platform actor types must be normalized for the legacy audit enum.');
$expect(str_contains($sink, "'result' => $record->output"), 'Platform audit output must survive the Kernel audit bridge.');

echo "Platform Audit runtime wiring OK\n";
