<?php
declare(strict_types=1);
namespace Domains\Sales\Action;

use Common\Services\DatabaseService;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class SendMessageHandler implements ActionHandlerInterface
{
    public function __construct(private DatabaseService $database) {}
    public function supports(string $actionType): bool { return in_array($actionType, ['sales.send_message', 'sales.send_followup', 'sales.send_financing_followup'], true); }
    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        $body = trim((string) ($action->parameters['body'] ?? $action->parameters['message'] ?? ''));
        if ($body === '') return ExecutionResult::failure('Message body is required.');
        $channel = (string) ($action->parameters['channel'] ?? 'internal');
        $statement = $this->database->connection()->prepare(
            "INSERT INTO tn_client_case_activities (client_case_id, activity_type, title, body, completed_at) "
            . "VALUES (:id, 'message', :title, :body, NOW())"
        );
        $statement->execute(['id' => $action->targetId, 'title' => 'Outbound via ' . $channel, 'body' => $body]);
        return ExecutionResult::success(['message_id' => (string) $this->database->connection()->lastInsertId(), 'channel' => $channel], ['messages_sent' => 1]);
    }
}
