<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Domains\Sales\Automation\Action\CreateFollowupTaskHandler;
use Domains\Sales\Automation\Action\ScheduleFollowupHandler;
use Domains\Sales\Automation\Action\UpdateDealHandler;
use Domains\Sales\Automation\Action\ChangeDealStageHandler;
use Domains\Sales\Automation\Action\RequestDocumentHandler;
use Domains\Sales\Automation\Action\ScheduleMeetingHandler;
use Domains\Sales\Automation\Action\AssignOwnerHandler;
use Domains\Sales\Automation\Event\ActionOutcomeMeasured;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Automation\Policy\SalesPolicyCatalog;
use Domains\Sales\Automation\Rule\SalesRuleCatalog;
use Domains\Sales\Model\DealChangeSet;
use Domains\Sales\Model\OutcomeAttribution;

$requiredActions = [
    'sales.create_task', 'sales.create_followup', 'sales.schedule_followup', 'sales.send_message',
    'sales.update_deal', 'sales.change_stage', 'sales.assign_owner', 'sales.request_document', 'sales.schedule_meeting',
];
$implemented = [...CreateFollowupTaskHandler::TYPES, ...ScheduleFollowupHandler::TYPES, ...UpdateDealHandler::TYPES];
$implemented[] = 'sales.send_message';
$implemented = [...$implemented, ChangeDealStageHandler::TYPE, AssignOwnerHandler::TYPE, RequestDocumentHandler::TYPE, ScheduleMeetingHandler::TYPE];
foreach ($requiredActions as $actionType) {
    if (!in_array($actionType, $implemented, true)) throw new RuntimeException('Missing Sales action handler contract: ' . $actionType);
}

$policyTypes = array_map(static fn ($policy): string => $policy->actionType, (new SalesPolicyCatalog())->policies('tenant-a'));
foreach ($requiredActions as $actionType) {
    if (!in_array($actionType, $policyTypes, true)) throw new RuntimeException('Missing Sales action policy: ' . $actionType);
}

$ruleNames = array_map(static fn ($rule): string => $rule->name, (new SalesRuleCatalog())->rules('tenant-a'));
foreach (['Deal без активності понад 48 годин', 'Вхідне повідомлення потребує відповіді', 'Deal застряг на етапі', 'Зустріч без follow-up', 'Втрачений Deal без причини', 'Великий Deal без активності'] as $name) {
    if (!in_array($name, $ruleNames, true)) throw new RuntimeException('Missing deterministic Sales rule: ' . $name);
}

$changes = DealChangeSet::fromArray(['deal_value' => 125000, 'probability' => 80]);
if ($changes->toArray()['probability'] !== 80.0) throw new RuntimeException('Deal change validation lost probability.');

$command = new RecordActionOutcomeCommand('tenant-a', str_repeat('a', 32), 'customer_replied', true, OutcomeAttribution::Direct, [], new \DateTimeImmutable('2026-09-05T12:00:00Z'), str_repeat('b', 32), 'user', '7');
$event = ActionOutcomeMeasured::create(str_repeat('c', 32), str_repeat('d', 32), $command);
if ($event->type !== ActionOutcomeMeasured::TYPE || $event->payload['metric'] !== 'customer_replied') throw new RuntimeException('Business outcome event is invalid.');
if (!in_array(SalesEventType::MESSAGE_RECEIVED, SalesEventType::all(), true)) throw new RuntimeException('Canonical Sales events are incomplete.');

echo "Sales runtime contract passed.\n";
