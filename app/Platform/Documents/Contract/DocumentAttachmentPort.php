<?php
declare(strict_types=1);

namespace Platform\Documents\Contract;

interface DocumentAttachmentPort extends DocumentsCapabilityBoundary
{
    /** @return array<string,mixed> */
    public function attachExistingDocument(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $documentId,
        string $relatedType,
        string $relatedId,
        string $idempotencyKey,
    ): array;
}
