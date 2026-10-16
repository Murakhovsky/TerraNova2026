<?php
declare(strict_types=1);

namespace Platform\Documents\Contract;

/** Stable generation port; uses canonical Documents persistence and storage. */
interface DocumentTemplateGenerationPort extends DocumentsCapabilityBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function generateFromTemplate(
        string $organizationId, int $actorId, string $correlationId,
        string $templateId, string $idempotencyKey, array $input,
    ): array;
}
