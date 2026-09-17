<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Contract\AuditSinkInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\AgentRunHistory;
use Platform\Audit\Model\ResourceReference;
use Platform\Audit\Model\TraceEvent;
use Platform\Audit\Model\TraceEventType;
use Platform\Audit\Service\AuditRecorder;

$sink = new class implements AuditSinkInterface {
    public array $records = [];
    public function append(ActivityRecord $record): void { $this->records[] = $record; }
};
$audit = new AuditRecorder($sink);
$record = new ActivityRecord(
    'act-1', OrganizationId::fromString('org-1'), new Actor('agent', 'sales-agent'), 'lead.qualify',
    new ResourceReference('lead', 'lead-7'), ['question' => 'qualify'], ['score' => 0.9],
    'sales-agent', null, 'lead-qualification', 125, 0.0021, 'usd', ActivityStatus::SUCCESS, null,
    'corr-1', new DateTimeImmutable(), ['model' => 'test']
);
$audit->record($record);
assert(count($sink->records) === 1);
assert($record->toArray()['duration_ms'] === 125);
assert($record->toArray()['cost'] === 0.0021);

$history = new AgentRunHistory('run-1', OrganizationId::fromString('org-1'), 'sales-agent', 'corr-1');
foreach ([
    TraceEventType::REASONING_REQUEST,
    TraceEventType::TOOL_CALL,
    TraceEventType::TOOL_RESULT,
    TraceEventType::DECISION,
    TraceEventType::ACTION,
    TraceEventType::RESULT,
] as $index => $type) {
    $history->append(new TraceEvent($index + 1, $type, ['n' => $index + 1], ActivityStatus::SUCCESS, new DateTimeImmutable()));
}
assert(count($history->events()) === 6);
assert($history->events()[0]->type === TraceEventType::REASONING_REQUEST);
assert($history->events()[5]->type === TraceEventType::RESULT);

echo "Platform Audit foundation OK\n";
