<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use Domains\Sales\Application\Contract\DealRepositoryInterface;
use Domains\Sales\Model\DealChangeSet;
use InvalidArgumentException;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class UpdateDealHandler implements ActionHandlerInterface
{
    public const TYPE = 'sales.update_deal';

    public function __construct(private DealRepositoryInterface $deals)
    {
    }

    public function supports(string $actionType): bool
    {
        return $actionType === self::TYPE;
    }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) {
            return ExecutionResult::failure('Deal target is required.');
        }

        try {
            $changes = DealChangeSet::fromArray($action->parameters);
        } catch (InvalidArgumentException $exception) {
            return ExecutionResult::failure($exception->getMessage());
        }

        $result = $this->deals->update($action->organizationId, $action->targetId, $changes);
        return $result->successful
            ? ExecutionResult::success(['deal_id' => $action->targetId, ...$result->data], ['deals_updated' => 1])
            : ExecutionResult::failure($result->error ?? 'Deal update failed.', $result->data);
    }
}
