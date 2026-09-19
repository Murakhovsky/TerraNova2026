<?php
declare(strict_types=1);

namespace App\Application\Documents\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SignDocumentCommand implements CommandInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $signatureId,
        public string $signedBy,
        public string $signatureReference,
        public string $idempotencyKey,
    ) {}
}
