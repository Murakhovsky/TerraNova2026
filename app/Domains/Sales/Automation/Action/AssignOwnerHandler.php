<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use Domains\Sales\Application\UseCase\AssignDealOwner;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class AssignOwnerHandler implements ActionHandlerInterface
{
    public const TYPE = 'sales.assign_owner';
    public function __construct(private AssignDealOwner $assignOwner) {}
    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        $ownerId = filter_var($action->parameters['owner_id'] ?? $action->parameters['assigned_user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ownerId === false || $ownerId < 1) return ExecutionResult::failure('A valid owner_id is required.');
        $result = $this->assignOwner->execute($action->organizationId, $action->targetId, $ownerId, $action->correlationId, $action->sourceType, $action->sourceId);
        return $result->successful
            ? ExecutionResult::success(['deal_id' => $action->targetId, 'owner_id' => $ownerId, ...$result->data], ['owners_assigned' => ($result->data['changed'] ?? false) ? 1 : 0])
            : ExecutionResult::failure($result->error ?? 'Owner assignment failed.', $result->data);
    }
}
