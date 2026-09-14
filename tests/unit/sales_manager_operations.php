<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Application\UseCase\ScheduleDealFollowup;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

final class SalesV05TransactionManager implements TransactionManagerInterface
{
    private bool $active = false;
    public function transactional(callable $operation): mixed
    {
        $previous = $this->active;
        $this->active = true;
        try { return $operation(); } finally { $this->active = $previous; }
    }
    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void { $callback(); }
}

final class SalesV05EventStore implements EventStoreInterface
{
    /** @var list<DomainEvent> */
    public array $events = [];
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent { foreach ($this->events as $event) if ($event->id === $eventId) return $event; return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array
    { return array_values(array_filter($this->events, fn (DomainEvent $event) => $event->organizationId === $organizationId && $event->aggregateType === $aggregateType && $event->aggregateId === $aggregateId)); }
}

final class SalesV05FollowupRepository implements FollowupRepositoryInterface
{
    public ?ScheduleFollowupCommand $last = null;
    public function __construct(private bool $duplicate = false) {}
    public function schedule(ScheduleFollowupCommand $command): OperationResult
    {
        $this->last = $command;
        return OperationResult::success('123', ['duplicate' => $this->duplicate]);
    }
}

$transactions = new SalesV05TransactionManager();
$store = new SalesV05EventStore();
$repository = new SalesV05FollowupRepository();
$useCase = new ScheduleDealFollowup($repository, new EventBus($store, $transactions), $transactions);
$dueAt = new DateTimeImmutable('+2 hours');
$result = $useCase->execute(new ScheduleFollowupCommand('org-1', '42', 'Call back', 'Discuss terms', $dueAt, 'key-1'), 'corr-1', 'USER', '7');
$assert($result->successful && $result->externalId === '123', 'Follow-up use case did not return repository result.');
$assert(count($store->events) === 1, 'New follow-up must publish exactly one domain event.');
$event = $store->events[0];
$assert($event->type === SalesEventType::FOLLOWUP_CREATED, 'Follow-up must publish sales.followup.created.');
$assert($event->aggregateType === 'deal' && $event->aggregateId === '42', 'Follow-up event aggregate is wrong.');
$assert(($event->payload['followup_id'] ?? null) === '123', 'Follow-up event must contain followup_id.');
$assert($event->metadata->actorType === 'USER' && $event->metadata->actorId === '7', 'Follow-up event actor metadata is wrong.');

$duplicateStore = new SalesV05EventStore();
$duplicate = new ScheduleDealFollowup(new SalesV05FollowupRepository(true), new EventBus($duplicateStore, $transactions), $transactions);
$duplicate->execute(new ScheduleFollowupCommand('org-1', '42', 'Call back', null, $dueAt, 'key-1'), 'corr-2');
$assert($duplicateStore->events === [], 'Duplicate follow-up must not publish another event.');

$files = [
    'routes' => $root . '/app/Interfaces/Web/Routing/SalesRoutes.php',
    'frontend_routes' => $root . '/app/Interfaces/Web/Routing/FrontendRoutes.php',
    'api' => $root . '/app/Interfaces/Api/Controller/SalesController.php',
    'view' => $root . '/app/Interfaces/Web/View/sales/deal.phtml',
    'pipeline' => $root . '/app/Interfaces/Web/View/sales/pipeline.phtml',
    'today' => $root . '/app/Interfaces/Web/View/sales/today.phtml',
    'js' => $root . '/frontend/features/sales/workspace.js',
    'css' => $root . '/frontend/features/sales/workspace.css',
    'repo' => $root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlFollowupRepository.php',
];
foreach ($files as $name => $path) $assert(is_file($path), 'Missing Sales manager operations ' . $name . ' file.');
$routes = file_get_contents($files['routes']);
$frontendRoutes = file_get_contents($files['frontend_routes']);
$api = file_get_contents($files['api']);
$view = file_get_contents($files['view']);
$pipeline = file_get_contents($files['pipeline']);
$today = file_get_contents($files['today']);
$js = file_get_contents($files['js']);
$css = file_get_contents($files['css']);
$repo = file_get_contents($files['repo']);
foreach (['/quick', '/activities', '/followups', '/meetings', '/owner'] as $route) $assert(str_contains($routes, $route), 'Missing manager operation route ' . $route);
$assert(str_contains($frontendRoutes, "'/api/sales/deals/{id:[0-9]+}/stage'"), 'Canonical stage route is missing.');
foreach (['quickUpdateAction', 'activityAction', 'followupAction', 'meetingAction', 'ownerAction'] as $method) $assert(str_contains($api, $method), 'Missing manager operation API method ' . $method);
foreach (['data-operation="quick"', 'data-operation="owner"', 'data-operation="followup"', 'data-operation="meeting"', 'data-operation="activity"'] as $marker) $assert(str_contains($view, $marker), 'Deal workspace is missing ' . $marker);
foreach (['quick:', 'owner:', 'activity:', 'followup:', 'meeting:'] as $marker) $assert(str_contains($js, $marker), 'Sales JS is missing operation mapping ' . $marker);
$assert(str_contains($repo, "'followup'"), 'Follow-up repository must persist followup activity type.');

foreach (['data-sales-pipeline-root', 'data-sales-stage-dropzone', 'data-sales-deal-card', 'draggable="true"', 'data-csrf='] as $marker) {
    $assert(str_contains($pipeline, $marker), 'Pipeline workspace is missing operational marker ' . $marker);
}
foreach (['postStageChange', 'initSalesPipeline', 'is-drop-target', '/stage'] as $marker) {
    $assert(str_contains($js, $marker), 'Sales JS is missing Pipeline stage interaction ' . $marker);
}

// Today V0.6.3 moved incoming replies to the first-class Communications panel.
// Test the current semantic mappings instead of preserving the old Timeline destination.
foreach (['work', 'intelligence', 'communications'] as $anchor) {
    $assert(str_contains($today, "'" . $anchor . "'"), 'Today must map signals to Deal Workspace section ' . $anchor);
    $assert(str_contains($view, 'id="' . $anchor . '"'), 'Mapped Deal Workspace section is missing: ' . $anchor);
}
$assert(str_contains($today, "'#'.\$anchor") || str_contains($today, "'#' . \$anchor"), 'Today must append the mapped section as a Deal Workspace fragment.');
foreach (['is-drop-target', 'is-dragging'] as $marker) {
    $assert(str_contains($css, $marker), 'Sales CSS is missing Pipeline interaction state ' . $marker);
}

echo "Sales manager operations passed: canonical follow-up, Deal actions, Pipeline stage mutation and Today deep links are wired.\n";
