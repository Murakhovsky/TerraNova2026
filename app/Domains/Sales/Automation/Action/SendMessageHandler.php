<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;

final readonly class SendMessageHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPES = ['sales.send_message', 'sales.send_followup', 'sales.send_financing_followup'];

    public function __construct(private SalesOperationService $operations) {}
    public function supports(string $actionType): bool { return in_array($actionType, self::TYPES, true); }
    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        $body = trim((string) ($action->parameters['body'] ?? $action->parameters['message'] ?? ''));
        if ($body === '') return ExecutionResult::failure('Message body is required.');

        $result = $this->operations->sendMessage(new SendMessageCommand(
            $action->organizationId, $action->targetId, (string) ($action->parameters['channel'] ?? 'WEB'),
            $body, $this->idempotencyKey($action),
        ), ['purpose' => 'sales_message'], $action->sourceType, $action->sourceId);

        return $result->successful
            ? ExecutionResult::success(['message_id' => $result->externalId, ...$result->data], ['messages_sent' => 1])
            : ExecutionResult::failure($result->error ?? 'Message delivery failed.', $result->data);
    }
}
