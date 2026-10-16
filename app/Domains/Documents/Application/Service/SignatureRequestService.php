<?php
declare(strict_types=1);

namespace Domains\Documents\Application\Service;

use InvalidArgumentException;
use Platform\Documents\Service\DocumentsRuntimeService;

/**
 * Documents owns signature-request workflow semantics; persistence and
 * document bytes remain Platform Documents' canonical responsibility.
 */
final readonly class SignatureRequestService
{
    public function __construct(private DocumentsRuntimeService $documents) {}

    /** @return array<string,mixed> */
    public function request(
        string $organizationId,
        int $requestingActorId,
        string $correlationId,
        string $documentId,
        string $signerId,
        string $idempotencyKey,
    ): array {
        if ($organizationId === '' || $requestingActorId <= 0
            || trim($documentId) === '' || trim($signerId) === ''
            || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Documents signature request requires authorized tenant, actor, document, signer and idempotency key.');
        }
        return $this->documents->requestSignature(
            $organizationId, $requestingActorId, $correlationId,
            $documentId, $signerId, $idempotencyKey,
        );
    }
}
