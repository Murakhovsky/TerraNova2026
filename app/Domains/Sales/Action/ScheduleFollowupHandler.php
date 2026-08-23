<?php
declare(strict_types=1);
namespace Domains\Sales\Action;

use Common\Services\DatabaseService;
use DateTimeImmutable;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class ScheduleFollowupHandler implements ActionHandlerInterface
{
    public function __construct(private DatabaseService $database) {}
    public function supports(string $actionType): bool { return $actionType === 'sales.schedule_followup'; }
    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        $dueAt = isset($action->parameters['due_at'])
            ? new DateTimeImmutable((string) $action->parameters['due_at'])
            : (new DateTimeImmutable())->modify('+' . max(1, (int) ($action->parameters['due_in_minutes'] ?? 1440)) . ' minutes');
        $pdo = $this->database->connection();
        $pdo->prepare('UPDATE tn_client_cases SET next_contact_at = :due_at WHERE id = :id')
            ->execute(['id' => $action->targetId, 'due_at' => $dueAt->format('Y-m-d H:i:s')]);
        $statement = $pdo->prepare(
            "INSERT INTO tn_client_case_activities (client_case_id, activity_type, title, body, due_at) "
            . "VALUES (:id, 'task', :title, :body, :due_at)"
        );
        $statement->execute([
            'id' => $action->targetId, 'title' => (string) ($action->parameters['title'] ?? 'Follow-up'),
            'body' => $action->parameters['body'] ?? null, 'due_at' => $dueAt->format('Y-m-d H:i:s'),
        ]);
        return ExecutionResult::success(['followup_id' => (string) $pdo->lastInsertId(), 'due_at' => $dueAt->format(DATE_ATOM)], ['followups_scheduled' => 1]);
    }
}
