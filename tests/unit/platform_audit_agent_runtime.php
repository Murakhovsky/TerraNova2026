<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infrastructure\Audit\PlatformAgentAudit;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Model\AgentAuditEvent;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Contract\AgentTraceRepositoryInterface;
use Platform\Audit\Contract\AuditSinkInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\AgentRunHistory;
use Platform\Audit\Service\AuditRecorder;

$traces = new class implements AgentTraceRepositoryInterface {
    public array $items = [];
    public function find(string $runId): ?AgentRunHistory { return $this->items[$runId] ?? null; }
    public function findByCorrelationId(OrganizationId $organizationId, string $correlationId): ?AgentRunHistory {
        foreach ($this->items as $history) {
            if ($history->organizationId->equals($organizationId) && $history->correlationId === $correlationId) return $history;
        }
        return null;
    }
    public function save(AgentRunHistory $history): void { $this->items[$history->runId] = $history; }
};
$sink = new class implements AuditSinkInterface {
    public array $items = [];
    public function append(ActivityRecord $record): void { $this->items[] = $record; }
};
$audit = new PlatformAgentAudit($traces, new AuditRecorder($sink));
$agent = new AgentDefinition(
    name: 'audit-test',
    version: '1.0.0',
    systemPrompt: 'test',
    promptVersion: '1',
    schemaVersion: '1',
    allowedActionTypes: [],
    configurationManaged: false,
);
$invocation = new AgentInvocation('org-1', 'lead', 'lead-1', 'qualify', 'corr-1');
$audit->record('run-1', $agent, $invocation, AgentAuditEvent::REASONING_REQUEST, ['question' => 'qualify']);
$audit->record('run-1', $agent, $invocation, AgentAuditEvent::DECISION, ['decision' => 'contact']);
$audit->record('run-1', $agent, $invocation, AgentAuditEvent::RESULT, ['ok' => true], 15, 0.001, 'usd');
assert(count($traces->items['run-1']->events()) === 3);
assert(count($sink->items) === 3);
assert($sink->items[2]->cost === 0.001);
assert($traces->findByCorrelationId(OrganizationId::fromString('org-1'), 'corr-1')?->runId === 'run-1');

echo "Platform Agent audit hook OK\n";
