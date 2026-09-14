<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class RequestDocumentHandler implements ActionHandlerInterface
{
    public const TYPE = 'sales.request_document';

    public function __construct(private SalesOperationService $operations) {}
    public function supports(string $type): bool { return $type === self::TYPE; }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        $documents = array_values(array_filter((array) ($action->parameters['documents'] ?? []), 'is_string'));
        $body = trim((string) ($action->parameters['body'] ?? ''));
        if ($body === '' && $documents !== []) $body = 'Будь ласка, надішліть: ' . implode(', ', $documents) . '.';
        if ($body === '') return ExecutionResult::failure('Document request requires body or documents.');

        $result = $this->operations->sendMessage(new SendMessageCommand(
            $action->organizationId, $action->targetId, (string) ($action->parameters['channel'] ?? 'EMAIL'),
            $body, $action->idempotencyKey ?? $action->id,
        ), ['purpose' => 'document_request', 'documents' => $documents], $action->sourceType, $action->sourceId);

        return $result->successful
            ? ExecutionResult::success(['message_id' => $result->externalId, ...$result->data], ['document_requests_sent' => 1])
            : ExecutionResult::failure($result->error ?? 'Document request failed.');
    }
}
