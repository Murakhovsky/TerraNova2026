<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivitySource;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\ActorKind;
use Platform\Audit\Model\ResourceReference;

$human = new Actor('user', 'user-1');
$agent = new Actor('agent', 'agent-1');
$system = new Actor('worker', 'worker-1');

assert($human->kind() === ActorKind::HUMAN);
assert($agent->kind() === ActorKind::AGENT);
assert($system->kind() === ActorKind::SYSTEM);

$record = new ActivityRecord(
    id: 'history-1',
    organizationId: OrganizationId::fromString('org-1'),
    actor: $human,
    action: 'lead.update',
    resource: new ResourceReference('lead', 'lead-1'),
    input: ['field' => 'status'],
    output: ['status' => 'qualified'],
    agent: null,
    tool: 'lead-editor',
    workflow: null,
    durationMs: 5,
    cost: null,
    costUnit: null,
    status: ActivityStatus::SUCCESS,
    error: null,
    correlationId: 'corr-1',
    timestamp: new DateTimeImmutable(),
    metadata: [],
    source: ActivitySource::TOOL,
);

$array = $record->toArray();
assert($array['actor']['kind'] === 'human');
assert($array['source'] === 'TOOL');
assert($array['correlation_id'] === 'corr-1');

echo "Platform Audit history model OK\n";
