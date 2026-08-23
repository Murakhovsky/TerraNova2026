<?php
declare(strict_types=1);
namespace Domains\Sales\Action;

use Common\Services\DatabaseService;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class UpdateDealHandler implements ActionHandlerInterface
{
    public function __construct(private DatabaseService $database) {}
    public function supports(string $actionType): bool { return $actionType === 'sales.update_deal'; }
    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        $allowed = ['stage', 'status', 'priority', 'next_contact_at'];
        $changes = array_intersect_key($action->parameters, array_flip($allowed));
        if ($changes === []) return ExecutionResult::failure('No allowed Deal fields supplied.');
        $sets = []; $params = ['id' => $action->targetId];
        foreach ($changes as $field => $value) { $sets[] = $field . ' = :' . $field; $params[$field] = $value; }
        $statement = $this->database->connection()->prepare('UPDATE tn_client_cases SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $statement->execute($params);
        return ExecutionResult::success(['deal_id' => $action->targetId, 'changes' => $changes], ['rows_affected' => $statement->rowCount()]);
    }
}
