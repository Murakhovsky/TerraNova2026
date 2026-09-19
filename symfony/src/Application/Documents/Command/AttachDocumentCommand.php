<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class AttachDocumentCommand implements CommandInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $documentId,
        public string $relatedType,
        public string $relatedId,
        public string $idempotencyKey,
    ) {}
}
