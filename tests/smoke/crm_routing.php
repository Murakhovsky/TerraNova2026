<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\CrmProviderInterface;
use Domains\Sales\Application\Contract\OrganizationCrmResolverInterface;
use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Model\DealChangeSet;
use Domains\Sales\Automation\Action\CreateFollowupTaskHandler;
use Infrastructure\Integration\Crm\CrmRegistry;
use Infrastructure\Integration\Crm\RoutedCrmGateway;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionExecutor;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Kernel\\' => '/app/Kernel/',
        'Domains\\' => '/app/Domains/',
        'Infrastructure\\' => '/app/Infrastructure/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

$adapter = new class implements CrmProviderInterface {
    public ?CreateTaskCommand $received = null;
    public ?SendMessageCommand $message = null;
    public ?ScheduleFollowupCommand $followup = null;
    public ?DealChangeSet $changes = null;

    public function provider(): string
    {
        return 'external-test-crm';
    }

    public function createTask(CreateTaskCommand $command): OperationResult
    {
        $this->received = $command;
        return OperationResult::success('external-task-42', ['provider' => $this->provider()]);
    }
    public function send(SendMessageCommand $command): OperationResult { $this->message = $command; return OperationResult::success('message-1'); }
    public function schedule(ScheduleFollowupCommand $command): OperationResult { $this->followup = $command; return OperationResult::success('followup-1'); }
    public function update(string $organizationId, string $dealReference, DealChangeSet $changes): OperationResult { $this->changes = $changes; return OperationResult::success($dealReference); }
};

$resolver = new class implements OrganizationCrmResolverInterface {
    public function providerFor(string $organizationId): string
    {
        return $organizationId === 'client-with-external-crm' ? 'external-test-crm' : 'missing';
    }
};

$gateway = new RoutedCrmGateway($resolver, new CrmRegistry([$adapter]));
$handler = new CreateFollowupTaskHandler($gateway);
$executor = new ActionExecutor([$handler]);
$action = new Action(
    'action-1',
    'client-with-external-crm',
    'sales.create_followup_task',
    'deal',
    'external-deal-17',
    ['title' => 'Follow up', 'due_in_minutes' => 60],
    'RULE',
    'rule-1',
    'AUTO',
    'LOW',
    'idempotency-1',
    new DateTimeImmutable(),
);
$action->transitionTo(ActionStatus::Queued);
$result = $executor->execute($action);

if (!$result->successful || $action->status !== ActionStatus::Completed) {
    throw new RuntimeException('The routed CRM action did not complete.');
}
if ($adapter->received?->dealReference !== 'external-deal-17') {
    throw new RuntimeException('The CRM adapter did not receive the canonical task command.');
}
if (($result->data['external_id'] ?? null) !== 'external-task-42') {
    throw new RuntimeException('The external CRM result was not returned to the Action Engine.');
}

$gateway->send(new SendMessageCommand('client-with-external-crm', 'external-deal-17', 'email', 'Hello', 'message-key'));
$gateway->schedule(new ScheduleFollowupCommand('client-with-external-crm', 'external-deal-17', 'Call', null, new DateTimeImmutable('+1 day'), 'followup-key'));
$gateway->update('client-with-external-crm', 'external-deal-17', DealChangeSet::fromArray(['priority' => 'high']));
if ($adapter->message?->dealReference !== 'external-deal-17'
    || $adapter->followup?->dealReference !== 'external-deal-17'
    || $adapter->changes?->toArray() !== ['priority' => 'high']
) {
    throw new RuntimeException('External CRM did not receive the complete outbound capability surface.');
}

echo "CRM routing passed: Sales Action -> organization provider -> external adapter.\n";
