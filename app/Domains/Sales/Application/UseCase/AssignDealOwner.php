<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\DealAssignmentRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AssignDealOwner
{
    public function __construct(
        private DealAssignmentRepositoryInterface $deals,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {}

    public function execute(string $organizationId, string $dealId, int $ownerId, string $correlationId, string $actorType, string $actorId): OperationResult
    {
        return $this->transactions->transactional(function () use ($organizationId, $dealId, $ownerId, $correlationId, $actorType, $actorId): OperationResult {
            $result = $this->deals->assignOwner($organizationId, $dealId, $ownerId);
            if (!$result->successful || ($result->data['changed'] ?? false) !== true) return $result;
            $this->events->publish(ClientCaseChanged::create(
                bin2hex(random_bytes(16)), $organizationId, $dealId, ['assigned_user_id' => $ownerId],
                new EventMetadata($correlationId, null, $actorType, $actorId),
            ));
            return $result;
        });
    }
}
