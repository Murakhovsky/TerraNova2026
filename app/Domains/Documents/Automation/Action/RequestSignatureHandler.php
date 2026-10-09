<?php
declare(strict_types=1);

namespace Domains\Documents\Automation\Action;

use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Domains\Documents\Application\Service\SignatureRequestService;
use Throwable;

/**
 * A signature request is NOT a signed document.
 * Delegates only to the canonical tenant-owned Documents request workflow.
 * The real signature is a separate human/provider-controlled event.
 */
final readonly class RequestSignatureHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'documents.signature.request';

    public function __construct(private SignatureRequestService $documents) {}

    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function idempotencyKey(Action $action): string
    {
        return ExternalActionIdempotency::resolve($action);
    }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'document'
            || !is_string($action->targetId) || $action->targetId === ''
            || !is_string($action->parameters['signer_id'] ?? null)
            || trim($action->parameters['signer_id']) === ''
            || !ctype_digit($action->sourceId)
            || (int) $action->sourceId <= 0) {
            return ExecutionResult::failure('Signature request requires a Document target, signer and authenticated numeric requesting actor.');
        }
        try {
            $signature = $this->documents->request(
                $action->organizationId,
                (int) $action->sourceId,
                $action->correlationId !== '' ? $action->correlationId : $action->id,
                $action->targetId,
                trim($action->parameters['signer_id']),
                $this->idempotencyKey($action),
            );
            if (!is_string($signature['signature_id'] ?? null)
                || ($signature['status'] ?? null) !== 'requested') {
                return ExecutionResult::failure('Documents did not confirm an outstanding signature request.');
            }
            return ExecutionResult::success([
                'signature_id' => $signature['signature_id'],
                'status' => 'requested',
                'document_id' => $action->targetId,
                'signature_verified' => false,
            ]);
        } catch (Throwable $failure) {
            return ExecutionResult::failure('Documents signature request failed: ' . $failure->getMessage());
        }
    }
}
