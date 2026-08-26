<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class SendMessageHandler implements ActionHandlerInterface
{
    public const TYPES = ['sales.send_message', 'sales.send_followup', 'sales.send_financing_followup'];

    public function __construct(private MessageGatewayInterface $messages)
    {
    }

    public function supports(string $actionType): bool
    {
        return in_array($actionType, self::TYPES, true);
    }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) {
            return ExecutionResult::failure('Deal target is required.');
        }
        $body = trim((string) ($action->parameters['body'] ?? $action->parameters['message'] ?? ''));
        if ($body === '') {
            return ExecutionResult::failure('Message body is required.');
        }

        $result = $this->messages->send(new SendMessageCommand(
            $action->organizationId,
            $action->targetId,
            (string) ($action->parameters['channel'] ?? 'internal'),
            $body,
            $action->idempotencyKey ?? $action->id,
        ));

        return $result->successful
            ? ExecutionResult::success(['message_id' => $result->externalId, ...$result->data], ['messages_sent' => 1])
            : ExecutionResult::failure($result->error ?? 'Message delivery failed.', $result->data);
    }
}
