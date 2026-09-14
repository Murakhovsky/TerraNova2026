<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\DealAssignmentRepositoryInterface;
use Domains\Sales\Application\Contract\SalesAssignmentAuthorityInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AssignDealOwner
{
    public function __construct(
        private DealAssignmentRepositoryInterface $deals,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private ?SalesAssignmentAuthorityInterface $authority = null,
    ) {}

    public function execute(
        string $organizationId,
        string $dealId,
        int $ownerId,
        string $correlationId,
        string $actorType,
        string $actorId,
    ): OperationResult {
        return $this->transactions->transactional(function () use ($organizationId, $dealId, $ownerId, $correlationId, $actorType, $actorId): OperationResult {
            $this->authority?->assertCanAssign($organizationId, $actorType, $actorId, $ownerId);

            $result = $this->deals->assignOwner($organizationId, $dealId, $ownerId);
            if (!$result->successful || ($result->data['changed'] ?? false) !== true) {
                return $result;
            }

            $metadata = new EventMetadata($correlationId, null, $actorType, $actorId);
            $eventId = bin2hex(random_bytes(16));
            $this->events->publish(new DomainEvent(
                $eventId,
                $organizationId,
                SalesEventType::DEAL_OWNER_ASSIGNED,
                'deal',
                $dealId,
                [
                    'previous_owner_id' => $result->data['previous_owner_id'] ?? null,
                    'owner_id' => $ownerId,
                ],
                $metadata,
                new DateTimeImmutable(),
            ));

            // Compatibility event for existing listeners. Historical attribution uses the
            // dedicated immutable owner-assignment event above.
            $this->events->publish(ClientCaseChanged::create(
                bin2hex(random_bytes(16)),
                $organizationId,
                $dealId,
                ['assigned_user_id' => $ownerId],
                $metadata,
            ));

            return $result;
        });
    }
}
